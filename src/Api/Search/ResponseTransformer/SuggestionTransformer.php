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
use Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Output\Util\LocaleUtil;
use Elio\ElioSearch\Api\Search\Response\SuggestionResponse;
use Elio\ElioSearch\Api\Transform\ResponseTransformerInterface;
use Elio\ElioSearch\Api\Request\ApiRequest;
use Elio\ElioSearch\Api\Response\ResponseCollection;
use Elio\ElioSearch\Configuration\Configuration;
use Elio\ElioSearch\Configuration\ElioSearchConfigServiceInterface;
use Elio\ElioSearch\Core\Exception\InvalidTypeException;
use Elio\ElioSearch\Core\Suggest\SuggestGroup;
use Elio\ElioSearch\Core\Suggest\SuggestItem;
use Psr\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swagger\Client\Model\ModelInterface;
use Swagger\Client\Model\SuggestionResult;
use Swagger\Client\Model\SuggestionResultCollection;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Throwable;

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
    private ElioSearchConfigServiceInterface $configService;
    private EventDispatcherInterface $eventDispatcher;
    private EntityRepository $languageRepository;

    /**
     * SuggestionTransformer constructor.
     * @param ElioSearchConfigServiceInterface $configService
     * @param EventDispatcherInterface $eventDispatcher
     * @param EntityRepository $languageRepository
     */
    public function __construct(
        ElioSearchConfigServiceInterface $configService,
        EventDispatcherInterface $eventDispatcher,
        EntityRepository $languageRepository
    ) {
        $this->configService = $configService;
        $this->eventDispatcher = $eventDispatcher;
        $this->languageRepository = $languageRepository;
    }

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

        $criteria = new Criteria([$context->getLanguageId()]);
        $criteria->addAssociation('locale');
        /** @var LanguageEntity $language */
        $language = $this->languageRepository->search($criteria, $context->getContext())->first();
        $locale = LocaleUtil::getLocaleByLanguage($language);

        /** @var SuggestionResponse|null $suggestionResponse */
        $suggestionResponse = $responseCollection->get(SuggestionResponse::class) ?? new SuggestionResponse();
        $responseCollection->set(SuggestionResponse::class, $suggestionResponse);
        $config = $this->configService->getByContext($context);
        $groupLabels = $config->getSuggestTypeLabels();
        $suggestGroups = [];

        foreach ($model->getSuggestionResults() as $suggestionResult) {
            if (!$suggestionResult->getHits()) {
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
                $group = $suggestGroups[$type] ?? new SuggestGroup($type, $groupLabels[$type] ?? $type);
                $suggestGroups[$type] = $group;
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
            $namePropertyPath = 'highlight._i8n.'.$locale.'.name';
            if ($propertyAccess->isReadable($hit, $namePropertyPath)) {
                $suggestItem->setName(strip_tags($propertyAccess->getValue($hit, $namePropertyPath)));
            }

            $urlPropertyPath = 'highlight._i8n.'.$locale.'.url';
            if ($propertyAccess->isReadable($hit, $urlPropertyPath)) {
                $suggestItem->setUrl(strip_tags($propertyAccess->getValue($hit, $urlPropertyPath)));
            }

            $productPropertyPath = 'highlight._product';
            if ($propertyAccess->isReadable($hit, $productPropertyPath)) {
                $attributes = [];
                $productMasterProductNumberPropertyPath = 'highlight._product.masterProductNumber';
                if ($propertyAccess->isReadable($hit, $productMasterProductNumberPropertyPath)) {
                    $attributes['MasterProductNumber'] = $propertyAccess->getValue($hit, $productMasterProductNumberPropertyPath);
                }

                $productThumbnailPropertyPath = 'highlight._product.thumbnailUrl';
                if ($propertyAccess->isReadable($hit, $productThumbnailPropertyPath)) {
                    $suggestItem->setImgUrl($propertyAccess->getValue($hit, $productThumbnailPropertyPath));
                }

                $suggestItem->setType(SuggestionProductTransformer::TYPE);
                $suggestItem->setAttributes($attributes);
            }

            $contentPropertyPath = 'highlight._content';
            if ($propertyAccess->isReadable($hit, $contentPropertyPath)) {
                $suggestItem->setType(SuggestionCategoryTransformer::TYPE);
            }

            return $suggestItem;
        }

        $suggestItem->setName($hit->value);
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
                $group->setPosition($acceptedTypePosition);

            }
        }

        // sort groups
        uasort($groups, static function (SuggestGroup $a, SuggestGroup $b) {
            $posA = $a->getPosition();
            $posB = $b->getPosition();
            if ($posA === $posB) {
                return 0;
            }
            return ($posA < $posB) ? -1 : 1;
        });
        return $groups;
    }
}
