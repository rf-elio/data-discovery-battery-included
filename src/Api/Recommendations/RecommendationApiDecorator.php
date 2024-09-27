<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Recommendations;

use Elio\ElioBatteryIncludedApiClient\Model\RecommendationResultCollection;
use Elio\ElioBatteryIncludedSearchExtension\Api\ApiClientFactory;
use Elio\ElioBatteryIncludedSearchExtension\Api\Service\LocaleService;
use Elio\ElioDataDiscovery\Api\Recommendations\RecommendationApi;
use Elio\ElioDataDiscovery\Api\Recommendations\Request\RecommendationRequest;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Api\Transform\Transformer;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class RecommendationApiDecorator extends RecommendationApi
{
    /**
     * SearchApi constructor.
     * @param ApiClientFactory $apiFactory
     * @param Transformer $transformer
     * @param LocaleService $localeService
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ApiClientFactory $apiFactory,
        private readonly Transformer $transformer,
        private readonly LocaleService $localeService,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    /**
     * @param RecommendationRequest $request
     * @param SalesChannelContext $context
     * @return ResponseCollection
     */
    public function getRecommendations(RecommendationRequest $request, SalesChannelContext $context): ResponseCollection
    {
        $apiClient = $this->apiFactory->createSearchApi($context);
        $locale = $this->localeService->getLocaleByContext($context);
        $result = new RecommendationResultCollection($apiClient->recommend($request->getProductNumber(), $locale));
        return $this->transformer->transformResponse($result, $context, $request);
    }
}
