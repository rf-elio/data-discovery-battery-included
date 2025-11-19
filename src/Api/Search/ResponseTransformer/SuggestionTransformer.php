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
use Elio\ElioDataDiscovery\Api\Search\ResponseTransformer\EntityResolveStruct;
use Elio\ElioDataDiscovery\Api\Transform\ResponseTransformerInterface;
use Elio\ElioDataDiscovery\Api\Request\ApiRequest;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Configuration\ElioDataDiscoveryConfigServiceInterface;
use Elio\ElioDataDiscovery\Core\Exception\InvalidTypeException;
use Elio\ElioDataDiscovery\Core\Suggest\SuggestGroup;
use Elio\ElioDataDiscovery\Core\Suggest\SuggestGroupCollection;
use Elio\ElioDataDiscovery\Core\Suggest\SuggestItem;
use Elio\ElioDataDiscovery\Core\Sync\DataTypes\ContentDataType;
use Elio\ElioDataDiscovery\Core\Sync\DataTypes\ProductDataType;
use Elio\ElioDataDiscovery\Core\Util\StripClassPathUtil;
use Psr\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\LandingPage\LandingPageDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Elio\ElioDataDiscovery\Swagger\ModelInterface;
use Elio\ElioBatteryIncludedApiClient\Model\SuggestionResult;
use Elio\ElioBatteryIncludedApiClient\Model\SuggestionResultCollection;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;

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
        $found = 0;

        foreach ($model->getSuggestionResults() as $suggestionResult) {
            if (!$suggestionResult->getHits() || $suggestionResult->getKind() === PromotionTransformer::TYPE_PROMOTION) {
                continue;
            }

            foreach ($suggestionResult->getHits() as $hit) {
                $suggestItem = $this->transformSuggestion($hit, $suggestionResult->getKind(), $locale);

                $event = new SuggestItemTransformEvent($suggestItem, $model, $responseCollection, $request, $context);
                $this->eventDispatcher->dispatch($event);
                if ($event->isRemoveSuggestItemFromResult()) {
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

            $found += $suggestionResult->getFound() ?? 0;
        }

        $suggestionResponse->setFound($found);
        $suggestionResponse->setGroups(new SuggestGroupCollection($suggestGroups));
    }

    /**
     * @param object $hit
     * @param string $kind
     * @param string $locale
     * @return SuggestItem
     */
    private function transformSuggestion(object $hit, string $kind, string $locale): SuggestItem
    {
        $propertyAccess = PropertyAccess::createPropertyAccessor();
        $suggestItem = new SuggestItem();
        if ($propertyAccess->isReadable($hit, 'highlighted.type')) {
            $suggestItem->setType($propertyAccess->getValue($hit, 'highlighted.type'));
        } else {
            $suggestItem->setType('other');
        }

        if ($kind === SuggestionResult::RESULT_TYPE_DOCUMENT) {
            $this->addEntityResolveStruct($suggestItem, $hit, $propertyAccess);
            $this->getCommonFields($suggestItem, $locale, $hit, $propertyAccess);
            $this->addAiPickAttribute($suggestItem, $hit, $propertyAccess);

            //Fallback for extra data types
            if (property_exists($hit, 'value') && is_string($hit->value)) {
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

        $this->addData($suggestItem, $kind, $hit, $propertyAccess);

        $suggestItem->setType($kind);
        return $suggestItem;
    }

    /**
     * Extracts the identifier and type of the document from the hit.
     *
     * @param SuggestItem $item
     * @param object $hit
     * @param PropertyAccessor $propertyAccess
     */
    private function addEntityResolveStruct(SuggestItem $item, object $hit, PropertyAccessor $propertyAccess): void
    {
        $type = $item->getType();
        $idPropertyPath = 'highlighted.id';
        if ($type === StripClassPathUtil::stripClassPath(ProductDataType::class)) {
            $productPropertyPath = 'highlighted._product';
            $productProductNumberPropertyPath = $productPropertyPath . '.productNumber';
            $productMasterProductNumberPropertyPath = $productPropertyPath . '.masterProductNumber';
            //TODO: We need to implement something that allows products to be differentiated by type.
            $item->setType(SuggestTypes::PRODUCT->value);

            if ($propertyAccess->isReadable($hit, $productProductNumberPropertyPath)) {
                $productNumbers = $propertyAccess->getValue($hit, $productProductNumberPropertyPath);
                $item->setAttribute(EntityResolveStruct::class, new EntityResolveStruct(
                    str_replace(['<mark>', '</mark>'], ['', ''], $productNumbers[0]),
                    ProductDefinition::ENTITY_NAME,
                ));
            } elseif ($propertyAccess->isReadable($hit, $productMasterProductNumberPropertyPath)) {
                $item->setAttribute(EntityResolveStruct::class, new EntityResolveStruct(
                    str_replace(['<mark>', '</mark>'], ['', ''], $propertyAccess->getValue($hit, $productMasterProductNumberPropertyPath)),
                    ProductDefinition::ENTITY_NAME,
                ));
            } elseif ($propertyAccess->isReadable($hit, $idPropertyPath)) {
                $item->setAttribute(EntityResolveStruct::class, new EntityResolveStruct(
                    str_replace(['<mark>', '</mark>'], ['', ''], $propertyAccess->getValue($hit, $idPropertyPath)),
                    ProductDefinition::ENTITY_NAME,
                ));
            }
        } elseif ($type === StripClassPathUtil::stripClassPath(ContentDataType::class)) {
            if ($propertyAccess->isReadable($hit, $idPropertyPath)) {
                $id = $propertyAccess->getValue($hit, $idPropertyPath);
                $contentPropertyPath = 'highlighted._content';
                if ($propertyAccess->isReadable($hit, $contentPropertyPath)) {
                    $contentTypePath = $contentPropertyPath.'.contentType';
                    if ($propertyAccess->isReadable($hit, $contentTypePath)) {
                        $contentType = $propertyAccess->getValue($hit, $contentTypePath);
                        $item->setType($contentType);
                        $item->setAttribute(EntityResolveStruct::class, new EntityResolveStruct(
                            $id,
                            $contentType === 'landingpage' ? LandingPageDefinition::ENTITY_NAME : CategoryDefinition::ENTITY_NAME,
                        ));
                    }
                }
            }
        }
    }

    /**
     * Extracts the common fields from the hit.
     *
     * @param SuggestItem $item
     * @param string $locale
     * @param object $hit
     * @param PropertyAccessor $propertyAccess
     */
    private function getCommonFields(SuggestItem $item, string $locale, object $hit, PropertyAccessor $propertyAccess): void
    {
        $commonPropertyPath = 'highlighted._common';
        if ($propertyAccess->isReadable($hit, $commonPropertyPath)) {
            $commonImageUrlPropertyPath = $commonPropertyPath.'.imageUrl';
            if (
                $propertyAccess->isReadable($hit, $commonImageUrlPropertyPath)
                && $propertyAccess->getValue($hit, $commonImageUrlPropertyPath) !== null
            ) {
                $item->setImgUrl($propertyAccess->getValue($hit, $commonImageUrlPropertyPath));
            }
            $commonThumbnailUrlPropertyPath = $commonPropertyPath.'.thumbnailUrl';
            if (
                $propertyAccess->isReadable($hit, $commonThumbnailUrlPropertyPath)
                && $propertyAccess->getValue($hit, $commonThumbnailUrlPropertyPath) !== null
                && !empty($propertyAccess->getValue($hit, $commonThumbnailUrlPropertyPath))
            ) {
                $item->setImgUrl($propertyAccess->getValue($hit, $commonThumbnailUrlPropertyPath));
            }
        }

        if ($propertyAccess->isReadable($hit, 'highlighted._common_i18n.' . $locale)) {
            $commonTranslationPropertyPath = 'highlighted._common_i18n.' . $locale;
        } else {
            $commonTranslationPropertyPath = 'highlighted._common_i18n';
        }
        if ($propertyAccess->isReadable($hit, $commonTranslationPropertyPath)) {
            $urlPropertyPath = $commonTranslationPropertyPath.'.url';
            if ($propertyAccess->isReadable($hit, $urlPropertyPath)) {
                $item->setUrl(strip_tags((string) $propertyAccess->getValue($hit, $urlPropertyPath)));
            }
        }
    }

    /**
     * Extracts the AI pick from the hit.
     *
     * @param SuggestItem $item
     * @param object $hit
     * @param PropertyAccessor $propertyAccess
     */
    private function addAiPickAttribute(SuggestItem $item, object $hit, PropertyAccessor $propertyAccess): void
    {
        $aIPickPath = 'highlighted._ai.pick';
        if ($propertyAccess->isReadable($hit, $aIPickPath)) {
            $aIAttribute = [];
            $aIPickCategoryPath = $aIPickPath . '.category';
            if ($propertyAccess->isReadable($hit, $aIPickCategoryPath)) {
                $aIAttribute['category'] = $propertyAccess->getValue($hit, $aIPickCategoryPath);
            }
            $aIPickNamePath = $aIPickPath . '.name';
            if ($propertyAccess->isReadable($hit, $aIPickNamePath)) {
                $aIAttribute['name'] = $propertyAccess->getValue($hit, $aIPickNamePath);
            }
            $item->setAttribute('ai_pick', $aIAttribute);
        }
    }

    /**
     * Checks if the data attribute exists and adds the data to the item.
     *
     * @param SuggestItem $item
     * @param string $kind
     * @param object $hit
     * @param PropertyAccessor $propertyAccess
     */
    private function addData(SuggestItem $item, string $kind, object $hit, PropertyAccessor $propertyAccess): void
    {
        $dataIdPath = 'data.id';
        if ($propertyAccess->isReadable($hit, $dataIdPath) && str_contains($kind, '.categoryTree.name')) {
            $id = $propertyAccess->getValue($hit, $dataIdPath);
            $item->setAttribute(EntityResolveStruct::class, new EntityResolveStruct(
                $id, CategoryDefinition::ENTITY_NAME
            ));
        }
    }
}
