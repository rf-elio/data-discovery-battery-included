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
use Elio\ElioSearch\Api\Response\ResponseCollection;
use Elio\ElioSearch\Api\Search\Request\ContentSearchRequest;
use Elio\ElioSearch\Api\Search\Request\NavigationRequestProduct;
use Elio\ElioSearch\Api\Search\Request\ProductSearchRequest;
use Elio\ElioSearch\Api\Search\SearchApi;
use Elio\ElioSearch\Api\Transform\Transformer;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
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
    public function __construct(
        private ApiClientFactory $apiFactory,
        private readonly Transformer $transformer,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    public function search(ProductSearchRequest $searchRequest, SalesChannelContext $context): ResponseCollection
    {
        $this->searchDebug('search', $this, [$searchRequest, $context]);
        $apiClient = $this->apiFactory->createSearchApi($context);
        $result = $apiClient->filter($searchRequest->getQuery());
        return $this->transformer->transformResponse($result, $context, $searchRequest);
    }

    public function searchContent(ContentSearchRequest $searchRequest, SalesChannelContext $context): ResponseCollection
    {
        $apiClient = $this->apiFactory->createSearchApi($context);
        $result = $apiClient->filter($searchRequest->getQuery());
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
    public function navigation(NavigationRequestProduct $searchRequest, SalesChannelContext $context): ResponseCollection
    {
        $apiClient = $this->apiFactory->createSearchApi($context);
        $result = $apiClient->filter($searchRequest->getQuery(), ['category' => 'Clothing']);
        return $this->transformer->transformResponse($result, $context, $searchRequest);
    }
}