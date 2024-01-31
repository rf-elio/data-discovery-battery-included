<?php
/**
 * Copyright (c) 2023, elio GmbH.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its contributors
 * may be used to endorse or promote products derived from this software without
 * specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;


use Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Util\LocaleFilterUtil;
use Elio\ElioBatteryIncludedSearchExtension\Api\Service\LocaleService;
use Elio\ElioSearch\Api\Request\ApiRequest;
use Elio\ElioSearch\Api\Response\ResponseCollection;
use Elio\ElioSearch\Api\Search\Response\ProductListingResponse;
use Elio\ElioSearch\Api\Transform\ResponseTransformerInterface;
use Elio\ElioSearch\Core\Exception\InvalidTypeException;
use Elio\ElioSearch\Core\FilterRestrictions\FilterEntity;
use Elio\ElioSearch\Core\FilterRestrictions\FilterInterface;
use Elio\ElioSearch\Core\Framework\DataAbstractionLayer\Search\AggregationResult\DefaultFacetExtension;
use Elio\ElioSearch\Core\Framework\DataAbstractionLayer\Search\AggregationResult\FacetCollection;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\Tree\Tree;
use Shopware\Core\Content\Category\Tree\TreeItem;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\EntityResult;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Elio\ElioSearch\Swagger\ModelInterface;
use Elio\ElioBatteryIncludedApiClient\Model\Result;

/**
 * Class FacetTransformer
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer
 * @category  Shopware
 * @author    elio GmbH <support@elio-systems.com>
 * @author    Ralf Frommherz <rf@elio-systems.com>
 * @copyright Copyright (c) 2023, elio GmbH (https://www.elio-systems.com)
 */
