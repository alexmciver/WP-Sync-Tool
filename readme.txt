=== LivePull ===
Contributors: alexmciver
Tags: sync, local development, staging, migration, content
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pull the posts, pages, products and media your client adds on the live site into your local copy, without overwriting your own work.

== Description ==

You're building new features on a local copy of a site. Meanwhile, your client keeps adding blog posts, products and pages on the live site. Normally that leaves you two choices: copy the whole live database down and lose your local work, or copy their changes over by hand.

**LivePull pulls just the content that changed on live into your local site, and leaves everything you changed locally alone.**

* **Only what changed.** LivePull keeps a record of content changes on live, and each pull brings over only what's new since the last one.
* **Your work is safe.** If something was edited both on live and locally, nothing is overwritten. You choose which version to keep.
* **Images come too.** Media files are downloaded, and every image and link inside the content is pointed at your local copy.
* **Works with your page builder.** Gutenberg, Elementor, Divi, Beaver Builder, SiteOrigin, Spectra, Kadence Blocks and GenerateBlocks layouts are pulled with their images and links intact.
* **Shops, fields and SEO.** WooCommerce products (including variations and attributes), ACF fields, Yoast SEO and Polylang translations.
* **Menus and key settings.** Menus, menu locations, homepage, site title, logo and icon.
* **Undo.** LivePull backs up your local database before every pull, and you can restore it with one click.
* **Copy everything.** When you want local to be an exact copy of live, one button copies the whole database and uploads. Live has to allow this first.
* **Point and click or terminal.** Use Tools → LivePull, or `wp livepull pull` with WP-CLI.

= What gets pulled =

Every pull brings over posts, pages and custom post types, including WooCommerce products. It also brings media (with files), categories, tags and custom taxonomies, custom fields and page-builder content, menus, and the site's title, tagline, homepage, logo and icon.

These are left to you, because they're usually part of your development work: theme and plugin settings, Divi Theme Builder and Library, Elementor templates, widgets, and users. *Copy everything* brings these over too when you want them.

Never copied: WooCommerce orders, logged-in sessions, and theme or plugin files (keep those in version control).

= How it connects =

LivePull only talks to the live site you connect it to. Install it on both sites and copy the connection code from the live site into the local one. The connection code contains a secret key. Live only answers requests that carry that key, it only ever sends data (it can't be changed from outside), and it never sends anything to any other service.

= Testing =

Every release is tested end to end with real live and local sites (with different table prefixes and IDs). The tests cover Gutenberg, Elementor, Divi, Beaver Builder, SiteOrigin, Spectra, Kadence Blocks, GenerateBlocks, WooCommerce, ACF, Yoast SEO, Polylang, menus, conflicts, undo and Copy everything. The tests are in the [GitHub repository](https://github.com/alexmciver/WP-Sync-Tool).

== Installation ==

1. Install and activate LivePull on **both** the live site and your local site.
2. On the **live** site, go to Tools → LivePull and click **Copy** next to the connection code.
3. On the **local** site, go to Tools → LivePull, paste the code and click **Connect**.
4. Click **Pull changes from live**. That's it.

If your local site started as a copy of live, LivePull recognises the content that's already there and doesn't duplicate it.

== Frequently Asked Questions ==

= Does it push my local changes up to live? =

No. LivePull only pulls from live to local. Deploy your own changes the way you normally do.

= What happens if the same page was changed in both places? =

It's listed as a conflict and nothing is overwritten. You choose whether to take the live version or keep yours, for each item.

= Does it change anything on the live site? =

No. On live, LivePull records which content changed and answers read-only requests from your local site.

= Something was edited on live but "Nothing to pull" =

On the live site, open Tools → LivePull and look at *Recent changes recorded on this site*. If the change isn't listed, it's something normal pulls don't include (see "What gets pulled"). Use *Copy everything* instead.

= "The live site returned a web page instead of data" =

A page cache or firewall on live is answering instead of WordPress. Exclude `/wp-json/` and URLs containing `rest_route=` from caching.

= Is multisite supported? =

Not yet. WPML and comments aren't supported yet either.

== Screenshots ==

1. Pulling changes from live on the local site.

== Changelog ==

= 1.1.0 =
* Security: requests to live never send the key anywhere else after a redirect, and redirected media downloads are checked at every step.
* Security: pulls refuse settings, post types and taxonomies that aren't meant to be pulled.
* Copy everything stages downloaded files until the database has switched over, so cancelling leaves the site unchanged.
* Backups restore all or nothing, and pull backups no longer contain users.
* Copy everything always finishes moving files after the switch-over, even if the request ends early.
* Backups inside the website folder can't be downloaded over the web, on any server.
* Ready for translation.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.1.0 =
Security and reliability improvements. Update both your live and local sites.

= 1.0.0 =
First release. Install it on both your live and local sites.
