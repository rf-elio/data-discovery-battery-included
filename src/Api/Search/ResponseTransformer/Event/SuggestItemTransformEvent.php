<?php

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Event;

use Elio\ElioSearch\Api\Request\ApiRequest;
use Elio\ElioSearch\Api\Response\ResponseCollection;
use Elio\ElioSearch\Core\Suggest\SuggestItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Elio\ElioSearch\Swagger\ModelInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Class SuggestItemTransformEvent
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Event
 * @category  Shopware
 * @author    elio GmbH <support@elio-systems.com>
 * @author    Ralf Frommherz <rf@elio-systems.com>
 * @copyright Copyright (c) 2021, elio GmbH (https://www.elio-systems.com)
 */
class SuggestItemTransformEvent extends Event
{
    private bool $removeSuggestItemFromResult = false;

    public function __construct(
        private readonly SuggestItem $suggestItem,
        private readonly ModelInterface $model,
        private readonly ResponseCollection $responseCollection,
        private readonly ApiRequest $request,
        private readonly SalesChannelContext $context
    ) {}

    /**
     * @return SuggestItem
     */
    public function getSuggestItem(): SuggestItem
    {
        return $this->suggestItem;
    }

    /**
     * @return ModelInterface
     */
    public function getModel(): ModelInterface
    {
        return $this->model;
    }

    /**
     * @return ResponseCollection
     */
    public function getResponseCollection(): ResponseCollection
    {
        return $this->responseCollection;
    }

    /**
     * @return ApiRequest
     */
    public function getRequest(): ApiRequest
    {
        return $this->request;
    }

    /**
     * @return SalesChannelContext
     */
    public function getContext(): SalesChannelContext
    {
        return $this->context;
    }

    /**
     * @return bool
     */
    public function isRemoveSuggestItemFromResult(): bool
    {
        return $this->removeSuggestItemFromResult;
    }

    /**
     * @param bool $removeSuggestItemFromResult
     */
    public function setRemoveSuggestItemFromResult(bool $removeSuggestItemFromResult): void
    {
        $this->removeSuggestItemFromResult = $removeSuggestItemFromResult;
    }
}