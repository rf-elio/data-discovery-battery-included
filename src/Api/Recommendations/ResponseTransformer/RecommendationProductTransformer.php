<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Recommendations\ResponseTransformer;

use Elio\ElioBatteryIncludedApiClient\Model\RecommendationResultCollection;
use Elio\ElioBatteryIncludedSearchExtension\Api\Recommendations\Util\ProductNumberExtractor;
use Elio\ElioDataDiscovery\Api\Recommendations\ResponseTransformer\AbstractRecommendationProductTransformer;
use Elio\ElioDataDiscovery\Api\Request\ApiRequest;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Core\Exception\InvalidTypeException;
use Elio\ElioDataDiscovery\Swagger\ModelInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class RecommendationProductTransformer extends AbstractRecommendationProductTransformer
{
    /**
     * @param ModelInterface $model
     * @param ApiRequest $request
     * @param SalesChannelContext $context
     * @return bool
     */
    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof RecommendationResultCollection;
    }

    /**
     * @param ModelInterface $model
     * @param ResponseCollection $responseCollection
     * @param SalesChannelContext $context
     * @param ApiRequest $request
     */
    public function transform(ModelInterface $model, ResponseCollection $responseCollection, SalesChannelContext $context, ApiRequest $request): void
    {
        if (!$model instanceof RecommendationResultCollection) {
            throw new InvalidTypeException($model, RecommendationResultCollection::class);
        }

        $productNumbersPerType = ProductNumberExtractor::extractProductNumbers($model);
        $this->loadProductsForTypes($productNumbersPerType, $responseCollection, $context);
    }
}