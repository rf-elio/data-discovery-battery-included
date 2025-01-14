<?php
declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;

use Elio\ElioBatteryIncludedApiClient\Model\Result;
use Elio\ElioDataDiscovery\Api\Request\ApiRequest;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Api\Search\ResponseTransformer\AbstractInterrupterTransformer;
use Elio\ElioDataDiscovery\Core\Content\Interrupter\SalesChannel\InterrupterItem;
use Elio\ElioDataDiscovery\Core\Exception\InvalidTypeException;
use Elio\ElioDataDiscovery\Swagger\ModelInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class InterrupterTransformer extends AbstractInterrupterTransformer
{
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
        $interrupters = [];

        foreach ($extensions as $extension) {
            if ($extension->getType() !== InterrupterItem::INTERRUPTER_ITEM_TYPE) {
                continue;
            }

            $data = $extension->getData();
            $imageData = get_object_vars($data['image']);
            if (isset($data['itemId'])) {
                $data['itemId'] = trim($data['itemId']);
            }

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

            $interrupters[] = $interrupter;
        }

        $this->createInterrupterResponse($responseCollection, $interrupters, $context);
    }
}