class FacetTransformer implements ResponseTransformerInterface
{
    public function __construct(
        private readonly FilterInterface $filterService,
        private readonly LocaleService $localeService
    ) {
    }

    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof Result;
    }

    public function transform(
        ModelInterface $model,
        ResponseCollection $responseCollection,
        SalesChannelContext $context,
        ApiRequest $request
    ): void {
        if (!$model instanceof Result) {
            throw new InvalidTypeException($model, Result::class);
        }

        if (!$model->getFacetCounts()) {
            return;
        }

        $listing = $responseCollection->get(ProductListingResponse::class) ?? new ProductListingResponse();
        $responseCollection->set(ProductListingResponse::class, $listing);
        $locale = $this->localeService->getLocaleByContext($context);
        $filters = $this->filterService->getFilterByType(FilterEntity::FILTER_TYPE_FILTER, $context);

        $filterNames = [];
        foreach ($model->getFacetCounts() as $facet) {
            $filterNames[] = $facet->field_name;
        }

        $allowedFilterNames = $this->filterService->filter($filterNames, FilterEntity::FILTER_TYPE_FILTER, $request, $context);
        $aggregationResultCollection = $listing->getAggregations() ?? new AggregationResultCollection();
        $listing->setAggregations($aggregationResultCollection);

        $facetCollection = new FacetCollection('elio-search-default');
        $aggregationResultCollection->add($facetCollection);

        foreach ($model->getFacetCounts() as $facet) {
            $fieldName = $facet->field_name;
            if (!in_array($fieldName, $allowedFilterNames, true)) {
                continue;
            }

            // only keep filters that match the current locale
            if (!LocaleFilterUtil::fieldByLocalAllowed($fieldName, $locale)) {
                continue;
            }

            $style = 'TREE'; // TODO: Fetch style from custom fields
            $name = $this->getFilterName($fieldName, $filters->getElements());
            switch ($style) {
                case 'DEFAULT':
                    $defaultCollection = new PropertyGroupCollection();
                    $entity = $this->transformDefault($facet, $request);
                    $defaultCollection->add($entity);
                    $facetCollection->addAggregation(
                        new EntityResult($name, $defaultCollection),
                        $style
                    );
                    break;
                case 'TREE':
                    $tree = $this->transformCategoryTree($facet, $context);
                    $group = $this->transformTree($facet);
                    $defaultCollection = new PropertyGroupCollection();
                    $defaultCollection->add($group);
                    $facetCollection->addAggregation(
                        new EntityResult($name, $defaultCollection),
                        $style
                    );
                    $defaultCollection->addExtension('elioSearchTree', $tree);
                    break;
            }
        }

        foreach ($facetCollection->getAggregations() as $aggregation){
            $aggregationResultCollection->add($aggregation);
        }
    }

    /**
     * Transforms the default filter to an "property" filter
     *
     * @param object $facet
     * @param ApiRequest $request
     * @return PropertyGroupEntity
     */
    protected function transformDefault(object $facet, ApiRequest $request): PropertyGroupEntity
    {
        $options = new PropertyGroupOptionCollection();

        foreach ($facet->counts as $element) {
            $elementLabel = $element->value;
            $option = new PropertyGroupOptionEntity();
            $option->setId(Uuid::randomHex());
            $option->setUniqueIdentifier(Uuid::randomHex());
            $option->setName($elementLabel);
            $option->setTranslated(['name' => $elementLabel]);
            $option->addExtension(DefaultFacetExtension::KEY, new DefaultFacetExtension(
                $facet->field_name, $element->value,
                $element->count,
                false // @todo: $element->getSelected() === 'TRUE'
            ));
            $options->add($option);
        }

        $group = new PropertyGroupEntity();
        $group->setId(Uuid::randomHex());
        $group->setUniqueIdentifier(Uuid::randomHex());
        $group->setOptions($options);
        $group->setName($facet->field_name);
        $group->setTranslated(['name' => $facet->field_name]);
        $group->setDisplayType('text');
        $group->addExtension(DefaultFacetExtension::KEY, new ArrayStruct([
            'selectedCount' => 0 // @todo: count($facet->getSelectedElements())
        ]));
        return $group;
    }

    /**
     * @param object $facet
     * @param SalesChannelContext $salesChannelContext
     * @return Tree
     */
    protected function transformCategoryTree(object $facet, SalesChannelContext $salesChannelContext): Tree
    {
        $rootTree = [];
        $treeItems = [];
        foreach ($facet->counts as $element) {
            $labels = array_map('trim', explode('>', $element->value));
            $level = count($labels) - 1;
            $elementLabel = !empty($labels) ? trim(end($labels)) : $element->value;

            $category = new CategoryEntity();
            $category->setId(Uuid::randomHex());
            $category->setName($element->value);
            $category->setTranslated(['name' => $elementLabel]);

            $treeItem = new TreeItem($category, []);

            if ($level === 0) {
                $treeItems[$level][$elementLabel] = $treeItem;
                $rootTree[] = $treeItem;
            } else {
                /** @var TreeItem $previousItem */
                $previousItem = $treeItems[$level - 1][prev($labels)];
                $treeItems[$level][$elementLabel] = $treeItem;
                $previousItem->addChildren($treeItem);
            }
        }

        $tree = new Tree(null, $rootTree);

        $counts = [];
        foreach ($facet->counts as $element){
            $counts[$element->value] = $element->count;
        }

        $treeItems = [];
        foreach ($tree->getTree() as $treeItem){
            if (!isset($counts[$treeItem->getCategory()->getName()])) {
                continue;
            }

            $treeItems[] = $treeItem;
            $this->recursiveAddTreeItemExtension($facet, $treeItem, $counts);
        }

        $tree->setTree($treeItems);

        return $tree;
    }

    /**
     * @param object $facet
     * @param TreeItem $treeItem
     * @param array $counts
     */
    protected function recursiveAddTreeItemExtension(object $facet, TreeItem $treeItem, array $counts): void
    {
        $itemCategory = $treeItem->getCategory();

        if($itemCategory->getName() !== null)
            $treeItem->addExtension(DefaultFacetExtension::KEY, new DefaultFacetExtension(
                $facet->field_name, $itemCategory->getName(),
                $counts[$itemCategory->getName()] ?? 0
            ));

        foreach ($treeItem->getChildren() as $childItem) {
            $this->recursiveAddTreeItemExtension($facet, $childItem, $counts);
        }
    }

    /**
     * @param object $facet
     * @return PropertyGroupEntity
     */
    protected function transformTree(object $facet): PropertyGroupEntity
    {
        $options = new PropertyGroupOptionCollection();
        $group = new PropertyGroupEntity();
        $group->setId(Uuid::randomHex());
        $group->setUniqueIdentifier(Uuid::randomHex());
        $group->setOptions($options);
        $group->setName($facet->field_name);
        $group->setTranslated(['name' => $facet->field_name]);
        $group->setDisplayType('text');

        foreach ($facet->counts as $element) {
            $elementLabel = $element->value;
            $option = new PropertyGroupOptionEntity();
            $option->setId(Uuid::randomHex());
            $option->setUniqueIdentifier(Uuid::randomHex());
            $option->setName($elementLabel);
            $option->setTranslated(['name' => $elementLabel]);
            $option->addExtension(DefaultFacetExtension::KEY, new DefaultFacetExtension(
                $facet->field_name, $elementLabel,
                $element->count
            ));
            $options->add($option);
        }

        return $group;
    }

    protected function getFilterName(string $fieldName, array $filters): string
    {
        /** @var FilterEntity $filter */
        foreach ($filters as $filter) {
            if ($filter->getTechnicalName() === $fieldName) {
                return $filter->getTranslation('propertyName');
            }
        }

        return $fieldName;
    }
}