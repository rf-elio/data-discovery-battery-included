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

namespace Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Output;

use Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Output\Service\BatteryIncludedService;
use Elio\ElioDataDiscovery\Core\Sync\ChangeSet\EntityStatusEntity;
use Elio\ElioDataDiscovery\Core\Sync\Output\DeltaAwareInterface;
use Elio\ElioDataDiscovery\Core\Sync\Output\Exception\OutputException;
use Elio\ElioDataDiscovery\Core\Sync\Output\OutputInterface;
use Elio\ElioDataDiscovery\Core\Sync\SyncContext;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Struct\Collection;

/**
 * Class BatteryIncludedApi
 * @package Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Api
 * @category Shopware
 * @author elio GmbH <support@elio-systems.com>
 * @author Danil Lukov <dl@elio-systems.com>
 * @copyright Copyright (c) 2023, elio GmbH (https://www.elio-systems.com)
 */
class BIOutput implements OutputInterface, DeltaAwareInterface
{
    public const TYPE = self::class;

    public function __construct(
        private readonly Client $client,
        private readonly BatteryIncludedService $batteryIncludedService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Checks if writer is supported
     *
     * @param string $type
     * @return bool
     */
    public function supports(string $type): bool
    {
        return self::TYPE === $type;
    }

    /**
     * Created entries in battery api
     *
     * @param Collection $collection
     * @param SyncContext $syncContext
     * @return void
     * @throws GuzzleException
     * @throws JsonException
     */
    public function create(Collection $collection, SyncContext $syncContext): void
    {
        $this->logger->info('BIOutput: Create');
        $this->sync($collection, $syncContext);
    }

    /**
     * Updates entries in battery api
     *
     * @param Collection $collection
     * @param SyncContext $syncContext
     * @return void
     * @throws GuzzleException
     * @throws JsonException
     */
    public function update(Collection $collection, SyncContext $syncContext): void
    {
        $this->logger->info('BIOutput: Update');
        $this->sync($collection, $syncContext);
    }

    /**
     * Deletes entries in battery api
     *
     * @param Collection $collection
     * @param SyncContext $syncContext
     * @return void
     * @throws GuzzleException
     * @throws JsonException
     */
    public function delete(Collection $collection, SyncContext $syncContext): void
    {
        $identifiers = [];
        /** @var EntityStatusEntity $entityStatus */
        foreach ($collection as $entityStatus) {
            $identifiers[] = $entityStatus->getIdentifier();
        }

        $this->logger->info('BIOutput: Delete', ['identifier' => $identifiers]);
        $context = $syncContext->getSalesChannelContexts()->getFirst();
        $url = $this->batteryIncludedService->getApiUrl($context) . 'delete';
        $response = $this->client->request('DELETE', $url, [
            'headers' => [
                'X-BI-API-KEY' => $this->batteryIncludedService->getConfiguration($context)->getServerToken(),
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($identifiers, JSON_THROW_ON_ERROR),
        ]);

        $this->handleResponse($response);
    }

    /**
     * Sync entries in battery api
     *
     * @param Collection $collection
     * @param SyncContext $syncContext
     * @return void
     * @throws GuzzleException
     * @throws JsonException
     */
    private function sync(Collection $collection, SyncContext $syncContext): void
    {
        $context = $syncContext->getSalesChannelContexts()->getFirst();
        $url = $this->batteryIncludedService->getApiUrl($context) . 'import';
        $data = $this->batteryIncludedService->prepareSyncParameters($collection, $syncContext, $context);
        $this->logger->info('BIOutput: Sync', ['data' => count($data)]);

        if (empty($data)) {
            $this->logger->info('BIOutput: Sync not executed, empty changeset');
            return;
        }

        $response = $this->client->request('POST', $url, [
            'headers' => [
                'X-BI-API-KEY' => $this->batteryIncludedService->getConfiguration($context)->getServerToken(),
                'Content-Type' => 'application/x-ndjson'
            ],
            'body' => $this->batteryIncludedService->ndJsonEncode($data),
        ]);

        $this->handleResponse($response);
    }

    /**
     * Handle battery api errors
     *
     * @param ResponseInterface $response
     * @return void
     * @throws JsonException
     * @throws OutputException
     */
    private function handleResponse(ResponseInterface $response): void
    {
        $this->logger->info('BIOutput: Response', ['statusCode' => $response->getStatusCode()]);
        $body = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        if ($response->getStatusCode() !== 200) {
            throw new OutputException(sprintf('Invalid status code %s', $response->getStatusCode()));
        }

        $errors = [];
        foreach ($body as $item) {
            if (isset($item['success']) && $item['success'] === false) {
                $errors[] = [
                    'code' => $item['code'] ?? 500,
                    'id' => isset($item['document'])
                        ? json_decode((string) $item['document'], true, 512, JSON_THROW_ON_ERROR)
                        : null,
                    'error' => $item['error'] ?? ''
                ];
            }
        }

        if (!empty($errors)) {
            $this->logger->warning('BIOutput: Unable to update entities', [
                'plugin' => 'ElioBatteryIncluded',
                'errors' => $errors,
            ]);
            throw new OutputException('Response contains errors');
        }
    }
}