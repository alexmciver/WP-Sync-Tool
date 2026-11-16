#!/usr/bin/env bash
# LivePull big-site test: builds a live site with lots of content and a separate local
# site, then times a first pull, an incremental pull and "Copy everything", and reports
# peak memory. Use it to check LivePull copes with large sites.
#
# Env: LIVEPULL_PERF_POSTS (default 10000), LIVEPULL_PERF_IMAGES (default 1500),
#      LIVEPULL_PERF_TERMS (default 300), LIVEPULL_TEST_CORE, DB_USER / DB_PASS / DB_HOST
# Usage: tests/perf.sh
set -uo pipefail
export WP_CLI_ALLOW_ROOT=1
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK=/tmp/livepull-perf
POSTS="${LIVEPULL_PERF_POSTS:-10000}"; IMAGES="${LIVEPULL_PERF_IMAGES:-1500}"; TERMS="${LIVEPULL_PERF_TERMS:-300}"
DB_USER="${DB_USER:-root}"; DB_PASS="${DB_PASS:-}"; DB_HOST="${DB_HOST:-127.0.0.1}"
mysql_q() { mysql -u"$DB_USER" ${DB_PASS:+-p"$DB_PASS"} -h"$DB_HOST" -e "$1"; }
say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
timed() { # label, command...  → prints seconds and peak memory of the command
	local label=$1; shift
	local start end
	start=$(date +%s.%N)
	/usr/bin/time -f "%M" -o "$WORK/mem" "$@" 2>&1 | tail -2
	end=$(date +%s.%N)
	printf '  %-34s %6.1f s   peak memory %4d MB\n' "$label" "$(echo "$end - $start" | bc)" "$(( $(tail -1 "$WORK/mem") / 1024 ))" | tee -a "$WORK/results.txt"
}

site() { # dir db prefix port
	mysql_q "DROP DATABASE IF EXISTS $2; CREATE DATABASE $2;"
	rm -rf "$1"
	if [ -n "${LIVEPULL_TEST_CORE:-}" ]; then cp -r "$LIVEPULL_TEST_CORE" "$1"; else wp core download --path="$1" --quiet; fi
	wp config create --path="$1" --dbname="$2" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost="$DB_HOST" --dbprefix="$3" --quiet --skip-check
	wp core install --path="$1" --url="http://localhost:$4" --title=Perf --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email --quiet
	wp rewrite structure '/%postname%/' --path="$1" --quiet
	# The test web server handles one request at a time: keep WordPress's background tasks
	# (which call wordpress.org) from queueing in front of LivePull's requests.
	wp config set DISABLE_WP_CRON true --raw --path="$1" --quiet
	mkdir -p "$1/wp-content/plugins/livepull"
	cp -r "$ROOT/livepull.php" "$ROOT/includes" "$ROOT/assets" "$ROOT/uninstall.php" "$1/wp-content/plugins/livepull/"
	wp plugin activate livepull --path="$1" --quiet
	(cd "$1" && nohup php -S "localhost:$4" > "$WORK/$4.log" 2>&1 &)
}

pkill -f "php -S localhost:811[12]" 2>/dev/null
mkdir -p "$WORK"; : > "$WORK/results.txt"
say "Building live: $POSTS posts, $IMAGES images, $TERMS categories"
site "$WORK/live" lp_perf_live wp_ 8111
site "$WORK/local" lp_perf_local loc_ 8112
cat > "$WORK/gen.php" <<'PHP'
<?php
// Bulk content for the big-site test.
list( $posts, $images, $terms ) = array_map( "intval", $args );
wp_defer_term_counting( true );
$cats = array();
for ( $i = 1; $i <= $terms; $i++ ) {
	$t = wp_insert_term( "Category $i", 'category' );
	$cats[] = $t['term_id'];
}
$up  = wp_upload_dir();
$ids = array();
$img = imagecreatetruecolor( 400, 300 );
for ( $i = 1; $i <= $images; $i++ ) {
	imagefill( $img, 0, 0, imagecolorallocate( $img, $i % 255, ( $i * 7 ) % 255, ( $i * 13 ) % 255 ) );
	$file = $up['path'] . "/perf-$i.jpg";
	imagejpeg( $img, $file, 70 );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => "Perf image $i", 'post_status' => 'inherit' ), $file );
	update_post_meta( $id, '_wp_attached_file', _wp_relative_upload_path( $file ) );
	update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => 400, 'height' => 300, 'file' => _wp_relative_upload_path( $file ), 'sizes' => array() ) );
	$ids[] = $id;
}
$para = str_repeat( 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 20 );
for ( $i = 1; $i <= $posts; $i++ ) {
	$a = $ids ? $ids[ $i % count( $ids ) ] : 0;
	$content = "<!-- wp:paragraph --><p>$para</p><!-- /wp:paragraph -->";
	if ( $a ) {
		$content .= '<!-- wp:image {"id":' . $a . '} --><figure class="wp-block-image"><img src="' . wp_get_attachment_url( $a ) . '" class="wp-image-' . $a . '"/></figure><!-- /wp:image -->';
	}
	$p = wp_insert_post( array( 'post_title' => "Perf post $i", 'post_status' => 'publish', 'post_content' => $content, 'post_category' => array( $cats[ $i % count( $cats ) ] ) ) );
	if ( $a ) {
		set_post_thumbnail( $p, $a );
	}
	update_post_meta( $p, 'perf_field', str_repeat( 'x', 200 ) );
	if ( 0 === $i % 1000 ) {
		wp_cache_flush();
		echo "  $i posts\n";
	}
}
wp_defer_term_counting( false );
PHP
timed "create content on live" wp --path="$WORK/live" eval-file "$WORK/gen.php" "$POSTS" "$IMAGES" "$TERMS"
du -sh "$WORK/live/wp-content/uploads" | sed 's/^/  uploads: /'

say "Connecting"
CODE=$(wp --path="$WORK/live" livepull key | grep -o 'livepull1:[A-Za-z0-9+/=]*')
wp --path="$WORK/local" livepull connect "$CODE" >/dev/null

say "First pull (everything)"
timed "first pull" wp --path="$WORK/local" livepull pull --skip-backup
echo "  local posts: $(wp --path="$WORK/local" post list --post_type=post --format=count), images: $(wp --path="$WORK/local" post list --post_type=attachment --format=count), files: $(find "$WORK/local/wp-content/uploads" -name 'perf-*.jpg' | wc -l)"

say "Incremental pull (100 edits, 20 new posts, 10 deletions)"
wp --path="$WORK/live" eval '
$ids = get_posts( array( "numberposts" => 130, "fields" => "ids", "orderby" => "ID", "order" => "ASC" ) );
foreach ( array_slice( $ids, 0, 100 ) as $id ) { wp_update_post( array( "ID" => $id, "post_title" => "Edited " . $id ) ); }
foreach ( array_slice( $ids, 100, 10 ) as $id ) { wp_delete_post( $id, true ); }
for ( $i = 0; $i < 20; $i++ ) { wp_insert_post( array( "post_title" => "New perf post $i", "post_status" => "publish" ) ); }'
timed "incremental pull (incl. backup)" wp --path="$WORK/local" livepull pull

say "Copy everything"
wp --path="$WORK/live" option update livepull_allow_full_copy always --quiet
timed "copy everything (with files)" wp --path="$WORK/local" livepull clone --yes
echo "  local posts after copy: $(wp --path="$WORK/local" post list --post_type=post --format=count)"

say "Results"
cat "$WORK/results.txt"
pkill -f "php -S localhost:811[12]" 2>/dev/null
