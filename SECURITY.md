# Security policy

LivePull moves site data between WordPress installs, so security reports are taken seriously.

## Reporting a vulnerability

Please **don't open a public issue**. Report it privately through GitHub: on this repository, go to **Security → Report a vulnerability** ([direct link](https://github.com/alexmciver/WP-Sync-Tool/security/advisories/new)).

Include what you found, how to reproduce it, the versions involved (LivePull, WordPress, PHP) and the impact you expect. You'll get a reply as soon as possible, and credit in the release notes if you'd like it.

## Supported versions

Only the latest release receives security fixes. Update from **Tools → LivePull → Check for updates**.

## Design notes

- The live site only answers read-only requests authenticated with a shared key (32+ characters, compared in constant time). Nothing is written to the live site.
- *Copy everything* is opt-in on live and can be time-limited. Sessions are never copied.
- Backups are stored outside the web root where possible, otherwise with deny rules and unguessable names.
- Files received from live are never written as executable or server-config files, and media is never downloaded from private, reserved or link-local addresses.
- Remote data is only unserialized with `allowed_classes` limited to `stdClass` (a plain data object some page builders store, with no methods or magic behaviour), so no plugin or WordPress classes can be created from it. Raw meta values are copied byte for byte without unserializing.
