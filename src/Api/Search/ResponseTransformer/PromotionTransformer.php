<?php declare(strict_types=1);
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

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;


use Elio\ElioBatteryIncludedApiClient\Model\Extension;
use Elio\ElioBatteryIncludedApiClient\Model\Result;
use Elio\ElioBatteryIncludedApiClient\Model\SuggestionResultCollection;
use Elio\ElioBatteryIncludedSearchExtension\Configuration\BatteryIncludedConfiguration;
use Elio\ElioDataDiscovery\Api\Request\ApiRequest;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Api\Search\Response\CampaignFeedbackResponse;
use Elio\ElioDataDiscovery\Api\Search\Response\CampaignFeedbackResponseCollection;
use Elio\ElioDataDiscovery\Api\Transform\ResponseTransformerInterface;
use Elio\ElioDataDiscovery\Configuration\ElioDataDiscoveryConfigServiceInterface;
use Elio\ElioDataDiscovery\Core\Exception\InvalidTypeException;
use Elio\ElioDataDiscovery\Swagger\ModelInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Class PromotionTransformer
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer
 * @category Shopware
 * @author elio GmbH <support@elio-systems.com>
 * @author Danil Lukov <dl@elio-systems.com>
 * @copyright Copyright (c) 2024, elio GmbH (
 * https://www.elio-systems.com)
 */
class PromotionTransformer implements ResponseTransformerInterface
{
    public const TYPE_PROMOTION = 'promotions';

    public function __construct(
        private readonly ElioDataDiscoveryConfigServiceInterface $configService
    ) {}

    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof Result || $model instanceof SuggestionResultCollection;
    }

    public function transform(
        ModelInterface $model,
        ResponseCollection $responseCollection,
        SalesChannelContext $context,
        ApiRequest $request
    ): void {
        if ($model instanceof Result) {
            $promotions = $this->getPromotionsFromResult($model);
        } elseif ($model instanceof SuggestionResultCollection) {
            $promotions = $this->getPromotionsFromSuggestionResultCollection($model);
        } else {
            throw new InvalidTypeException($model, Result::class . ' or ' . SuggestionResultCollection::class);
        }

        if (empty($promotions)) {
            return;
        }

        /** @var BatteryIncludedConfiguration $bIConfig */
        $bIConfig = $this->configService->getByContext($context)->getExtension(BatteryIncludedConfiguration::NAME);
        $promotionTemplate = $model instanceof Result ? $bIConfig->getPromotionTemplate() : $bIConfig->getSuggestPromotionTemplate();

        $campaignFeedbackResponseCollection = $responseCollection->get(CampaignFeedbackResponseCollection::KEY) ?? new CampaignFeedbackResponseCollection();
        $responseCollection->set(CampaignFeedbackResponseCollection::KEY, $campaignFeedbackResponseCollection);

        foreach ($promotions as $promotion) {
            $campaignFeedbackResponseCollection->addCampaignFeedbackResponse(new CampaignFeedbackResponse(
                'above product listing',
                $this->generatePromotionHtml(
                    $promotionTemplate,
                    $promotion['url'] ?? '',
                    $promotion['image']->desktop ?? '',
                    $promotion['image']->mobile ?? '',
                    $promotion['name'] ?? ''
                ),
                true
            ));
        }
    }

    /**
     * Extracts the promotions from the result model. This is used for navigation or search result.
     *
     * @param ModelInterface $model
     * @return array
     */
    private function getPromotionsFromResult(ModelInterface $model): array
    {
        $promotions = [];
        $extensions = [];
        if (method_exists($model, 'getExtensions')) {
            $extensions = $model->getExtensions();
        }
        /** @var Extension $extension */
        foreach ($extensions as $extension) {
            if ($extension->getType() === self::TYPE_PROMOTION) {

                if (!empty($data = $extension->getData())) {
                    $promotions[] = $data;
                }
            }
        }
        return $promotions;
    }

    /**
     * Get promotions from the suggestion result collection. This is used for suggest.
     *
     * @param SuggestionResultCollection $model The suggestion result collection.
     * @return array The array of promotions.
     */
    private function getPromotionsFromSuggestionResultCollection(SuggestionResultCollection $model): array
    {
        $promotions = [];
        foreach ($model->getSuggestionResults() as $suggestionResult) {
            if (!$suggestionResult->getHits() || $suggestionResult->getKind() !== PromotionTransformer::TYPE_PROMOTION) {
                continue;
            }

            foreach ($suggestionResult->getHits() as $hit) {
                $promotions[] = get_object_vars($hit);
            }
        }

        return $promotions;
    }

    private function generatePromotionHtml(
        string $promotionTemplate,
        string $url,
        string $imageDesktopUrl,
        string $imageMobileUrl,
        string $name
    ): string {
        return str_replace(
            ['%url%', '%imageDesktopUrl%', '%imageMobileUrl%', '%name%'],
            [$url, $imageDesktopUrl, $imageMobileUrl, $name],
            $promotionTemplate
        );
    }
}