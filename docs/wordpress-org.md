# Getting LivePull into the WordPress.org plugin directory

Everything in the plugin is ready. These steps need you, because they happen in your WordPress.org account.

## 1. Account (5 minutes, once)

1. Sign in or register at <https://login.wordpress.org>. Your **username** (not your email) is what goes in `readme.txt` next to `Contributors:`. If yours isn't `alexmciver`, change that line.
2. Turn on **two-factor authentication**. WordPress.org requires it before you can submit a plugin: *Profile → Edit → Account & Security*.

## 2. Submit (5 minutes)

1. Download **`livepull-wordpress-org.zip`** from the [latest GitHub release](https://github.com/alexmciver/WP-Sync-Tool/releases/latest). Use this file, not `livepull.zip`: the directory doesn't allow plugins that update themselves from GitHub.
2. Go to <https://wordpress.org/plugins/developers/add/>, tick the boxes and upload the zip.
3. The page runs Plugin Check automatically. LivePull passes with no errors or warnings, and the same check runs on GitHub on every change.
4. Confirm the name **LivePull** and the address `wordpress.org/plugins/livepull`. The address can't be changed later.

You'll get an email saying it's in the queue.

## 3. Review (usually weeks)

A volunteer reviewer reads the code and emails you. The queue has been long in 2026, so expect anything from one week to several. If they ask for changes:

- Reply to their email (don't submit again). Send me the email and I'll make the changes.
- Points a reviewer might raise, and the answers:
  - *Direct database queries:* LivePull's whole job is copying content between databases. Every query goes through `$wpdb->prepare()`, including table structures (rebuilt from data by `LivePull_Schema`) and backup restores.
  - *Connecting to another site:* it only connects to the live site that the user connects it to, using a key the user copies across. No third-party services.
  - *Files:* everything LivePull writes is in one protected folder in uploads (`livepull-<random>`). `LIVEPULL_BACKUP_DIR` lets users choose another place for backups.

## 4. After approval (10 minutes, once)

1. WordPress.org emails you an SVN address (`https://plugins.svn.wordpress.org/livepull`).
2. Create an **SVN password**: *Profile → Edit → Account & Security → SVN credentials → Generate Password*.
3. On GitHub: *WP-Sync-Tool → Settings → Secrets and variables → Actions → New repository secret*. Add:
   - `SVN_USERNAME`: your WordPress.org username
   - `SVN_PASSWORD`: the SVN password from step 2
4. *Actions → Release → Run workflow*. This publishes the current version, the banners, the icon and the screenshots in `.wordpress-org` to WordPress.org.

## From then on

Bump the version in `livepull.php`, `includes/class-config.php` and `readme.txt` (*Stable tag*), add a `CHANGELOG.md` entry, and push to `main`. GitHub tests it, publishes the GitHub release and updates WordPress.org automatically. WordPress.org holds new releases for up to 24 hours while its automatic security scan runs.

When a new WordPress version comes out, test it and update *Tested up to* in `readme.txt`. WordPress.org warns people about plugins that aren't kept up to date.
