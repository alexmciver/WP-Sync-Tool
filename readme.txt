=== LivePull ===
Contributors: alexmciver
Tags: sync, local development, staging, migration, content
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pull the posts, pages, products and media your client adds on the live site into your local copy, without overwriting your own work.

== Description ==

You're building new features on a local copy of a site. Meanwhile, your client keeps adding blog posts, products and pages on the live site. Normally that leaves you two choices: copy the whole live database down and lose your local work, or copy their changes over by hand.

**LivePull pulls just the content that changed on live into your local site, and leaves everything you changed locally alone.** Anything changed in both places is flagged as a conflict, never overwritten. And it only ever reads from live: your client's content and settings there are never changed.

* **Only what changed.** LivePull keeps a record of content changes on live, and each pull brings over only what's new since the last one.
* **You choose what comes across.** *Check for changes* lists everything that changed on live with a tick box for each. Anything you untick is held back for later, not lost.
* **Your work is safe.** If something was edited both on live and locally, nothing is overwritten. Compare the two versions side by side, then choose which to keep.
* **Images come too.** Media files are downloaded, and every image and link inside the content is pointed at your local copy.
* **Works with your page builder.** Gutenberg, Elementor, Divi, Beaver Builder, SiteOrigin, Spectra, Kadence Blocks and GenerateBlocks layouts are pulled with their images and links intact.
* **Shops, fields and SEO.** WooCommerce products (including variations and attributes), ACF fields, Yoast SEO and Polylang translations.
* **Menus and key settings.** Menus, menu locations, homepage, site title, logo and icon.
* **Undo and history.** LivePull backs up your local database before every pull, and you can restore it with one click. The History tab lists every pull and what it brought across.
* **Pull automatically.** Pull every hour, twice a day, daily or weekly, with an email when something came across or needs a decision.
* **Fix it.** If the connection fails, one click checks each step (address, firewall, caching, key) and says how to fix what's wrong.
* **Copy everything.** When you want local to be an exact copy of live, one button copies the whole database and uploads. Live has to allow this first.
* **Point and click or terminal.** Use Tools → LivePull, or `wp livepull pull` with WP-CLI.

= What gets pulled =

Every pull brings over posts, pages and custom post types, including WooCommerce products. It also brings media (with files), categories, tags and custom taxonomies, custom fields and page-builder content, menus, and the site's title, tagline, homepage, logo and icon.

You can also switch on, under Settings: page-builder templates (Divi Library and Theme Builder, Elementor, Beaver Builder and Bricks templates), Contact Form 7 and WPForms forms, comments and product reviews, and ACF options pages.

These are left to you, because they're usually part of your development work: theme and plugin settings, widgets, and users. *Copy everything* brings these over too when you want them.

Never copied: WooCommerce orders, logged-in sessions, and theme or plugin files (keep those in version control).

= How it connects =

