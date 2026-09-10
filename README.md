# FourZero Term Importer

A lightweight WordPress category and tag CSV importer from FourZero.

## Version

2.0.0

## Features

- Import categories and tags from CSV
- Parent/subcategory support
- Automatic or custom slugs
- Term descriptions
- Preview / dry-run mode
- Optional updates to existing terms
- Duplicate detection and validation
- Optional SEO metadata support for Yoast SEO, Rank Math and AIOSEO
- UTF-8 CSV/BOM handling
- Works as a normal WordPress plugin or can be adapted for FluentSnippets

## CSV format

Required columns:

```text
type,name
```

Optional columns:

```text
slug,parent,description,seo_title,seo_description
```

Example:

```csv
type,name,slug,parent,description,seo_title,seo_description
category,Digital,digital,,,Digital Services | FourZero,Digital services from FourZero.
category,Web Design,web-design,Digital,Professional website design,Web Design | FourZero,Professional website design from FourZero.
category,SEO,seo,Digital,Search engine optimisation,SEO Services | FourZero,SEO services from FourZero.
tag,WordPress,wordpress,,,WordPress websites and development,WordPress | FourZero,WordPress websites and development.
tag,Elementor,elementor,,,Elementor website design,Elementor | FourZero,Elementor website design and development.
```

For a subcategory, use the parent category name in the `parent` column.

## Installation

### WordPress plugin

1. Download `fourzero-term-importer.php`.
2. Place it in `/wp-content/plugins/fourzero-term-importer/`.
3. Activate **FourZero Term Importer** under Plugins.
4. Open **Tools → Term Importer**.
5. Upload a CSV.
6. Run **Validate / Preview** first.
7. When the results are correct, run the import with Preview Only disabled.

### FluentSnippets

The importer code can also be used as a PHP snippet in FluentSnippets. For production use, the normal plugin file is recommended.

## Safety

Preview mode makes no database changes. Existing terms are skipped by default. Existing terms are only changed when **Update existing terms** is explicitly enabled.

## SEO metadata

If a supported SEO plugin is active, the importer can write the corresponding term SEO fields. Test this on a staging site before bulk importing SEO metadata into a production site.

## License

GPL-2.0-or-later.

## Author

FourZero — https://fourzero.work
