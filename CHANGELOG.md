# CHANGELOG.md
## 6.6.2 - 2024-12-12
### Fix (7 changes)
- `SearchApiDecorator`:
  - Added fallback for maximum value in range filter to fix broken product filtering when it is not set
  - Category path filter in navigation can be sliced via new Core config setting
- Added `startLevelExport` config setting to determine the level from with the category path begins while mapping
- `CategoryPathUtil`: Added `sliceCategoryExportBreadcrumb` function that slices the original category path based on new config setting
- `ProductMappingService`:
  - `category` path tree is now dependent on new config setting
  - Added support for multi value properties
- `ContentMappingService`: `contentStructure` is now dependent on new config setting

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