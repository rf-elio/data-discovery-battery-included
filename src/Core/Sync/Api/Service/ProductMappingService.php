<?php declare(strict_types=1);
/**
 * Copyright (c) 2023, elio GmbH.
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

namespace Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Api\Service;

use Elio\ElioSearch\Core\Defaults;
use Elio\ElioSearch\Core\Export\Generator\ExportDefaults;
use Elio\ElioSearch\Core\Export\Generator\Product\ProductExportDefaults;
use Elio\ElioSearch\Core\Export\Generator\Util\ValueUtil;
use Elio\ElioSearch\Core\Sync\DataTypes\ProductType;
use Elio\ElioSearch\Core\Sync\Export\Converter\Exception\InvalidDataTypeException;
use Elio\ElioSearch\Core\Sync\SyncProfileEntity;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Class ProductMappingService
 * @package Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Api\Service
 * @category Shopware
 * @author elio GmbH <support@elio-systems.com>
 * @author Danil Lukov <dl@elio-systems.com>
 * @copyright Copyright (c) 2023, elio GmbH (https://www.elio-systems.com)
 */
class ProductMappingService
{
    private const PRODUCT_THUMBNAIL_SIZE = 200;

    public function __construct(private readonly EntityRepository $productRepository)
    {
    }

    /**
     * Maps data for create, update request
     *
     * @param array $collection
     * @param SyncProfileEntity $syncProfile
     * @param SalesChannelContext $context
     * @return array
     * @throws InvalidDataTypeException
     */
    public function mapData(array $collection, SyncProfileEntity $syncProfile, SalesChannelContext $context): array
    {
        $product = array_values($collection)[0] ?? null;
        if (!$product instanceof ProductType) {
            throw new InvalidDataTypeException('Unsupported type');
        }

        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $convertedData = [];
        $convertedData['_product'] = $this->prepareBaseFields($product, $context);
        $convertedData['_i8n'] = $this->prepareTranslatedFields($collection);
        $mappedData = $this->addMappedPropertiesToExportItem($product, $syncProfile->getMapping(), $propertyAccessor);
        $convertedData['_product'] = array_merge($convertedData['_product'], $mappedData);

        return $convertedData;
    }

    /**
     * Prepare base fields
     *
     * @param ProductType $product
     * @param SalesChannelContext $context
     * @return array
     */
    protected function prepareBaseFields(
        ProductType $product,
        SalesChannelContext $context
    ): array {
        $parentProduct = null;
        if($product->getParentId()) {
            /** @var ProductEntity|null  $parentProduct */
            $parentProduct = $this->productRepository->search(new Criteria([$product->getParentId()]), $context->getContext())->first();
        }

        [$price, $redPrice] = $this->getProductPrice($product) ?? [null, null];
        return [
            ProductExportDefaults::FIELD_ID => $product->getId(),
            ProductExportDefaults::FIELD_MASTER_PRODUCT_NUMBER => $parentProduct?->getProductNumber(),
            ProductExportDefaults::FIELD_PRODUCT_ID => $product->getProductNumber(),
            ProductExportDefaults::FIELD_MANUFACTURER_NUMBER => $product->getManufacturerNumber(),
            ProductExportDefaults::FIELD_PRICE => ValueUtil::formatPrice($price),
            ProductExportDefaults::FIELD_RED_PRICE => ValueUtil::formatPrice($redPrice),
            ProductExportDefaults::FIELD_CATEGORY_IDS => $this->getCategoryIds($product),
            ProductExportDefaults::FIELD_EAN => $product->getEan(),
            ProductExportDefaults::FIELD_STOCK => $product->getStock(),
            ProductExportDefaults::FIELD_CLOSEOUT => $product->getIsCloseout() ? 1 : 0,
            ProductExportDefaults::FIELD_RATING_AVERAGE => $product->getRatingAverage(),
            ProductExportDefaults::FIELD_SHIPPING_FREE => $product->getShippingFree(),
            ProductExportDefaults::FIELD_SALES_COUNT => $product->getSales(),
            ProductExportDefaults::FIELD_RELEASE_DATE => $product->getReleaseDate()
                ? $product->getReleaseDate()->format(ExportDefaults::DATE_TIME_FORMAT)
                : '',
            ProductExportDefaults::FIELD_IMAGE_URL => $product->getCover()?->getMedia()?->getUrl(),
            ProductExportDefaults::FIELD_THUMBNAIL_URL => $this->getThumbnailUrl($product->getCover()?->getMedia()?->getThumbnails()),
            ProductExportDefaults::FIELD_PRODUCT_URL => '',
        ];
    }

