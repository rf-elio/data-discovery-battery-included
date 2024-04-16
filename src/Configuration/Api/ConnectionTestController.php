<?php
/**
 * Copyright (c) 2024, elio GmbH.
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

namespace Elio\ElioBatteryIncludedSearchExtension\Configuration\Api;

use Elio\ElioBatteryIncludedSearchExtension\Configuration\BatteryIncludedConfiguration;
use Elio\ElioDataDiscovery\Configuration\ElioDataDiscoveryConfigServiceInterface;
use Elio\ElioDataDiscovery\Core\Sync\Output\Exception\OutputException;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use GuzzleHttp\Client;

/**
 * Class ConnectionTestController
 *
 * @category Shopware
 * @author Andrei Baev <anb@elio-systems.com>
 * @author elio GmbH <support@elio-systems.com>
 * @copyright Copyright (c) 2024, elio GmbH (https://www.elio-systems.com)
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ConnectionTestController extends AbstractController
{
    public function __construct(
        private readonly Client                                  $client,
        private readonly ElioDataDiscoveryConfigServiceInterface $configService,
        private readonly EntityRepository                        $salesChannelRepository
    )
    {
    }

    #[Route(path: '/api/_action/elio-battery-included/api-connection-test', name: 'api.custom.elio_battery_included.api-connection-test', methods: ['GET'])]
    public function categoryExclusion(Context $context): Response
    {
        $salesChannels = $this->salesChannelRepository->search(new Criteria(), $context);
        /** @var SalesChannelEntity $salesChannel */
        foreach ($salesChannels as $salesChannel) {
            $salesChannelsId = $salesChannel->getId();

            /** @var BatteryIncludedConfiguration $batteryIncludedConfig */
            $batteryIncludedConfig = $this->configService->get($salesChannel->getId())->getExtension(BatteryIncludedConfiguration::NAME);
            if (!$batteryIncludedConfig instanceof BatteryIncludedConfiguration) {
                throw new Exception('Configuration not found');
            }

            $url = sprintf(
                '%s/api/v1/collections/%s/documents/browse?q=test',
                $batteryIncludedConfig->getUrl(),
                $batteryIncludedConfig->getCollection()
            );

            try {
                foreach ([$batteryIncludedConfig->getBrowserToken(), $batteryIncludedConfig->getServerToken()] as $token) {
                    $response = $this->client->request('GET', $url, [
                        'headers' => [
                            'X-BI-API-KEY' => $token,
                            'Content-Type' => 'application/json'
                        ],
                        'timeout' => ($batteryIncludedConfig->getApiTimeOut() / 1000)
                    ]);
                    if ($response->getStatusCode() !== 200) {
                        throw new OutputException(sprintf('Invalid status code %s', $response->getStatusCode()));
                    }
                }
            } catch (\Exception $e) {
                $invalid = $salesChannels->filterByProperty('id', $salesChannelsId)->first();
                return new JsonResponse(['success' => false, 'name' => $invalid->getName()], 400);
            }
        }
        return new JsonResponse(['message' => 'Connection successfully established'], 200);
    }
}