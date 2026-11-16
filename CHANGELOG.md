# Changelog

All notable changes to LivePull. Versions follow [semantic versioning](https://semver.org/).

## 1.1.0
- **Security:** requests to live no longer follow redirects blindly. The key is only ever sent to the live site itself, and every hop of a media download is checked, so a redirect can't reach an internal address.
- **Security:** a pull only changes what pulls are meant to change. Settings outside the pulled list (site address, active plugins, user registration and similar) are refused, as are excluded post types and taxonomies, even if a compromised live site sends them.
- *Copy everything* downloads files into a temporary folder and moves them into place only after the database has switched over, so cancelling or a failure really does leave this site unchanged.
- Restoring a backup is now all or nothing: if anything goes wrong, the site is left exactly as it was. Restoring your oldest backup can no longer delete it first.
- Pull backups no longer include users or password hashes (pulls never change users). Backups that can't be kept outside the website folder go in a folder with an unguessable name, and the screen explains how to move them.
- The GitHub download checks each update against a published checksum before installing it.
- The admin screen is ready for translation, with whole sentences for translators.
- *Copy everything* can always finish moving its files, even if the request ends right after the switch-over (time limit, closed tab, logged out). Staged files are never deleted once the database has switched, and any file that can't be moved is kept and reported.
- Backups that have to stay inside the website folder are saved as files that can't be downloaded over the web on any server, nginx included.
- A terminal restore stops if the safety backup before it fails.
- Downloads connect to the exact address that passed the safety check (no DNS tricks), and staging folders get the same access rules as backups.
- Live URLs are only rewritten where the address ends, so `site.co` never touches `site.co.uk`. The key only opens LivePull's own routes. Live's table definitions are checked before *Copy everything* runs them. Very large media libraries are listed without loading everything into memory.

## 1.0.0
- First release.
- Pull new and edited posts, pages, custom post types, media, categories, tags, custom fields, menus and key site settings from a live site into a local copy, without overwriting local work. Items changed in both places are flagged as conflicts.
- Images and links inside content are pointed at the local copies, including in Gutenberg, Elementor, Divi, Beaver Builder, SiteOrigin, Spectra, Kadence Blocks and GenerateBlocks layouts, WooCommerce products, ACF fields, Yoast SEO and Polylang.
- Backup before every pull, with one-click undo.
- *Copy everything*: an exact copy of the live database and uploads, which live must allow first.
- Tools → LivePull in WordPress, or `wp livepull` in the terminal.
- Tested end to end on PHP 7.4 to 8.4, including a 10,000-post site.
