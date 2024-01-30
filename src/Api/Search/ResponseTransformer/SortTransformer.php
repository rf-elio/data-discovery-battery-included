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


use Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Util\LocaleFilterUtil;
use Elio\ElioBatteryIncludedSearchExtension\Api\Service\LocaleService;
use Elio\ElioSearch\Api\Request\ApiRequest;
use Elio\ElioSearch\Api\Response\ResponseCollection;
use Elio\ElioSearch\Api\Search\Request\NavigationRequestProduct;
use Elio\ElioSearch\Api\Search\Response\ProductListingResponse;
use Elio\ElioSearch\Api\Transform\ResponseTransformerInterface;
use Elio\ElioSearch\Core\Exception\InvalidTypeException;
use Elio\ElioSearch\Core\FilterRestrictions\FilterEntity;
use Elio\ElioSearch\Core\FilterRestrictions\FilterInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingCollection;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Elio\ElioSearch\Swagger\ModelInterface;
use Elio\ElioBatteryIncludedApiClient\Model\Result;

/**
 * Adds sortings to the result
 *
 * Class SortTransformer
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer
 * @category  Shopware
 * @author    elio GmbH <support@elio-systems.com>
 * @author    Ralf Frommherz <rf@elio-systems.com>
 * @copyright Copyright (c) 2021, elio GmbH (https://www.elio-systems.com)
 */
class SortTransformer implements ResponseTransformerInterface
{
    public const ASCENDING = 'asc';
    public const DESCENDING = 'desc';
    public const CATEGORY_PATH_REPLACE = '%categoryPath%';

    public function __construct(
        private readonly FilterInterface $filterService,
        private readonly LocaleService $localeService,
        private readonly LoggerInterface $logger
    )
    {
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
    public function transform(ModelInterface $model, ResponseCollection $responseCollection, SalesChannelContext $context, ApiRequest $request): void
    {
        if(!$model instanceof Result) {
            throw new InvalidTypeException($model, Result::class);
        }

        $listing = $responseCollection->get(ProductListingResponse::class) ?? new ProductListingResponse();
        $responseCollection->set(ProductListingResponse::class, $listing);

        $sortingCollection = new ProductSortingCollection();
        $listing->setAvailableSortings($sortingCollection);

        $locale = $this->localeService->getLocaleByContext($context);
        $filters = $this->filterService->getFilterByType(FilterEntity::FILTER_TYPE_SORTING, $context);
        $filterNames = [];
        /** @var FilterEntity $filter */
        foreach ($filters as $filter) {
            $filterNames[] = $filter->getTechnicalName();
        }

        $allowedFilterNames = $this->filterService->filter($filterNames, FilterEntity::FILTER_TYPE_SORTING, $request, $context);

        $categoryPath = null;
        if ($request instanceof NavigationRequestProduct) {
            $categoryPath = $request->getCategoryPath();
            $categoryPath = implode(' > ', $categoryPath);
        }

        $priority = 0;
        foreach ($filters as $filter) {
            if (!in_array($filter->getTechnicalName(), $allowedFilterNames, true)) {
                continue;
            }

            // only keep filters that match the current locale
            if (!LocaleFilterUtil::fieldByLocalAllowed($filter->getTechnicalName(), $locale)) {
                continue;
            }

            $sortingLabelChunks = explode(':', $filter->getTechnicalName());
            if (count($sortingLabelChunks) !== 2) {
                $this->logger->warning(sprintf('Wrong configuration for sorting label %s', $filter->getTechnicalName()));
                continue;
            }

            $direction = $sortingLabelChunks[1];
            $key = $sortingLabelChunks[0] . '.' . $direction;

            if (!$categoryPath && str_contains($key, self::CATEGORY_PATH_REPLACE)) {
                continue;
            } elseif ($categoryPath) {
                $key = str_replace(self::CATEGORY_PATH_REPLACE, $categoryPath, $key);
            }
            
            $label = $filter->getTranslation('propertyName');

            $sorting = new ProductSortingEntity();
            $sorting->setId(Uuid::randomHex());
            $sorting->setKey($key);
            $sorting->setPriority($priority);
            $sorting->setActive(true);
            $sorting->setLabel($label);
            $sorting->setTranslated(['label' => $label]);
            $sorting->setFields([[
                'field' => '_'.$sorting->getKey(),
                'order' => $direction === self::ASCENDING ? FieldSorting::ASCENDING : FieldSorting::DESCENDING,
                'priority' => $sorting->getPriority(),
                'naturalSorting' => false
            ]]);
            $sorting->setLocked(false);
            $sorting->setUniqueIdentifier($key);
            $sortingCollection->add($sorting);

            if ($request->getSort() !== null && implode('.', $request->getSort()) === $key) {
                $listing->setCurrentSorting($sorting);
            }

            if ($listing->getCurrentSorting() === null && $filter->isDisplayedByDefault()) {
                $listing->setCurrentSorting($sorting);
            }

            $priority++;
        }
    }
}
