<?php
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

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer;


use Elio\ElioSearch\Api\Request\ApiRequest;
use Elio\ElioSearch\Api\Response\ResponseCollection;
use Elio\ElioSearch\Api\Search\Request\NavigationRequestProduct;
use Elio\ElioSearch\Api\Search\Request\ProductSearchRequest;
use Elio\ElioSearch\Api\Search\Response\ProductListingResponse;
use Elio\ElioSearch\Api\Search\Response\TrackingResponse;
use Elio\ElioSearch\Api\Transform\ResponseTransformerInterface;
use Elio\ElioSearch\Core\Exception\InvalidTypeException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swagger\Client\Model\ModelInterface;
use Swagger\Client\Model\Result;

/**
 * Class TrackingTransformer
 * @package Elio\ElioBatteryIncludedSearchExtension\Api\Search\ResponseTransformer
 * @category  Shopware
 * @author    elio GmbH <support@elio-systems.com>
 * @author    Ralf Frommherz <rf@elio-systems.com>
 * @copyright Copyright (c) 2022, elio GmbH (https://www.elio-systems.com)
 */
class TrackingTransformer implements ResponseTransformerInterface
{
    public function supports(ModelInterface $model, ApiRequest $request, SalesChannelContext $context): bool
    {
        return $model instanceof Result && $request instanceof ProductSearchRequest;
    }

    /**
     * Adds the information about the tracking parameters to the response
     *
     * @param ModelInterface $model
     * @param ResponseCollection $responseCollection
     * @param SalesChannelContext $context
     * @param ApiRequest $request
     * @return void
     */
    public function transform(ModelInterface $model, ResponseCollection $responseCollection, SalesChannelContext $context, ApiRequest $request): void
    {
        if(!$model instanceof Result) {
            throw new InvalidTypeException($model, Result::class);
        }

        $trackingResponse = $responseCollection->get(TrackingResponse::class) ?? new TrackingResponse();
        $responseCollection->set(TrackingResponse::class, $trackingResponse);

        if ($request instanceof NavigationRequestProduct) {
            $trackingResponse->setQuery(implode('/', $request->getCategoryPath()));
        } elseif ($request instanceof ProductSearchRequest) {
            $trackingResponse->setQuery($request->getQuery());
        }

        $listing = $responseCollection->get(ProductListingResponse::class) ?? new ProductListingResponse();
        $trackingResponse->setPageSize($listing->getHitsPerPage());
        $trackingResponse->setPageNo($listing->getCurrentPage());
    }
}
