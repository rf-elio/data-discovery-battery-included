# CHANGELOG.md
## 5.6.11 - 2025-05-30
### Feature (6 changes)
- Suggest now accepts filters (applied changes from 6.6.11)
- Filter names and values are now decoded if Unicode encoded (applied changes from 6.6.11)
- Added alt text for promotion HTML in `PromotionTransformer` (applied changes from 6.6.11)
- Added support for AI pick badges in suggest (applied changes from 6.6.11)
- Added `SuggestProductCollectCriteriaEvent` in `SuggestProductTransformer` to modify criteria when products are collected (applied changes from 6.6.11)
- Changed handling of locale and make usage of `useLegacyLocale` configuration option from Core plugin (applied changes from 6.6.11)

### Fix (1 change)
- Refactored code for range filter preparation in `SearchApiDecorator` (applied changes from 6.6.11)
- Added missing `updateDestructive` method to `RemoveSyncProfile` migration
- Added handling of the new `found` parameter, which returns the count of found matches for the suggest request (applied changes from 6.6.0)

## 2.2.46 - 2025-04-24
### Fix (1 change)
- Compatibility with Data Discovery Core 2.2.46

## 2.2.45 - 2025-03-26
### Fix (1 change)
- Fixed missing getter for `ignoreLocaleForListingRequest` setting

## 2.2.44 - 2025-03-26
### Fix(2 changes)
- Fixed handling of filters when locale is ignored (applied changes from 6.6.7)
- Added `ignoreLocaleForListingRequest` config setting to ignore the locale when querying BI in the navigation (applied changes from 6.6.7)

## 2.2.43 - 2025-03-20
### Feature (1 change)
- Removed BI Sync, as it is now handled directly by BI instead of the plugin (applied changes from 6.6.6)

### Fix (3 changes)
- `SearchApi.php`:
    - $locale is now mapped to 'v[locale]' parameter instead of 'locale' (applied changes from 6.6.1)
- Applied change from 6.6.6 that allows the locale to be omitted when defining filters and sort options
- Applied change from 6.6.6 that sanitizes product numbers in the `SuggestProductTransformer`

## 2.2.42 - 2025-02-20
### Fix (2 changes)
- Variant display logic from latest 6.6.4 version applied to disable shopware's custom variant display logic for navigation and search results provided by search engines
- Implemented property mapping from latest 6.6.4 version

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