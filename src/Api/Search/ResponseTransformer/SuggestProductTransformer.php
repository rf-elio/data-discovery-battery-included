<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;

use Elio\ElioBatteryIncludedApiClient\Model\SuggestionResultCollection;
use Elio\ElioDataDiscovery\Api\Request\ApiRequest;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Api\Search\Components\SuggestTypes;
use Elio\ElioDataDiscovery\Api\Search\Response\SuggestionResponse;
use Elio\ElioDataDiscovery\Api\Search\ResponseTransformer\Event\SuggestProductCollectCriteriaEvent;
use Elio\ElioDataDiscovery\Api\Transform\ResponseTransformerInterface;
use Elio\ElioDataDiscovery\Configuration\ElioDataDiscoveryConfigServiceInterface;
use Elio\ElioDataDiscovery\Core\Exception\InvalidTypeException;
use Elio\ElioDataDiscovery\Core\Suggest\SuggestGroup;
use Elio\ElioDataDiscovery\Core\Suggest\SuggestItem;
use Elio\ElioDataDiscovery\Swagger\ModelInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class SuggestProductTransformer implements ResponseTransformerInterface
{
    public function __construct(
        private readonly SalesChannelRepository $productRepository,
        private readonly ElioDataDiscoveryConfigServiceInterface $configService,
        private readonly EventDispatcherInterface $eventDispatcher
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

        $productGroupKey = SuggestTypes::PRODUCT->value;
        if (isset($groupLabels[SuggestTypes::PRODUCT->value])) {
            $productGroupKey = $groupLabels[SuggestTypes::PRODUCT->value];
        }
        
        if(
            !$suggestionResponse ||
            !$suggestionResponse->hasGroup($productGroupKey)) {
            return;
        }

        $productGroup = $suggestionResponse->getGroup($productGroupKey);
        $products = $this->collect($productGroup, $context);
        $this->enrich($productGroup, $products);
    }

    protected function collect(SuggestGroup $group, SalesChannelContext $context): array
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
        $event = new SuggestProductCollectCriteriaEvent($criteria, $context);
        $this->eventDispatcher->dispatch($event);

        $products = [];

        /** @var ProductEntity $product */
        foreach ($this->productRepository->search($event->getCriteria(), $context) as $product) {
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
