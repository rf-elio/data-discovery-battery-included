<?php
/**
 * Copyright (c) 2021, elio GmbH.
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


use Elio\ElioSearch\Api\Request\ApiRequest;
use Elio\ElioSearch\Api\Response\ResponseCollection;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\Request\NavigationRequestProduct;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\Request\ProductSearchRequest;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\Request\SearchRequest;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\Response\ProductListingResponse;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Facet\FacetTreeHelper;
use Elio\ElioSearch\Api\Transform\ResponseTransformerInterface;
use Elio\ElioSearch\Core\Exception\InvalidTypeException;
use Elio\ElioSearch\Core\FilterRestrictions\FilterInterface;
use Elio\ElioSearch\Core\Framework\DataAbstractionLayer\Search\AggregationResult\FacetCollection;
use Elio\ElioSearch\Core\Framework\DataAbstractionLayer\Search\AggregationResult\DefaultFacetExtension;
use Elio\ElioSearch\Core\Framework\DataAbstractionLayer\Search\AggregationResult\SliderResult;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\Tree\Tree;
use Shopware\Core\Content\Category\Tree\TreeItem;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\EntityResult;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Struct\Collection;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swagger\Client\Model\Facet;
use Swagger\Client\Model\FacetElement;
use Swagger\Client\Model\ModelInterface;
use Swagger\Client\Model\Result;
use Elio\ElioSearch\Core\FilterRestrictions\FilterService;

/**
 * Converts the facets to shopware facets
 *
 * Class FacetTransformer
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer
 * @category  Shopware
 * @author    elio GmbH <support@elio-systems.com>
 * @author    Ralf Frommherz <rf@elio-systems.com>
 * @copyright Copyright (c) 2021, elio GmbH (https://www.elio-systems.com)
 */
class FacetTransformer implements ResponseTransformerInterface
{
    private FilterInterface $filterService;

    /**
     * ProductHandler constructor.
     * @param FilterInterface $filterService
     */
    public function __construct(
        FilterInterface $filterService
    ) {
        $this->filterService = $filterService;
    }

