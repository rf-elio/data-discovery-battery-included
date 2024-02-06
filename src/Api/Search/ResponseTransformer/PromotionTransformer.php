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
use Elio\ElioBatteryIncludedSearchExtension\Configuration\BatteryIncludedConfiguration;
use Elio\ElioSearch\Api\Request\ApiRequest;
use Elio\ElioSearch\Api\Response\ResponseCollection;
use Elio\ElioSearch\Api\Search\Response\AdvisorCampaignResponseCollection;
use Elio\ElioSearch\Api\Search\Response\CampaignFeedbackResponse;
use Elio\ElioSearch\Api\Search\Response\CampaignFeedbackResponseCollection;
use Elio\ElioSearch\Api\Transform\ResponseTransformerInterface;
use Elio\ElioSearch\Configuration\ElioSearchConfigServiceInterface;
use Elio\ElioSearch\Core\Exception\InvalidTypeException;
use Elio\ElioSearch\Swagger\ModelInterface;
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

    public function __construct(private readonly ElioSearchConfigServiceInterface $configService)
    {
    }

    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof Result;
    }

    public function transform(
        ModelInterface $model,
        ResponseCollection $responseCollection,
        SalesChannelContext $context,
        ApiRequest $request
    ): void
    {
        if (!$model instanceof Result) {
            throw new InvalidTypeException($model, Result::class);
        }

        $promotions = [];
        $extensions = $model->getExtensions() ?? [];
        /** @var Extension $extension */
        foreach ($extensions as $extension) {
            if ($extension->getType() === self::TYPE_PROMOTION) {
                $promotions[] = $extension;
            }
        }

        if (empty($promotions)) {
            return;
        }

        /** @var BatteryIncludedConfiguration $BIConfig */
        $BIConfig = $this->configService->getByContext($context)->getExtension(BatteryIncludedConfiguration::NAME);
        $promotionTemplate = $BIConfig->getPromotionTemplate();

        $campaignFeedbackResponseCollection = new CampaignFeedbackResponseCollection();
        $responseCollection->set(CampaignFeedbackResponseCollection::KEY, $campaignFeedbackResponseCollection);

        $advisorCampaignResponseCollection = new AdvisorCampaignResponseCollection();
        $responseCollection->set(AdvisorCampaignResponseCollection::KEY, $advisorCampaignResponseCollection);

        foreach ($promotions as $promotion) {
            if (empty($data = $promotion->getData())) {
                continue;
            }

            $campaignFeedbackResponseCollection->addCampaignFeedbackResponse(new CampaignFeedbackResponse(
                'above product listing',
                $this->generatePromotionHtml($promotionTemplate, $data['url'], $data['image']->desktop, $data['name']),
                true
            ));
        }
    }

    private function generatePromotionHtml(string $promotionTemplate, string$url, string $imageUrl, string $alt = ''): string
    {
        return str_replace(
            ['%url%', '%imageUrl%', '%alt%'],
            [$url, $imageUrl, $alt],
            $promotionTemplate
        );
    }
}