    /**
     * Prepare translation fields
     *
     * @param array $collection
     * @return array
     */
    protected function prepareTranslatedFields(array $collection): array
    {
        $translatedFields = [];
        // TODO: Change language id to locale
        foreach ($collection as $languageId => $product) {
            $translated = $product->getTranslated();
            $translatedFields[$languageId] = [
                ProductExportDefaults::FIELD_NAME => $product->getName() ?? $translated['name'] ?? '',
                ProductExportDefaults::FIELD_DESCRIPTION => ValueUtil::cleanValue($product->getDescription() ?? $translated['description'] ?? ''),
                ProductExportDefaults::FIELD_META_TITLE => ValueUtil::cleanValue($product->getMetaTitle() ?? $translated['metaTitle'] ?? ''),
                ProductExportDefaults::FIELD_MANUFACTURER => $product->getManufacturer()?->getTranslation('name') ?? $product->getManufacturer()?->getName(),
                ProductExportDefaults::FIELD_KEYWORDS => $product->getKeywords() ?? $translated['keywords'] ?? '',
                ProductExportDefaults::FIELD_SEARCH_KEYWORDS => implode(', ', $product->getSearchKeywords() ?? $translated['customSearchKeywords'] ?? []),
                ProductExportDefaults::FIELD_CATEGORY_PATH => $this->getCategoryPath($product),
                ProductExportDefaults::FIELD_ATTRIBUTE => $this->getProductAttribute($this->getFilterableProductProperties($product)),
                ProductExportDefaults::FIELD_ATTRIBUTE_NON_FILTERABLE => $this->getProductAttribute($this->getNonFilterableProductProperties($product)),
                ProductExportDefaults::FIELD_TAGS => $this->getProductTags($product),
            ];
        }

        return $translatedFields;
    }


    /**
     * Adds the fields that are defined in the dynamic mapping
     *
     * - supports different levels and Collection::first()
     * - examples: manufacturer.name, price.first.gross
     * - can be extended to provide more options for mapping language
     * @param ProductType $product
     * @param array $mappings
     * @param PropertyAccessorInterface $propertyAccessor
     */
    protected function addMappedPropertiesToExportItem(
        ProductType $product, array $mappings, PropertyAccessorInterface $propertyAccessor
    ): array
    {
        $mappedData = [];
        foreach ($mappings as $mapping) {
            if (str_contains($mapping['source'], '.')) {
                $parts = explode('.',$mapping['source']);
                $previousObj = $product;
                foreach ($parts as $part) {
                    if ($part === 'first') {
                        $previousObj = array_values($propertyAccessor->getValue($previousObj, 'elements'))[0];
                    } elseif (is_object($previousObj) || is_array($previousObj)) {
                        $previousObj = $propertyAccessor->getValue($previousObj, $part);
                    }
                }
                $mappedData[$mapping['target']] = $previousObj;
            } else {
                $mappedData[$mapping['target']] = $propertyAccessor->getValue($product, $mapping['source']);
            }
        }

        return $mappedData;
    }

    /**
     * Builds the category path for elio search
     *
     * @param ProductEntity $product
     * @return string
     */
    protected function getCategoryPath(ProductEntity $product): string
    {
        if(!$product->getCategories()) {
            return '';
        }

        $path = '';
        $categories = $product->getCategories()->getElements();

        $index = 0;
        $numCategories = count($categories);
        foreach ($categories as $category) {
            $path .= implode('/', array_map('rawurlencode', array_slice($category->getBreadcrumb(), 1)));
            if (++$index < $numCategories) {
                $path .= Defaults::VALUE_SEPARATOR;
            }
        }

        return $path;
    }

    /**
     * Builds the category path for elio search
     *
     * @param ProductEntity $product
     * @return string
     */
    protected function getCategoryIds(ProductEntity $product): string
    {
        if(!$product->getCategories()) {
            return '';
        }

        $productCategoryIds = [];
        $categories = $product->getCategories()->getElements();

        foreach ($categories as $category) {
            $path = $category->getPath();
            $ids = explode('|', $path);
            $ids = array_filter($ids);
            $productCategoryIds[] = implode('/', $ids);
        }

        return implode(Defaults::VALUE_SEPARATOR, $productCategoryIds);
    }

