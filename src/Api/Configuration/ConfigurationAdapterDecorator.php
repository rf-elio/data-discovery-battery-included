<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Configuration;

use Elio\ElioDataDiscovery\Api\Configuration\Response\PresetConfigurationResponse;
use Elio\ElioBatteryIncludedSearchExtension\Api\ApiClientFactory;
use Elio\ElioBatteryIncludedSearchExtension\Api\Service\LocaleService;
use Elio\ElioDataDiscovery\Api\Configuration\ConfigurationAdapter;
use Elio\ElioDataDiscovery\Api\Configuration\Request\ConfigurationRequest;
use Elio\ElioDataDiscovery\Api\Configuration\Response\ConfigurationResponseCollection;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ConfigurationAdapterDecorator extends ConfigurationAdapter
{
    public function __construct(
        private readonly ApiClientFactory $apiClientFactory,
        LoggerInterface $logger
    )
    {
        parent::__construct($logger);
    }

    public function getConfig(ConfigurationRequest $request, SalesChannelContext $context): ConfigurationResponseCollection
    {
        $apiClient = $this->apiClientFactory->createSearchApi($context);
        $presets = $apiClient->configuration($request->getType());
        $response = new ConfigurationResponseCollection();
        $response->addConfigurationResponse(new PresetConfigurationResponse($presets));
        return $response;
    }
}
