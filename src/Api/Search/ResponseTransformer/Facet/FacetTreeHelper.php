<?php declare(strict_types=1);
/**
 * Copyright (c) 2022, elio GmbH.
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

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Facet;

use Elio\ElioBatteryIncludedSearchExtension\Core\Framework\DataAbstractionLayer\Search\AggregationResult\DefaultFacetExtension;
use Elio\ElioBatteryIncludedSearchExtension\Core\Util\Tree\Node;
use Elio\ElioBatteryIncludedSearchExtension\Core\Util\Tree\RandomAddTree;
use Shopware\Core\Content\Category\CategoryEntity;
use Shopware\Core\Content\Category\Tree\TreeItem;
use Shopware\Core\Framework\Struct\Collection;
use Shopware\Core\Framework\Struct\StructCollection;
use Shopware\Core\Framework\Uuid\Uuid;
use Elio\ElioBatteryIncludedApiClient\Model\Facet;
use Elio\ElioBatteryIncludedApiClient\Model\FacetElement;

/**
 * Class FacetTreeHelper
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer\Facet
 * @category  Shopware
 * @author    elio GmbH <support@elio-systems.com>
 * @author    Ralf Frommherz <rf@elio-systems.com>
 * @copyright Copyright (c) 2022, elio GmbH (https://www.elio-systems.com)
 */
class FacetTreeHelper
{
    /**
     * Converts the facet elements to a tree. Facet elements will just contain a simple path (root/products/someCat,
     * root/products/otherCat) that must be split up at the char "/" to build a virtual tree for them.
     *
     * root
     * - products
     *    - someCat
     *    - otherCat
     * @param Facet $facet
     * @return Node[]
     */
    public static function transformTreeFacet(Facet $facet): array
    {
        $randomAddTree = new RandomAddTree();
        foreach (array_merge($facet->getSelectedElements(), $facet->getElements()) as $element) {
            $searchParams = $element->getSearchParams();

            $searchParamsFilters = $searchParams->getFilters() ?? [];
            $parents = [];
            foreach ($searchParamsFilters as $searchParamsFilter) {
                if ($searchParamsFilter->getName() === $facet->getAssociatedFieldName()) {
                    $searchParamsFilterValues = $searchParamsFilter->getValues() ?? [];
                    foreach ($searchParamsFilterValues as $searchParamsFilterValue) {
                        $parents = $searchParamsFilterValue->getValue();
                    }
                }
            }

            $label = $element->getText();
            $lastParent = end($parents);
            if ($lastParent === $label) {
                array_pop($parents);
            }

            $id = $parents;
            $id[] = $label;
            $parents = implode('/', $parents);
            $id = implode('/', $id);

            $randomAddTree->add($id, $parents, [$label, $label, $element]);
        }

        return $randomAddTree->create();
    }

    /**
     * Converts the random add tree nodes to shopware tree items that can be used in the template to render the tree.
     *
     * @param Facet $facet
     * @param Node[] $nodes
     * @return TreeItem[]
     */
    public static function convertNodesToTreeItems(Facet $facet, array $nodes): array
    {
        $treeItems = [];
        foreach ($nodes as $node) {
            /** @var FacetElement $element */
            [$label, $value, $element] = $node->getValue();

            $treeItemCategory = new CategoryEntity();
            $treeItemCategory->setId(Uuid::randomHex());
            $treeItemCategory->setName($label);
            $treeItemCategory->setTranslated(['name' => $label]);

            $treeItem = new TreeItem($treeItemCategory, self::convertNodesToTreeItems($facet, $node->getChildNodes()));
            $treeItem->addExtension(DefaultFacetExtension::KEY, new DefaultFacetExtension(
                $facet->getAssociatedFieldName(),
                $value,
                $element->getTotalHits(),
                $element->getSelected() === 'TRUE'
            ));

            $treeItems[] = $treeItem;
        }

        return $treeItems;
    }

    /**
     * Calculates the tree hits for each level.
     *
     * @param TreeItem[] $treeItems
     */
    public static function calculateTreeHits(array $treeItems): int
    {
        $levelHits = 0;
        foreach ($treeItems as $treeItem) {
            $children = $treeItem->getChildren();
            $childrenHits = self::calculateTreeHits($children);

            if (!$treeItem->hasExtension(DefaultFacetExtension::KEY)) {
                continue;
            }

            /** @var DefaultFacetExtension $ffExtension */
            $ffExtension = $treeItem->getExtension(DefaultFacetExtension::KEY);

            if (!empty($children)) {
                $ffExtension->setTotalHits($childrenHits);
            }

            $levelHits += $ffExtension->getTotalHits();
        }

        return $levelHits;
    }

    /**
     * @param TreeItem[] $treeItems
     * @param StructCollection|null $flattTree
     * @return Collection
     */
    public static function flattenTree(array $treeItems, ?StructCollection $flattTree = null) : Collection
    {
        $flattTree ??= new StructCollection();
        foreach ($treeItems as $treeItem) {
            $flattTree->add($treeItem);
            self::flattenTree($treeItem->getChildren(), $flattTree);
        }

        return $flattTree;
    }
}
