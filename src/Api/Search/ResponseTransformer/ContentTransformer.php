<?php

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;


use DateTimeImmutable;
use DateTimeInterface;
use Elio\ElioBatteryIncludedSearchExtension\Api\Service\LocaleService;
use Elio\ElioDataDiscovery\Api\Request\ApiRequest;
use Elio\ElioDataDiscovery\Api\Response\Response;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Api\Response\StructWrapper;
use Elio\ElioDataDiscovery\Api\Search\Request\ContentSearchRequest;
use Elio\ElioDataDiscovery\Api\Search\Response\ContentListingResponse;
use Elio\ElioDataDiscovery\Api\Transform\AbstractContentTransformer;
use Elio\ElioDataDiscovery\Core\Content\Content\SalesChannel\ContentItem;
use Elio\ElioDataDiscovery\Core\Exception\InvalidTypeException;
use Elio\ElioDataDiscovery\Core\Sync\Defaults\ContentSyncDefaults;
use Elio\ElioDataDiscovery\Core\Sync\Defaults\SyncDefaults;
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
class ContentTransformer extends AbstractContentTransformer
{
    public function __construct(
        private readonly LocaleService $localeService
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

        $locale = $this->localeService->getLocaleByContext($context);

        $listing = $responseCollection->get(ContentListingResponse::class) ?? new ContentListingResponse();
        $responseCollection->set(ContentListingResponse::class, $listing);

        foreach ($model->getHits() as $hit) {
            if (!isset($hit['document']['_content'])) {
                continue;
            }

            $content = new ContentItem(
                $hit->getDocument()['id'],
                $hit->getDocument()['_content']->contentType ?? '',
                $hit->getDocument()['_content_i18n']->$locale->contentStructure ?? '',
                $hit->getDocument()['_content_i18n']->$locale->name ?? '',
                $hit->getDocument()['_content_i18n']->$locale->description ?? '',
                $hit->getDocument()['_content_i18n']->$locale->url ?? '',
                $hit->getDocument()['_content']->imageUrl ?? '',
                $this->restoreDateTime($hit->getDocument()['_content']->publicationDate ?? ''),
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
}
