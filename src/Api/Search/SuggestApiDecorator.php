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
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Util\ApiUtil;
use Elio\ElioBatteryIncludedSearchExtension\Api\Service\LocaleService;
use Elio\ElioDataDiscovery\Api\Event\SuggestParametersPreparedEvent;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Api\Search\Request\SuggestRequest;
use Elio\ElioDataDiscovery\Api\Search\SuggestApi;
use Elio\ElioDataDiscovery\Api\Transform\Transformer;
use Elio\ElioDataDiscovery\Configuration\ElioDataDiscoveryConfigServiceInterface;
use Elio\ElioDataDiscovery\Core\Logging\RequestLoggingService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Elio\ElioBatteryIncludedApiClient\Model\SuggestionResultCollection;
use Throwable;

/**
 * Class SuggestApiDecorator
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search
 * @category Shopware
 * @author elio GmbH <support@elio-systems.com>
 * @author Danil Lukov <dl@elio-systems.com>
 * @copyright Copyright (c) 2023, elio GmbH (https://www.elio-systems.com)
 */
class SuggestApiDecorator extends SuggestApi
{
    /**
     * @param ApiClientFactory $apiFactory
     * @param Transformer $transformer
     * @param LocaleService $localeService
     * @param ElioDataDiscoveryConfigServiceInterface $configService
     * @param EventDispatcherInterface $eventDispatcher
     * @param LoggerInterface $logger
     * @param RequestLoggingService $requestLoggingService
     */
    public function __construct(
        private readonly ApiClientFactory $apiFactory,
        private readonly Transformer $transformer,
        private readonly LocaleService $localeService,
        private readonly ElioDataDiscoveryConfigServiceInterface $configService,
        private readonly EventDispatcherInterface $eventDispatcher,
        LoggerInterface $logger,
        private readonly RequestLoggingService $requestLoggingService
    ) {
        parent::__construct($logger);
    }

    /**
     * @param SuggestRequest $suggestRequest
     * @param SalesChannelContext $context
     * @return ResponseCollection
     * @throws Throwable
     */
    public function suggest(SuggestRequest $suggestRequest, SalesChannelContext $context): ResponseCollection
    {
        $apiClient = $this->apiFactory->createSearchApi($context, ['request_id' => $suggestRequest->getRequestId()]);
        $config = $this->configService->getByContext($context);
        $locale = $this->localeService->getLocaleByContext($context);
        $filters = $this->prepareFilters($suggestRequest);
        $variables = ApiUtil::prepareVariables($locale);

        $event = new SuggestParametersPreparedEvent($suggestRequest, $filters, $variables, $context);
        $this->eventDispatcher->dispatch($event);

        if ($config->isLoggingSearchRequestActive()) {
            $this->requestLoggingService->logRequest($event->getRequest(), $context, 'suggest');
        }
        $this->searchDebug('suggest', $this, [$event->getRequest(), $context]);
        $result = new SuggestionResultCollection($apiClient->suggest($event->getRequest(), $event->getVariables(), $event->getFilters()));
        return $this->transformer->transformResponse($result, $context, $event->getRequest());
    }

    private function prepareFilters(SuggestRequest $suggestRequest): array
    {
        $type = $suggestRequest->getType();
        if ($type) {
            $suggestRequest->addFilter('type', $type, SuggestRequest::FILTER_TYPE_EQUALS);
        }

        return ApiUtil::prepareFilters($suggestRequest->getFilters());
    }
}
