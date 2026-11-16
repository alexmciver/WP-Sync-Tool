#!/usr/bin/env bash
# WP Sync end-to-end test suite.
#
# Builds two real WordPress sites ("live" and "local", with different table prefixes and
# different IDs), installs WP Sync and popular plugins/page builders on both, then for each
# scenario in tests/scenarios: creates content on live → pulls → checks it on local
# → changes it on live → pulls → checks again. Finally runs "Copy everything" and checks
# the copied site renders.
#
# Requirements: PHP 7.4+, MySQL/MariaDB, WP-CLI (`wp`), zip/unzip.
# Env:
#   WPSYNC_TEST_DIR      work dir (default /tmp/wpsync-test)
#   WPSYNC_TEST_PLUGINS  folder of plugin zips to install (default /tmp/plugins)
#   WPSYNC_TEST_CORE     an unpacked WordPress core to copy (default: wp core download)
#   DB_USER / DB_PASS / DB_HOST  (default root / "" / 127.0.0.1)
# Usage: tests/run.sh [scenario-name ...]     e.g. tests/run.sh elementor divi
set -uo pipefail
export WP_CLI_ALLOW_ROOT=1

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${WPSYNC_TEST_DIR:-/tmp/wpsync-test}"
PLUGINS="${WPSYNC_TEST_PLUGINS:-/tmp/plugins}"
DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; DB_HOST="${DB_HOST:-127.0.0.1}"
LIVE_PORT=8101; LOCAL_PORT=8102
LIVE="$WORK/live"; LOCAL="$WORK/local"; STATE="$WORK/state"
W_LIVE="wp --path=$LIVE --quiet"; W_LOCAL="wp --path=$LOCAL --quiet"
export WPSYNC_TEST_STATE="$STATE" WPSYNC_TEST_LIB="$ROOT/tests/lib.php"

say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
mysql_q() { mysql -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} -h"$DB_HOST" -e "$1"; }

