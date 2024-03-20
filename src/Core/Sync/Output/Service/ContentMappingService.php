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

use Elio\ElioSearch\Core\Defaults;
use Elio\ElioSearch\Core\Sync\DataTypes\ContentDataType;
use Elio\ElioSearch\Core\Sync\Output\SeoRoute;
use Elio\ElioSearch\Core\Sync\SyncContext;
use Elio\ElioSearch\Core\Sync\Util\MappingUtil;
use Elio\ElioSearch\Core\Sync\Util\ValueUtil;
use Shopware\Core\Content\Seo\SeoUrl\SeoUrlEntity;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Class ContentMappingService
 * @package Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Output\Service
 * @category Shopware
 * @author elio GmbH <support@elio-systems.com>
 * @author Danil Lukov <dl@elio-systems.com>
 * @copyright Copyright (c) 2023, elio GmbH (https://www.elio-systems.com)
 */
class ContentMappingService
{
    public const TYPE = ContentDataType::class;

    /**
     * Maps data for create, update request
     *
     * @param ContentDataType $content
     * @param SyncContext $syncContext
     * @return array
     */
    public function mapData(ContentDataType $content, SyncContext $syncContext): array
    {
        $convertedData = [];
        $convertedData['id'] = $content->getIdentifier();
        $convertedData['_content'] = $this->prepareBaseFields($content);
        $convertedData['_content_i18n'] = $this->prepareTranslatedFields($content->getDataTypeTranslations(), $syncContext);
        $convertedData['type'] = get_class($content);

        // TODO: Add mapping
        return $convertedData;
    }

    /**
     * Prepares base fields
     *
     * @param ContentDataType $content
     * @return array
     */
    protected function prepareBaseFields(ContentDataType $content): array
    {
        return [
            'type' => $content->getType(),
            'imageurl' => $content->getMedia()?->getUrl(),
            'publicationdate' => $content->getCreatedAt()?->format('Y-m-d'),
        ];
    }

    /**
     * Prepare translation fields
     *
     * @param array $collection
     * @return array
     */
    protected function prepareTranslatedFields(array $collection, SyncContext $syncContext): array
    {
        $translatedFields = [];
        foreach ($collection as $languageId => $content) {
            /** @var SeoRoute|null $seoRoute */
            $seoRoute = $content->getExtension(SeoRoute::class);

            $translatedFields[$languageId] = [
                'name' => $content->getName(),
                'title' => $content->getTitle(),
                'seotext' => $content->getSeoText(),
                'url' => $seoRoute?->getUrl() ?? '',
                'keywords' => $content->getKeywords(),
                'description' => $content->getDescription(),
                'contentstructure' => ValueUtil::cleanValue(implode('/', array_map('rawurlencode', array_slice($content->getBreadcrumb() ?? [], 1)))),
                'tags' => $this->getTags($content),
                'mappedFields' => MappingUtil::addMappedProperties($content, $syncContext->getSyncProfile()->getMapping(), PropertyAccess::createPropertyAccessor()),
            ];
        }

        return $translatedFields;
    }

    /**
     * Creates the content tags string
     *
     * @param ContentDataType $content
     * @return string
     */
    protected function getTags(ContentDataType $content) : string
    {
        if(!$content->getTags()) {
            return '';
        }

        $tags = [];
        foreach ($content->getTags() as $tag) {
            $tags[] = $tag->getTranslation('name') ?? $tag->getName();
        }

        return implode(Defaults::VALUE_SEPARATOR, $tags);
    }
}