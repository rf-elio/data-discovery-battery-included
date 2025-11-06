<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Configuration;

use Elio\ElioBatteryIncludedSearchExtension\Configuration\BatteryIncludedConfiguration;
use Elio\ElioDataDiscovery\Api\Configuration\Response\PresetConfigurationResponse;
use Elio\ElioBatteryIncludedSearchExtension\Api\ApiClientFactory;
use Elio\ElioDataDiscovery\Api\Configuration\ConfigurationAdapter;
use Elio\ElioDataDiscovery\Api\Configuration\Request\ConfigurationRequest;
use Elio\ElioDataDiscovery\Api\Configuration\Response\ConfigurationResponseCollection;
use Elio\ElioDataDiscovery\Configuration\ElioDataDiscoveryConfigServiceInterface;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ConfigurationAdapterDecorator extends ConfigurationAdapter
{
    public function __construct(
        private readonly ApiClientFactory $apiClientFactory,
        private readonly ElioDataDiscoveryConfigServiceInterface $configService,
        LoggerInterface $logger
    )
    {
        parent::__construct($logger);
    }

    public function getConfig(ConfigurationRequest $request, SalesChannelContext $context): ConfigurationResponseCollection
    {
        /** @var BatteryIncludedConfiguration $config */
        $config = $this->configService->getByContext($context)->getExtension(BatteryIncludedConfiguration::NAME);
        $apiClient = $this->apiClientFactory->createSearchApi($context);
        $presets = $apiClient->configuration($request);
        $response = new ConfigurationResponseCollection();
        $response->addConfigurationResponse(new PresetConfigurationResponse($presets, $config->getCollection()));
        return $response;
    }
}
