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

namespace Elio\ElioBatteryIncludedSearchExtension\Core\Export\Generator\BatteryIncluded;

use Elio\ElioSearch\Core\Export\ExportEntity;
use Elio\ElioSearch\Core\Export\ExportItem;
use Elio\ElioSearch\Core\Export\Generator\ExportDefaults;
use Elio\ElioSearch\Core\Export\Generator\ExportGeneratorInterface;
use Elio\ElioSearch\Core\Export\Generator\Product\Event\FilterProductExportItemPrepareEvent;
use Elio\ElioSearch\Core\Export\Generator\Util\ValueUtil;
use Elio\ElioSearch\Core\Export\OutputStream;
use Elio\ElioSearch\Core\Export\SeoRoute;
use Elio\ElioSearch\Core\Features\FeatureServiceInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\Common\RepositoryIterator;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Storefront\Framework\Seo\SeoUrlRoute\ProductPageSeoUrlRoute;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Class ProductExportGenerator
 * @package Elio\ElioBatteryIncludedSearchExtension\Core\Export\Generator\BatteryIncluded
 * @category Shopware
 * @author elio GmbH <support@elio-systems.com>
 * @author Danil Lukov <dl@elio-systems.com>
 * @copyright Copyright (c) 2023, elio GmbH (https://www.elio-systems.com)
 */
class ProductExportGenerator implements ExportGeneratorInterface
{
    private const PRODUCT_CHUNK_SIZE = 500;
    public function __construct(
        private EntityRepository $productRepository,
        private EventDispatcherInterface $eventDispatcher,
        private EntityRepository $salesChannelRepository,
        private FeatureServiceInterface $featureService
    ){
    }

    public function supports(ExportEntity $export): bool
    {
//        return $export->getType() === ProductExportDefaults::TYPE;
        return true;
    }

    public function getModel(ExportEntity $export): array
    {
        $model = [
            ProductExportDefaults::FIELD_CONTAINER => [
                ProductExportDefaults::FIELD_PRODUCT_NUMBER,
                ProductExportDefaults::FIELD_IMAGES,
                ProductExportDefaults::FIELD_PRICE,
                ProductExportDefaults::FIELD_URL,
            ],
            ProductExportDefaults::FIELD_ID
        ];

        // TODO: Add _i8n and event

        return $model;
    }

    public function generate(ExportEntity $export, OutputStream $output, SalesChannelContext $context): void
    {
        // add currencies for price calculation
        $criteria = new Criteria([$context->getSalesChannelId()]);
        $criteria->addAssociation('currencies');

        /** @var SalesChannelEntity|null $salesChannel */
        $salesChannel = $this->salesChannelRepository->search($criteria, $context->getContext())->first();
        if($salesChannel) {
            $context->getSalesChannel()->setCurrencies($salesChannel->getCurrencies());
        }

        // fetch products
        $criteria = new Criteria();
        $criteria->addAssociation('manufacturer.media');
        $criteria->addAssociation('visibilities');
        $criteria->addAssociation('media');
        $criteria->addAssociation('cover');
        $criteria->addAssociation('properties.group');
        $criteria->addAssociation('categories');
        $criteria->addAssociation('tags');
        $criteria->addAssociation('translations');
        $criteria->addFilter(new EqualsFilter('product.active', true));
        $criteria->addFilter(new EqualsFilter('product.visibilities.salesChannelId', $export->getSalesChannelId()));
        $criteria->setLimit(self::PRODUCT_CHUNK_SIZE);

        $iterator = new RepositoryIterator($this->productRepository, $context->getContext(), $criteria);
        while ($products = $iterator->fetch()) {
            // TODO: Fetch products from compare table
            /** @var ProductEntity $product */
            foreach ($products as $product) {
                if ($product->getProductNumber() === 'SWDEMO10005.4') {
                    dd($product->getTranslations());
                }

                // TODO: Compare hashes if they are similar continue
                $item = new ExportItem();
                $this->prepareExportItem(
                    $product,
                    $item,
                    $context
                );
                // TODO: Add mapped properties and event

                $output->write($item);
            }
        }
    }

    protected function prepareExportItem(ProductEntity $product, ExportItem $item, SalesChannelContext $context): void
    {
        $item->set(ProductExportDefaults::FIELD_ID, $product->getId());
        [$price, $redPrice] = $this->getProductPrice($product) ?? [null, null];
        $item->set(ProductExportDefaults::FIELD_CONTAINER, [
            ProductExportDefaults::FIELD_PRODUCT_NUMBER => [$product->getProductNumber()],
            ProductExportDefaults::FIELD_IMAGES => [],
            ProductExportDefaults::FIELD_URL => '', // TODO: Add field url
            ProductExportDefaults::FIELD_PRICE => $price,
        ]);

        $parentProduct = null;
        if($product->getParentId()) {
            /** @var ProductEntity|null  $parentProduct */
            $parentProduct = $this->productRepository->search(new Criteria([$product->getParentId()]), $context->getContext())->first();
        }

        // TODO: Mapping for language name
        $item->set('_i8n', [
            'de' => [
                'attributes' => [
                    'brand' => $product->getManufacturer()?->getName() ?? ''
                ],
                'categories' => $this->getProductCategories($product),
                'description' => $product->getDescription() ?? $parentProduct?->getDescription(),
                'name' => $product->getName() ?? $parentProduct?->getName(),
                'url' => ''
            ]
        ]);
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

    private function getProductCategories(ProductEntity $product): array
    {
        $categories = $product->getCategories();
        if (!$categories) {
            return [];
        }

        return array_values($categories->map(function (CategoryEntity $category) {
            return $category->getName();
        }));
    }
}