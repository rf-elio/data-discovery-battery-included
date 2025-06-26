# CHANGELOG.md
## 6.6.18 - 2025-06-26
### Fix (3 changes)
- Added `useLegacyLocale` configuration setting from Core plugin
- Changed type of API key plugin configuration fields to `password`
- Renamed `getFilter` method in `SearchApiDecorator` to `getFilters`

## 6.6.12 - 2025-06-03
### Fix (1 change)
- Compatibility with Core update

## 6.6.11 - 2025-06-02
### Feature (7 changes)
- Suggest now accepts filters
- Filter names and values are now decoded if Unicode encoded
- Added alt text for promotion HTML in `PromotionTransformer`
- Added support for AI pick badges in suggest
- Added `SuggestProductCollectCriteriaEvent` in `SuggestProductTransformer` to modify criteria when products are collected
- Changed handling of locale and make usage of `useLegacyLocale` configuration option from Core plugin
- Added `ApiUtil` to handle filter preparation for request parameters and refactored code

### Fix (2 changes)
- Refactored code for parameter preparation in `SearchApiDecorator`
- Moved most logic of the `SuggestProductTransformer` to an abstract class in Core plugin

## 6.6.7 - 2025-03-21
### Feature (1 change)
- Compatibility with Shopware 6.6.10

### Fix (2 changes)
- Fixed handling of filters when locale is ignored
- Added `ignoreLocaleForListingRequest` config setting to ignore the locale when querying BI in the navigation

## 6.6.6 - 2025-03-20
### Feature (1 change)
- Removed BI Sync, as it is now handled directly by BI instead of the plugin

### Fix (3 changes)
- Locale can now be omitted when defining filters and sort options
- Product numbers are now sanitized in the `SuggestProductTransformer`
- Moved function from sync `LocaleUtil` to the response transformer `LocaleUtil` and adjusted `LocaleService`

## 6.6.5 - 2025-03-05
### Features (1 change)
- Added support for AI pick badges

### Fix (1 change)
- `ProductMappingService`: Replaced product with productTranslation when mapping custom fields in case no custom field value for the default language exists

## 6.6.4 - 2025-02-13
### Fix (3 changes)
- Added missing return in `ProductListingLoaderDecorator` which prevented falling back to the original service (Core)
- Replaced `form-check` class selector for multi-select filter items with `edd-filter-form-check` selector for styling (Core)
- `search.html.twig`: Added `searchWidgetMinChars` option to use Shopware's `Minimal search term length` setting in the suggest (Core)

## 6.6.3 - 2025-01-24
### Features (1 change)
- Compatibility with Shopware 6.6.8 and 6.6.9

## 6.6.2 - 2025-01-15
### Features (1 change)
- Added Interrupters:
  - `InterrupterTransformer`: transforms the response received by BI

### Fix (8 changes)
- `SearchApiDecorator`: Added fallback for maximum value in range filter to fix broken product filtering when it is not set
- Added `navigationStartLevelExport` config setting to determine the level from with the category path begins while mapping
- `CategoryPathUtil`:
  - Added `sliceCategoryExportBreadcrumb` function that slices the original category path based on new config setting
  - Removed unused `createCategoryPath` function
- `ProductMappingService`:
  - `category` path tree is now dependent on new `navigationStartLevelExport` config setting
  - Added support for multi value properties
- `ContentMappingService`: `contentStructure` is now dependent on new `navigationStartLevelExport` config setting
- `FacetTransformer`: `transformCategoryTree` now checks if a tree item was already added as a child to the previous tree item to prevent duplication in the category filter

## 6.6.1 - 2024-11-20
### Fix (3 changes)
- `ProductMappingService.php`: ratingCount is now mapped directly from product
- `SearchApi.php`:
  - $locale is now mapped to 'v[locale]' parameter instead of 'locale'
  - Fixed wrong function call in `configurationAsyncWithHttpInfo` method

## 6.6.0 - 2024-11-14
### Fix (3 changes)
- README.md: Updated
- ProductMappingService.php: doc types updated
- Added handling of the new `found` parameter, which returns the count of found matches for the suggest request

## 2.2.0 - 2024-03-18
### Fix (1 change)
- Naming adjusted to match the new data discovery core plugin name

## Template
### Security (x changes)
- changed...

### Features (x changes)
- changed...

### Fix (x changes)
- changed...

### Bugfixes (x changes)
- changed...