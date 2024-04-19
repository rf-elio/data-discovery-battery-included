<?php
/**
 * Copyright (c) 2024, elio GmbH.
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

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Test;


use Elio\ElioBatteryIncludedSearchExtension\ElioBatteryIncludedSearchExtension;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class ConnectionTest
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Test
 * @category  Shopware
 * @author    elio GmbH <support@elio-systems.com>
 * @author    Ralf Frommherz <rf@elio-systems.com>
 * @copyright Copyright (c) 2024, elio GmbH (https://www.elio-systems.com)
 */
class ConnectionTest
{
    public function __construct(
        private readonly Client $client,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    /**
     * Test the connection for a given sales channel.
     *
     * @param SalesChannelEntity|null $salesChannel The sales channel to test.
     *
     * @return TestResult Returns true if the connection is successful, false otherwise.
     */
    public function test(?SalesChannelEntity $salesChannel): TestResult
    {
        $pluginConfig = $this->systemConfigService->get(
            ElioBatteryIncludedSearchExtension::PLUGIN_CONFIG_PREFIX,
            $salesChannel?->getId()
        ) ?? [];

        $apiUrl = $pluginConfig['apiUrl'];
        $collection = $pluginConfig['collection'];
        $timeout = ($pluginConfig['apiTimeout'] ?? 1000) / 1000;

        $testResult = TestResult::SUCCESS;

        $statusCode = $this->testConnection($apiUrl, $collection, $pluginConfig['browserApiKey'] ?? '', $timeout);
        $testResult = TestResult::takeMoreServe($testResult, $this->convertStatusCodeToTestResult($statusCode));

        $statusCode = $this->testConnection($apiUrl, $collection, $pluginConfig['serverApiKey'] ?? '', $timeout);
        $testResult = TestResult::takeMoreServe($testResult, $this->convertStatusCodeToTestResult($statusCode));

        return $testResult;
    }

    private function convertStatusCodeToTestResult(int $statusCode): TestResult
    {
        if ($statusCode === Response::HTTP_OK) {
            return TestResult::SUCCESS;
        }

        // there is no possibility to validate the API key correctly. As long as there was no sync, we will receive an
        // 500 error, after syncing data and doing all the configuration we will receive a 200 status code. To validate
        // at the time of installation we have to check for a 500 status code.
        if ($statusCode === Response::HTTP_INTERNAL_SERVER_ERROR) {
            return TestResult::CONFIGURATION_NEEDED;
        }

        return TestResult::FAIL;
    }

    /**
     * Test the connection to a given URL and collection using the specified token and timeout.
     *
     * @param string $url The base URL.
     * @param string $collection The collection name.
     * @param string $token The API token.
     * @param int $timeout The timeout value in seconds.
     *
     * @return int The HTTP status code of the response, or 0 if an error occurred.
     */
    private function testConnection(string $url, string $collection, string $token, int $timeout): int {

        $url = sprintf(
            '%s/api/v1/collections/%s/documents/browse?q=test',
            $url,
            $collection
        );

        try {
            $response = $this->client->request('GET', $url, [
                'headers' => [
                    'X-BI-API-KEY' => $token,
                    'Content-Type' => 'application/json'
                ],
                'timeout' => $timeout
            ]);
            return $response->getStatusCode();
        }
        catch (RequestException $ex) {
            return $ex->getResponse()->getStatusCode();
        }
        catch (\Throwable) {
            return 0;
        }
    }
}
