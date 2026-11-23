# Security policy

LivePull moves site data between WordPress installs, so security reports are taken seriously.

## Reporting a vulnerability

Please **don't open a public issue**. Report it privately through GitHub: on this repository, go to **Security → Report a vulnerability** ([direct link](https://github.com/alexmciver/WP-Sync-Tool/security/advisories/new)).

Include what you found, how to reproduce it, the versions involved (LivePull, WordPress, PHP) and the impact you expect. You'll get a reply as soon as possible, and credit in the release notes if you'd like it.

## Supported versions

Only the latest release receives security fixes. Update from **Tools → LivePull → Check for updates**.

## Design notes

- The live site only answers read-only requests authenticated with a shared key (32+ characters, compared in constant time). Pulls never change live's content or settings. On live, LivePull itself keeps only its own change log table, an ID tag on each post, term and comment, its own settings and a daily clean-up task.
- *Copy everything* is opt-in on live and can be time-limited. Sessions are never copied.
- Backups are stored in a protected LivePull folder in uploads (unguessable name, deny rules, and files that stop if requested over the web). They store tables as data, never as SQL, and restores rebuild them with prepared queries only.
- Pulled HTML follows WordPress's `unfiltered_html` rule for the person pulling: kept as live has it for administrators (as if they had saved it themselves), and run through `wp_kses_post()` for everyone else, including page-builder data, custom fields and settings. LivePull never asks anyone to enter code. Scheduled pulls run as the administrator who set them up.
- Files received from live are never written as executable or server-config files, and media is never downloaded from private, reserved or link-local addresses.
- Remote data is only unserialized with `allowed_classes` limited to `stdClass` (a plain data object some page builders store, with no methods or magic behaviour), so no plugin or WordPress classes can be created from it. Raw meta values are copied byte for byte without unserializing.

## Updates from GitHub

The GitHub download (`livepull.zip`) updates itself from this repository's releases. An update only installs if all of these hold:

- it comes from this repository's releases over HTTPS;
- it matches its published SHA-256 checksum;
- it carries a valid Ed25519 signature (`livepull.zip.sig`) from the release workflow, checked against the public key built into the installed copy (`LivePull_Updater::SIGNING_KEY`);
- the version inside the zip is the version it was offered as, and newer than the installed one, so an old signed release can't be passed off as an update.

The private key is the repository secret `LIVEPULL_SIGNING_KEY`. If it is missing, releases stop at the signing step and nothing is published. The signature protects against tampered release files; it can't protect against someone who can change the repository's workflows, since they could use the secret. To narrow that, keep `main` and tags protected, and consider moving the secret into a GitHub Environment that needs approval.

**Rotating the key.** Installed copies trust only the public key they shipped with. To change keys, release one version signed with the old key that contains the new public key, then switch the secret. If the old private key is lost, sites can't auto-update and need the new version uploaded by hand. Keep a copy of the key somewhere safe.

The WordPress.org version has no self-updater; WordPress.org delivers its updates.
