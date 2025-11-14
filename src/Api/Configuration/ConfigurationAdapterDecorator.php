<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Configuration;

use Elio\ElioBatteryIncludedSearchExtension\Configuration\BatteryIncludedConfiguration;
use Elio\ElioDataDiscovery\Api\Configuration\Response\PresetConfigurationResponse;
use Elio\ElioBatteryIncludedSearchExtension\Api\ApiClientFactory;
use Elio\ElioDataDiscovery\Api\Configuration\ConfigurationAdapter;
use Elio\ElioDataDiscovery\Api\Configuration\Request\ConfigurationRequest;
use Elio\ElioDataDiscovery\Api\Configuration\Response\ConfigurationResponseCollection;
use Elio\ElioDataDiscovery\Configuration\ElioDataDiscoveryConfigServiceInterface;
use Elio\ElioDataDiscovery\Core\Logging\RequestLoggingService;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ConfigurationAdapterDecorator extends ConfigurationAdapter
{
    /**
     * @param ApiClientFactory $apiClientFactory
     * @param ElioDataDiscoveryConfigServiceInterface $configService
     * @param LoggerInterface $logger
     * @param RequestLoggingService $requestLoggingService
     */
    public function __construct(
        private readonly ApiClientFactory $apiClientFactory,
        private readonly ElioDataDiscoveryConfigServiceInterface $configService,
        LoggerInterface $logger,
        private readonly RequestLoggingService $requestLoggingService
    )
    {
        parent::__construct($logger);
    }

    public function getConfig(ConfigurationRequest $request, SalesChannelContext $context): ConfigurationResponseCollection
    {
        $config = $this->configService->getByContext($context);
        /** @var BatteryIncludedConfiguration $biConfig */
        $biConfig = $config->getExtension(BatteryIncludedConfiguration::NAME);
        $apiClient = $this->apiClientFactory->createSearchApi($context, ['request_id' => $request->getRequestId()]);
        if ($config->isLoggingSearchRequestActive()) {
            $this->requestLoggingService->logRequest($request, $context, 'ConfigurationAdapter::getConfig');
        }
        //TODO: Add Debug logging <- SalesChannelContext is not available in admin
        $presets = $apiClient->configuration($request);
        $response = new ConfigurationResponseCollection();
        $response->addConfigurationResponse(new PresetConfigurationResponse($presets, $biConfig->getCollection()));
        return $response;
    }
}
