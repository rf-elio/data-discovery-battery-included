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

namespace Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Output\Service;

use Elio\ElioBatteryIncludedSearchExtension\Configuration\BatteryIncludedConfiguration;
use Elio\ElioDataDiscovery\Configuration\ElioDataDiscoveryConfigServiceInterface;
use Elio\ElioDataDiscovery\Core\Sync\DataTypes\ContentDataType;
use Elio\ElioDataDiscovery\Core\Sync\DataTypes\ProductDataType;
use Elio\ElioDataDiscovery\Core\Sync\DataTypes\DataTypeInterface;
use Elio\ElioDataDiscovery\Core\Sync\SyncContext;
use InvalidArgumentException;
use JsonException;
use Shopware\Core\Framework\Struct\Collection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class BatteryIncludedService
 * @package Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Output\Service
 * @category Shopware
 * @author elio GmbH <support@elio-systems.com>
 * @author Danil Lukov <dl@elio-systems.com>
 * @copyright Copyright (c) 2023, elio GmbH (https://www.elio-systems.com)
 */
class BatteryIncludedService
{
    public function __construct(
        private readonly ElioDataDiscoveryConfigServiceInterface $configService,
        private readonly ProductMappingService $productMappingService,
        private readonly ContentMappingService $contentMappingService
    ) {}

    /**
     * Prepare collection fields for battery included sync
     *
     * @param Collection $collection
     * @param SyncContext $syncContext
     * @param SalesChannelContext $context
     * @return array
     */
    public function prepareSyncParameters(Collection $collection, SyncContext $syncContext, SalesChannelContext $context): array
    {
        $mapper = match ($syncContext->getSyncProfile()->getDataType()) {
            ProductDataType::class => $this->productMappingService,
            ContentDataType::class => $this->contentMappingService,
            default => throw new InvalidArgumentException(),
        };

        $data = [];
        foreach ($collection->getElements() as $entity) {
            $data[] = $mapper->mapData($entity, $syncContext, $this->getConfiguration($context));
        }

        return $data;
    }

    /**
     * Prepare collection fields for battery included delete
     *
     * @param array $collection
     * @return array
     */
    public function prepareDeleteParameters(array $collection): array
    {
        $ids = [];
        foreach ($collection as $entities) {
            /** @var DataTypeInterface $element */
            $element = array_values($entities)[0];
            $ids[] = $element->getId();
        }

        return $ids;
    }

    /**
     * Gets battery included api url
     *
     * @param SalesChannelContext $context
     * @return string
     */
    public function getApiUrl(SalesChannelContext $context): string
    {
        $batteryIncludedConfig = $this->getConfiguration($context);
        return sprintf(
            '%s/api/v1/collections/%s/documents/',
            $batteryIncludedConfig->getUrl(),
            $batteryIncludedConfig->getCollection()
        );
    }

    /**
     * Gets battery included configuration
     *
     * @param SalesChannelContext $context
     * @return BatteryIncludedConfiguration
     */
    public function getConfiguration(SalesChannelContext $context): BatteryIncludedConfiguration
    {
        $configuration = $this->configService->get($context->getSalesChannelId());
        /** @var BatteryIncludedConfiguration $batteryIncludedConfig */
        $batteryIncludedConfig = $configuration->getExtension(BatteryIncludedConfiguration::NAME);
        return $batteryIncludedConfig;
    }

    /**
     * Encode data to ndjson format
     *
     * @param array $data
     * @return string
     * @throws JsonException
     */
    public function ndJsonEncode(array $data): string
    {
        $encoded = [];
        foreach ($data as $dataSet) {
            $encoded[] = json_encode($dataSet, JSON_THROW_ON_ERROR);
        }

        return implode(PHP_EOL, $encoded);
    }
}