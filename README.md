# Elio Battery Included Search Integration

## Installation
### Composer Installation
Add the composer repository
```
composer config repositories.1168 composer https://git.elio-systems.io/api/v4/group/1168/-/packages/composer/
composer config repositories.201 composer https://git.elio-systems.io/api/v4/group/201/-/packages/composer/
composer config repositories.147 composer https://git.elio-systems.io/api/v4/group/147/-/packages/composer/

```

Require package **elio/battery-included-search-extension**
```
composer req elio/battery-included-search-extension 1.0.3
```

To install ElioFoundation in Shopware add the following code into the config/bundles.php file:
```php
return [
    // ...
    Elio\Foundation\ElioFoundation::class => ['all' => true]
];
```

Install plugin
```shell
bin/console cache:clear
bin/console plugin:refresh
bin/console plugin:install ElioSearch --activate
bin/console plugin:install ElioBatteryIncludedSearchExtension --activate
```
## Configuration