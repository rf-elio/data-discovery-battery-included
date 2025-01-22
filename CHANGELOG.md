# CHANGELOG.md
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