    /**
     * @inheritDoc
     */
    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof Result;
    }

    /**
     * @param ModelInterface $model
     * @param ResponseCollection $responseCollection
     * @param SalesChannelContext $context
     * @param ApiRequest $request
     */
    public function transform(
        ModelInterface $model,
        ResponseCollection $responseCollection,
        SalesChannelContext $context,
        ApiRequest $request
    ): void {
        if (!$model instanceof Result) {
            throw new InvalidTypeException($model, Result::class);
        }

        $level = FilterService::LEVEL_GLOBAL;
        if ($request instanceof NavigationRequestProduct) {
            $level = FilterService::LEVEL_CATEGORY;
        } else if ($request instanceof ProductSearchRequest) {
            $level = FilterService::LEVEL_SEARCH;
        }

        $filtersRestrictions = $this->filterService->getFilters($context, $level, $request);
        $listing = $responseCollection->get(ProductListingResponse::class) ?? new ProductListingResponse();
        $responseCollection->set(ProductListingResponse::class, $listing);

        $aggregationResultCollection = $listing->getAggregations() ?? new AggregationResultCollection();
        $listing->setAggregations($aggregationResultCollection);

        $facetCollection = new FacetCollection('ff-default');
        $aggregationResultCollection->add($facetCollection);
        foreach ($model->getFacets() as $facet) {
            if ($filtersRestrictions[1] === null) { // blocked all
                continue;
            }

            if (
                (($filtersRestrictions[0] !== null) && !in_array($facet->getName(), $filtersRestrictions[0], true))
                // isn't allowed
                || in_array($facet->getName(), $filtersRestrictions[1], true)
            ) {
                continue;
            }

            $style = $facet->getFilterStyle();
            switch ($style) {
                case 'MULTISELECT':
                case 'DEFAULT':
                    $defaultCollection = new PropertyGroupCollection();
                    $entity = $this->transformDefault($facet, $request);
                    $defaultCollection->add($entity);
                    /** @var EntityCollection<Entity> $defaultCollection */
                    $facetCollection->addAggregation(
                        new EntityResult($facet->getName(), $defaultCollection),
                        'DEFAULT'
                    );
                    break;
                case 'SLIDER':
                    $facetCollection->addAggregation(
                        $this->transformSlider($facet),
                        $style
                    );
                    break;
                case 'TREE':
                    $tree = $this->transformTreeFacet($facet);
                    $defaultCollection = new PropertyGroupCollection();
                    $defaultCollection->add($this->transformTree($facet, FacetTreeHelper::flattenTree($tree->getTree())));

                    /** @var EntityCollection<Entity> $defaultCollection */
                    $aggregation = new EntityResult($facet->getName(), $defaultCollection);
                    $aggregation->addExtension('ffTree', $tree);

                    $facetCollection->addAggregation($aggregation, $style);
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
     * @param Facet $facet
     * @param ApiRequest $request
     * @return PropertyGroupEntity
     */
    protected function transformDefault(Facet $facet, ApiRequest $request): PropertyGroupEntity
    {
        $options = new PropertyGroupOptionCollection();
        $elements = array_merge($facet->getSelectedElements(), $facet->getElements());

        foreach ($elements as $element) {
            $elementLabel = $element->getText();
            $option = new PropertyGroupOptionEntity();
            $option->setId(Uuid::randomHex());
            $option->setUniqueIdentifier(Uuid::randomHex());
            $option->setName($elementLabel);
            $option->setTranslated(['name' => $elementLabel]);
            $option->addExtension(DefaultFacetExtension::KEY, new DefaultFacetExtension(
                $facet->getAssociatedFieldName(), $this->resolveDefaultElementValue($facet, $element, $request),
                $element->getTotalHits(),
                $element->getSelected() === 'TRUE'
            ));
            $options->add($option);
        }

        $group = new PropertyGroupEntity();
        $group->setId(Uuid::randomHex());
        $group->setUniqueIdentifier(Uuid::randomHex());
        $group->setOptions($options);
        $group->setName($facet->getName());
        $group->setTranslated(['name' => $facet->getName()]);
        $group->setDisplayType('text');
        $group->addExtension(DefaultFacetExtension::KEY, new ArrayStruct([
            'selectedCount' => count($facet->getSelectedElements())
        ]));
        return $group;
    }

    /**
     * @param Facet $facet
     * @param FacetElement $element
     * @param ApiRequest $request
     * @return string
     */
    private function resolveDefaultElementValue(Facet $facet, FacetElement $element, ApiRequest $request): string
    {
        if (
            $facet->getType() !== 'FLOAT' ||
            !$element->getSearchParams()->getFilters()
        ) {
            return $element->getText();
        }


        // take the first value from the search params only for FLOAT facet
        foreach ($element->getSearchParams()->getFilters() as $filter) {
            if ($filter->getValues() && $facet->getAssociatedFieldName() === $filter->getName()) {
                return $filter->getValues()[0]->getValue()[0];
            }
        }

        if ($request instanceof SearchRequest) {
            // extract original technical filter value from request to restore it in the element filter selection
            foreach ($request->getFilter() as $filterName => $filter) {
                if ($filter['values'] && $facet->getAssociatedFieldName() === $filterName) {
                    return $filter['values'][0];
                }
            }
        }

        return $element->getText();
    }

    /**
     * Transforms slider filters
     *
     * @param Facet $facet
     * @return SliderResult|null
     */
    protected function transformSlider(Facet $facet): ?SliderResult
    {
        $minValue = null;
        $maxValue = null;
        $elements = array_merge($facet->getElements(), $facet->getSelectedElements());
        foreach ($elements as $element) {
            $minValue = $element->getAbsoluteMinValue();
            $maxValue = $element->getAbsoluteMaxValue();
        }

        if (!$minValue || !$maxValue) {
            return null;
        }

        return new SliderResult(
            $facet->getName(),
            $minValue, $maxValue,
            null, null,
            $facet->getUnit()
        );
    }

    /**
     * @param Facet $facet
     * @return Tree
     */
    protected function transformTreeFacet(Facet $facet): Tree
    {
        $nodes = FacetTreeHelper::transformTreeFacet($facet);
        $treeItems = FacetTreeHelper::convertNodesToTreeItems($facet, $nodes);
        FacetTreeHelper::calculateTreeHits($treeItems);

        $treeRoot = new CategoryEntity();
        $treeRoot->setId(Uuid::randomHex());
        return new Tree($treeRoot, $treeItems);
    }

    /**
     * @param Facet $facet
     * @param Collection $items
     * @return PropertyGroupEntity
     */
    protected function transformTree(Facet $facet, Collection $items): PropertyGroupEntity
    {
        $options = new PropertyGroupOptionCollection();
        $group = new PropertyGroupEntity();
        $group->setId(Uuid::randomHex());
        $group->setUniqueIdentifier(Uuid::randomHex());
        $group->setOptions($options);
        $group->setName($facet->getName());
        $group->setTranslated(['name' => $facet->getName()]);
        $group->setDisplayType('text');

        /** @var TreeItem $element */
        foreach ($items as $element) {
            $category = $element->getCategory();
            $option = new PropertyGroupOptionEntity();
            $option->setId($category->getId());
            $option->setUniqueIdentifier($category->getId());
            $option->setName($category->getName());
            $option->setTranslated($category->getTranslated());
            $option->addExtension(DefaultFacetExtension::KEY, $element->getExtension(DefaultFacetExtension::KEY));
            $options->add($option);
        }

        return $group;
    }
}
