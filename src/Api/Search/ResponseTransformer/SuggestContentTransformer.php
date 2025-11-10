<?php
declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;

use Elio\ElioBatteryIncludedApiClient\Model\SuggestionResultCollection;
use Elio\ElioDataDiscovery\Api\Request\ApiRequest;
use Elio\ElioDataDiscovery\Api\Search\ResponseTransformer\AbstractSuggestContentTransformer;
use Elio\ElioDataDiscovery\Swagger\ModelInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class SuggestContentTransformer extends AbstractSuggestContentTransformer
{
    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof SuggestionResultCollection;
    }
}
