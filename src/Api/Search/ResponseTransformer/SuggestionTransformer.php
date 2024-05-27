<?php
/**
 * Copyright (c) 2021, elio GmbH.
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

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;


use Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Event\SuggestItemTransformEvent;
use Elio\ElioBatteryIncludedSearchExtension\Api\Service\LocaleService;
use Elio\ElioDataDiscovery\Api\Search\Components\SuggestTypes;
use Elio\ElioDataDiscovery\Api\Search\Response\SuggestionResponse;
use Elio\ElioDataDiscovery\Api\Transform\ResponseTransformerInterface;
use Elio\ElioDataDiscovery\Api\Request\ApiRequest;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Configuration\Configuration;
use Elio\ElioDataDiscovery\Configuration\ElioDataDiscoveryConfigServiceInterface;
use Elio\ElioDataDiscovery\Core\Exception\InvalidTypeException;
use Elio\ElioDataDiscovery\Core\Suggest\SuggestGroup;
use Elio\ElioDataDiscovery\Core\Suggest\SuggestItem;
use Psr\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Elio\ElioDataDiscovery\Swagger\ModelInterface;
use Elio\ElioBatteryIncludedApiClient\Model\SuggestionResult;
use Elio\ElioBatteryIncludedApiClient\Model\SuggestionResultCollection;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Converts suggest result to internal structure
 *
 * Class SuggestionTransformer
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer
 * @category Shopware
 * @author elio GmbH <support@elio-systems.com>
 * @author Andrey Baev <anb@elio-systems.com>
 * @copyright Copyright (c) 2021, elio GmbH (https://www.elio-systems.com)
 */
class SuggestionTransformer implements ResponseTransformerInterface
{
    /**
     * SuggestionTransformer constructor.
     * @param ElioDataDiscoveryConfigServiceInterface $configService
     * @param EventDispatcherInterface $eventDispatcher
     * @param LocaleService $localeService
     */
    public function __construct(
        private readonly ElioDataDiscoveryConfigServiceInterface $configService,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LocaleService $localeService
    ) {}

