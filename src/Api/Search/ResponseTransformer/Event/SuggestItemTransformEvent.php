<?php

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Event;

use Elio\ElioBatteryIncludedSearchExtension\Api\Request\ApiRequest;
use Elio\ElioBatteryIncludedSearchExtension\Api\Response\ResponseCollection;
use Elio\ElioBatteryIncludedSearchExtension\Core\Suggest\SuggestItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swagger\Client\Model\ModelInterface;
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
    private SuggestItem $suggestItem;
    private ModelInterface $model;
    private ResponseCollection $responseCollection;
    private ApiRequest $request;
    private SalesChannelContext $context;
    private bool $removeSuggestItemFromResult = false;

    public function __construct(
        SuggestItem $suggestItem,
        ModelInterface $model,
        ResponseCollection $responseCollection,
        ApiRequest $request,
        SalesChannelContext $context
    )
    {
        $this->suggestItem = $suggestItem;
        $this->model = $model;
        $this->responseCollection = $responseCollection;
        $this->request = $request;
        $this->context = $context;
    }

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