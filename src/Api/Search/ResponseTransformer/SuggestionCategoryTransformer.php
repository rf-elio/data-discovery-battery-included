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
use Elio\ElioBatteryIncludedSearchExtension\Api\Search\Response\SuggestionResponse;
use Elio\ElioSearch\Api\Transform\ResponseTransformerInterface;
use Elio\ElioSearch\Core\Exception\InvalidTypeException;
use Elio\ElioSearch\Core\Suggest\SuggestGroup;
use Elio\ElioSearch\Core\Util\ArrayUtil;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\Service\AbstractCategoryUrlGenerator;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Elio\ElioSearch\Core\Suggest\SuggestItem;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Swagger\Client\Model\ModelInterface;
use Swagger\Client\Model\SuggestionResult;

/**
 * Enriches the category suggest group
 *
 * Class SuggestionCategoryTransformer
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer
 * @author Ralf Frommherz <ralf@frommherz.me>
 */
class SuggestionCategoryTransformer implements ResponseTransformerInterface
{
    public const TYPE = 'category';
    private const CATEGORY_ID_ATTRIBUTE = 'CategoryID';
    private const URL_ATTRIBUTE = 'ProductURL';

    private EntityRepository $categoryRepository;
    private AbstractCategoryUrlGenerator $categoryUrlGenerator;

    /**
     * SuggestionTransformer constructor.
     * @param EntityRepository $categoryRepository
     * @param AbstractCategoryUrlGenerator $categoryUrlGenerator
     */
    public function __construct(EntityRepository $categoryRepository, AbstractCategoryUrlGenerator $categoryUrlGenerator) {
        $this->categoryRepository = $categoryRepository;
        $this->categoryUrlGenerator = $categoryUrlGenerator;
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

        $categoryGroup = $suggestionResponse->getGroup(self::TYPE);
        $this->resolveCategoryId($categoryGroup, $context->getContext());
        $categories = $this->collect($categoryGroup, $context->getContext());
        $this->enrich($categoryGroup, $categories, $context->getSalesChannel());
    }

    /**
     * Resolves the category by the given name and parent category
     *
     * @param SuggestGroup $group
     * @param Context $context
     */
    protected function resolveCategoryId(SuggestGroup $group, Context $context) : void
    {
        // extract all category names by the given items
        $categoryNames = [];
        $itemsWithoutCategoryId = [];
        foreach ($group->getItems() as $item) {
            if($this->getCategoryId($item)) {
                continue;
            }

            $categoryPath = $item->getAttribute('parentCategory', '').'/'.$item->getName();
            $categoryPath = explode('/', ltrim($categoryPath, '/'));
            $item->setAttribute('categoryPath', $categoryPath);
            $categoryNames[] = $categoryPath;
            $itemsWithoutCategoryId[] = $item;
        }

        $categoryNames = !empty($categoryNames) ? array_merge(...$categoryNames) : [];
        $categoryNames = array_unique($categoryNames);

        if(empty($categoryNames)) {
            return;
        }

        // lookup all categories we have in the group
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('name', $categoryNames));
        $categories = $this->categoryRepository->search($criteria, $context);

        /** @var CategoryEntity[][] $categoriesByName */
        $categoriesByName = [];
        /** @var CategoryEntity $category */
        foreach ($categories as $category) {
            ArrayUtil::arrayKeyPush($categoriesByName, $category, $category->getName());
        }

        // find valid paths
        foreach ($itemsWithoutCategoryId as $item) {
            $categoryPath = $item->getAttribute('categoryPath');
            $categoryPath = array_reverse($categoryPath);
            $categoryName = array_shift($categoryPath);
            $candidates = $categoriesByName[$categoryName] ?? [];
            foreach ($candidates as $candidate) {
                if($candidate->getActive() && $this->isValidParentCategory($categoriesByName, $categoryPath, $candidate->getParentId())) {
                    $item->setAttribute(self::CATEGORY_ID_ATTRIBUTE, $candidate->getId());
                    break;
                }
            }
        }
    }

    /**
     * The given category path ['measuring sensors', 'optical sensors', 'sensors'] will be used to find a valid
     * category path. The 'measuring sensors' could exist multiple times. To find the correct category, we now check the
     * parent categories to only allow the 'measuring sensors' which has the parent category 'optical sensors'. The same
     * check is applied to 'optical sensors', here we use the category which has 'sensors' as parent.
     *
     * @param CategoryEntity[][] $categoriesByName
     * @param array $categoryPath
     * @param string|null $parentId
     * @return bool
     */
    protected function isValidParentCategory(array $categoriesByName, array $categoryPath, ?string $parentId = null): bool
    {
        $pathPart = array_shift($categoryPath);
        $endOfPath = count($categoryPath) <= 0;
        $categories = $categoriesByName[$pathPart] ?? [];

        foreach ($categories as $category) {
            if(!$endOfPath && !$this->isValidParentCategory($categoriesByName, $categoryPath, $category->getParentId())) {
                return false;
            }

            if($parentId && $category->getId() === $parentId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Collects all category entities
     * @param SuggestGroup $group
     * @param Context $context
     * @return EntityCollection<CategoryEntity>
     */
    protected function collect(SuggestGroup $group, Context $context): EntityCollection
    {
        $categoryIds = [];
        foreach ($group->getItems() as $item) {
            if($categoryId = $this->getCategoryId($item)) {
                $categoryIds[] = $categoryId;
            }
        }

        if(empty($categoryIds)) {
            return new EntityCollection();
        }

        $criteria = new Criteria($categoryIds);
        $criteria->addAssociation('media');
        /* @phpstan-ignore-next-line */
        return $this->categoryRepository->search($criteria, $context)->getEntities();
    }

    /**
     * Adds the url and the image if present
     *
     * @param SuggestGroup $group
     * @param EntityCollection $categories
     * @param SalesChannelEntity|null $salesChannel
     */
    protected function enrich(SuggestGroup $group, EntityCollection $categories, ?SalesChannelEntity $salesChannel): void
    {
        foreach ($group->getItems() as $item) {
            // add url
            $attributes = $item->getAttributes();
            if(isset($attributes[self::URL_ATTRIBUTE])) {
                $item->setUrl($attributes[self::URL_ATTRIBUTE]);
            }

            // add image
            $categoryId = $this->getCategoryId($item);
            if($categories->has($categoryId)) {
                /** @var CategoryEntity $category */
                $category = $categories->get($categoryId);

                if(!$item->hasUrl()) {
                    $url = $this->categoryUrlGenerator->generate($category, $salesChannel); // seo_url
                    $item->setUrl($url);
                }

                if (!$item->hasImage() && $category->getMedia()) {
                    $item->setImgUrl($category->getMedia()->getUrl());
                }
            }

            if(!$item->hasUrl()) {
                $group->remove($item);
            }
        }
    }

    /**
     * Extracts the product number by the given item
     *
     * @param SuggestItem $item
     * @return string|null
     */
    protected function getCategoryId(SuggestItem $item): ?string
    {
        $attributes = $item->getAttributes();
        return $attributes[self::CATEGORY_ID_ATTRIBUTE] ?? null;
    }
}
