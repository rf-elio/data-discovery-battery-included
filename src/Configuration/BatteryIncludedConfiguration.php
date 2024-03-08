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

namespace Elio\ElioBatteryIncludedSearchExtension\Configuration;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Class BatteryIncludedConfiguration
 * @package Elio\ElioBatteryIncludedSearchExtension\Configuration
 * @category Shopware
 * @author elio GmbH <support@elio-systems.com>
 * @author Danil Lukov <dl@elio-systems.com>
 * @copyright Copyright (c) 2023, elio GmbH (https://www.elio-systems.com)
 */
class BatteryIncludedConfiguration extends Struct
{
    public const NAME = 'batteryIncluded';
    public const BROWSER_API_KEY = 'browserApiKey';
    public const SERVER_API_KEY = 'serverApiKey';

    public function __construct(
        private readonly string $collection,
        private readonly string $url,
        private readonly string $browserToken,
        private readonly string $serverToken,
        private readonly int $apiTimeOut,
        private readonly string $promotionTemplate,
        private readonly string $suggestPromotionTemplate
    ) {}

    /**
     * Battery included collection
     *
     * @return string
     */
    public function getCollection(): string
    {
        return $this->collection;
    }

    /**
     * Battery included app url
     *
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Battery included browser token
     *
     * @return string
     */
    public function getBrowserToken(): string
    {
        return $this->browserToken;
    }

    /**
     * Battery included server token
     *
     * @return string
     */
    public function getServerToken(): string
    {
        return $this->serverToken;
    }

    /**
     * Battery included timeout
     *
     * @return int
     */
    public function getApiTimeOut(): int
    {
        return $this->apiTimeOut;
    }

    public function getPromotionTemplate(): string
    {
        return $this->promotionTemplate;
    }

    public function getSuggestPromotionTemplate(): string
    {
        return $this->suggestPromotionTemplate;
    }
}