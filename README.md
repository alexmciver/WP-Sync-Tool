# WP Sync – pull client changes from live into local

Pulls new and edited **posts, pages, custom post types, media, categories/tags and their meta** from a live WordPress site into your local copy, **without wiping your local work**. If something changed on live *and* locally, it's left alone and shown as a conflict for you to decide.

Use it from **Tools → WP Sync** in WordPress, or from the terminal with `wp sync`. Both use the same engine.

Nothing is ever written to the live site. Live only answers read-only, key-protected requests.

## Install (both sites)

**As a normal plugin (easiest):** on **live** and **local**, go to **Plugins → Add New → Upload Plugin**, upload `wp-sync.zip`, and click **Activate**. To update later, upload the new zip and choose **Replace current with uploaded**.

**Updates:** when a new version is released on [GitHub](https://github.com/alexmciver/WP-Sync-Tool/releases), Tools → WP Sync shows **"WP Sync x.y.z is available"** with an **Update now** button (it also appears on the Plugins page and Dashboard → Updates). Use **Check for updates** at the top of the WP Sync screen to look straight away. Update both sites so they stay on the same version. Prefer hands-off? Click **Enable auto-updates** next to WP Sync on the Plugins screen, or add `define( 'WPSYNC_AUTO_UPDATE', true );` to wp-config.php.

**Or as a must-use plugin** (can't be deactivated by accident, but can't update itself either): copy `wp-sync.php` **and** the `wp-sync/` folder into `wp-content/mu-plugins/`.

Keep it active on live at all times: changes made on live while it's deactivated aren't recorded (use catch-up mode to recover).

## Connect (in WordPress)

1. **Live site:** go to **Tools → WP Sync**, then click **Generate key**. Copy the **Site URL** and **WP Sync key**.
2. **Local site:** go to **Tools → WP Sync**, paste both into **"Is this your local or staging copy?"**, then click **Save and connect**.

That's it. The local screen now shows how many changes are waiting on live.

> Prefer wp-config? Put `define( 'WPSYNC_SECRET', '…' );` on both sites and `define( 'WPSYNC_REMOTE', 'https://live-site' );` on local. Constants override the screen.

## Use it

**In WordPress (local site → Tools → WP Sync):**
- **Preview changes** shows what would be created, updated or deleted, plus conflicts. It changes nothing.
- **Pull changes** backs up the local database, then pulls in batches with a progress bar. Large sites are fine, and if it's interrupted, just press it again to carry on.
- **Conflicts** lists items changed on both sides. Choose **Use live version** or **Keep mine**.
- **Your local changes** lists everything you've edited locally since it was last pulled.
- **More options** has catch-up from a date, let live win all conflicts, and re-download media.

**In the terminal (local site folder; in Local, right-click the site → Open site shell):**

| Command | What it does |
|---|---|
| `wp sync status` | Checks the connection and shows what's waiting |
| `wp sync pull --dry-run` | Preview; changes nothing |
| `wp sync pull` | Pull (backs up first) |
| `wp sync conflicts` | List conflicts |
| `wp sync take 123` / `wp sync keep 123` | Resolve a conflict (add `--term` for categories/tags) |
| `wp sync local-changes` | What you've changed locally |
| `wp sync key` | (on live) show or generate this site's key |
| `wp sync connect <url> <key>` | (on local) connect without the admin screen |

## When to copy, and catch-up mode

WP Sync records changes on live from the moment it's installed there.

- **Best:** install on live first, *then* copy live down to local as you normally would (Local, WP Migrate, All-in-One…). WP Sync notices the database is a fresh copy and starts from that exact point automatically.
- **Local copy made before installing?** Do your first pull with **More options → Catch-up from date** (or `wp sync pull --since=YYYY-MM-DD`) set to the day you made the copy. After that, a normal pull is all you need.

## What is and isn't pulled

**Pulled (client-owned content):** posts, pages, custom post types, media + files (all sizes), Woo products and variations, categories/tags/custom taxonomies, all post and term meta, ACF field values. Image and post IDs are re-pointed to the matching local items in featured images, galleries, ACF image/relationship/taxonomy fields, Gutenberg blocks, `[gallery]`, Divi galleries, Woo galleries/upsells and Elementor data. Live URLs are rewritten to your local URL.

**Never pulled (developer-owned structure, deploy via Git or your usual process):** menus, theme and plugin settings, ACF field groups, Divi/Elementor templates and Theme Builder, form definitions, Woo orders, users (authors are matched by username, falling back to the first admin).

**Not yet:** comments/reviews, data in plugin-specific tables (Gravity Forms entries, Woo HPOS orders), WPML, multisite. Pushing local changes *up* to live is a later phase.

**Safety during a pull:** no emails, pingbacks or Woo webhooks are sent from the local site; a database backup is made first (kept in `wp-content/wpsync-backups/`, last 10); only one pull runs at a time.

## Restoring a backup

```
wp db import wp-content/wpsync-backups/pre-pull-YYYYMMDD-HHMMSS.sql
```
(`.sql.gz` files: `gunzip` first, or import in phpMyAdmin.) Backups made from the admin screen contain the content tables a pull touches; terminal backups are full `wp db export` dumps.

## Extending for unusual sites

If a theme stores an image/post ID in its own meta key, register it so the ID is re-pointed:
```php
add_filter( 'wpsync_meta_ref_keys', function ( $keys, $meta, $type ) {
	if ( 'post' === $type && isset( $meta['hero_image_id'] ) ) {
		$keys['hero_image_id'] = 'post';
	}
	return $keys;
}, 10, 3 );
```
Other filters: `wpsync_walk_content` (custom shortcodes), `wpsync_content_meta_keys` (builder data), `wpsync_excluded_post_types`, `wpsync_excluded_taxonomies`, `wpsync_ignored_meta_keys`, `wpsync_backup_tables`. Action: `wpsync_after_pull`.

## Optional wp-config settings

```php
define( 'WPSYNC_REMOTE_BASIC_AUTH', 'user:pass' );        // staging behind a password
define( 'WPSYNC_SSL_VERIFY', false );                      // staging with a self-signed certificate
define( 'WPSYNC_PAGE_SIZE', 20 );                          // smaller batches for huge page-builder pages
define( 'WPSYNC_EXCLUDE_POST_TYPES', 'my_cpt,another' );   // never pull these
define( 'WPSYNC_EXCLUDE_TAXONOMIES', 'my_tax' );
define( 'WPSYNC_DEFAULT_AUTHOR', 'alex' );                 // fallback author on local
```

## Releasing a new version (for developers)

Bump the version in **both** `wp-sync.php` (`Version:`) and `wp-sync/includes/class-config.php` (`VERSION`), then push to `main`. The GitHub Action builds `wp-sync.zip` and publishes release `vX.Y.Z`; every site picks it up on its next update check.

## Troubleshooting

- **"Invalid sync key"**: the key on local doesn't match live's. Copy it again from live's Tools → WP Sync.
- **401/403, "did not return JSON"**: a security plugin or firewall (Wordfence, Cloudflare, "Disable REST API") is blocking `?rest_route=/wpsync/v1/…` on live. Allow that path.
- **"Is WP Sync installed…on the live site?" (404)**: the files aren't in live's `wp-content/mu-plugins/`, or the `wp-sync/` folder is missing next to `wp-sync.php`.
- **"Another pull is already running"**: if nothing is running (e.g. the tab was closed mid-pull), click **Unlock and pull again**. It also unlocks by itself after 15 minutes.
- **"The live site returned a web page instead of data"**: a page cache or firewall on live is answering instead of WordPress. Exclude `/wp-json/` and URLs containing `rest_route=` from caching (host cache, cache plugin, Cloudflare rules).
- **Quick check from a browser:** open `https://your-live-site/wp-json/wpsync/v1/status`. You should see `{"code":"wpsync_forbidden","message":"Invalid sync key." …}`, which is correct: it means WP Sync is running and protected. A web page means something is caching it; `rest_no_route` means the plugin isn't active.
- **Pull seems slow**: the first pull downloads every image size for each new media item. The progress line shows what it's doing; you can Cancel at any time and press Pull again to carry on.
