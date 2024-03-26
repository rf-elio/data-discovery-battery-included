<?php

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;


use DateTimeImmutable;
use DateTimeInterface;
use Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Output\Util\LocaleUtil;
use Elio\ElioDataDiscovery\Api\Request\ApiRequest;
use Elio\ElioDataDiscovery\Api\Response\Response;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Api\Response\StructWrapper;
use Elio\ElioDataDiscovery\Api\Search\Request\ContentSearchRequest;
use Elio\ElioDataDiscovery\Api\Search\Response\ContentListingResponse;
use Elio\ElioDataDiscovery\Api\Transform\ResponseTransformerInterface;
use Elio\ElioDataDiscovery\Core\Content\Content\SalesChannel\ContentGroup;
use Elio\ElioDataDiscovery\Core\Content\Content\SalesChannel\ContentItem;
use Elio\ElioDataDiscovery\Core\Exception\InvalidTypeException;
use Elio\ElioDataDiscovery\Core\Sync\Defaults\ContentSyncDefaults;
use Elio\ElioDataDiscovery\Core\Sync\Defaults\SyncDefaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Elio\ElioDataDiscovery\Swagger\ModelInterface;
use Elio\ElioBatteryIncludedApiClient\Model\Result;

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
    protected const TOP_CONTENT_PREFIX = 'top-';

    public function __construct(
        private readonly EntityRepository $languageRepository
    ) {}

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

        $criteria = new Criteria([$context->getLanguageId()]);
        $criteria->addAssociation('locale');
        /** @var LanguageEntity $language */
        $language = $this->languageRepository->search($criteria, $context->getContext())->first();
        $locale = LocaleUtil::getLocaleByLanguage($language);

        $listing = $responseCollection->get(ContentListingResponse::class) ?? new ContentListingResponse();
        $responseCollection->set(ContentListingResponse::class, $listing);

        foreach ($model->getHits() as $hit) {
            if (!isset($hit['document']['_content'])) {
                continue;
            }

            $content = new ContentItem(
                $hit->getDocument()['id'],
                $hit->getDocument()['_content']->type ?? '',
                $hit->getDocument()['_content_i18n']->$locale->contentstructure ?? '',
                $hit->getDocument()['_content_i18n']->$locale->name ?? '',
                $hit->getDocument()['_content_i18n']->$locale->description ?? '',
                $hit->getDocument()['_content_i18n']->$locale->url ?? '',
                $hit->getDocument()['_content']->imageurl ?? '',
                $this->restoreDateTime($hit->getDocument()['_content']->publicationdate ?? ''),
                $hit->getDocument()['_content_i18n']->$locale->mappedFields->priority ?? ContentSyncDefaults::DEFAULT_PRIORITY,
                $hit->getDocument()['_content_i18n']->$locale->mappedFields->position ?? 0,
            );
            $content->addExtension(Response::DATA_SOURCE, new StructWrapper($hit));
            $listing->addContentItem($content);
        }

        $this->createContentGroups($listing);
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
            if (str_starts_with($type, self::TOP_CONTENT_PREFIX)) {
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
