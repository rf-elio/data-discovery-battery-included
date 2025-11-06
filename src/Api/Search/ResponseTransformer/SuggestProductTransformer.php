<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;

use Elio\ElioBatteryIncludedApiClient\Model\SuggestionResultCollection;
use Elio\ElioDataDiscovery\Api\Request\ApiRequest;
use Elio\ElioDataDiscovery\Api\Search\ResponseTransformer\AbstractSuggestProductTransformer;
use Elio\ElioDataDiscovery\Core\Suggest\SuggestItem;
use Elio\ElioDataDiscovery\Swagger\ModelInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class SuggestProductTransformer extends AbstractSuggestProductTransformer
{
    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof SuggestionResultCollection;
    }

    protected function getProductNumber(SuggestItem $item): ?string
    {
        $identifier = $item->getIdentifier();
        if ($identifier) {
            return str_replace(['<mark>', '</mark>'], ['', ''], $identifier);
        }

        return null;
    }
}
