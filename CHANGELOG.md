# CHANGELOG.md
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