<?php declare(strict_types=1);
/**
 * Copyright (c) 2021, elio GmbH.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its contributors
 * may be used to endorse or promote products derived from this software without
 * specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;

use Elio\ElioSearch\Api\Request\ApiRequest;
use Elio\ElioSearch\Api\Response\ResponseCollection;
use Elio\ElioSearch\Api\Transform\ResponseTransformerInterface;
use Elio\ElioSearch\Configuration\Configuration;
use Elio\ElioSearch\Configuration\ElioSearchConfigServiceInterface;
use Elio\ElioSearch\Core\Exception\InvalidTypeException;
use Elio\ElioSearch\Core\Suggest\SuggestGroup;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Seo\SeoUrlPlaceholderHandlerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Elio\ElioSearch\Core\Suggest\SuggestItem;
use Shopware\Storefront\Framework\Seo\SeoUrlRoute\ProductPageSeoUrlRoute;
use Swagger\Client\Model\ModelInterface;
use Swagger\Client\Model\SuggestionResult;

/**
 * Enriches the product suggest group
 *
 * Class SuggestionProductTransformer
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer
 * @author Ralf Frommherz <ralf@frommherz.me>
 */
class SuggestionProductTransformer implements ResponseTransformerInterface
{
    public const TYPE = 'productName';
    private const URL_ATTRIBUTE = 'ProductURL';

    private EntityRepository $productRepository;
    private ElioSearchConfigServiceInterface $configService;
    private SeoUrlPlaceholderHandlerInterface $seoUrlPlaceholderHandler;

    /**
     * SuggestionTransformer constructor.
     * @param EntityRepository $productRepository
     * @param SeoUrlPlaceholderHandlerInterface $seoUrlPlaceholderHandler
     * @param ElioSearchConfigServiceInterface $configService
     */
    public function __construct(
        EntityRepository $productRepository,
        SeoUrlPlaceholderHandlerInterface $seoUrlPlaceholderHandler,
        ElioSearchConfigServiceInterface $configService
    ) {
        $this->productRepository = $productRepository;
        $this->configService = $configService;
        $this->seoUrlPlaceholderHandler = $seoUrlPlaceholderHandler;
    }

    /**
     * @inheritDoc
     */
    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof SuggestionResult;
    }

    /**
     * Enriches the product suggest result with product urls and product images
     *
     * @param ModelInterface $model
     * @param ResponseCollection $responseCollection
     * @param SalesChannelContext $context
     * @param ApiRequest $request
     */
    public function transform(
        ModelInterface $model,
        ResponseCollection $responseCollection,
        SalesChannelContext $context,
        ApiRequest $request
    ): void {
        if (!$model instanceof SuggestionResult) {
            throw new InvalidTypeException($model, SuggestionResult::class);
        }

        /** @var SuggestionResponse|null $suggestionResponse */
        $suggestionResponse = $responseCollection->get(SuggestionResponse::class) ?? new SuggestionResponse();
        if(!$suggestionResponse || !$suggestionResponse->hasGroup(self::TYPE)) {
            return;
        }

        $config = $this->configService->getByContext($context);
        $productGroup = $suggestionResponse->getGroup(self::TYPE);
        $products = $this->collect($productGroup, $context->getContext(), $config);
        $this->enrich($productGroup, $products, $config);
    }

    /**
     * Collects all product entities
     *
     * @param SuggestGroup $group
     * @param Context $context
     * @param Configuration $config
     * @return ProductEntity[]
     */
    protected function collect(SuggestGroup $group, Context $context, Configuration $config): array
    {
        $productNumbers = [];
        foreach ($group->getItems() as $item) {
            if($productNumber = $this->getProductNumber($item, $config)) {
                $productNumbers[] = $productNumber;
            }
        }

        if(empty($productNumbers)) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addAssociation('cover');
        $criteria->addFilter(new EqualsAnyFilter('productNumber', $productNumbers));
        $products = [];

        /** @var ProductEntity $product */
        foreach ($this->productRepository->search($criteria, $context) as $product) {
            $products[$product->getProductNumber()] = $product;
        }

        return $products;
    }

    /**
     * Adds the url and the image if present
     *
     * @param SuggestGroup $group
     * @param ProductEntity[] $products
     * @param Configuration $config
     */
    protected function enrich(SuggestGroup $group, array $products, Configuration $config): void
    {
        foreach ($group->getItems() as $item) {
            // add url
            $attributes = $item->getAttributes();
            if(isset($attributes[self::URL_ATTRIBUTE])) {
                $item->setUrl($attributes[self::URL_ATTRIBUTE]);
            }

            $productNumber = $this->getProductNumber($item, $config);
            if($productNumber && isset($products[$productNumber])) {
                $product = $products[$productNumber];

                if(!$item->hasUrl()) {
                    $url = $this->seoUrlPlaceholderHandler->generate(ProductPageSeoUrlRoute::ROUTE_NAME, ['productId' => $product->getId()]); // seo_url
                    $item->setUrl($url);
                }

                // add image
                if (
                    !$item->hasImage() &&
                    $product->getCover() &&
                    $product->getCover()->getMedia()
                ) {
                    $item->setImgUrl($product->getCover()->getMedia()->getUrl());
                }
            }
        }
    }

    /**
     * Extracts the product number by the given item
     *
     * @param SuggestItem $item
     * @param Configuration $config
     * @return string|null
     */
    protected function getProductNumber(SuggestItem $item, Configuration $config): ?string
    {
        $attributes = $item->getAttributes();
        return $attributes[$config->getSuggestProductNumberAttribute()] ?? null;
    }
}
