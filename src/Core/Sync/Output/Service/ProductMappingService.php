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
use Elio\ElioDataDiscovery\Core\Defaults;
use Elio\ElioDataDiscovery\Core\Sync\DataTypes\ProductDataType;
use Elio\ElioDataDiscovery\Core\Sync\Defaults\SyncDefaults;
use Elio\ElioDataDiscovery\Core\Sync\Output\SeoRoute;
use Elio\ElioDataDiscovery\Core\Sorting\ProductSortingCollection;
use Elio\ElioDataDiscovery\Core\Sync\SyncContext;
use Elio\ElioDataDiscovery\Core\Sync\Util\ProductUtil;
use Elio\ElioDataDiscovery\Core\Sync\Util\MappingUtil;
use Elio\ElioDataDiscovery\Core\Sync\Util\ValueUtil;
use Elio\ElioDataDiscovery\Core\Util\StripClassPathUtil;
use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionCollection;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionEntity;
use Shopware\Core\System\Currency\CurrencyCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\PropertyAccess\PropertyAccess;

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
    /**
     * Maps data for create, update request
     *
     * @param ProductDataType $product
     * @param SyncContext $syncContext
     * @return array
     */
    public function mapData(ProductDataType $product, SyncContext $syncContext): array
    {
        $convertedData = [];
        $convertedData['id'] = $product->getIdentifier();
        $convertedData['_history'] = $this->prepareHistoryFields($product);
        $convertedData['_product'] = $this->prepareBaseFields($product);
        $convertedData['_product_i18n'] = $this->prepareTranslatedFields($product, $syncContext);
        $convertedData['_common'] = $this->prepareCommonFields($product);
        $convertedData['_common_i18n'] = $this->prepareTranslatedCommonFields($product->getDataTypeTranslations(), $syncContext);
        $convertedData['type'] = StripClassPathUtil::stripClassPath(get_class($product));

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
    ): array {
        [$price, $redPrice] = ProductUtil::getProductPrice($product) ?? [null, null];

        return [
            'masterProductNumber' => $product->getVariant()->getParentProduct()?->getIdentifier() ?? $product->getIdentifier(),
            'productNumber' => [$product->getProductNumber()],
            'manufacturerNumber' => $product->getManufacturerNumber(),
            'price' => (float)ValueUtil::formatPrice($price),
            'redPrice' => (float)ValueUtil::formatPrice($redPrice),
            'categoryIds' => $product->getCategoryIds(),
            'ean' => $product->getEan(),
            'stock' => $product->getStock(),
            'closeout' => $product->getIsCloseout() ? 1 : 0,
            'shippingFree' => $product->getShippingFree(),
            'id' => $product->getId(),
            'streamIds' => $product->getStreamIds() ?? [],
            'visibility' => $product->getVisibility()
        ];
    }

    /**
     * @param ProductDataType $product
     * @return array
     */
    protected function prepareCommonFields(ProductDataType $product): array
    {
        return [
            'releaseDate' => $product->getReleaseDate()
                ? $product->getReleaseDate()->format(SyncDefaults::DATE_TIME_FORMAT)
                : '',
            'imageUrl' => $product->getCover()?->getMedia()?->getUrl(),
            'thumbnailUrl' => $product->getThumbnailUrl(),
            'grouping' => [
                'groupingKey' => $product->getVariant()->getGroupingKey(),
                'position' => $product->getVariant()->getPosition(),
                'displayByDefault' => $product->getVariant()->isDisplayByDefault()
            ],
        ];
    }

    /**
     * @param array $collection
     * @param SyncContext $syncContext
     * @return array
     */
    protected function prepareTranslatedCommonFields(array $collection, SyncContext $syncContext): array
    {
        $contexts = $syncContext->getSalesChannelContexts();
        $translatedFields = [];
        foreach ($collection as $languageId => $productTranslation) {
            $locale = LocaleUtil::getLocaleByLanguage($contexts->getLanguage($languageId));
            /** @var SeoRoute|null $seoRoute */
            $seoRoute = $productTranslation->getExtension(SeoRoute::class);
            $translatedFields[$locale] = [
                'url' => $seoRoute?->getUrl() ?? ''
            ];
        }

        return $translatedFields;
    }

    /**
     * Prepare translation fields
     *
     * @param ProductDataType $product
     * @param SyncContext $syncContext
     * @return array
     */
    protected function prepareTranslatedFields(
        ProductDataType $product,
        SyncContext $syncContext
    ): array
    {
        $collection = $product->getDataTypeTranslations();
        $translatedFields = [];

        /**
         * @var string $languageId
         * @var ProductDataType $productTranslation
         **/
        foreach ($collection as $languageId => $productTranslation) {
            $locale = LocaleUtil::getLocaleByLanguage($syncContext->getSalesChannelContexts()->getLanguage($languageId));
            $translated = $productTranslation->getTranslated();

            $translatedFields[$locale] = [
                'name' => $productTranslation->getName() ?? $translated['name'] ?? '',
                'description' => ValueUtil::cleanValue($productTranslation->getDescription() ?? $translated['description'] ?? ''),
                'metaTitle' => ValueUtil::cleanValue($productTranslation->getMetaTitle() ?? $translated['metaTitle'] ?? ''),
                'manufacturer' => $productTranslation->getManufacturer()?->getTranslation('name') ?? $productTranslation->getManufacturer()?->getName(),
                'keywords' => $productTranslation->getKeywords() ?? $translated['keywords'] ?? '',
                'searchKeywords' => $productTranslation->getSearchKeywords() ?? $translated['customSearchKeywords'] ?? [],
                'categories' => $this->getCategoryPath($productTranslation),
                'categorySort' => $this->getCategorySort($productTranslation),
                'attributes' => ProductUtil::getProductAttribute(ProductUtil::getFilterableProductProperties($productTranslation)),
                'attributesNotFilterable' => ProductUtil::getProductAttribute(ProductUtil::getNonFilterableProductProperties($productTranslation)),
                'tags' => ProductUtil::getProductTags($productTranslation),
                'variant' => [
                    'options' => $this->getProductOptions($productTranslation->getOptions()),
                ],
                'mappedFields' => MappingUtil::addMappedProperties($product, $syncContext->getSyncProfile()->getMapping(), PropertyAccess::createPropertyAccessor()),
            ];
        }

        return $translatedFields;
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
        $categories = $product->getCategories() ?? new CategoryCollection();
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
        $categories = $product->getCategories() ?? new CategoryCollection();
        /** @var ProductSortingCollection $productSortingCollection */
        $productSortingCollection = $product->getExtension('elioDataDiscoveryProductSortingTree');

        foreach ($categories as $category) {
            $firstSkipped = false;

            foreach ($category->getPlainBreadcrumb() as $categoryId => $breadcrumb) {
                // first one is home, we don't want to have home
                if (!$firstSkipped) {
                    $firstSkipped = true;
                    continue;
                }

                if (!$productSorting = $productSortingCollection->filterByProperty('categoryId', $categoryId)->first()) {
                    continue;
                }

                $sort[$categoryId] = $productSorting->getPosition();
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
            $ids = explode('|', (string) $path);
            $ids = array_filter($ids);
            $productCategoryIds[] = implode('/', $ids);
        }

        return implode(Defaults::VALUE_SEPARATOR, $productCategoryIds);
    }

    /**
     * Appends the product attributes
     *
     * @param PropertyGroupOptionCollection|null $groupOptionCollection
     * @return array
     */
    protected function getProductOptions(?PropertyGroupOptionCollection $groupOptionCollection): array
    {
        if (!$groupOptionCollection) {
            return [];
        }

        $attributes = [];
        foreach ($groupOptionCollection as $groupOption) {
            if (!$groupOption instanceof PropertyGroupOptionEntity) {
                continue;
            }
            $group = $groupOption->getGroup();
            if (!$group) {
                continue;
            }
            $name = $group->getTranslation('name') ?? $group->getName();
            $attributes[$name] = ValueUtil::cleanValue($groupOption->getTranslation('name') ?? $group->getName());
        }

        return $attributes;
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
        [$price] = ProductUtil::getProductPrice($product) ?? [null];
        if (!$price) {
            return '';
        }

        $prices = [];
        $currencies = $context->getSalesChannel()->getCurrencies() ?? new CurrencyCollection();
        foreach ($currencies as $currency) {
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

    private function prepareHistoryFields(ProductDataType $product): array
    {
        return [
            'ratingAverage' => $product->getRatingAverage(),
            'salesCount' => $product->getSales(),
        ];
    }
}
