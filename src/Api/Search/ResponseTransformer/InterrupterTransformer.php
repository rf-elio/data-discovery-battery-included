<?php
declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;

use Elio\ElioBatteryIncludedApiClient\Model\Result;
use Elio\ElioDataDiscovery\Api\Request\ApiRequest;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Api\Search\Response\InterrupterResponse;
use Elio\ElioDataDiscovery\Api\Transform\ResponseTransformerInterface;
use Elio\ElioDataDiscovery\Core\Content\Interrupter\SalesChannel\InterrupterItem;
use Elio\ElioDataDiscovery\Core\Content\Interrupter\SalesChannel\SeoResolver;
use Elio\ElioDataDiscovery\Core\Exception\InvalidTypeException;
use Elio\ElioDataDiscovery\Swagger\ModelInterface;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class InterrupterTransformer implements ResponseTransformerInterface
{
    public function __construct(
        private readonly SeoResolver $seoResolver,
    )
    {
    }

    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof Result;
    }

    public function transform(ModelInterface $model, ResponseCollection $responseCollection, SalesChannelContext $context, ApiRequest $request): void
    {
        if(!$model instanceof Result) {
            throw new InvalidTypeException($model, Result::class);
        }

        $extensions = $model->getExtensions();
        $productInterrupters = [];
        $interrupterResponse = new InterrupterResponse();

        foreach ($extensions as $extension) {
            if ($extension->getType() !== InterrupterItem::INTERRUPTER_ITEM_TYPE) {
                continue;
            }

            $data = $extension->getData();
            $imageData = json_decode(json_encode($data['image']), true);

            $interrupter = new InterrupterItem(
                $data['name'] ?? '',
                ($data['position'] ?? 1) - 1,
                $data['format'] ?? '1x1',
                $data['url'] ?? '',
                $imageData['desktop'] ?? '',
                $imageData['mobile'] ?? '',
                $imageData['alt'] ?? '',
                $data['code'] ?? '',
                $data['itemId'] ?? '',
                $data['itemType'] ?? ''
            );

            if ($interrupter->getItemType() === ProductDefinition::ENTITY_NAME) {
                $productInterrupters[] = $interrupter;
                continue;
            }

            $interrupterResponse->addInterrupterItem($interrupter);
        }

        if (!empty($productInterrupters)) {
            $productInterrupters = $this->seoResolver->resolveProductNumbersIntoIds($productInterrupters, $context);
            foreach ($productInterrupters as $interrupter) {
                $interrupterResponse->addInterrupterItem($interrupter);
            }
        }

        $responseCollection->set(InterrupterResponse::class, $interrupterResponse);
    }
}
