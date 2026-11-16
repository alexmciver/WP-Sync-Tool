# Changelog

All notable changes to LivePull. Versions follow [semantic versioning](https://semver.org/).

## 1.0.0
- First release.
- Pull new and edited posts, pages, custom post types, media, categories, tags, custom fields, menus and key site settings from a live site into a local copy, without overwriting local work. Items changed in both places are flagged as conflicts.
- Images and links inside content are pointed at the local copies, including in Gutenberg, Elementor, Divi, Beaver Builder, SiteOrigin, Spectra, Kadence Blocks and GenerateBlocks layouts, WooCommerce products, ACF fields, Yoast SEO and Polylang.
- Backup before every pull, with one-click undo.
- *Copy everything*: an exact copy of the live database and uploads, which live must allow first.
- Tools → LivePull in WordPress, or `wp livepull` in the terminal.
- Tested end to end on PHP 7.4 to 8.4, including a 10,000-post site.
