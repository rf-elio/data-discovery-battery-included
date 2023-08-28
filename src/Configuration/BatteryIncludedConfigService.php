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

use Elio\ElioSearch\Configuration\Configuration;
use Elio\ElioSearch\Configuration\Credentials;
use Elio\ElioSearch\Configuration\Event\ConfigurationLoadedEvent;
use Elio\ElioSearch\Configuration\FactFinderConfigServiceInterface;
use Elio\ElioSearch\Configuration\LanguageHelper;
use Elio\ElioSearch\Core\Defaults;
use Psr\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class BatteryIncludedConfigService implements FactFinderConfigServiceInterface
{
    public const PLUGIN_CONFIG_PREFIX = 'ElioBatteryIncludedSearchExtension.config';
    protected const CONFIG_VALUE_SEPARATOR = Defaults::VALUE_SEPARATOR;
    public function __construct(
        private SystemConfigService $systemConfigService,
        private EventDispatcherInterface $eventDispatcher,
        private EntityRepository $languageRepository
    ) {
    }

    public function getByContext(SalesChannelContext $salesChannelContext): Configuration
    {
        return $this->get(
            $salesChannelContext->getSalesChannelId(),
            LanguageHelper::getLanguageIdBySalesChannelContext($salesChannelContext)
        );
    }

    public function get(string $salesChannelId, ?string $languageId = null): Configuration
    {
        $languagePrefix = $this->getLanguagePrefix($languageId);

        if (isset($this->loadedConfigurations[$salesChannelId][$languagePrefix])) {
            return $this->loadedConfigurations[$salesChannelId][$languagePrefix];
        }

        $config = $this->systemConfigService->get(self::PLUGIN_CONFIG_PREFIX, $salesChannelId);
        parse_str(
            $this->getConfigWithLanguagePrefix($config, 'additionalRequestParameters', $languagePrefix) ?? '',
            $additionalRequestParameters
        );

        $configuration = new Configuration(
            $this->getConfigWithLanguagePrefix($config, 'active', $languagePrefix) ?? false,
            $this->getConfigWithLanguagePrefix($config, 'apiChannel', $languagePrefix) ?? '',
            $this->getConfigWithLanguagePrefix($config, 'apiTimeout', $languagePrefix) ?? 0,
            $this->getConfigWithLanguagePrefix($config, 'useAso', $languagePrefix) ?? false,
            $this->getConfigWithLanguagePrefix($config, 'loggingDebugActive', $languagePrefix) ?? false,
            $this->prepareValueList($config, 'loggingDebugIpFilter', $languagePrefix),
            $this->getConfigWithLanguagePrefix($config, 'searchUseFactFinder', $languagePrefix) ?? false,
            !empty($this->getConfigWithLanguagePrefix($config, 'trackRequireConsent', $languagePrefix)),
            !empty($this->getConfigWithLanguagePrefix($config, 'trackCart', $languagePrefix)),
            !empty($this->getConfigWithLanguagePrefix($config, 'trackCheckout', $languagePrefix)),
            !empty($this->getConfigWithLanguagePrefix($config, 'trackLogin', $languagePrefix)),
            !empty($this->getConfigWithLanguagePrefix($config, 'trackProductView', $languagePrefix)),
            $this->prepareValueList($config, 'disallowTrackingForUserAgents', $languagePrefix),
            !empty($this->getConfigWithLanguagePrefix($config, 'listingUseFactFinder', $languagePrefix)),
            $additionalRequestParameters,
            $this->getConfigWithLanguagePrefix($config, 'botProtectionActive',  $languagePrefix) ?? false,
            $this->getConfigWithLanguagePrefix($config, 'botProtectionUseBadBotList', $languagePrefix) ?? false,
            $this->prepareValueList($config, 'botProtectionSearchTermFilter', $languagePrefix),
            $this->prepareValueList($config, 'botProtectionUserAgentFilter', $languagePrefix),
            $this->prepareValueList($config, 'botProtectionIpFilter', $languagePrefix),
            $this->getConfigWithLanguagePrefix($config, 'suggestUseFactFinder', $languagePrefix) ?? false,
            $this->getConfigWithLanguagePrefix($config, 'restrictionsParentCategories', $languagePrefix) ?? false,
            $this->getConfigWithLanguagePrefix($config, 'restrictionsOverridingTopToDown', $languagePrefix) ?? false,
            $this->getConfigWithLanguagePrefix($config, 'restrictionsCacheTime', $languagePrefix) ?? 60,
            $this->getConfigWithLanguagePrefix($config, 'apiContentChannel', $languagePrefix) ?? '',
            $this->getConfigWithLanguagePrefix($config, 'searchUseContentChannel', $languagePrefix) ?? false,
            $this->prepareValueListWithKeyValuePair($config, 'suggestTypeLabels', $languagePrefix),
            $this->prepareValueList($config, 'suggestAcceptedTypes', $languagePrefix),
            $this->getConfigWithLanguagePrefix($config, 'suggestProductNumberAttribute', $languagePrefix) ?? '',
            $this->getConfigWithLanguagePrefix($config, 'productDetailPageCampaignsActive', $languagePrefix) ?? false,
            $this->getConfigWithLanguagePrefix($config, 'useProductDetailRecommendations',  $languagePrefix) ?? false,
            $this->getConfigWithLanguagePrefix($config, 'useProductDetailSimilar',  $languagePrefix) ?? false,
            $this->getConfigWithLanguagePrefix($config, 'recommendationExcludedProducts', $languagePrefix) ?? [],
            $this->getConfigWithLanguagePrefix($config, 'productDetailSliderLimit', $languagePrefix) ?? 24,
            $this->getConfigWithLanguagePrefix($config, 'maxAdvisorProducts', $languagePrefix) ?? 10,
            $this->getConfigWithLanguagePrefix($config, 'searchTermForAdvisorCmsElement', $languagePrefix) ?? '',
            $this->getConfigWithLanguagePrefix($config, 'showPassedAdvisorAfterDays', $languagePrefix) ?? 0,
            $this->getConfigWithLanguagePrefix($config, 'productRankingActive', $languagePrefix) ?? false,
            $this->getConfigWithLanguagePrefix($config, 'productRankingMaxOrderAge', $languagePrefix) ?? 14,
            $this->getConfigWithLanguagePrefix($config, 'productRankingOrderStates', $languagePrefix) ?? [],
            $this->getConfigWithLanguagePrefix($config, 'productRankingOrderDeliveryStates', $languagePrefix) ?? [],
        );

        $event = new ConfigurationLoadedEvent($configuration, $salesChannelId);
        $this->eventDispatcher->dispatch($event);
        $this->loadedConfigurations[$salesChannelId][$languagePrefix] = $event->getConfiguration();
        return $event->getConfiguration();
    }

    public function getApiCredentials(string $salesChannelId): Credentials
    {
        $config = $this->systemConfigService->get(self::PLUGIN_CONFIG_PREFIX, $salesChannelId);
        return new Credentials(
            $config['apiUrl'] ?? '',
            $config['serverApiKey'] ?? '',
            ''
        );
    }

    /**
     * Prepares a pipe separated values list
     *
     * @param array $config
     * @param string $value
     * @param string $languagePrefix
     * @return string[]
     */
    protected function prepareValueList(array $config, string $value, string $languagePrefix): array
    {
        $valueList = array_key_exists($languagePrefix . $value, $config) ? explode(
            self::CONFIG_VALUE_SEPARATOR,
            $config[$languagePrefix . $value] ?? ''
        ) : explode(self::CONFIG_VALUE_SEPARATOR, $config[$value] ?? '');
        return array_filter($valueList);
    }

    /**
     * Converts a key value pair string into an associative array
     * key:value|hello:world
     * ->
     * [
     *      "key" => "value",
     *      "hello" => "world"
     * ]
     *
     * @param array $config
     * @param string $value
     * @param string $languagePrefix
     * @return array
     */
    protected function prepareValueListWithKeyValuePair(array $config, string $value, string $languagePrefix) : array
    {
        $valueList = $this->prepareValueList($config, $value, $languagePrefix);
        $keyValuePairs = [];

        foreach ($valueList as $keyValuePair) {
            $split = explode(':', $keyValuePair);

            if(count($split) === 2) {
                $keyValuePairs[$split[0]] = $split[1];
            }
        }

        return $keyValuePairs;
    }

    /**
     * Returns plugin config for specified key with languagePrefix or default
     * @param array $config
     * @param string $key
     * @param string $languagePrefix
     * @return mixed
     */
    protected function getConfigWithLanguagePrefix(array $config, string $key, string $languagePrefix): mixed
    {
        if (array_key_exists($languagePrefix . $key, $config)) {
            return $config[$languagePrefix . $key];
        }

        return $config[$key] ?? null;
    }

    /**
     * Sets languagePrefix by languageId
     * to fetch plugin configuration based on language
     *
     * @param string|null $languageId
     * @return string
     * @throws InconsistentCriteriaIdsException
     */
    private function getLanguagePrefix(?string $languageId): string
    {
        if(!$languageId) {
            return '';
        }

        if(isset($this->languagePrefixCache[$languageId])) {
            return $this->languagePrefixCache[$languageId];
        }

        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');
        /** @var LanguageEntity|null $language */
        $language = $this->languageRepository->search($criteria, new Context(new SystemSource()))->first();

        if($language && $language->getLocale()) {
            $languagePrefix = $language->getLocale()->getCode() . '_';
            $this->languagePrefixCache[$languageId] = $languagePrefix;
            return $languagePrefix;
        }

        return '';
    }
}