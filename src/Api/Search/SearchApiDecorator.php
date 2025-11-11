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
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Util\ApiUtil;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Util\LocaleUtil;
use Elio\ElioBatteryIncludedSearchExtension\Api\Service\LocaleService;
use Elio\ElioBatteryIncludedSearchExtension\Configuration\BatteryIncludedConfiguration;
use Elio\ElioDataDiscovery\Api\Event\ContentSearchParametersPreparedEvent;
use Elio\ElioDataDiscovery\Api\Event\NavigationParametersPreparedEvent;
use Elio\ElioDataDiscovery\Api\Event\SearchParametersPreparedEvent;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Api\Search\Request\ContentSearchRequest;
use Elio\ElioDataDiscovery\Api\Search\Request\NavigationRequestProduct;
use Elio\ElioDataDiscovery\Api\Search\Request\ProductSearchRequest;
use Elio\ElioDataDiscovery\Api\Search\Request\SearchRequest;
use Elio\ElioDataDiscovery\Api\Search\SearchApi;
use Elio\ElioDataDiscovery\Api\Transform\Transformer;
use Elio\ElioDataDiscovery\Configuration\ElioDataDiscoveryConfigServiceInterface;
use Elio\ElioDataDiscovery\Core\FilterRestrictions\FilterEntity;
use Elio\ElioDataDiscovery\Core\Logging\RequestLoggingService;
use Elio\ElioDataDiscovery\Core\Sync\DataTypes\Aggregation\Visibilities;
use Elio\ElioDataDiscovery\Core\Sync\DataTypes\ProductDataType;
use Elio\ElioDataDiscovery\Core\Util\StripClassPathUtil;
use Psr\EventDispatcher\EventDispatcherInterface;
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
        private readonly EntityRepository $filterRepository,
        private readonly RequestLoggingService $requestLoggingService,
        private readonly ElioDataDiscoveryConfigServiceInterface $configService,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
        parent::__construct($logger);
    }

    public function search(ProductSearchRequest $searchRequest, SalesChannelContext $context): ResponseCollection
    {
        $config = $this->configService->getByContext($context);
        $apiClient = $this->apiFactory->createSearchApi($context);

        $locale = $this->localeService->getLocaleByContext($context);
        $filters = $this->prepareFilters($searchRequest, $context);
        $filters = $this->preparePagination($filters, $searchRequest, $context);
        $filters = $this->addSorting($filters, $searchRequest, $locale, $context->getContext());
        $filters = $this->addAdditionalParameters($filters, $searchRequest, $context);
        $filters = $this->localeService->addLocaleToFilters($filters, $locale);
        $variables = ApiUtil::prepareVariables($locale);

        $event = new SearchParametersPreparedEvent($searchRequest, $filters, $variables, $context);
        $this->eventDispatcher->dispatch($event);

        if ($config->isLoggingSearchRequestActive()) {
            $this->requestLoggingService->logRequest($event->getRequest(), $context, 'search');
        }
        $this->searchDebug('search', $this, [$event->getRequest(), $context, $locale]);
        $result = $apiClient->filter($event->getRequest(), $event->getVariables(), $event->getFilters());
        return $this->transformer->transformResponse($result, $context, $event->getRequest());
    }

    public function searchContent(ContentSearchRequest $searchRequest, SalesChannelContext $context): ResponseCollection
    {
        $locale = $this->localeService->getLocaleByContext($context);
        $config = $this->configService->getByContext($context);
        $apiClient = $this->apiFactory->createSearchApi($context);

        $filters = $this->prepareFilters($searchRequest, $context);
        $variables = ApiUtil::prepareVariables($locale);

        $event = new ContentSearchParametersPreparedEvent($searchRequest, $filters, $variables, $context);
        $this->eventDispatcher->dispatch($event);

        if ($config->isLoggingSearchRequestActive()) {
            $this->requestLoggingService->logRequest($event->getRequest(), $context, 'search');
        }
        $result = $apiClient->filter($event->getRequest(), $event->getVariables(), $event->getFilters());
        return $this->transformer->transformResponse($result, $context, $event->getRequest());
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
        $config = $this->configService->getByContext($context);
        $locale = $this->localeService->getLocaleByContext($context);

        /** @var BatteryIncludedConfiguration $biConfig */
        $biConfig = $config->getExtension(BatteryIncludedConfiguration::NAME);
        $filters = $this->prepareFilters($searchRequest, $context);
        $filters = $this->preparePagination($filters, $searchRequest, $context);
        $filters = $this->addSorting($filters, $searchRequest, $locale, $context->getContext());
        if (!empty($searchRequest->getStreamId())) {
            // stream ID as filter
            $filters['f[_product.streamIds]'] = $searchRequest->getStreamId();
        } elseif (!empty($searchRequest->getCategoryPath())) {
            $filters = $this->setCategoryFilterInNavigation($filters, $biConfig, $searchRequest);
        }

        $filters['f[_product.visibility]'] = [Visibilities::VISIBILITY_ALL->value];

        // locale
        $filters = $this->localeService->addLocaleToFilters($filters, $locale);
        $variables = ApiUtil::prepareVariables($locale);

        $event = new NavigationParametersPreparedEvent($searchRequest, $filters, $variables, $context);
        $this->eventDispatcher->dispatch($event);

        if ($config->isLoggingSearchRequestActive()) {
            $this->requestLoggingService->logRequest($event->getRequest(), $context, 'search');
        }
        $result = $apiClient->filter($event->getRequest(), $event->getVariables(), $event->getFilters());
        return $this->transformer->transformResponse($result, $context, $event->getRequest());
    }

    /**
     * @param SearchRequest $searchRequest
     * @param SalesChannelContext $context
     * @return array
     */
    protected function prepareFilters(SearchRequest $searchRequest, SalesChannelContext $context): array
    {
        if ($searchRequest instanceof ContentSearchRequest) {
            $searchRequest->addFilter('type', StripClassPathUtil::stripClassPath(ProductDataType::class), SearchRequest::FILTER_TYPE_NOT);
        } else {
            $searchRequest->addFilter('type', StripClassPathUtil::stripClassPath(ProductDataType::class), SearchRequest::FILTER_TYPE_EQUALS);
        }
        return ApiUtil::prepareFilters($searchRequest->getFilters());
    }

    /**
     * @param array $filters
     * @param SearchRequest $searchRequest
     * @param SalesChannelContext $context
     * @return array
     */
    protected function preparePagination(array $filters, SearchRequest $searchRequest, SalesChannelContext $context): array
    {
        $filters['page'] = $searchRequest->getPage();
        $limit = $this->systemConfigService->getInt('core.listing.productsPerPage', $context->getSalesChannelId());
        $filters['per_page'] = $limit <= 0 ? 24 : $limit;
        return $filters;
    }

    /**
     * @param array $filters
     * @param SearchRequest $searchRequest
     * @param string $locale
     * @param Context $context
     * @return array
     */
    protected function addSorting(
        array $filters,
        SearchRequest $searchRequest,
        string $locale,
        Context $context
    ): array {
        if (!empty($searchRequest->getSort())) {
            $filters['sort'] = $searchRequest->getSort()['name'] . ':' . $searchRequest->getSort()['order'];
            return $this->setDefaultSorting($filters);
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

        return $this->setDefaultSorting($filters);
    }

    /**
     * Removes the default ("Recommended") sorting option from the request parameters to retrieve products in the
     * default sorting order from BatteryIncluded.
     *
     * @param array $filters
     * @return array
     */
    private function setDefaultSorting(array $filters): array
    {
        if (!isset($filters['sort'])) {
            return $filters;
        }

        $defaultSort = self::DEFAULT_SORT . ':';
        if (str_starts_with($filters['sort'], $defaultSort)) {
            unset($filters['sort']);
        }
        return $filters;
    }

    /**
     * @param array $filters
     * @param SearchRequest $searchRequest
     * @param SalesChannelContext $context
     * @return array
     */
    protected function addAdditionalParameters(array $filters, SearchRequest $searchRequest, SalesChannelContext $context): array
    {
        $additionalParameters = $searchRequest->getAdditionalRequestParameters();
        foreach ($additionalParameters as $key => $value) {
            $filters[$key] = $value;
        }
        return $filters;
    }

    /**
     * Sets the category path as a filter for the navigation request based on the plugin configuration.
     *
     * @param array $filters
     * @param BatteryIncludedConfiguration $biConfig
     * @param NavigationRequestProduct $searchRequest
     * @return array
     */
    private function setCategoryFilterInNavigation(
        array $filters,
        BatteryIncludedConfiguration $biConfig,
        NavigationRequestProduct $searchRequest
    ): array
    {
        $categoryPath = $searchRequest->getCategoryPath();
        $categoryPath = implode(' > ', $categoryPath);

        $parameter = $biConfig->isIgnoreLocaleForListingRequest() ? 'f[_product_i18n.' : 'f[_product_i18n.{locale}.';
        $parameter .= $biConfig->isUseCategoryTree() ? 'categoryTree.name]' : 'categories]';
        $filters[$parameter] = $categoryPath;

        return $filters;
    }
}
