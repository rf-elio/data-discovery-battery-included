<?php declare(strict_types=1);

namespace Elio\ElioBatteryIncludedSearchExtension;

use Exception;
use Shopware\Core\Framework\Plugin;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

class ElioBatteryIncludedSearchExtension extends Plugin
{
    public const PLUGIN_CONFIG_PREFIX = 'ElioBatteryIncludedSearchExtension.config';

    public const X_BI_API_KEY = 'X-BI-API-KEY';

    /**
     * Adds the additional service definitions
     *
     * @param ContainerBuilder $container
     *
     * @throws Exception
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/DependencyInjection/'));
        $loader->load('services.xml');
    }
}
