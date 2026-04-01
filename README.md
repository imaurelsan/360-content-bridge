# 360 Content Bridge

Selective WordPress content migration made simple.

360 Content Bridge helps you export and import exactly what you need across WordPress sites: posts, pages, CPT, taxonomies, meta, ACF-compatible data, and referenced media.

## Why this plugin?

WordPress default importer is great for basic moves, but teams often need:
- selective sync (only latest content, or hand-picked content)
- safe re-import without duplication
- mapping old structures to new structures
- media relinking
- multilingual relation support

This plugin focuses on those practical needs while keeping the UI straightforward.

## Features

### Core import/export
- Export posts, pages, and public CPT
- Export statuses, date range, limits
- Export modes:
  - all matching content
  - latest X globally
  - manual selection by title search
- Import modes:
  - create only
  - upsert (update if match found)

### Content fidelity
- Native + custom taxonomies
- Native post meta
- ACF-compatible values and references
- Internal relation remap for common ACF relation patterns
- Preserve internal media links in content

### Media support
- Referenced media bundle export
- Reuse already imported files when possible
- Keep media metadata (title, alt, caption/excerpt, description/content)
- Continue import even if some media fail (with report)

### Mapping and compatibility
- Mapping JSON support:
  - post_type
  - taxonomy
  - meta
  - acf_field_key
  - acf_field_name
- Mapping assistant in UI (small helper to build mapping JSON)
- WPML/Polylang language and translation-group handling (when available)

### Advanced operations
- Advanced export filters:
  - meta_query JSON
  - tax_query JSON
- Dry-run report (no write)
- Batch import with resume token
- WP-CLI commands:
  - `wp cb360 export`
  - `wp cb360 import`

## Installation

1. Copy folder to `wp-content/plugins/360-content-bridge`.
2. Activate plugin in WordPress admin.
3. Go to `Tools > 360 Content Bridge`.

## Quick start

1. On source site: Export JSON.
2. On destination site: Import JSON.
3. For safe checks: run Dry-run first.
4. For large datasets: use batch size + resume token.

## WP-CLI examples

Export latest 10 posts/pages:

```bash
wp cb360 export --output=/tmp/cb360-export.json --post_types=post,page --selection_mode=latest --latest_count=10
```

Export with advanced meta filter:

```bash
wp cb360 export --output=/tmp/cb360-export.json --meta_query_json='[{"key":"external_id","value":"123","compare":"="}]'
```

Import:

```bash
wp cb360 import --input=/tmp/cb360-export.json --mode=upsert --import_media=1
```

## Notes for local environments

If you use S3 offload plugins locally (for example Media Cloud), media sideload may fail on SSL setup (`cURL error 60`).
Content import will continue, and media failures are reported.

## Author

- Aurel Yahoudeou
- https://yaurel.com
- aurelandyou@gmail.com

## License

GPL-2.0-or-later
