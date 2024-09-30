<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension\Api\Configuration;

use Elio\ElioBatteryIncludedSearchExtension\Api\ApiClientFactory;
use Elio\ElioBatteryIncludedSearchExtension\Api\Service\LocaleService;
use Elio\ElioDataDiscovery\Api\Configuration\ConfigurationAdapter;
use Elio\ElioDataDiscovery\Api\Configuration\Request\ConfigurationRequest;
use Elio\ElioDataDiscovery\Api\Configuration\Response\ConfigurationResponse;
use Psr\Log\LoggerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ConfigurationAdapterDecorator extends ConfigurationAdapter
{
    public function __construct(
        private readonly ApiClientFactory $apiClientFactory,
        private readonly LocaleService $localeService,
        LoggerInterface $logger
    )
    {
        parent::__construct($logger);
    }

    public function getPresets(ConfigurationRequest $request, SalesChannelContext $context): ConfigurationResponse
    {
        $apiClient = $this->apiClientFactory->createSearchApi($context);
        $locale = $this->localeService->getLocaleByContext($context);
        $presets = $apiClient->configuration($request->getType(), $locale);
        $response = new ConfigurationResponse();
        $response->setPresets($presets);
        return $response;
    }
}
