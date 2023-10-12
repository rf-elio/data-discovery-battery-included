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


use Elio\ElioBatteryIncludedSearchExtension\Configuration\BatteryIncludedConfiguration;
use Elio\ElioSearch\Api\Request\ApiRequest;
use Elio\ElioSearch\Api\Response\ResponseCollection;
use Elio\ElioSearch\Api\Search\Request\NavigationRequestProduct;
use Elio\ElioSearch\Api\Search\Request\ProductSearchRequest;
use Elio\ElioSearch\Api\Search\Response\ProductListingResponse;
use Elio\ElioSearch\Api\Transform\ResponseTransformerInterface;
use Elio\ElioSearch\Configuration\ElioSearchConfigService;
use Elio\ElioSearch\Core\Exception\InvalidTypeException;
use Elio\ElioSearch\Core\FilterRestrictions\FilterService;
use Elio\ElioSearch\Core\Framework\DataAbstractionLayer\Search\AggregationResult\DefaultFacetExtension;
use Elio\ElioSearch\Core\Framework\DataAbstractionLayer\Search\AggregationResult\FacetCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Content\Property\PropertyGroupCollection;
use Shopware\Core\Content\Property\PropertyGroupEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\AggregationResultCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\EntityResult;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swagger\Client\Model\ModelInterface;
use Swagger\Client\Model\Result;

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
    private ElioSearchConfigService $configService;

    public function __construct(ElioSearchConfigService $configService)
    {
        $this->configService = $configService;
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

        $level = FilterService::LEVEL_GLOBAL;
        if ($request instanceof NavigationRequestProduct) {
            $level = FilterService::LEVEL_CATEGORY;
        } else if ($request instanceof ProductSearchRequest) {
            $level = FilterService::LEVEL_SEARCH;
        }

        $listing = $responseCollection->get(ProductListingResponse::class) ?? new ProductListingResponse();
        $responseCollection->set(ProductListingResponse::class, $listing);

        $aggregationResultCollection = $listing->getAggregations() ?? new AggregationResultCollection();
        $listing->setAggregations($aggregationResultCollection);

        $facetCollection = new FacetCollection('elio-search-default');
        $aggregationResultCollection->add($facetCollection);

        /** @var BatteryIncludedConfiguration $config */
        $config = $this->configService->getByContext($context)->getExtension(BatteryIncludedConfiguration::NAME);

        foreach ($model->getFacetCounts() as $facet) {
            $defaultCollection = new PropertyGroupCollection();
            $entity = $this->transformDefault($facet, $request, $config);
            $defaultCollection->add($entity);
            /** @var EntityCollection<Entity> $defaultCollection */
            $facetCollection->addAggregation(
                new EntityResult($facet->field_name, $defaultCollection),
                'DEFAULT'
            );
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
     * @param BatteryIncludedConfiguration $config
     * @return PropertyGroupEntity
     */
    protected function transformDefault(object $facet, ApiRequest $request, BatteryIncludedConfiguration $config): PropertyGroupEntity
    {
        $options = new PropertyGroupOptionCollection();
        //$elements = array_merge($facet->getSelectedElements(), $facet->getElements());

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

        $name = $facet->field_name;
        $name = $config->getFacetLabels()[$name] ?? $name;
        $group = new PropertyGroupEntity();
        $group->setId(Uuid::randomHex());
        $group->setUniqueIdentifier(Uuid::randomHex());
        $group->setOptions($options);
        $group->setName($name);
        $group->setTranslated(['name' => $name]);
        $group->setDisplayType('text');
        $group->addExtension(DefaultFacetExtension::KEY, new ArrayStruct([
            'selectedCount' => 0 // @todo: count($facet->getSelectedElements())
        ]));
        return $group;
    }
}