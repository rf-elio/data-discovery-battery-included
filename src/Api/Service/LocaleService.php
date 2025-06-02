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

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Service;


use Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Util\LocaleUtil;
use Elio\ElioDataDiscovery\Configuration\ElioDataDiscoveryConfigServiceInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class LocaleService
 * @package Api\Search\Service
 * @category  Shopware
 * @author    elio GmbH <support@elio-systems.com>
 * @author    Ralf Frommherz <rf@elio-systems.com>
 * @copyright Copyright (c) 2024, elio GmbH (https://www.elio-systems.com)
 */
class LocaleService
{
    public function __construct(
        private readonly EntityRepository $languageRepository,
        private readonly ElioDataDiscoveryConfigServiceInterface $configService
    ) {}

    public function getLocaleByContext(SalesChannelContext $context): string
    {
        $config = $this->configService->getByContext($context);
        $criteria = new Criteria([$context->getLanguageId()]);
        $criteria->addAssociation('locale');
        /** @var LanguageEntity $language */
        $language = $this->languageRepository->search($criteria, $context->getContext())->first();
        return LocaleUtil::getLocaleByLanguage($language, $config->isUseLegacyLocale());
    }

    /**
     * Replaces the '{locale}' placeholder in each filter with the provided locale.
     *
     * @param array $filters An array of filters containing the '{locale}' placeholder.
     * @param string $locale The locale to be added to the filters.
     * @return array An array of filters with the '{locale}' placeholder replaced by the provided locale.
     */
    public function addLocaleToFilters(array $filters, string $locale): array
    {
        $localizedFilters = [];
        foreach ($filters as $key => $value) {
            $key = str_replace('{locale}', $locale, $key);
            if (is_string($value)) {
                $value = str_replace('{locale}', $locale, $value);
            }
            $localizedFilters[$key] = $value;
        }

        return $localizedFilters;
    }
}
