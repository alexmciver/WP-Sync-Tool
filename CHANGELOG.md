# Changelog

All notable changes to WP Sync. Versions follow [semantic versioning](https://semver.org/); while in 0.x, minor versions may add features and change behaviour.

## 0.5.1
- Released as open source under GPL-2.0-or-later.
- Plugin headers declare requirements (WordPress 5.8+, PHP 7.4+), licence and links.
- New README, changelog, security policy, contributing guide and issue templates.

## 0.5.0 – security & usability
- **Security:** backups moved outside the public web folder where possible (one folder per site), with unguessable names and deny rules everywhere else; existing backups are migrated.
- **Security:** files from live are never written as executable or server-config files; media is only downloaded from the live site or public addresses (private, reserved and cloud-metadata addresses are refused).
- **Security:** *Copy everything* is off by default on live and can be allowed for 1 hour or always; logged-in sessions are never copied.
- **Security:** warning when the key would travel over plain HTTP to a public host; updates only installed from GitHub over HTTPS; deleting the plugin removes its settings, log, ID tags and backups.
- Faster pulls on big sites (lookups built once per request, untagged-content scan throttled).
- Live tidies its change log daily (180 days by default); local catches up automatically if it falls behind.
- *Copy everything* backs up the local database in steps, so large databases can't time out.
- One-paste **connection code**; last pull summary; "What gets pulled?" panel; backup labels; duplicate-install warning.

## 0.4.2
- Fixed a false "fresh copy" detection when a site was loaded over both http and https, which could make pulls report "nothing to pull".
- Pulls recover automatically if this site is ever out of step with live's change list.
- Live shows **Recent changes recorded on this site**.

## 0.4.1
- Menus (items, order, nesting, links) and menu locations are pulled.
- Site title, tagline, homepage/posts page, sticky posts, site icon and logo are pulled.
- Fixed deletions of items with extra data (most pages and menu items) not reaching local.

## 0.4.0
- **Copy everything from live**: full database + uploads copy with safe URL rewriting, prefix handling, safe cancel and automatic database update.

## 0.3.x
- Updates from GitHub releases with an **Update now** button (0.3.0–0.3.1); update check shows real errors and falls back to github.com (0.3.3).
- Existing local pages, media and terms are matched instead of duplicated; **Undo a pull** (0.3.2).

## 0.2.x
- **Tools → WP Sync** admin screen; works as a normal or must-use plugin; batched pulls with progress, cancel and resume; cache-proof connection; **Pull all content from live**.

## 0.1.0
- First version: change log on live, `wp sync pull` with conflict detection, ID remapping, media download and backups.