    /**
     * @inheritDoc
     */
    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof SuggestionResultCollection;
    }

    /**
     * @param ModelInterface $model
     * @param ResponseCollection $responseCollection
     * @param SalesChannelContext $context
     * @param ApiRequest $request
     */
    public function transform(
        ModelInterface $model,
        ResponseCollection $responseCollection,
        SalesChannelContext $context,
        ApiRequest $request
    ): void {
        if (!$model instanceof SuggestionResultCollection) {
            throw new InvalidTypeException($model, SuggestionResultCollection::class);
        }

        $locale = $this->localeService->getLocaleByContext($context);

        /** @var SuggestionResponse $suggestionResponse */
        $suggestionResponse = $responseCollection->get(SuggestionResponse::class) ?? new SuggestionResponse();
        $responseCollection->set(SuggestionResponse::class, $suggestionResponse);
        $config = $this->configService->getByContext($context);
        $groupLabels = $config->getSuggestTypeLabels();
        $suggestGroups = [];

        foreach ($model->getSuggestionResults() as $suggestionResult) {
            if (!$suggestionResult->getHits() || $suggestionResult->getKind() === PromotionTransformer::TYPE_PROMOTION) {
                continue;
            }

            foreach ($suggestionResult->getHits() as $hit) {
                $suggestItem = $this->transformSuggestion($hit, $suggestionResult->getKind(), $locale);

                $event = new SuggestItemTransformEvent($suggestItem, $model, $responseCollection, $request, $context);
                $this->eventDispatcher->dispatch($event);
                if($event->isRemoveSuggestItemFromResult()) {
                    continue;
                }

                $type = $suggestItem->getType();
                if (!$type) {
                    continue;
                }

                $label = $groupLabels[$type] ?? $type;
                $group = $suggestGroups[$label] ?? new SuggestGroup($type, $label);
                $suggestGroups[$label] = $group;
                $group->addItem($suggestItem);
            }
        }

        $suggestGroups = $this->setResultRepresentation($suggestGroups, $config);
        $suggestionResponse->setGroups($suggestGroups);
    }

    /**
     * @param object $hit
     * @param string $type
     * @param string $locale
     * @return SuggestItem
     */
    private function transformSuggestion(object $hit, string $type, string $locale): SuggestItem
    {
        $propertyAccess = PropertyAccess::createPropertyAccessor();
        $suggestItem = new SuggestItem();
        $suggestItem->setType('other');

        if ($type === SuggestionResult::RESULT_TYPE_DOCUMENT) {
            if (
                $propertyAccess->isReadable($hit, 'highlighted._product') ||
                $propertyAccess->isReadable($hit, 'highlighted._product_i18n')
            ) {
                $suggestItem->setType(SuggestTypes::PRODUCT->value);
            }
            $namePropertyPath = 'highlighted._product_i18n.'.$locale.'.name';
            if ($propertyAccess->isReadable($hit, $namePropertyPath)) {
                $suggestItem->setName(strip_tags((string) $propertyAccess->getValue($hit, $namePropertyPath)));
            }
            $productPropertyPath = 'highlighted._product';
            if ($propertyAccess->isReadable($hit, $productPropertyPath)) {
                $attributes = [];
                $productMasterProductNumberPropertyPath = 'highlighted._product.masterProductNumber';
                if ($propertyAccess->isReadable($hit, $productMasterProductNumberPropertyPath)) {
                    $attributes['MasterProductNumber'] = $propertyAccess->getValue($hit, $productMasterProductNumberPropertyPath);
                }

                $productProductNumberPropertyPath = 'highlighted._product.productNumber';
                if ($propertyAccess->isReadable($hit, $productProductNumberPropertyPath)) {
                    $attributes['ProductNumber'] = $propertyAccess->getValue($hit, $productProductNumberPropertyPath);
                }

                $suggestItem->setAttributes($attributes);
            }

            $commonPropertyPath = 'highlighted._common';
            if ($propertyAccess->isReadable($hit, $commonPropertyPath)) {
                $commonImageUrlPropertyPath = $commonPropertyPath.'.imageUrl';
                if ($propertyAccess->isReadable($hit, $commonImageUrlPropertyPath)
                    && $propertyAccess->getValue($hit, $commonImageUrlPropertyPath) !== null) {
                    $suggestItem->setImgUrl($propertyAccess->getValue($hit, $commonImageUrlPropertyPath));
                }
                $commonThumbnailUrlPropertyPath = $commonPropertyPath.'.thumbnailUrl';
                if ($propertyAccess->isReadable($hit, $commonThumbnailUrlPropertyPath)
                    && $propertyAccess->getValue($hit, $commonThumbnailUrlPropertyPath) !== null) {
                    $suggestItem->setImgUrl($propertyAccess->getValue($hit, $commonThumbnailUrlPropertyPath));
                }
            }

            $commonTranslationPropertyPath = 'highlighted._common_i18n';
            if ($propertyAccess->isReadable($hit, $commonTranslationPropertyPath)) {
                $urlPropertyPath = $commonTranslationPropertyPath.'.'.$locale.'.url';
                if ($propertyAccess->isReadable($hit, $urlPropertyPath)) {
                    $suggestItem->setUrl(strip_tags((string) $propertyAccess->getValue($hit, $urlPropertyPath)));
                }
            }

            $contentPropertyPath = 'highlighted._content';
            if ($propertyAccess->isReadable($hit, $contentPropertyPath)) {
                $contentNamePropertyPath = 'highlighted._content_i18n.'.$locale.'.name';
                if ($propertyAccess->isReadable($hit, $contentNamePropertyPath)) {
                    $suggestItem->setName(strip_tags((string) $propertyAccess->getValue($hit, $contentNamePropertyPath)));
                }

                $contentTypePath = $contentPropertyPath.'.contentType';
                if ($propertyAccess->isReadable($hit, $contentTypePath)) {
                    $suggestItem->setType(strip_tags($propertyAccess->getValue($hit, $contentTypePath)));
                }
            }

            // fallback
            if (empty($suggestItem->getName()) && property_exists($hit, 'value') && is_string($hit->value)) {
                $suggestItem->setName($hit->value);
            }

            return $suggestItem;
        }

        // other types
        if (property_exists($hit, 'value') && is_string($hit->value)) {
            $suggestItem->setName($hit->value);
        } elseif (property_exists($hit, 'name') && is_string($hit->name)) {
            $suggestItem->setName($hit->name);
        }

        if (property_exists($hit, 'url') && is_string($hit->url)) {
            $suggestItem->setUrl($hit->url);
        }

        $suggestItem->setType($type);
        return $suggestItem;
    }

    /**
     * Sets the visibility and the order of the given groups
     *
     * @param SuggestGroup[] $groups
     * @param Configuration $config
     * @return SuggestGroup[]
     */
    protected function setResultRepresentation(array $groups, Configuration $config): array
    {
        $acceptedTypes = $config->getSuggestAcceptedTypes();

        if(empty($acceptedTypes)) {
            return $groups;
        }

        // set visibility and position
        foreach ($groups as $group) {
            $type = $group->getType();
            $acceptedTypePosition = array_search($type, $acceptedTypes, true);

            if($acceptedTypePosition === false) {
                $group->setVisible(false);
            } else {
                $group->setVisible(true);
                $group->setPosition((int)$acceptedTypePosition);
            }
        }

        // sort groups
        uasort($groups, static function (SuggestGroup $a, SuggestGroup $b) {
            $posA = $a->getPosition();
            $posB = $b->getPosition();
            return $posA <=> $posB;
        });
        return $groups;
    }
}
