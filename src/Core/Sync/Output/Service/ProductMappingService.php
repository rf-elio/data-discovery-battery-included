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

namespace Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Output\Service;

use Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Output\Util\CategoryPathUtil;
use Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Output\Util\LocaleUtil;
use Elio\ElioSearch\Core\Defaults;
use Elio\ElioSearch\Core\Sync\DataTypes\DataTypeInterface;
use Elio\ElioSearch\Core\Sync\DataTypes\ProductDataType;
use Elio\ElioSearch\Core\Sync\Defaults\SyncDefaults;
use Elio\ElioSearch\Core\Sync\Output\SeoRoute;
use Elio\ElioSearch\Core\Sync\Sorting\ProductSortingCollection;
use Elio\ElioSearch\Core\Sync\Sorting\ProductSortingEntity;
use Elio\ElioSearch\Core\Sync\SyncContext;
use Elio\ElioSearch\Core\Sync\Util\ValueUtil;
use Shopware\Core\Content\Media\Aggregate\MediaThumbnail\MediaThumbnailCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Class ProductMappingService
 * @package Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Output\Service
 * @category Shopware
 * @author elio GmbH <support@elio-systems.com>
 * @author Danil Lukov <dl@elio-systems.com>
 * @copyright Copyright (c) 2023, elio GmbH (https://www.elio-systems.com)
 */
class ProductMappingService
{
    private const PRODUCT_THUMBNAIL_SIZE = 200;

    /**
     * Maps data for create, update request
     *
     * @param ProductDataType $product
     * @param SyncContext $syncContext
     * @return array
     */
    public function mapData(ProductDataType $product, SyncContext $syncContext): array
    {
        $propertyAccessor = PropertyAccess::createPropertyAccessor();
        $convertedData = [];
        $convertedData['id'] = $product->getIdentifier();
        $convertedData['_product'] = $this->prepareBaseFields($product);
        $convertedData['_history'] = $this->prepareHistoryFields($product);
        $convertedData['_i8n'] = $this->prepareTranslatedFields($product->getDataTypeTranslations(), $syncContext);
        $mappedData = $this->addMappedPropertiesToExportItem($product, $syncContext->getSyncProfile()->getMapping(), $propertyAccessor);
        $convertedData['_product'] = array_merge($convertedData['_product'], $mappedData);
        return $convertedData;
    }

    /**
     * Prepare base fields
     *
     * @param ProductDataType $product
     * @return array
     */
    protected function prepareBaseFields(
        ProductDataType $product
    ): array
    {
        [$price, $redPrice] = $this->getProductPrice($product) ?? [null, null];
        return [
            'masterProductNumber' => $product->getVariant()?->getParentProduct()?->getIdentifier() ?? $product->getIdentifier(),
            'productNumber' => [$product->getProductNumber()],
            'manufacturerNumber' => $product->getManufacturerNumber(),
            'price' => (float)ValueUtil::formatPrice($price),
            'redPrice' => (float)ValueUtil::formatPrice($redPrice),
            'categoryIds' => $product->getCategoryIds(),
            'ean' => $product->getEan(),
            'stock' => $product->getStock(),
            'closeout' => $product->getIsCloseout() ? 1 : 0,
            'shippingFree' => $product->getShippingFree(),
            'releaseDate' => $product->getReleaseDate()
                ? $product->getReleaseDate()->format(SyncDefaults::DATE_TIME_FORMAT)
                : '',
            'imageUrl' => $product->getCover()?->getMedia()?->getUrl(),
            'thumbnailUrl' => $this->getThumbnailUrl($product->getCover()?->getMedia()?->getThumbnails()),
            'variant' => [
                'position' => $product->getVariant()->getPosition(),
                'displayByDefaultInListing' => $product->getVariant()->isDisplayByDefaultInListing(),
                'displayByDefaultInSearch' => $product->getVariant()->isDisplayByDefaultInSearch(),
            ],
            'id' => $product->getId(),
        ];
    }

    /**
     * Prepare translation fields
     *
     * @param DataTypeInterface[] $collection
     * @param SyncContext $syncContext
     * @return array
     */
    protected function prepareTranslatedFields(
        array $collection,
        SyncContext $syncContext
    ): array
    {
        $translatedFields = [];
        /**
         * @var string $languageId
         * @var ProductDataType $product
         **/
        foreach ($collection as $languageId => $product) {
            $locale = LocaleUtil::getLocaleByLanguage($syncContext->getSalesChannelContexts()->getLanguage($languageId));
            $translated = $product->getTranslated();
            $translatedFields[$locale] = [
                'name' => $product->getName() ?? $translated['name'] ?? '',
                'description' => ValueUtil::cleanValue($product->getDescription() ?? $translated['description'] ?? ''),
                'metaTitle' => ValueUtil::cleanValue($product->getMetaTitle() ?? $translated['metaTitle'] ?? ''),
                'manufacturer' => $product->getManufacturer()?->getTranslation('name') ?? $product->getManufacturer()?->getName(),
                'keywords' => $product->getKeywords() ?? $translated['keywords'] ?? '',
                'searchKeywords' => $product->getSearchKeywords() ?? $translated['customSearchKeywords'] ?? [],
                'categories' => $this->getCategoryPath($product),
                'categorySort' => $this->getCategorySort($product),
                'attributes' => $this->getProductAttribute($this->getFilterableProductProperties($product)),
                'attributesNotFilterable' => $this->getProductAttribute($this->getNonFilterableProductProperties($product)),
                'tags' => $this->getProductTags($product),
                'url' => $product->getExtension(SeoRoute::class)?->getUrl() ?? '',
                'variant' => [
                    'options' => $this->getProductOptions($product->getOptions()),
                ],
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
     * @param ProductDataType $product
     * @param array $mappings
     * @param PropertyAccessorInterface $propertyAccessor
     * @return array
     */
    protected function addMappedPropertiesToExportItem(
        ProductDataType $product, array $mappings, PropertyAccessorInterface $propertyAccessor
    ): array
    {
        $mappedData = [];
        foreach ($mappings as $mapping) {
            if (str_contains($mapping['source'], '.')) {
                $parts = explode('.', $mapping['source']);
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
     * @return array
     */
    protected function getCategoryPath(ProductEntity $product): array
    {
        $path = [];
        $categories = $product->getCategories();
        foreach ($categories as $category) {
            $parentBreadCrumb = '';
            $firstSkipped = false;
            foreach ($category->getBreadcrumb() as $breadcrumb) {
                // first one is home, we don't want to have home
                if (!$firstSkipped) {
                    $firstSkipped = true;
                    continue;
                }

                $path[] = $parentBreadCrumb . $breadcrumb;
                $parentBreadCrumb .= $breadcrumb . CategoryPathUtil::CATEGORY_PATH_SEPARATOR;
            }

        }

        return $path;
    }

    /**
     * Builds the category sort for elio search
     *
     * @param ProductDataType $product
     * @return array
     */
    protected function getCategorySort(ProductDataType $product): array
    {
        $sort = [];
        $categories = $product->getCategories();
        /** @var ProductSortingCollection $productSortingCollection */
        $productSortingCollection = $product->getExtension('elioSearchProductSorting');
        foreach ($categories as $category) {
            $parentBreadCrumb = '';
            $firstSkipped = false;

            foreach ($category->getPlainBreadcrumb() as $categoryId => $breadcrumb) {
                // first one is home, we don't want to have home
                if (!$firstSkipped) {
                    $firstSkipped = true;
                    continue;
                }

                /** @var ProductSortingEntity $productSorting */
                if (!$productSorting = $productSortingCollection->filterByProperty('categoryId', $categoryId)->first()) {
                    continue;
                }

                $sort[] = $parentBreadCrumb . $breadcrumb . ': ' . $productSorting->getPosition();
                $parentBreadCrumb .= $breadcrumb . CategoryPathUtil::CATEGORY_PATH_SEPARATOR;
            }
        }

        return $sort;
    }

    /**
     * Builds the category path for elio search
     *
     * @param ProductEntity $product
     * @return string
     */
    protected function getCategoryIds(ProductEntity $product): string
    {
        if (!$product->getCategories()) {
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
     * @return array
     */
    protected function getProductOptions(?PropertyGroupOptionCollection $groupOptionCollection): array
    {
        if (!$groupOptionCollection) {
            return [];
        }

        $attributes = [];
        foreach ($groupOptionCollection as $groupOption) {
            $group = $groupOption->getGroup();
            $name = $group->getTranslation('name') ?? $group->getName();
            $attributes[$name] = ValueUtil::cleanValue($groupOption->getTranslation('name') ?? $group->getName());
        }

        return $attributes;
    }

    /**
     * Appends the product attributes
     *
     * @param array<PropertyGroupOptionEntity> $properties
     * @return array
     */
    protected function getProductAttribute(array $properties): array
    {
        $attributes = [];
        foreach ($properties as $property) {
            $group = $property->getGroup();
            if ($group !== null) {
                $name = $group->getTranslation('name') ?? $group->getName();
                $value = $property->getTranslation('name') ?? $property->getName();
                $attributes[$name] = ValueUtil::cleanValue($value);
            }
        }

        return $attributes;
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
            static fn(PropertyGroupOptionEntity $option) => $option->getGroup() !== null && $option->getGroup()->getFilterable()
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
            static fn(PropertyGroupOptionEntity $option) => $option->getGroup() !== null && !$option->getGroup()->getFilterable()
        )->getElements();
    }

    /**
     * Creates the product tags string
     *
     * @param ProductEntity $product
     * @return array
     */
    protected function getProductTags(ProductEntity $product): array
    {
        if (!$product->getTags()) {
            return [];
        }

        $tags = [];
        foreach ($product->getTags() as $tag) {
            $tags[] = $tag->getTranslation('name') ?? $tag->getName();
        }

        return $tags;
    }

    /**
     * Fetches the main product price
     *
     * @param ProductEntity $product
     * @return array|null
     */
    protected function getProductPrice(ProductEntity $product): ?array
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
    protected function getProductPrices(ProductEntity $product, SalesChannelContext $context): string
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

    private function prepareHistoryFields(ProductDataType $product): array
    {
        return [
            'ratingAverage' => $product->getRatingAverage(),
            'salesCount' => $product->getSales(),
        ];
    }
}