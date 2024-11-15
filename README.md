# BatteryIncluded by elio

## Installation
### Parepare composer package
To install this plugin you need to move the code into a composer registry. The following options are available:
- Use the shopware store package registry (recommended).
- Move "elio/data-discovery-battery-included" and "elio/data-discovery-core" into your static plugins folder (custom/static-plugins).
- Use your own package registry.

### Composer Installation
Require package **elio/data-discovery-battery-included**
```
composer req elio/data-discovery-battery-included 6.6.0
```

Install plugin
```shell
bin/console cache:clear
bin/console plugin:refresh
bin/console plugin:install ElioDataDiscovery --activate
bin/console plugin:install ElioBatteryIncludedSearchExtension --activate
```

# Configuration
The plugin can be configured in the shopware administration. The documentation can be accessed with the following link:
https://confluence.external-share.com/content/27202295-6f5e-4cb5-adc2-1d6fbd18e2cb