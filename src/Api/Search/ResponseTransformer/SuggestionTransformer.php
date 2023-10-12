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
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swagger\Client\Model\ModelInterface;
use Swagger\Client\Model\SuggestionResult;
use Swagger\Client\Model\SuggestionResultCollection;
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

    /**
     * SuggestionTransformer constructor.
     * @param ElioSearchConfigServiceInterface $configService
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(
        ElioSearchConfigServiceInterface $configService,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->configService = $configService;
        $this->eventDispatcher = $eventDispatcher;
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
                $suggestItem = $this->transformSuggestion($hit, $suggestionResult->getKind());

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
     * @return SuggestItem
     */
    private function transformSuggestion(object $hit, string $type): SuggestItem
    {
        $suggestItem = new SuggestItem();

        if ($type === SuggestionResult::RESULT_TYPE_DOCUMENT) {
            $suggestItem->setName(strip_tags($hit->highlighted));
            // TODO: Get from const
            if (isset($hit->{'_product'})) {
                $suggestItem->setType(SuggestionProductTransformer::TYPE);
                $suggestItem->setAttributes(['MasterProductNumber' => $hit->value]);
            } elseif (isset($hit->{'_content'})) {
                $suggestItem->setType(SuggestionCategoryTransformer::TYPE);
            }
        } else {
            $suggestItem->setName($hit->value);
            $suggestItem->setType($type);
        }

//        if ($hit->getImage() !== '') {
//            $suggestItem->setImgUrl($hit->getImage());
//        }

//        /** @var array $attributes */
//        $attributes = $suggestion->getAttributes();
//        if (!empty($attributes)) {
//            $suggestItem->setAttributes($this->parseAttributes($attributes));
//        }
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
