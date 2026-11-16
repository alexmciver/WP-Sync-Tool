#!/usr/bin/env bash
# Build the two LivePull downloads into dist/:
#
#   livepull.zip                  GitHub download: updates itself from GitHub releases.
#   livepull-wordpress-org.zip    WordPress.org version: no self-updater (WordPress.org
#                                 handles updates), no "Update URI" header, no dev files.
#   wp-sync.zip                   Placeholder for sites on the old WP Sync name.
#
# Usage: bin/build.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
rm -rf "$DIST" && mkdir -p "$DIST"

build() { # edition
	local edition=$1 dir="$DIST/$1/livepull"
	mkdir -p "$dir"
	cp -r "$ROOT/livepull.php" "$ROOT/uninstall.php" "$ROOT/readme.txt" "$ROOT/includes" "$ROOT/assets" "$dir/"
	cp "$ROOT/LICENSE" "$dir/license.txt"
	if [ "$edition" = "wordpress-org" ]; then
		rm "$dir/includes/class-updater.php"
		sed -i '/^ \* Update URI:/d' "$dir/livepull.php"
	else
		cp "$ROOT/README.md" "$ROOT/CHANGELOG.md" "$dir/"
	fi
	find "$dir" -name '.*' -prune -exec rm -rf {} +
	(cd "$DIST/$edition" && zip -qr "$DIST/$2" livepull)
	rm -rf "$DIST/$edition"
	echo "Built dist/$2"
}

for f in "$ROOT/livepull.php" "$ROOT/uninstall.php" "$ROOT"/includes/*.php; do php -l "$f" >/dev/null; done
build github livepull.zip
build wordpress-org livepull-wordpress-org.zip

# For sites still on WP Sync 0.x (the old name): their updater looks for wp-sync.zip. This
# placeholder replaces WP Sync's code (not its data) and explains how to switch to LivePull.
mkdir -p "$DIST/bridge/wp-sync" && cp "$ROOT/bin/bridge/wp-sync.php" "$DIST/bridge/wp-sync/"
(cd "$DIST/bridge" && zip -qr "$DIST/wp-sync.zip" wp-sync) && rm -rf "$DIST/bridge"
echo "Built dist/wp-sync.zip"