    /**
     * Appends the product attributes
     *
     * @param array<PropertyGroupOptionEntity> $properties
     * @return string
     */
    protected function getProductAttribute(array $properties): string
    {
        $resultAttribute = Defaults::VALUE_SEPARATOR;
        foreach ($properties as $property) {
            $group = $property->getGroup();
            if($group !== null) {
                $name = $group->getTranslation('name') ?? $group->getName();
                $value = $property->getTranslation('name') ?? $property->getName();
                $resultAttribute .= $name . '=' . $value . Defaults::VALUE_SEPARATOR;
            }
        }

        return ValueUtil::cleanValue($resultAttribute);
    }

    /**
     * @param ProductEntity $product
     * @return array<PropertyGroupOptionEntity>
     */
    protected function getFilterableProductProperties(ProductEntity $product): array
    {
        if ($product->getProperties() === null) {
            return [];
        }
        return $product->getProperties()->filter(
            static fn (PropertyGroupOptionEntity $option) => $option->getGroup() !== null && $option->getGroup()->getFilterable()
        )->getElements();
    }

    /**
     * @param ProductEntity $product
     * @return array<PropertyGroupOptionEntity>
     */
    protected function getNonFilterableProductProperties(ProductEntity $product): array
    {
        if ($product->getProperties() === null) {
            return [];
        }
        return $product->getProperties()->filter(
            static fn (PropertyGroupOptionEntity $option) => $option->getGroup() !== null && !$option->getGroup()->getFilterable()
        )->getElements();
    }

    /**
     * Creates the product tags string
     *
     * @param ProductEntity $product
     * @return string
     */
    protected function getProductTags(ProductEntity $product) : string
    {
        if(!$product->getTags()) {
            return '';
        }

        $tags = [];
        foreach ($product->getTags() as $tag) {
            $tags[] = $tag->getTranslation('name') ?? $tag->getName();
        }

        return implode(Defaults::VALUE_SEPARATOR, $tags);
    }

    /**
     * Fetches the main product price
     *
     * @param ProductEntity $product
     * @return array|null
     */
    protected function getProductPrice(ProductEntity $product) : ?array
    {
        if ($product->getPrice() === null || !$price = $product->getPrice()->first()) {
            return null;
        }

        $redPrice = null;
        if (
            $price->getListPrice() &&
            $price->getListPrice()->getGross() &&
            $price->getListPrice()->getGross() > $price->getGross()
        ) {
            $redPrice = $price->getListPrice()->getGross();
        }

        return [$price->getGross(), $redPrice];
    }

    /**
     * Fetches the product price string with all currencies
     *
     * @param ProductEntity $product
     * @param SalesChannelContext $context
     *
     * @return string
     */
    protected function getProductPrices(ProductEntity $product, SalesChannelContext $context) : string
    {
        [$price] = $this->getProductPrice($product) ?? [null];
        if (!$price) {
            return '';
        }

        $prices = [];
        foreach ($context->getSalesChannel()->getCurrencies() as $currency) {
            $currencyPrice = $price;
            if ($currency->getId() !== $context->getCurrency()->getId()) {
                $currencyPrice *= $currency->getFactor();
            }

            $prices[] = sprintf(
                '%s~~%s=%s',
                $currency->getIsoCode(),
                $currency->getSymbol(),
                ValueUtil::formatPrice($currencyPrice)
            );
        }

        return !empty($prices) ? sprintf(
            '%s%s%s',
            Defaults::VALUE_SEPARATOR,
            implode(Defaults::VALUE_SEPARATOR, $prices),
            Defaults::VALUE_SEPARATOR
        ) : '';
    }

    /**
     * Searches for the best matching thumbnail
     *
     * @param MediaThumbnailCollection|null $thumbnailCollection
     * @return string
     */
    protected function getThumbnailUrl(?MediaThumbnailCollection $thumbnailCollection): string
    {
        if (!$thumbnailCollection || $thumbnailCollection->count() <= 0) {
            return '';
        }

        $targetSize = self::PRODUCT_THUMBNAIL_SIZE;
        $bestMatching = null;
        $bestMatchingSizeDifference = 0;
        foreach ($thumbnailCollection as $thumbnail) {
            $targetSizeDifference = abs($targetSize - $thumbnail->getWidth());
            if (!$bestMatching || $targetSizeDifference < $bestMatchingSizeDifference) {
                $bestMatching = $thumbnail;
                $bestMatchingSizeDifference = $targetSizeDifference;
            }
        }

        return $bestMatching ? $bestMatching->getUrl() : '';
    }
}