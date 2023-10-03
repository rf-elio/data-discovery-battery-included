<?php

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;


use DateTimeImmutable;
use DateTimeInterface;
use Elio\ElioSearch\Api\Request\ApiRequest;
use Elio\ElioSearch\Api\Response\ResponseCollection;
use Elio\ElioSearch\Api\Search\Request\ContentSearchRequest;
use Elio\ElioSearch\Api\Search\Response\ContentListingResponse;
use Elio\ElioSearch\Api\Transform\ResponseTransformerInterface;
use Elio\ElioSearch\Core\Content\Content\SalesChannel\ContentGroup;
use Elio\ElioSearch\Core\Content\Content\SalesChannel\ContentItem;
use Elio\ElioSearch\Core\Exception\InvalidTypeException;
use Elio\ElioSearch\Core\Sync\Defaults\ContentSyncDefaults;
use Elio\ElioSearch\Core\Sync\Defaults\SyncDefaults;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swagger\Client\Model\ModelInterface;
use Swagger\Client\Model\Result;

/**
 * Adds the content responses (content channel) to the search result
 *
 * Class ContentTransformer
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer
 * @category  Shopware
 * @author    elio GmbH <support@elio-systems.com>
 * @author    Ralf Frommherz <rf@elio-systems.com>
 * @copyright Copyright (c) 2021, elio GmbH (https://www.elio-systems.com)
 */
class ContentTransformer implements ResponseTransformerInterface
{
    protected const MASTER_VALUE_MASTER_PRODUCT_NUMBER = 'MasterProductNumber';
    protected const MASTER_VALUE_CATEGORY_PATH = 'CategoryPath';
    protected const MASTER_VALUE_NAME = 'Name';
    protected const MASTER_VALUE_DESCRIPTION = 'Description';
    protected const MASTER_VALUE_URL = 'ProductURL';
    protected const MASTER_VALUE_IMAGE_URL = 'ImageURL';
    protected const TOP_CONTENT_PREFIX = 'top-';

    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof Result && $request instanceof ContentSearchRequest;
    }

    /**
     * Adds the content responses to the result collection
     *
     * @param ModelInterface $model
     * @param ResponseCollection $responseCollection
     * @param SalesChannelContext $context
     * @param ApiRequest $request
     */
    public function transform(ModelInterface $model, ResponseCollection $responseCollection, SalesChannelContext $context, ApiRequest $request): void
    {
        if(!$model instanceof Result) {
            throw new InvalidTypeException($model, Result::class);
        }
        $listing = $responseCollection->get(ContentListingResponse::class) ?? new ContentListingResponse();
        $responseCollection->set(ContentListingResponse::class, $listing);

        foreach ($model->getHits() as $hit) {
            if (!isset($hit['document']['_content'])) {
                continue;
            }

            // TODO: Implement content
            $masterValues = $hit->getMasterValues();
            $content = new ContentItem(
                $hit->getId(),
                $this->getFirstValue($masterValues, ContentSyncDefaults::FIELD_TYPE) ?? '',
                $this->getFirstValue($masterValues, ContentSyncDefaults::FIELD_CONTENT_STRUCTURE) ?? '',
                $this->getFirstValue($masterValues, ContentSyncDefaults::FIELD_TITLE) ?? '',
                $this->getFirstValue($masterValues, ContentSyncDefaults::FIELD_DESCRIPTION) ?? '',
                $this->getFirstValue($masterValues, ContentSyncDefaults::FIELD_URL) ?? '',
                $this->getFirstValue($masterValues, ContentSyncDefaults::FIELD_IMAGE_URL) ?? '',
                $this->restoreDateTime(
                    $this->getFirstValue($masterValues, ContentSyncDefaults::FIELD_PUBLICATION_DATE) ?? ''
                ),
                (int)($this->getFirstValue($masterValues, ContentSyncDefaults::FIELD_PRIORITY) ?? ContentSyncDefaults::DEFAULT_PRIORITY),
                $hit->getPosition()
            );
            $listing->addContentItem($content);
        }

        $this->createContentGroups($listing);
    }

    /**
     * @param array $masterValues
     * @param string $key
     * @return mixed|null
     */
    protected function getFirstValue(array $masterValues, string $key): mixed
    {
        if(!isset($masterValues[$key])) {
            return null;
        }

        if(!is_array($masterValues[$key])) {
            return $masterValues[$key];
        }

        $values = $masterValues[$key];
        return array_shift($values);
    }

    /**
     * Restores the date time by the default export format
     *
     * @param string|null $value
     * @return DateTimeInterface|null
     */
    protected function restoreDateTime(?string $value) : ?DateTimeInterface
    {
        if(empty($value)) {
            return null;
        }

        $value = trim($value, '"');
        $dateTime = DateTimeImmutable::createFromFormat(SyncDefaults::DATE_TIME_FORMAT, $value);
        return $dateTime ?: null;
    }

    /**
     * Groups the content items by the given type
     *
     * @param ContentListingResponse $listing
     */
    protected function createContentGroups(ContentListingResponse $listing): void
    {
        $regularContentGroups = [];
        $topContentGroups = [];

        foreach ($listing->getContentItems() as $contentItem) {
            $type = $contentItem->getType();

            if (empty($type)) {
                continue;
            }

            // top content
            if (strpos($type, self::TOP_CONTENT_PREFIX) === 0) {
                if(!isset($topContentGroups[$type])) {
                    $topContentGroups[$type] = new ContentGroup($type, $type);
                }
                $topContentGroups[$type]->addContentItem($contentItem);
            } else {
                if(!isset($regularContentGroups[$type])) {
                    $regularContentGroups[$type] = new ContentGroup($type, $type);
                }
                $regularContentGroups[$type]->addContentItem($contentItem);
            }
        }

        $listing->setContentGroups($regularContentGroups);
        $listing->setTopContentGroups($topContentGroups);
    }
}
