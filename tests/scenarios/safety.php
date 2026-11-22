<?php
// Safety checks: what a compromised or spoofed live site must NOT be able to do to the local
// site, redirects can't leak the key or reach internal addresses, backups restore all or
// nothing, and Copy everything leaves no staged files behind.
require getenv( 'LIVEPULL_TEST_LIB' );
$phase = isset( $args[0] ) ? $args[0] : '';

if ( 'verify' === $phase ) {
	global $wpdb;
	$client = new LivePull_Client( LivePull_Config::remote(), LivePull_Config::remote_secret() );
	$remote = $client->get( 'status' );
	t_ok( is_array( $remote ), 'live answers' );

	// --- Spoofed items: settings, post types and taxonomies that must never be written.
	$item_opt = function ( $name, $value ) {
		return array( 'type' => 'option', 'sync_id' => LivePull_Config::option_sid( $name ), 'option' => array( 'name' => $name, 'mode' => 'whole', 'value' => maybe_serialize( $value ) ) );
	};
	$mods_name = 'theme_mods_' . get_option( 'stylesheet' );
	$mods      = get_option( $mods_name, array() );
	$before    = array(
		'users_can_register' => get_option( 'users_can_register' ),
		'default_role'       => get_option( 'default_role' ),
		'active_plugins'     => get_option( 'active_plugins' ),
		'siteurl'            => get_option( 'siteurl' ),
	);
	$items = array(
		$item_opt( 'users_can_register', '1' ),
		$item_opt( 'default_role', 'administrator' ),
		$item_opt( 'active_plugins', array( 'evil/evil.php' ) ),
		$item_opt( 'siteurl', 'https://evil.example' ),
		$item_opt( 'some_unlisted_option', 'x' ),
		array( 'type' => 'option', 'sync_id' => LivePull_Config::option_sid( 'blogname' ), 'option' => array( 'name' => 'users_can_register', 'mode' => 'whole', 'value' => '1' ) ), // name swapped under an allowed ID
		array( 'type' => 'option', 'sync_id' => LivePull_Config::option_sid( $mods_name ), 'option' => array( 'name' => $mods_name, 'mode' => 'whole', 'value' => maybe_serialize( array( 'custom_css_post_id' => 999, 'evil_key' => 'x' ) ) ) ),
		array( 'type' => 'post', 'sync_id' => md5( 'spoof-changeset' ), 'post' => array( 'post_type' => 'customize_changeset', 'post_title' => 'Spoofed changeset', 'post_status' => 'publish' ) ),
		array( 'type' => 'term', 'sync_id' => md5( 'spoof-term' ), 'term' => array( 'taxonomy' => 'wp_theme', 'name' => 'Spoofed', 'slug' => 'spoofed' ) ),
		array( 'type' => 'user', 'sync_id' => md5( 'spoof-user' ), 'user' => array( 'user_login' => 'evil' ) ),
	);
	$imp    = new LivePull_Importer( $client, $remote, array() );
	$report = $imp->run( $items );
	wp_cache_flush();
	foreach ( $before as $name => $value ) {
		t_eq( get_option( $name ), $value, "spoofed live can't change $name" );
	}
	t_ok( false === get_option( 'some_unlisted_option' ), "spoofed live can't add other settings" );
	$mods_after = get_option( $mods_name, array() );
	t_ok( ! isset( $mods_after['evil_key'] ) && ( isset( $mods['custom_css_post_id'] ) ? $mods_after['custom_css_post_id'] === $mods['custom_css_post_id'] : ! isset( $mods_after['custom_css_post_id'] ) ), 'theme settings: only menu locations and logo can be pulled' );
	t_eq( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = 'Spoofed changeset'" ), 0, "spoofed live can't create excluded post types" );
	t_ok( ! term_exists( 'spoofed', 'wp_theme' ), "spoofed live can't create terms in excluded taxonomies" );
	t_ok( ! username_exists( 'evil' ), 'unknown kinds of item are ignored' );
	t_ok( count( $report['skipped'] ) >= 9, 'refused items are reported (' . count( $report['skipped'] ) . ' skipped)' );
	// WordPress's HTML filter (kses) is never switched off by a pull: it stays as WordPress set it
	// for the person running the pull.
	$admins    = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$admin_id  = (int) $admins[0];
	$author_id = wp_insert_user( array( 'user_login' => 'lp_author', 'user_pass' => wp_generate_password(), 'role' => 'author' ) );
	wp_set_current_user( $author_id );
	( new LivePull_Importer( $client, $remote, array() ) )->run( array() );
	t_ok( false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' ), "pulls by someone without unfiltered-HTML permission keep WordPress's HTML filter on" );
	$evil = '<p>Hi</p><script>alert(1)</script><a href="javascript:alert(1)" onclick="x()">x</a>';
	$kept = LivePull_Importer::html( $evil );
	t_ok( false === stripos( $kept, '<script' ) && false === stripos( $kept, 'javascript:' ) && false === stripos( $kept, 'onclick' ) && false !== strpos( $kept, '<p>Hi</p>' ), 'content written after ID mapping is filtered for users without unfiltered-HTML permission' );
	wp_set_current_user( $admin_id );
	( new LivePull_Importer( $client, $remote, array() ) )->run( array() );
	t_ok( false === has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'an administrator\'s pull saves content as live has it (filter off, as WordPress decides)' );
	t_eq( LivePull_Importer::html( $evil ), $evil, "an administrator's content written after ID mapping is kept as live has it" );
	wp_set_current_user( 0 );
	wp_delete_user( $author_id );

	// --- URL rewriting stops at the end of the address.
	$f = array( 'https://site.co' );
	$t = array( 'http://local.test' );
	t_eq( LivePull_Serial::text( 'a https://site.co/x b https://site.co.uk/y c https://site.co:8080/z', $f, $t ), 'a http://local.test/x b https://site.co.uk/y c https://site.co:8080/z', 'URL rewriting leaves look-alike domains and other ports alone' );

	// --- Only LivePull's own REST routes accept the key.
	$ours = new ReflectionMethod( 'LivePull_Rest', 'is_ours' );
	$ours->setAccessible( true );
	$saved = array( $_SERVER['REQUEST_URI'] ?? null, $_GET );
	$_GET  = array();
	$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users?x=/livepull/v1/status';
	$a = $ours->invoke( null );
	$_SERVER['REQUEST_URI'] = '/wp-json/livepull/v1/status';
	$b = $ours->invoke( null );
	list( $_SERVER['REQUEST_URI'], $_GET ) = $saved;
	t_ok( ! $a && $b, 'the key only opens LivePull\'s own routes, not others with LivePull in the query string' );

	// --- Tables from live (or a backup) are rebuilt from data: nothing in it can run as SQL.
	$tn    = $wpdb->prefix . 'lpulltmp_x';
	$base  = array( 'engine' => 'InnoDB', 'keys' => array() );
	$col   = function ( $type, $extra = array() ) {
		return array_merge( array( 'name' => 'a', 'type' => $type, 'collation' => '', 'null' => true, 'default' => null, 'extra' => '' ), $extra );
	};
	$fine  = LivePull_Schema::create( $tn, $base + array( 'columns' => array( $col( 'varchar(40)', array( 'default' => "select'; DROP TABLE x; --" ) ) ) ) );
	$kept  = $wpdb->get_var( $wpdb->prepare( 'SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', DB_NAME, $tn ) );
	$evil1 = LivePull_Schema::create( $tn, $base + array( 'columns' => array( $col( 'int) ENGINE=FEDERATED CONNECTION=\'mysql://x\' -- ' ) ) ) );
	$evil2 = LivePull_Schema::create( $tn, $base + array( 'columns' => array( $col( 'int' ) ), 'engine' => "InnoDB DATA DIRECTORY='/tmp'" ) );
	$eng   = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', DB_NAME, $tn ) );
	$evil3 = LivePull_Schema::create( $tn, $base + array( 'columns' => array( $col( 'int', array( 'name' => 'a` int, b int) --x' ) ) ) ) );
	$cols3 = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $tn ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $tn ) );
	t_ok( true === $fine && false !== strpos( (string) $kept, 'DROP TABLE' ), 'a quote-laden default is stored as plain text, never run' );
	t_ok( is_wp_error( $evil1 ), 'a column type with SQL in it is refused' );
	t_ok( true === $evil2 && 'InnoDB' === $eng, 'an unknown storage engine (or options after it) falls back to InnoDB' );
	t_ok( true === $evil3 && array( 'a` int, b int) --x' ) === $cols3, 'a column name with SQL in it is just an odd name' );

	// --- Redirects: the key never leaves the live site, downloads can't be bounced inward.
	$seen = array();
	$fake = function ( $pre, $args, $url ) use ( &$seen ) {
		$seen[] = array( $url, isset( $args['headers']['X-LivePull-Key'] ) );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		if ( false !== strpos( $url, 'redirect-me' ) ) {
			$to = false !== strpos( $url, 'status' ) ? 'https://evil.example/steal' : 'http://169.254.169.254/latest/meta-data/';
			return array( 'response' => array( 'code' => 302, 'message' => 'Found' ), 'headers' => array( 'location' => $to ), 'body' => '', 'cookies' => array() );
		}
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array(), 'body' => '{}', 'cookies' => array(), 'host' => $host );
	};
	add_filter( 'pre_http_request', $fake, 10, 3 );
	$r = ( new LivePull_Client( LivePull_Config::remote() . '/redirect-me', 'k' . str_repeat( 'x', 40 ) ) )->get( 'status' );
	$leak = array_filter( $seen, function ( $s ) {
		return false !== strpos( $s[0], 'evil.example' );
	} );
	t_ok( is_wp_error( $r ) && 'livepull_redirect' === $r->get_error_code() && ! $leak, 'a redirect to another site stops the request; the key is never sent there' );
	$seen = array();
	$r    = $client->download( 'http://93.184.216.34/redirect-me/image.jpg', wp_get_upload_dir()['basedir'] . '/livepull-redirect-test.jpg' );
	$inner = array_filter( $seen, function ( $s ) {
		return false !== strpos( $s[0], '169.254.169.254' );
	} );
	t_ok( is_wp_error( $r ) && ! $inner, 'a download redirected to an internal address is refused before it is requested' );
	remove_filter( 'pre_http_request', $fake, 10 );

	// --- Backups: all-or-nothing restore; the backup being restored is never pruned.
	$p    = wp_insert_post( array( 'post_title' => 'Restore check', 'post_status' => 'draft' ) );
	$file = LivePull_Backup::create();
	t_ok( ! is_wp_error( $file ), 'backup created' );
	$dump = implode( '', '.gz' === substr( $file, -3 ) ? gzfile( $file ) : file( $file ) );
	t_ok( false === strpos( $dump, '`' . $wpdb->users . '`' ), 'pull backups contain no users or password hashes' );
	wp_update_post( array( 'ID' => $p, 'post_title' => 'Edited after backup' ) );
	$bad = dirname( $file ) . '/pre-pull-20200101-000000-0123456789abcdef.sql';
	file_put_contents( $bad, substr( $dump, 0, strpos( $dump, 'INSERT INTO `' . $wpdb->options . '`' ) ) . "INSERT INTO `{$wpdb->options}` VALUES (broken);\n" );
	$r = LivePull_Backup::restore( $bad );
	clean_post_cache( $p );
	t_ok( is_wp_error( $r ) && 'Edited after backup' === get_the_title( $p ), 'a restore that fails part-way changes nothing' );
	t_eq( count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'lprst_' ) . '%' ) ) ), 0, 'failed restore leaves no temporary tables' );
	wp_delete_file( $bad );
	$r = LivePull_Backup::restore( $file );
	clean_post_cache( $p );
	t_ok( true === $r && 'Restore check' === get_the_title( $p ), 'restore puts content back' );
	for ( $i = 0; $i < 10; $i++ ) {
		LivePull_Backup::create();
	}
	$all    = LivePull_Backup::all();
	$oldest = end( $all )['file'];
	LivePull_Backup::create( false, $oldest ); // What restoring it does first.
	t_ok( file_exists( $oldest ), 'restoring the oldest of 10 backups keeps that backup' );
	wp_delete_post( $p, true );
}

