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

use Doctrine\DBAL\Exception;
use Elio\ElioDataDiscovery\Api\Request\ApiRequest;
use Elio\ElioDataDiscovery\Api\Response\ResponseCollection;
use Elio\ElioDataDiscovery\Api\Search\ResponseTransformer\AbstractProductTransformer;
use Elio\ElioDataDiscovery\Core\Exception\InvalidTypeException;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Elio\ElioDataDiscovery\Swagger\ModelInterface;
use Elio\ElioBatteryIncludedApiClient\Model\Result;
use Elio\ElioBatteryIncludedApiClient\Model\SearchRecord;
use Elio\ElioDataDiscovery\Core\Sync\DataTypes\ProductDataType;

/**
 * Class ProductTransformer
 *
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer
 * @category  Shopware
 * @author    elio GmbH <support@elio-systems.com>
 * @author    Ralf Frommherz <rf@elio-systems.com>
 * @copyright Copyright (c) 2021, elio GmbH (https://www.elio-systems.com)
 */
class ProductTransformer extends AbstractProductTransformer
{
    /**
     * @param ModelInterface $model
     * @param ResponseCollection $responseCollection
     * @param SalesChannelContext $context
     * @param ApiRequest $request
     * @throws InconsistentCriteriaIdsException|Exception
     */
    public function transform(
        ModelInterface      $model,
        ResponseCollection  $responseCollection,
        SalesChannelContext $context,
        ApiRequest          $request
    ): void
    {
        if (!$model instanceof Result) {
            throw new InvalidTypeException($model, Result::class);
        }

        $mainNumbers = array_map(
            static function (SearchRecord $record) {
                if ($record->getDocument()['type'] === substr(strrchr(ProductDataType::class, '\\'), 1)) {
                    return $record->getDocument()['_product']->productNumber[0];
                }

                return null;
            },
            $model->getHits()
        );
        $productsData = $this->extractMainAndVariantProducts($mainNumbers);
        // TODO: Resolve main variant

        $productNumbers = array_keys($productsData);
        $listing = $this->parentTransform($productNumbers, $mainNumbers, $responseCollection, $context);
        $listing->setHitsPerPage($model->getRequestParams()['per_page']);
    }
}
