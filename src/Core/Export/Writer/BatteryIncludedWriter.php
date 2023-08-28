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

namespace Elio\ElioBatteryIncludedSearchExtension\Core\Export\Writer;

use Elio\ElioBatteryIncludedSearchExtension\Configuration\BatteryIncludedConfigService;
use Elio\ElioBatteryIncludedSearchExtension\Core\Export\Exception\BatteryIncludedWriteException;
use Elio\ElioSearch\Core\Export\ExportEntity;
use Elio\ElioSearch\Core\Export\ExportItem;
use Elio\ElioSearch\Core\Export\Writer\FileWriterInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Class BatteryIncludedWriter
 * @package Elio\ElioBatteryIncludedSearchExtension\Core\Export\Writer
 * @category Shopware
 * @author elio GmbH <support@elio-systems.com>
 * @author Danil Lukov <dl@elio-systems.com>
 * @copyright Copyright (c) 2023, elio GmbH (https://www.elio-systems.com)
 */
class BatteryIncludedWriter implements FileWriterInterface
{
    public const TYPE = 'batteryIncluded';

    protected array $model = [];

    public function __construct(private BatteryIncludedConfigService $configService)
    {
    }

    /**
     * Checks if the writer can be used for the given export
     *
     * @param ExportEntity $export
     * @return bool
     */
    public function supports(ExportEntity $export): bool
    {
//        return $export->getType() === self::TYPE;
        return true;
    }

    /**
     * @param SalesChannelContext $context
     * @return \CurlHandle|false|resource
     */
    public function open(SalesChannelContext $context)
    {
        $credentials = $this->configService->getApiCredentials($context->getSalesChannelId());
        $url = sprintf(
            '%s/api/v1/collections/%s/documents/import',
            $credentials->getApiUrl(),
                'elio' //TODO: Move to config
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-BI-API-KEY: ' . $credentials->getApiUsername(),
            'Content-Type: application/x-ndjson',
        ]);
        return $ch;
    }

    public function registerModel(array $model): void
    {
        $this->model = array_merge($this->model, $model);
    }

    /**
     * @param \CurlHandle $handle
     * @param array $items
     * @return void
     * @throws \JsonException
     * @throws BatteryIncludedWriteException
     */
    public function writeList($handle, array $items): void
    {
        // TODO: Add validator
        $data = array_map(static function (ExportItem $item) {
            return $item->getParams();
        }, $items);

        $postFields = $this->ndJsonEncode($data);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $postFields);
        $curlResult = curl_exec($handle);
        $result = json_decode($curlResult, true, 512, JSON_THROW_ON_ERROR);
        $errors = [];
        foreach ($result as $item) {
            if ($item['success'] === false) {
                $errors[] = [
                    'code' => $item['code'] ?? 500,
                    'id' => isset($item['document'])
                        ? json_decode($item['document'], true, 512, JSON_THROW_ON_ERROR)
                        : null,
                    'error' => $item['error'] ?? ''
                ];
            }
        }

        dd($result);
        if (!empty($errors)) {
            throw new BatteryIncludedWriteException($errors);
        }
    }

    public function abort($handle): void
    {
        $this->model = [];
        if ($handle instanceof \CurlHandle) {
            curl_close($handle);
        }
    }

    public function close(ExportEntity $export, SalesChannelContext $context, $handle): void
    {
        $this->model = [];
        if ($handle instanceof \CurlHandle) {
            curl_close($handle);
        }
    }

    /**
     * TODO: Move to another class
     * Encode data to ndjson format
     *
     * @param array $data
     * @return string
     * @throws \JsonException
     */
    private function ndJsonEncode(array $data): string
    {
        $encoded = [];
        foreach ($data as $dataSet) {
            $encoded[] = json_encode($dataSet, JSON_THROW_ON_ERROR);
        }

        return implode(PHP_EOL, $encoded);
    }
}