LivePull only talks to the live site you connect it to. Install it on both sites and copy the connection code from the live site into the local one. The connection code contains a secret key. Live only answers requests that carry that key, requests to live can only read (they can't change anything there), and it never sends anything to any other service.

= Testing =

Every release is tested end to end with real live and local sites (with different table prefixes and IDs). The tests cover Gutenberg, Elementor, Divi, Beaver Builder, SiteOrigin, Spectra, Kadence Blocks, GenerateBlocks, WooCommerce, ACF, Yoast SEO, Polylang, menus, conflicts, undo and Copy everything. The tests are in the [GitHub repository](https://github.com/alexmciver/WP-Sync-Tool).

== Installation ==

1. Install and activate LivePull on **both** the live site and your local site.
2. On the **live** site, go to Tools → LivePull, choose **This is the live site** and click **Copy** next to the connection code.
3. On the **local** site, go to Tools → LivePull, choose **This is my copy**, paste the code and click **Save and connect**.
4. Click **Check for changes**, untick anything you don't want yet, and click **Pull selected**. That's it.

If your local site started as a copy of live, LivePull recognises the content that's already there and doesn't duplicate it.

== Frequently Asked Questions ==

= Does it push my local changes up to live? =

No. LivePull only pulls from live to local. Deploy your own changes the way you normally do.

= What happens if the same page was changed in both places? =

It's listed as a conflict and nothing is overwritten. Click **Compare** to see the two versions side by side, then take the live version or keep yours.

= Does it change anything on the live site? =

It never changes your live content or settings. On live, LivePull keeps its own change log table, a hidden ID on each post, term and comment so the two sites can match them up, and a daily task that trims the log. Requests from your local site can only read.

= Something was edited on live but "Nothing to pull" =

On the live site, open Tools → LivePull and look at *Recent changes recorded on this site*. If the change isn't listed, it's something normal pulls don't include (see "What gets pulled"). Use *Copy everything* instead.

= "The live site returned a web page instead of data" =

A page cache or firewall on live is answering instead of WordPress. Exclude `/wp-json/` and URLs containing `rest_route=` from caching.

= Is multisite supported? =

Not yet. WPML and Gravity Forms aren't supported yet either.

= Something went wrong. How do I report it? =

Go to Tools → LivePull → Settings and click **Report a problem**. It gathers the details that help (your set-up and recent pulls, never passwords or keys) and opens a GitHub issue with them filled in.

== Screenshots ==

1. Check for changes: everything that changed on live, with a tick box for each. Untick anything you don't want yet.
2. Conflicts: changed on live and on your copy. Compare the two side by side, then choose which to keep.
3. History: every pull, including scheduled ones, and what each brought across.
4. Settings: what to pull, scheduled pulls, a connection check and Report a problem.
5. The live site's screen: copy the connection code into your local site. That's all.

== Changelog ==

= 1.2.2 =
* Copy everything is no longer blocked by a pull that was abandoned part-way.
* Safer handling of the pull lock, full scans and update addresses.
* Scheduled pulls too big for one run carry on next time without a failure email.
* GitHub updates show which WordPress versions they're tested with, instead of "Not tested".

= 1.2.1 =
* Comment conflicts: Compare, "Use live version" and held-back comments now work.
* Commenters' IP addresses and anti-spam data never leave live, and live only sends comments to sites that pull them.
* Only one pull or copy runs at a time, and Unlock can't start a second pull alongside a scheduled one.
* Updates must contain the version they were offered as.
* Scheduled pulls come back after Copy everything or a restore; failures are emailed once.
* Backups before full copies are kept separately; no backup when the disk is nearly full.
* ACF options pages start switched off. Clearer labels for forms and comments.
* Clearer wording: LivePull never changes your live content or settings.

= 1.2.0 =
* A simpler screen: one *Check for changes* button, a plain status line, and tabs for Pull, Conflicts, History, Copy everything, Backups and Settings. A short guide on first use, and a much simpler screen on the live site.
* Choose what to pull: every change has a tick box. Unticked items are held back and offered again next time.
* Compare a conflict side by side before choosing.
* History of every pull, scheduled pull, full copy and restore.
* Scheduled pulls with an optional email summary.
* *Fix it*: a connection check that says what's wrong and how to fix it. *Report a problem* gathers the details for a bug report.
* Optional: page-builder templates (including the Divi Theme Builder), Contact Form 7 and WPForms forms, comments and reviews, ACF options pages.
* Media kept on a CDN or S3 is downloaded from the normal uploads address if the CDN can't be reached.

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

= 1.2.2 =
Fixes Copy everything being blocked after an abandoned pull. Note: since 1.2.1, ACF options pages are off by default; if you pulled them on 1.2.0, switch them back on under Settings → What to pull.

= 1.2.1 =
ACF options pages are now off by default: if you pulled them on 1.2.0, switch them back on under Settings → What to pull. Also fixes comment conflicts, keeps commenter IP addresses on live and makes sure only one pull runs at a time. Update both sites.

= 1.2.0 =
A simpler screen, choose what to pull, history, scheduled pulls and a connection check. Update both your live and local sites.

= 1.1.0 =
Security and reliability improvements. Update both your live and local sites.

= 1.0.0 =
First release. Install it on both your live and local sites.
