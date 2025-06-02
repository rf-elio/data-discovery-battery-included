<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;

use Elio\ElioBatteryIncludedApiClient\Model\SuggestionResultCollection;
use Elio\ElioDataDiscovery\Api\Request\ApiRequest;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Api\Search\ResponseTransformer\AbstractSuggestProductTransformer;
use Elio\ElioDataDiscovery\Core\Exception\InvalidTypeException;
use Elio\ElioDataDiscovery\Core\Suggest\SuggestItem;
use Elio\ElioDataDiscovery\Swagger\ModelInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class SuggestProductTransformer extends AbstractSuggestProductTransformer
{
    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof SuggestionResultCollection;
    }

    public function transform(ModelInterface $model, ResponseCollection $responseCollection, SalesChannelContext $context, ApiRequest $request): void
    {
        if (!$model instanceof SuggestionResultCollection) {
            throw new InvalidTypeException($model, SuggestionResultCollection::class);
        }

        parent::transform($model, $responseCollection, $context, $request);
    }

    protected function getProductNumber(SuggestItem $item): ?string
    {
        $attributes = $item->getAttributes();
        if (!empty($attributes['ProductNumber'])) {
            return str_replace(['<mark>', '</mark>'], ['', ''], array_shift($attributes['ProductNumber']));
        }

        $masterProductNumber = $attributes['MasterProductNumber'] ?? null;
        if ($masterProductNumber) {
            return str_replace(['<mark>', '</mark>'], ['', ''], $masterProductNumber);
        }

        return null;
    }
}
