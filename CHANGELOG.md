# Changelog

All notable changes to LivePull. Versions follow [semantic versioning](https://semver.org/).

## 1.2.2
Fixes from the 1.2.1 review.
- **Copy everything** is no longer blocked for good by a pull that was abandoned (for example a tab closed mid-pull). It takes over an abandoned lock the way Pull does.
- **The pull lock** only ever changes the exact lock it checked, so a takeover landing at the same moment is never overwritten or removed.
- **Full scans** send settings and categories once, not with every page of comments.
- **History, emails and bug reports** only hide real key endings ("…a1b2"), not ordinary words like "ending".
- **Updates** are only trusted from this repository's releases (and its planned new name), not any GitHub address.
- **Scheduled pulls** too big for one run are recorded as progress, not emailed as a failure; the next run carries on.
- **ACF options pages** are off by default since 1.2.1. If you pulled them on 1.2.0, switch them back on under Settings → What to pull.
- The README and readme.txt now say up front that conflicts are never overwritten and that live's content and settings are never changed.
- Tidier code comments, and tests for the update signature and version checks, scheduled runs, the expired uploads list and abandoned locks.

## 1.2.1
Fixes from the 1.2.0 review.
- **Comments:** Compare, "Use live version" and held-back comments now work (live's single-item route accepted posts, terms and settings only). Replies whose parent isn't local yet keep their threading.
- **Privacy:** anti-spam data (Akismet, Antispam Bee) and any comment field holding the commenter's IP address or browser never leave live. Live only sends comments to sites that pull them. Commenters' names and emails do come across, and the setting now says so.
- **Spam:** new spam comments add nothing to live's change log and get no ID.
- **One pull at a time, for real.** The lock is claimed in a single database step, each run holds its own token, and a run that loses the lock stops at its next step. *Unlock* only works once a run has gone quiet, so it can't start a second pull alongside a scheduled one. Restoring a backup and "Use live version" take the lock too. *Unlock and pull again* keeps what you ticked.
- **Updates:** the version inside a downloaded update must match the release it was offered as, and be newer than the installed copy, so an old signed release can't be passed off as new.
- **Schedules** come back by themselves after Copy everything or a restore, and Settings and the connection check say if one is on but not scheduled. A run that can't finish in one go says so and carries on next time. A failure is emailed once, not every hour. A skipped run (another pull running) no longer overwrites the last result.
- **Copy everything** retries listing live's uploads if the list expires part-way (media was skipped before).
- **Backups:** the newest 10 pull backups and 3 full-copy backups are kept separately, so frequent scheduled pulls can't push out the backup from before a full copy. A backup is refused, and nothing changed, if the disk is nearly full.
- **ACF options pages** start switched off (they can hold API keys or tracking scripts). The forms setting says recipients come across too.
- **Turning a group on** explains how to bring across what already exists. Full scans page through all comments instead of stopping at 5,000. Settings in a switched-off group are refused even if live sends them.
- **Comment imports** accept only ordinary comment kinds (never order notes or logs), clean the commenter's name, email, website and the comment text, and link to a local user only when login and email both match.
- **Connection check** sends the staging password (LIVEPULL_REMOTE_BASIC_AUTH) to live, and only to live.
- **Privacy in history, emails and bug reports:** signed CDN/S3 links lose their query strings and key endings are hidden. The report note names the live address it includes. WordPress.org copies point to the support forum instead of GitHub.
- Comments show properly in the change lists and conflict links. "View details" shows the release notes as formatted text. PHP 8.4 deprecation fixed. The release workflow pins PHP with sodium, and SECURITY.md covers signed updates and key rotation.
- "Nothing is ever written to the live site" was wrong: LivePull never changes live's content or settings, but keeps its own change log, ID tags and a daily clean-up there. The wording now says that.

## 1.2.0
- **A simpler screen.** One main *Check for changes* button, a plain status line ("Connected to … · last pulled … · 3 changes waiting"), and tabs: Pull, Conflicts, History, Copy everything, Backups, Settings. New sites ask "Which site is this?" once, the live site gets a short screen with just its connection code, and a local copy gets a three-step connect guide and a first-run explainer.
- **Choose what to pull.** *Check for changes* lists every change with a tick box, grouped by what would happen and filterable by kind (Pages, Products, Settings…). Unticked items are held back and offered again in later checks; *Forget* drops them. Terminal: `wp livepull pull --exclude=… --include-held=…`, `wp livepull held`.
- **Compare** a conflict with the live version side by side before choosing.
- **History** of the last 30 pulls, scheduled pulls, full copies and restores, with what each brought across (`wp livepull history`).
- **Scheduled pulls** hourly, twice daily, daily or weekly, with an email when something came across or needs a decision (`wp livepull schedule`).
- **Fix it.** When live can't be reached, a connection check goes through each step (address, firewall or CDN, REST API, caching, key, versions) and says how to fix what failed (`wp livepull doctor`). *Report a problem* gathers your set-up and recent pulls (no keys) and opens a pre-filled GitHub issue (`wp livepull report`).
- **Optional groups** under Settings (`wp livepull groups`): page-builder templates (Divi Library and Theme Builder, Elementor, Beaver Builder, Bricks), Contact Form 7 and WPForms forms, comments and product reviews (never spam, never commenters' IP addresses), and ACF options pages. Shortcodes and blocks that show a form or template point at the local copy. Elementor's site kit is never pulled.
- Media kept on a CDN or S3 (WP Offload Media and similar) falls back to the normal uploads address.
- **Signed updates.** Updates from GitHub must carry a signature from the release workflow as well as a matching checksum, so a swapped download can't install.

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
