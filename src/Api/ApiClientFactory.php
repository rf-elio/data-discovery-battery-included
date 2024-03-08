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

namespace Elio\ElioBatteryIncludedSearchExtension\Api;

use Elio\ElioBatteryIncludedSearchExtension\Configuration\BatteryIncludedConfiguration;
use Elio\ElioSearch\Configuration\ElioSearchConfigServiceInterface;
use Elio\ElioSearch\Core\Logging\GuzzleLogWrapper;
use Elio\ElioSearch\Core\Logging\LoggingService;
use Elio\ElioSearch\Swagger\ClientConfiguration;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\MessageFormatter;
use GuzzleHttp\Middleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Elio\ElioBatteryIncludedApiClient\Api\SearchApi;

class ApiClientFactory
{
    public function __construct(
        private readonly ElioSearchConfigServiceInterface $configService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Creates a search api client
     *
     * @param SalesChannelContext $salesChannelContext
     * @return SearchApi
     */
    public function createSearchApi(SalesChannelContext $salesChannelContext): SearchApi
    {
        return new SearchApi(
            $this->createClient($salesChannelContext->getSalesChannelId(), $salesChannelContext),
            $this->createConfiguration($salesChannelContext->getSalesChannelId(), $salesChannelContext)
        );
    }

    /**
     * Creates the api client wit the configured settings
     * @param string $salesChannelId
     * @param SalesChannelContext|null $salesChannelContext
     * @param array $params
     *
     * @return ClientInterface
     */
    protected function createClient(string $salesChannelId, SalesChannelContext $salesChannelContext = null, array $params = []): ClientInterface
    {
        if ($salesChannelContext === null) {
            $configuration = $this->configService->get($salesChannelId);
        } else {
            $configuration = $this->configService->getByContext($salesChannelContext);
        }

        $stack = HandlerStack::create();
        $mapResponse = Middleware::mapResponse(static function (ResponseInterface $response) {
            $response->getBody()->rewind();
            return $response;
        });
        $stack->push($mapResponse);
        $stack->push(Middleware::log(
            new GuzzleLogWrapper($this->logger, $this, ['context' => $salesChannelContext, 'params' => $params]),
            new MessageFormatter(LoggingService::LOG_FORMAT))
        );

        /** @var BatteryIncludedConfiguration $batteryIncludedConfig */
        $batteryIncludedConfig = $configuration->getExtension(BatteryIncludedConfiguration::NAME);

        $config = [
            'max' => $batteryIncludedConfig->getApiTimeOut(),
            'handler' => $stack,
        ];

        return new Client($config);
    }

    /**
     * Creates the configuration struct that contains the api address and credentials
     *
     * @param string $salesChannelId
     * @param SalesChannelContext|null $salesChannelContext
     * @return ClientConfiguration
     */
    protected function createConfiguration(string $salesChannelId, SalesChannelContext $salesChannelContext = null): ClientConfiguration
    {
        if ($salesChannelContext === null) {
            $configuration = $this->configService->get($salesChannelId);
        } else {
            $configuration = $this->configService->getByContext($salesChannelContext);
        }

        /** @var BatteryIncludedConfiguration $batteryIncludedConfig */
        $batteryIncludedConfig = $configuration->getExtension(BatteryIncludedConfiguration::NAME);
        $apiConfiguration = new ClientConfiguration();
        $apiConfiguration->setHost($batteryIncludedConfig->getUrl());
        $apiConfiguration->setApiKey(
            BatteryIncludedConfiguration::BROWSER_API_KEY,
            $batteryIncludedConfig->getBrowserToken()
        );
        $apiConfiguration->setApiKey(
            BatteryIncludedConfiguration::SERVER_API_KEY,
            $batteryIncludedConfig->getServerToken()
        );
        $apiConfiguration->setCollection($batteryIncludedConfig->getCollection());
        return $apiConfiguration;
    }
}