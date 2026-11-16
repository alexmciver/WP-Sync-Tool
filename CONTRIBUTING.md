# Contributing to WP Sync

Thanks for helping! Bug reports, ideas and pull requests are all welcome.

## Reporting a bug

Open an issue with the **Bug report** template and include:
- WP Sync, WordPress and PHP versions on **both** sites
- What you did, what you expected, and what happened (error messages help a lot)
- Relevant plugins or page builders (e.g. Divi, Elementor, ACF, WooCommerce)

Security problems: please report privately instead – see [SECURITY.md](SECURITY.md).

## Pull requests

- Keep changes focused; one fix or feature per PR.
- Follow the existing style (WordPress coding standards, PHP 7.4 compatible, no build step).
- Never write to the live site from the pull side, and keep everything that changes the local site backed up and undoable.
- Run `php -l` on changed files. If you can, test a pull and a full copy between two sites (for example with Local or `wp server`).
- Describe how you tested it.

## Releasing (maintainers)

Bump the version in **both** `wp-sync.php` (`Version:`) and `wp-sync/includes/class-config.php` (`VERSION`), add a `CHANGELOG.md` entry, and push to `main`. The GitHub Action builds `wp-sync.zip` and publishes the release; sites see it as an update.
