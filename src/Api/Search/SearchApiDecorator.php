<?php declare(strict_types=1);
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

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search;

use Elio\ElioBatteryIncludedSearchExtension\Api\ApiClientFactory;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\SortTransformer;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Util\LocaleUtil;
use Elio\ElioBatteryIncludedSearchExtension\Api\Service\LocaleService;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Api\Search\Request\ContentSearchRequest;
use Elio\ElioDataDiscovery\Api\Search\Request\NavigationRequestProduct;
use Elio\ElioDataDiscovery\Api\Search\Request\ProductSearchRequest;
use Elio\ElioDataDiscovery\Api\Search\Request\SearchRequest;
use Elio\ElioDataDiscovery\Api\Search\SearchApi;
use Elio\ElioDataDiscovery\Api\Transform\Transformer;
use Elio\ElioDataDiscovery\Core\FilterRestrictions\FilterEntity;
use Elio\ElioDataDiscovery\Core\Sync\DataTypes\Aggregation\Visibilities;
use Elio\ElioDataDiscovery\Core\Sync\DataTypes\ContentDataType;
use Elio\ElioDataDiscovery\Core\Sync\DataTypes\ProductDataType;
use Elio\ElioDataDiscovery\Core\Util\StripClassPathUtil;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Throwable;

/**
 * Class SearchApiDecorator
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search
 * @category Shopware
 * @author elio GmbH <support@elio-systems.com>
 * @author Danil Lukov <dl@elio-systems.com>
 * @copyright Copyright (c) 2023, elio GmbH (https://www.elio-systems.com)
 */
class SearchApiDecorator extends SearchApi
{
    private const DEFAULT_SORT = 'default';

    public function __construct(
        private readonly ApiClientFactory $apiFactory,
        private readonly Transformer $transformer,
        private readonly LocaleService $localeService,
        LoggerInterface $logger,
        private readonly SystemConfigService $systemConfigService,
        private readonly EntityRepository $filterRepository
    ) {
        parent::__construct($logger);
    }

    public function search(ProductSearchRequest $searchRequest, SalesChannelContext $context): ResponseCollection
    {
        $apiClient = $this->apiFactory->createSearchApi($context);
        $locale = $this->localeService->getLocaleByContext($context);
        $filters = $this->prepareFilters($searchRequest, $context);
        $filters = $this->addSortingFilter($filters, $searchRequest, $locale, $context->getContext());
        $filters = $this->localeService->addLocaleToFilters($filters, $locale);

        $this->searchDebug('search', $this, [$searchRequest, $context, $locale]);
        $result = $apiClient->filter($searchRequest->getQuery(), $locale, $filters);
        return $this->transformer->transformResponse($result, $context, $searchRequest);
    }

    public function searchContent(ContentSearchRequest $searchRequest, SalesChannelContext $context): ResponseCollection
    {
        $locale = $this->localeService->getLocaleByContext($context);
        $apiClient = $this->apiFactory->createSearchApi($context);
        $result = $apiClient->filter(
            $searchRequest->getQuery(),
            $locale,
            ['f[type]' => StripClassPathUtil::stripClassPath(ContentDataType::class)]
        );
        return $this->transformer->transformResponse($result, $context, $searchRequest);
    }

    /**
     * Executes the elio search navigation request
     *
     * @param NavigationRequestProduct $searchRequest
     * @param SalesChannelContext $context
     * @return ResponseCollection
     * @throws Throwable
     */
    public function navigation(
        NavigationRequestProduct $searchRequest,
        SalesChannelContext $context
    ): ResponseCollection {
        $apiClient = $this->apiFactory->createSearchApi($context);
        $locale = $this->localeService->getLocaleByContext($context);
        $filters = $this->prepareFilters($searchRequest, $context);
        $filters = $this->addSortingFilter($filters, $searchRequest, $locale, $context->getContext());
        if (!empty($searchRequest->getStreamId())) {
            // stream ID as filter
            $filters['f[_product.streamIds]'] = $searchRequest->getStreamId();
        } elseif (!empty($searchRequest->getCategoryPath())) {
            // category path as filter
            $categoryPath = $searchRequest->getCategoryPath();
            $categoryPath = implode(' > ', $categoryPath);
            $filters['f[_product_i18n.{locale}.categories]'] = $categoryPath;
        }

        $filters['f[_product.visibility]'] = [Visibilities::VISIBILITY_ALL->value];

        // locale
        $filters = $this->localeService->addLocaleToFilters($filters, $locale);
        $result = $apiClient->filter($searchRequest->getQuery(), $locale, $filters);
        return $this->transformer->transformResponse($result, $context, $searchRequest);
    }

    protected function prepareFilters(SearchRequest $searchRequest, SalesChannelContext $context): array
    {
        $filters = [];
        $filters['f[type]'] = StripClassPathUtil::stripClassPath(ProductDataType::class);

        foreach ($searchRequest->getFilter() as $key => $values) {
            $value = array_shift($values['values']);
            if (is_array($value) && isset($value['type']) && $value['type'] === 'range') {
                if ($value['from']) {
                    $filters['f[' . $key . '][from]'] = $value['from'];
                }
                if ($value['till']) {
                    $filters['f[' . $key . '][till]'] = $value['till'];

                    if (!$value['from']) {
                        $filters['f[' . $key . '][from]'] = 1;
                    }
                }
            } else {
               $filters['f[' . $key . ']'] = $value;
            }
        }

        $filters['page'] = $searchRequest->getPage();
        $limit = $this->systemConfigService->getInt('core.listing.productsPerPage', $context->getSalesChannelId());
        $filters['per_page'] = $limit <= 0 ? 24 : $limit;
        return $filters;
    }

    protected function addSortingFilter(
        array $filters,
        SearchRequest $searchRequest,
        string $locale,
        Context $context
    ): array {
        if (!empty($searchRequest->getSort())) {
            $filters['sort'] = $searchRequest->getSort()['name'] . ':' . $searchRequest->getSort()['order'];
            return $this->prepareSorting($filters);
        }

        if ($searchRequest instanceof NavigationRequestProduct && !empty($searchRequest->getStreamId())) {
            return $filters;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('type', FilterEntity::FILTER_TYPE_SORTING));
        $criteria->addFilter(new EqualsFilter('displayedByDefault', true));

        /** @var FilterEntity $defaultFilter */
        foreach ($this->filterRepository->search($criteria, $context) as $defaultFilter) {
            if ($searchRequest instanceof NavigationRequestProduct) {
                $defaultFilter->setTechnicalName(
                    str_replace(SortTransformer::CATEGORY_REPLACE, $searchRequest->getCategoryId(),
                        $defaultFilter->getTechnicalName())
                );
            }

            if (LocaleUtil::fieldByLocalAllowed($defaultFilter->getTechnicalName(), $locale)) {
                $filters['sort'] = $defaultFilter->getTechnicalName();
            }
        }

        return $this->prepareSorting($filters);
    }

    private function prepareSorting(array $filters): array
    {
        if (!isset($filters['sort'])) {
            return $filters;
        }

        // default sort is not sent to BI, remove the option
        $defaultSort = self::DEFAULT_SORT . ':';
        if (str_starts_with($filters['sort'], $defaultSort)) {
            unset($filters['sort']);
        }
        return $filters;
    }
}