setup_site() { # dir db prefix port title
	local dir=$1 db=$2 prefix=$3 port=$4 title=$5
	mysql_q "DROP DATABASE IF EXISTS $db; CREATE DATABASE $db;"
	rm -rf "$dir"
	if [ -n "${WPSYNC_TEST_CORE:-}" ]; then cp -r "$WPSYNC_TEST_CORE" "$dir"; else wp core download --path="$dir" --quiet; fi
	wp config create --path="$dir" --dbname="$db" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --dbprefix="$prefix" --quiet --skip-check
	wp core install --path="$dir" --url="http://localhost:$port" --title="$title" --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
	wp rewrite structure '/%postname%/' --path="$dir" --quiet
	for z in "$PLUGINS"/*.zip; do [ -e "$z" ] && unzip -qo "$z" -d "$dir/wp-content/plugins/"; done
	mkdir -p "$dir/wp-content/plugins/wp-sync"
	cp -r "$ROOT/wp-sync.php" "$ROOT/wp-sync" "$ROOT/uninstall.php" "$dir/wp-content/plugins/wp-sync/"
	# Classic menu locations for the menu scenario.
	mkdir -p "$dir/wp-content/mu-plugins"
	echo "<?php add_action( 'after_setup_theme', function () { register_nav_menus( array( 'primary' => 'Primary' ) ); } );" > "$dir/wp-content/mu-plugins/test-menus.php"
	cat > "$dir/wp-content/mu-plugins/test-acf.php" <<'PHP'
<?php add_action( 'acf/init', function () {
	acf_add_local_field_group( array( 'key' => 'group_t', 'title' => 'Test', 'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ), 'fields' => array(
		array( 'key' => 'field_t_hero', 'name' => 'hero', 'label' => 'Hero', 'type' => 'image', 'return_format' => 'id' ),
		array( 'key' => 'field_t_related', 'name' => 'related', 'label' => 'Related', 'type' => 'relationship' ),
		array( 'key' => 'field_t_topics', 'name' => 'topics', 'label' => 'Topics', 'type' => 'taxonomy', 'taxonomy' => 'category', 'field_type' => 'multi_select' ),
	) ) );
} );
PHP
	for pl in $(wp plugin list --path="$dir" --status=inactive --field=name 2>/dev/null); do
		[ "$pl" = "hello" ] || [ "$pl" = "akismet" ] && continue
		wp plugin activate "$pl" --path="$dir" --quiet 2>&1 | sed "s#^#  [setup $title] #" || true
	done
	wp plugin is-active wp-sync --path="$dir" || { echo "WP Sync failed to activate on $title"; exit 1; }
	# Builders that ship their own "getting started" redirects/notices: keep them quiet.
	wp option update elementor_onboarded 1 --path="$dir" --quiet 2>/dev/null || true
	(cd "$dir" && nohup php -S "localhost:$port" > "$WORK/$port.log" 2>&1 &)
}

stop_servers() { pkill -f "php -S localhost:$LIVE_PORT" 2>/dev/null; pkill -f "php -S localhost:$LOCAL_PORT" 2>/dev/null; true; }

scenarios=()
if [ $# -gt 0 ]; then for s in "$@"; do scenarios+=("$ROOT/tests/scenarios/$s.php"); done
else for s in "$ROOT"/tests/scenarios/*.php; do scenarios+=("$s"); done; fi

mkdir -p "$WORK"; rm -rf "$STATE"; mkdir -p "$STATE"
stop_servers
say "Setting up live (wp_) and local (loc_) sites"
mysql_q "SELECT 1" >/dev/null || { echo "Can't connect to MySQL as $DB_USER@$DB_HOST (set DB_USER / DB_PASS / DB_HOST)."; exit 1; }
setup_site "$LIVE" wpsync_live wp_ $LIVE_PORT "Live site"
setup_site "$LOCAL" wpsync_local loc_ $LOCAL_PORT "Local site"
sleep 1

say "Connecting local to live, then making local a full copy"
CODE=$(wp --path="$LIVE" sync key | grep -o 'wpsync1:[A-Za-z0-9+/=]*')
$W_LIVE option update wpsync_allow_full_copy always
$W_LOCAL sync connect "$CODE" >/dev/null
wp --path="$LOCAL" sync clone --yes --skip-files | tail -1
# After a copy, local's users are live's: same login (admin/admin).
# Local-only content so new live items get DIFFERENT IDs here.
for i in $(seq 1 25); do $W_LOCAL post create --post_title="Local only $i" --post_status=draft; done
for i in 1 2 3; do $W_LOCAL term create category "Local cat $i"; done

pass=0; fail=0
run_phase() { # site phase
	local site=$1 phase=$2
	for s in "${scenarios[@]}"; do
		out=$(wp --path="$site" eval-file "$s" "$phase" 2>&1)
		[ -n "$out" ] && echo "$out" | sed "s#^#  [$(basename "$s" .php)] #"
	done
}
count() {
	pass=$((pass + $(grep -c '^  \[.*\] PASS' "$1" || true)))
	fail=$((fail + $(grep -c '^  \[.*\] FAIL' "$1" || true)))
	# A scenario that crashed or couldn't run counts as a failure too.
	fail=$((fail + $(grep -c '^  \[.*\] \(Error\|PHP Fatal\|Fatal error\|PHP Warning\)' "$1" || true)))
}

say "1. Creating content on live"
run_phase "$LIVE" create | tee "$WORK/out-create.txt" | grep -v PASS || true
say "   Pulling"
wp --path="$LOCAL" sync pull --skip-backup 2>&1 | tail -3
say "   Checking on local"
run_phase "$LOCAL" verify | tee "$WORK/out-verify.txt"; count "$WORK/out-verify.txt"

say "2. Changing content on live (edits, deletions, conflicts)"
run_phase "$LOCAL" local_change > /dev/null
run_phase "$LIVE" change | grep -v PASS || true
wp --path="$LOCAL" sync pull --skip-backup 2>&1 | tail -3
say "   Checking on local"
run_phase "$LOCAL" verify_change | tee "$WORK/out-change.txt"; count "$WORK/out-change.txt"

say "3. Copy everything from live (full copy) and render check"
wp --path="$LOCAL" sync clone --yes 2>&1 | tail -1
run_phase "$LOCAL" verify_full | tee "$WORK/out-full.txt"; count "$WORK/out-full.txt"

say "Result: $pass passed, $fail failed"
[ "${WPSYNC_TEST_KEEP:-}" = "1" ] || stop_servers
[ "$pass" -gt 0 ] && [ "$fail" -eq 0 ]
