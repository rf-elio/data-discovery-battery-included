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
use Elio\ElioSearch\Api\Search\Response\ProductListingResponse;
use Elio\ElioSearch\Api\Transform\ResponseTransformerInterface;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Elio\ElioSearch\Swagger\ModelInterface;
use Elio\ElioBatteryIncludedApiClient\Model\Result;

/**
 * Adds shopware's sorting translations
 *
 * Class TranslationSortTransformer
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer
 * @category  Shopware
 * @author    elio GmbH <support@elio-systems.com>
 * @author    Ralf Frommherz <rf@elio-systems.com>
 * @copyright Copyright (c) 2021, elio GmbH (https://www.elio-systems.com)
 */
class TranslationSortTransformer implements ResponseTransformerInterface
{
    /**
     * SortTransformer constructor.
     * @param EntityRepository $sortingRepository
     * @param ResponseTransformerInterface $decorated
     */
    public function __construct(
        private readonly EntityRepository $sortingRepository,
        private readonly ResponseTransformerInterface $decorated
    ) {}

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
    public function transform(ModelInterface $model, ResponseCollection $responseCollection, SalesChannelContext $context, ApiRequest $request): void
    {
        $this->decorated->transform($model, $responseCollection, $context, $request);

        $listing = $responseCollection->get(ProductListingResponse::class) ?? new ProductListingResponse();
        if($sortingCollection = $listing->getAvailableSortings()) {
            $this->translate($sortingCollection, $context);
        }
    }

    /**
     * Translates the snippets based on the shopware product sorting labels
     *
     * @param ProductSortingCollection $sortingCollection
     * @param SalesChannelContext $context
     */
    protected function translate(ProductSortingCollection $sortingCollection, SalesChannelContext $context): void
    {
        $existingSortings = $this->getProductSortings($context);

        foreach ($sortingCollection as $sortingCollectionKey => $sorting) {
            $key = $sorting->getKey();
            $existingSorting = $existingSortings[$key] ?? null;

            if(!$existingSorting) {
                $this->createProductSorting($sorting, $context);
            } else {
                $sorting->setLabel($existingSorting->getTranslations()['label'] ?? $existingSorting->getLabel());
                $sorting->setTranslated($existingSorting->getTranslated());
                $sorting->setActive($existingSorting->isActive());

                if (!$sorting->isActive()) {
                    $sortingCollection->remove($sortingCollectionKey);
                }
            }
        }
    }

    /**
     * Fetches all existing sortings
     *
     * @param SalesChannelContext $context
     * @return ProductSortingEntity[]
     */
    protected function getProductSortings(SalesChannelContext $context): array
    {
        $criteria = new Criteria();
        $existingSortings = $this->sortingRepository->search($criteria, $context->getContext());
        $sortingsByKey = [];

        /** @var ProductSortingEntity $existingSorting */
        foreach ($existingSortings as $existingSorting) {
            $sortingsByKey[$existingSorting->getKey()] = $existingSorting;
        }

        return $sortingsByKey;
    }

    /**
     * Creates the shopware product sorting
     *
     * @param ProductSortingEntity $sorting
     * @param SalesChannelContext $context
     */
    protected function createProductSorting(ProductSortingEntity $sorting, SalesChannelContext $context) : void
    {
        $data = [
            'key' => $sorting->getKey(),
            'priority' => $sorting->getPriority(),
            'active' => $sorting->isActive(),
            'fields' => $sorting->getFields(),
            'label' => $sorting->getLabel(),
            'translation' => $sorting->getTranslations(),
            'locked' => false
        ];

        $this->sortingRepository->create([$data], $context->getContext());
    }
}