if ( 'verify_full' === $phase ) {
	t_eq( count( (array) glob( WP_CONTENT_DIR . '/livepull-stage-*', GLOB_ONLYDIR ) ), 0, 'Copy everything leaves no staged files behind' );

	// --- A copy whose request dies right after the switch resumes; staged files are never lost.
	update_option( 'livepull_allow_full_copy', 'always' ); // (local's own setting; live allows it in run.sh)
	$up    = wp_get_upload_dir()['basedir'];
	$gone  = array_slice( (array) glob( $up . '/20*/*/*.jpg' ), 0, 10 );
	foreach ( $gone as $f ) {
		unlink( $f );
	}
	$state = LivePull_Full::start( array( 'files' => true, 'backup' => false ) );
	t_ok( is_array( $state ), 'copy started' );
	if ( is_array( $state ) ) {
		$ct = new ReflectionMethod( 'LivePull_Full', 'copy_table_chunk' );
		$fc = new ReflectionMethod( 'LivePull_Full', 'files_chunk' );
		$sw = new ReflectionMethod( 'LivePull_Full', 'swap' );
		$ct->setAccessible( true );
		$fc->setAccessible( true );
		$sw->setAccessible( true );
		while ( is_array( $state ) && $state['ti'] < count( $state['tables'] ) ) {
			$state = $ct->invoke( null, $state );
		}
		$state['phase'] = 'files';
		while ( 'files' === $state['phase'] ) {
			$state = $fc->invoke( null, $state, '__return_false' );
		}
		$stale = $state; // What a request that dies right after the switch leaves behind.
		$args  = array( &$state );
		$sw->invokeArgs( null, $args );
		t_ok( '' !== LivePull_Full::placing(), 'switching records that files still need moving' );
		$res = LivePull_Full::step( $stale, 8 );
		t_ok( is_array( $res ) && $res['done'], 'the next request carries on instead of cancelling' );
		t_eq( count( array_filter( $gone, 'is_file' ) ), count( $gone ), 'every staged file reached the uploads folder' );
		t_ok( '' === LivePull_Full::placing() && ! glob( WP_CONTENT_DIR . '/livepull-stage-*', GLOB_ONLYDIR ), 'nothing left to move, no staging folder left' );
	}
}
