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

use ArrayObject;
use Elio\ElioSearch\Api\Request\ApiRequest;
use Elio\ElioSearch\Api\Response\ResponseCollection;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\Request\SearchRequest;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\Response\AdvisorCampaignResponse;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\Response\AdvisorCampaignResponseCollection;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\Response\CampaignFeedbackResponse;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\Response\CampaignFeedbackResponseCollection;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\Response\ProductListingResponse;
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\Response\CampaignRedirectionResponse;
use Elio\ElioSearch\Api\Transform\ResponseTransformerInterface;
use Elio\ElioSearch\Core\AdvisorCampaign\AdvisorAnswer;
use Elio\ElioSearch\Core\AdvisorCampaign\AdvisorQuestion;
use Elio\ElioSearch\Core\Exception\InvalidTypeException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swagger\Client\Model\DetailPage;
use Swagger\Client\Model\ModelInterface;
use Swagger\Client\Model\Question;
use Swagger\Client\Model\Result;

/**
 * Converts the campaigns to the internal campaign objects
 *
 * Class CampaignTransformer
 * @category  Shopware
 * @author    elio GmbH <support@elio-systems.com>
 * @author    Simon Greiner <sg@elio-systems.com>
 * @copyright Copyright (c) 2021, elio GmbH (https://www.elio-systems.com)
 */
class CampaignTransformer implements ResponseTransformerInterface
{
    private CONST FLAVOR_REDIRECT = 'REDIRECT';
    private CONST FLAVOR_ADVISOR = 'ADVISOR';

    /**
     * @inheritDoc
     */
    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof Result || $model instanceof DetailPage;
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
    ): void
    {
        if (!$model instanceof Result && !$model instanceof DetailPage) {
            throw new InvalidTypeException($model, Result::class);
        }

        $listing = $responseCollection->get(ProductListingResponse::class) ?? new ProductListingResponse();
        $responseCollection->set(ProductListingResponse::class, $listing);

        $campaignFeedbackResponseCollection = new CampaignFeedbackResponseCollection();
        $responseCollection->set(CampaignFeedbackResponseCollection::KEY, $campaignFeedbackResponseCollection);

        $advisorCampaignResponseCollection = new AdvisorCampaignResponseCollection();
        $responseCollection->set(AdvisorCampaignResponseCollection::KEY, $advisorCampaignResponseCollection);

        foreach ($model->getCampaigns() as $campaign) {
            if ($campaign->getFlavour() === self::FLAVOR_REDIRECT) {
                $responseCollection->set(CampaignRedirectionResponse::class, new CampaignRedirectionResponse(
                    $campaign->getTarget()->getName(),
                    $campaign->getTarget()->getDestination(),
                ));
            }

            foreach ($campaign->getFeedbackTexts() as $feedbackText){
                $campaignFeedbackResponseCollection->addCampaignFeedbackResponse(new CampaignFeedbackResponse(
                    $feedbackText->getLabel(),
                    $feedbackText->getText(),
                    $feedbackText->getHtml()
                ));
            }

            if ($campaign->getFlavour() === self::FLAVOR_ADVISOR) {
                // if a campaign is answered, we return only this campaign in the response
                if (
                    $request instanceof SearchRequest &&
                    $request->getAdvisorStatus() &&
                    $request->getAdvisorStatus()->getCampaignId() !== $campaign->getId()
                ) {
                    continue;
                }

                $questions = [];
                foreach ($campaign->getAdvisorTree() as $question) {
                    $questions[] = $this->questionWalk($question);
                }

                $questionPath = new ArrayObject();
                $this->buildAnswerPath($questions, $questionPath);
                $questionPath = $questionPath->getArrayCopy();

                $answerPath = '';
                /** @var AdvisorQuestion[] $questionPath */
                if (!empty($questionPath)) {
                    $latestQuestion = current($questionPath);
                    if($latestSelectedAnswer = $latestQuestion->getSelectedAnswer()) {
                        $answerPath = $latestSelectedAnswer->getAnswerPath();
                    }
                }

                $activeQuestions = [];
                foreach ($campaign->getActiveQuestions() as $activeQuestion) {
                    $activeQuestions[] = $this->questionWalk($activeQuestion);
                }

                $advisorCampaignResponseCollection->addAdvisorCampaignResponse(new AdvisorCampaignResponse(
                    $campaign->getId(),
                    $campaign->getName(),
                    $activeQuestions,
                    array_reverse($questionPath),
                    $answerPath
                ));
            }
        }
    }

    /**
     * Creates the advisor tree based on the given ff response
     *
     * @param Question $question
     * @return AdvisorQuestion
     */
    protected function questionWalk(Question $question): AdvisorQuestion
    {
        $advisorQuestion = (new AdvisorQuestion())
            ->setId($question->getId())
            ->setText($question->getText())
            ->setVisible($question->getVisible());

        foreach ($question->getAnswers() as $answer) {
            $advisorAnswer = (new AdvisorAnswer())
                ->setId($answer->getId())
                ->setText($answer->getText())
                ->setAnswerPath($answer->getSearchParams()->getAdvisorStatus()->getAnswerPath())
                ->setSelected($answer->getSelected());

            foreach ($answer->getQuestions() as $subQuestion) {
                $advisorAnswer->addQuestion($this->questionWalk($subQuestion));
            }

            $advisorQuestion->addAnswer($advisorAnswer);
        }

        return $advisorQuestion;
    }

    /**
     * Creates a list of selected questions and sets the parent question / answer as selected (ff set's only the last
     * question as selected).
     *
     * @param AdvisorQuestion[] $questions
     */
    protected function buildAnswerPath(array $questions, ArrayObject $questionPath): bool
    {
        foreach ($questions as $question) {
            foreach ($question->getAnswers() as $answer) {
                if ($this->buildAnswerPath($answer->getQuestions(), $questionPath)) {
                    $answer->setSelected(true);
                }

                if ($answer->isSelected()) {
                    $questionPath->append($question);
                    return true;
                }
            }
        }

        return false;
    }
}
