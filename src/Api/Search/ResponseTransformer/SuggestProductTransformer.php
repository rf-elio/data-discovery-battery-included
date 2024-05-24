<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;

use Elio\ElioBatteryIncludedApiClient\Model\SuggestionResultCollection;
use Elio\ElioDataDiscovery\Api\Request\ApiRequest;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Api\Search\Response\SuggestionResponse;
use Elio\ElioDataDiscovery\Api\Transform\ResponseTransformerInterface;
use Elio\ElioDataDiscovery\Configuration\ElioDataDiscoveryConfigServiceInterface;
use Elio\ElioDataDiscovery\Core\Exception\InvalidTypeException;
use Elio\ElioDataDiscovery\Core\Suggest\SuggestGroup;
use Elio\ElioDataDiscovery\Core\Suggest\SuggestItem;
use Elio\ElioDataDiscovery\Swagger\ModelInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class SuggestProductTransformer implements ResponseTransformerInterface
{
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly ElioDataDiscoveryConfigServiceInterface $configService
    ) {}

    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof SuggestionResultCollection;
    }

    public function transform(ModelInterface $model, ResponseCollection $responseCollection, SalesChannelContext $context, ApiRequest $request): void
    {
        if (!$model instanceof SuggestionResultCollection) {
            throw new InvalidTypeException($model, SuggestionResultCollection::class);
        }

        /** @var SuggestionResponse|null $suggestionResponse */
        $suggestionResponse = $responseCollection->get(SuggestionResponse::class) ?? new SuggestionResponse();
        $responseCollection->set(SuggestionResponse::class, $suggestionResponse);
        $config = $this->configService->getByContext($context);
        $groupLabels = $config->getSuggestTypeLabels();
        if(!$suggestionResponse || !$suggestionResponse->hasGroup($groupLabels['product'])) {
            return;
        }

        $productGroup = $suggestionResponse->getGroup($groupLabels['product']);
        $products = $this->collect($productGroup, $context->getContext());
        $this->enrich($productGroup, $products);
    }

    protected function collect(SuggestGroup $group, Context $context): array
    {
        $productNumbers = [];
        foreach ($group->getItems() as $item) {
            if($productNumber = $this->getProductNumber($item)) {
                $productNumbers[] = $productNumber;
            }
        }

        if(empty($productNumbers)) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('productNumber', $productNumbers));
        $products = [];

        /** @var ProductEntity $product */
        foreach ($this->productRepository->search($criteria, $context) as $product) {
            $products[$product->getProductNumber()] = $product;
        }

        return $products;
    }

    protected function enrich(SuggestGroup $group, array $products): void
    {
        foreach ($group->getItems() as $item) {
            $productNumber = $this->getProductNumber($item);
            if($productNumber && isset($products[$productNumber])) {
                $item->setEntity($products[$productNumber]);
            }
        }
    }

    protected function getProductNumber(SuggestItem $item): ?string
    {
        $attributes = $item->getAttributes();
        return $attributes['MasterProductNumber'] ?? null;
    }
}
