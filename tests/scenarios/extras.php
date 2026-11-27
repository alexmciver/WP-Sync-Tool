<?php
// Optional groups: comments and replies, Contact Form 7 forms (block + shortcode), ACF option
// fields that point at pages, and media that live serves from a CDN that isn't reachable.
require getenv( 'LIVEPULL_TEST_LIB' );
$phase = isset( $args[0] ) ? $args[0] : '';

if ( 'create' === $phase ) {
	$post = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Post with comments' ) );
	$c1   = wp_insert_comment( array( 'comment_post_ID' => $post, 'comment_author' => 'Jane Visitor', 'comment_author_email' => 'jane@example.com', 'comment_content' => 'Great post, see ' . home_url( '/about/' ), 'comment_approved' => 1, 'comment_author_IP' => '203.0.113.9' ) );
	$c2   = wp_insert_comment( array( 'comment_post_ID' => $post, 'comment_parent' => $c1, 'comment_author' => 'admin', 'user_id' => 1, 'comment_content' => 'Thanks Jane', 'comment_approved' => 1 ) );
	$c3   = wp_insert_comment( array( 'comment_post_ID' => $post, 'comment_author' => 'Spammer', 'comment_content' => 'buy now', 'comment_approved' => 'spam' ) );
	$c4   = wp_insert_comment( array( 'comment_post_ID' => $post, 'comment_author' => 'Held Back', 'comment_content' => 'Hold me', 'comment_approved' => 1 ) );
	// Anti-spam data and anything else that recorded the IP address must stay on live.
	add_comment_meta( $c1, 'akismet_as_submitted', array( 'user_ip' => '203.0.113.9', 'REMOTE_ADDR' => '203.0.113.9' ) );
	add_comment_meta( $c1, 'visitor_log', 'from 203.0.113.9' );
	add_comment_meta( $c1, 'rating', '5' );
	global $wpdb;
	t_eq( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE object_type = 'comment' AND object_id = %d", LivePull_Config::table(), $c3 ) ), 0, 'new spam comment adds nothing to the change log' );
	t_eq( (string) get_comment_meta( $c3, LivePull_Config::META_KEY, true ), '', 'new spam comment gets no sync ID' );
	$data = array( 'post' => t_sid( $post ), 'c1' => t_sid( $c1, 'comment' ), 'c2' => t_sid( $c2, 'comment' ), 'c4' => t_sid( $c4, 'comment' ) );

	if ( t_plugin_active( 'contact-form-7/wp-contact-form-7.php' ) ) {
		$form = wp_insert_post( array( 'post_type' => 'wpcf7_contact_form', 'post_status' => 'publish', 'post_title' => 'Enquiry form' ) );
		update_post_meta( $form, '_form', '[text* your-name] [submit "Send"]' );
		$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Contact with form', 'post_content' => '<!-- wp:contact-form-7/contact-form-selector {"id":' . $form . ',"title":"Enquiry form"} -->' . "\n" . '<div class="wp-block-contact-form-7-contact-form-selector">[contact-form-7 id="' . $form . '" title="Enquiry form"]</div>' . "\n" . '<!-- /wp:contact-form-7/contact-form-selector -->' ) );
		$data['form'] = t_sid( $form );
		$data['page'] = t_sid( $page );
	}

	if ( function_exists( 'update_field' ) ) {
		$target = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Options target' ) );
		update_option( 'options_footer_page', $target );
		update_option( '_options_footer_page', 'field_t_opt_page' );
		update_option( 'options_phone', '020 7946 0000' );
		update_option( '_options_phone', 'field_t_opt_phone' );
		$data['target'] = t_sid( $target );
	}

	// Media "offloaded" to a CDN that local can't reach: the uploads address is the fallback.
	$img = t_image( 'Offloaded image' );
	file_put_contents( WPMU_PLUGIN_DIR . '/test-offload.php', "<?php add_filter( 'wp_get_attachment_url', function ( \$u, \$id ) { return {$img} === (int) \$id ? str_replace( home_url(), 'http://127.0.0.1:9', \$u ) : \$u; }, 10, 2 );" );
	$data['img'] = t_sid( $img );
	t_save( 'extras', $data );
}

if ( 'verify' === $phase ) {
	$s    = t_load( 'extras' );
	$post = t_local( $s['post'] );
	$c1   = t_local( $s['c1'], 'comment' );
	$c2   = t_local( $s['c2'], 'comment' );
	t_ok( $c1 > 0 && $c2 > 0, 'comment and reply pulled' );
	$one = get_comment( $c1 );
	$two = get_comment( $c2 );
	t_eq( $one ? (int) $one->comment_post_ID : 0, $post, 'comment is on the local copy of its post' );
	t_eq( $two ? (int) $two->comment_parent : 0, $c1, 'reply keeps its parent' );
	t_eq( $one ? $one->comment_author_IP : 'x', '', "commenter's IP address is not copied" );
	t_ok( $one && false !== strpos( $one->comment_content, home_url( '/about/' ) ), 'live address in a comment becomes local' );
	t_eq( (int) get_comments( array( 'author__in' => array(), 'search' => 'buy now', 'count' => true ) ), 0, 'spam is not pulled' );
	t_eq( (string) get_comment_meta( $c1, 'rating', true ), '5', 'ordinary comment fields come across' );
	t_eq( get_comment_meta( $c1, 'akismet_as_submitted', true ), '', 'Akismet data (with the IP address) stays on live' );
	t_eq( (string) get_comment_meta( $c1, 'visitor_log', true ), '', 'any field holding the commenter IP stays on live' );
	t_eq( (int) get_comments_number( $post ), 3, 'comment count on the post' );

	// Groups switched off are refused even if live sends them.
	LivePull_Config::save_groups( array( 'templates' => 1, 'forms' => 1, 'acf_options' => 1 ) );
	t_ok( '' !== LivePull_Importer::refused( array( 'type' => 'comment', 'sync_id' => $s['c1'] ) ), 'comments are refused when switched off' );
	LivePull_Config::save_groups( array( 'templates' => 1, 'forms' => 1, 'comments' => 1 ) );
	t_ok( '' !== LivePull_Importer::refused( array( 'type' => 'option', 'sync_id' => LivePull_Config::option_sid( 'options_phone' ), 'option' => array( 'name' => 'options_phone' ) ) ), 'ACF options are refused when switched off' );
	LivePull_Config::save_groups( array( 'templates' => 1, 'forms' => 1, 'comments' => 1, 'acf_options' => 1 ) );

	// One pull or copy at a time.
	$a = LivePull_Puller::lock_acquire( 'test' );
	t_ok( '' !== $a, 'the lock can be taken' );
	t_eq( LivePull_Puller::lock_acquire( 'test' ), '', 'a second run can\'t take the lock while the first holds it' );
	t_ok( is_wp_error( LivePull_Puller::cancel() ), 'Unlock refuses while a run is active' );
	t_ok( true === LivePull_Puller::cancel( $a ), 'a run can always stop itself' );
	$b = LivePull_Puller::lock_acquire( 'test' );
	t_ok( '' !== $b && ! LivePull_Puller::lock_renew( $a ), 'an old run finds the lock is no longer its own' );
	LivePull_Puller::lock_release( $b );
	t_eq( LivePull_Puller::lock_info(), null, 'releasing the lock removes it' );
	// A pull abandoned mid-way (tab closed) mustn't block Copy everything for good.
	update_option( LivePull_Puller::LOCK, array( 'owner' => 'gone', 'time' => time() - 3600, 'source' => 'manual' ), false );
	$c = LivePull_Puller::lock_acquire( 'copy' );
	t_ok( '' !== $c, 'an abandoned pull lock doesn\'t block Copy everything' );
	LivePull_Puller::lock_release( $c );

	// The connection check passes on a healthy pair of sites.
	$fails = array_filter( LivePull_Health::run(), function ( $c ) { return 'fail' === $c['status']; } );
	t_eq( count( $fails ), 0, 'connection check finds nothing wrong' );
	t_ok( false === strpos( LivePull_Config::scrub( 'x https://cdn.example/a.jpg?X-Amz-Signature=s3cr3t y key ending …ab12' ), 's3cr3t' ), 'signed links lose their secrets in stored messages' );

	// Credentials are never pulled or compared, even if a filter or LIVEPULL_PULL_OPTIONS adds them.
	add_filter( 'livepull_pulled_options', $add_keys = function ( $o ) { $o['connectors_ai_openai_api_key'] = true; $o['my_plugin_secret'] = true; $o['footer_text'] = true; return $o; } );
	t_ok( ! LivePull_Config::option_allowed( 'connectors_ai_openai_api_key' ) && ! LivePull_Config::option_allowed( 'my_plugin_secret' ) && LivePull_Config::option_allowed( 'footer_text' ), 'API keys and secrets are never pulled, whatever the settings say' );
	update_option( 'connectors_ai_openai_api_key', 'sk-local-secret' );
	add_filter( 'pre_http_request', $forge = function ( $pre, $args, $url ) {
		if ( false !== strpos( $url, 'livepull/v1/object' ) ) {
			return array( 'headers' => array( 'content-type' => 'application/json' ), 'body' => wp_json_encode( array( 'option' => array( 'name' => 'connectors_ai_openai_api_key', 'value' => 's:1:"x";' ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return $pre;
	}, 10, 3 );
	$cmp = LivePull_Puller::compare( 'option:' . LivePull_Config::option_sid( 'connectors_ai_openai_api_key' ) );
	remove_filter( 'pre_http_request', $forge, 10 );
	t_ok( is_wp_error( $cmp ) && false === strpos( wp_json_encode( $cmp ), 'sk-local-secret' ), 'compare refuses settings LivePull doesn\'t pull, so a local API key is never read' );
	delete_option( 'connectors_ai_openai_api_key' );
	remove_filter( 'livepull_pulled_options', $add_keys );

	// The key never travels over plain http when live is https.
	$cl = new LivePull_Client( 'https://live.example', 'k' );
	$ss = new ReflectionMethod( 'LivePull_Client', 'same_site' );
	$ss->setAccessible( true );
	t_ok( $ss->invoke( $cl, 'https://www.live.example/wp-json/' ) && ! $ss->invoke( $cl, 'http://live.example/wp-json/' ), 'an https live site may not redirect LivePull to plain http' );
	t_ok( $ss->invoke( new LivePull_Client( 'http://live.example', 'k' ), 'https://live.example/x' ), 'an http live site may move up to https' );

	// A backup that can't be written completely is an error, and no partial file is left.
	if ( ! in_array( 'lpfull', stream_get_wrappers(), true ) ) {
		stream_wrapper_register( 'lpfull', get_class( new class() {
			public $context;
			public static $bytes = 0;
			public function stream_open( $p, $m, $o, &$op ) { return true; }
			public function stream_write( $d ) { if ( self::$bytes > 2000 ) { return 0; } self::$bytes += strlen( $d ); return strlen( $d ); }
			public function stream_close() {}
			public function stream_flush() { return true; }
			public function stream_stat() { return array(); }
			public function url_stat( $p, $f ) { return false; }
			public function unlink( $p ) { $GLOBALS['lp_unlinked'] = $p; return true; }
		} ) );
	}
	$st         = LivePull_Backup::chunk_start( false );
	$st['file'] = 'lpfull://backup.lpb';
	$GLOBALS['lp_unlinked'] = '';
	$r = LivePull_Backup::chunk_step( $st, '__return_false', false );
	t_ok( is_wp_error( $r ) && 'livepull_backup_write' === $r->get_error_code(), 'a backup that runs out of disk space fails instead of reporting success' );

	if ( isset( $s['form'] ) ) {
		$form = t_local( $s['form'] );
		$page = get_post( t_local( $s['page'] ) );
		t_ok( $form > 0, 'Contact Form 7 form pulled' );
		t_ok( $page && false !== strpos( $page->post_content, '{"id":' . $form . ',' ), 'form block points at the local form' );
		t_ok( $page && false !== strpos( $page->post_content, '[contact-form-7 id="' . $form . '"' ), 'form shortcode points at the local form' );
	}
	if ( isset( $s['target'] ) ) {
		t_eq( (int) get_option( 'options_footer_page' ), t_local( $s['target'] ), 'ACF option field points at the local page' );
		t_eq( get_option( 'options_phone' ), '020 7946 0000', 'ACF option text value' );
	}

	$h = LivePull_History::all();
	t_ok( $h && 'pull' === $h[0]['kind'] && $h[0]['created_count'] > 0, 'the pull is listed in History with what came across' );
	LivePull_Schedule::save( 'daily', false );
	t_ok( LivePull_Schedule::next() > time(), 'turning on daily pulls schedules the next one' );
	wp_clear_scheduled_hook( LivePull_Schedule::HOOK ); // As Copy everything or a restore would.
	LivePull_Schedule::ensure();
	t_ok( LivePull_Schedule::next() > time(), 'a schedule lost in a copy or restore is put back' );
	LivePull_Schedule::save( 'off', false );
	t_eq( LivePull_Schedule::next(), 0, 'turning scheduled pulls off removes them' );

	$img  = t_local( $s['img'] );
	$file = $img ? get_attached_file( $img ) : '';
	t_ok( $file && file_exists( $file ) && filesize( $file ) > 0, 'offloaded media downloaded from the fallback address' );
}

if ( 'verify' === $phase && class_exists( 'LivePull_Updater' ) ) {
	// Signed updates: good, tampered, wrong key, junk.
	$kp   = sodium_crypto_sign_keypair();
	$pub  = base64_encode( sodium_crypto_sign_publickey( $kp ) );
	$data = 'release bytes';
	$sig  = base64_encode( sodium_crypto_sign_detached( $data, sodium_crypto_sign_secretkey( $kp ) ) );
	t_ok( LivePull_Updater::signature_ok( $sig, $data, $pub ), 'a correctly signed update passes' );
	t_ok( ! LivePull_Updater::signature_ok( $sig, $data . 'x', $pub ), 'a changed update fails its signature' );
	t_ok( ! LivePull_Updater::signature_ok( $sig, $data, base64_encode( sodium_crypto_sign_publickey( sodium_crypto_sign_keypair() ) ) ), 'a signature by another key fails' );
	t_ok( ! LivePull_Updater::signature_ok( 'junk', $data, $pub ), 'a junk signature fails' );
	t_ok( ! LivePull_Updater::signature_ok( $sig, $data ), 'only the built-in key is trusted for real updates' );

	// The version inside an update must be the one it was offered as, and newer.
	$zip = wp_tempnam( 'lp-test' ) . '.zip';
	$z   = new ZipArchive();
	$z->open( $zip, ZipArchive::CREATE );
	$z->addFromString( 'livepull/livepull.php', "<?php\n/**\n * Plugin Name: LivePull\n * Version:           9.9.9\n */" );
	$z->close();
	$url = function ( $v ) { return 'https://github.com/alexmciver/WP-Sync-Tool/releases/download/v' . $v . '/livepull.zip'; };
	t_ok( true === LivePull_Updater::version_ok( $url( '9.9.9' ), $zip, '1.2.1' ), 'an update with the version it claims installs' );
	t_ok( is_wp_error( LivePull_Updater::version_ok( $url( '9.9.10' ), $zip, '1.2.1' ) ), 'an update whose version differs from the release is refused' );
	t_ok( is_wp_error( LivePull_Updater::version_ok( $url( '9.9.9' ), $zip, '9.9.9' ) ), 'an update that isn\'t newer is refused' );
	@unlink( $zip );
	t_ok( LivePull_Updater::trusted_package( $url( '1.3.0' ) ) && ! LivePull_Updater::trusted_package( 'https://github.com/someone-else/WP-Sync-Tool/releases/download/v1.3.0/livepull.zip' ), 'updates only come from this repository\'s releases' );
}

if ( 'local_change' === $phase ) {
	$s = t_load( 'extras' );
	wp_update_comment( array( 'comment_ID' => t_local( $s['c2'], 'comment' ), 'comment_content' => 'Thanks Jane (edited locally)' ) );
}

if ( 'change' === $phase ) {
	$s   = t_load( 'extras' );
	$map = LivePull_Ids::map( 'comment' );
	wp_update_comment( array( 'comment_ID' => $map[ $s['c1'] ], 'comment_content' => 'Great post (edited on live)' ) );
	wp_update_comment( array( 'comment_ID' => $map[ $s['c2'] ], 'comment_content' => 'Thanks Jane (edited on live)' ) );
	wp_spam_comment( $map[ $s['c1'] ] ); // Spam on live = gone locally.
	@unlink( WPMU_PLUGIN_DIR . '/test-offload.php' );
}

if ( 'verify_change' === $phase ) {
	$s = t_load( 'extras' );
	t_eq( t_local( $s['c1'], 'comment' ) && get_comment( t_local( $s['c1'], 'comment' ) ) ? 'still here' : 'gone', 'gone', 'comment marked as spam on live is removed locally' );
	$two = get_comment( t_local( $s['c2'], 'comment' ) );
	t_ok( $two && false !== strpos( $two->comment_content, 'edited locally' ), 'comment edited on both sides keeps the local edit' );
	$conf = get_option( 'livepull_conflicts', array() );
	t_ok( isset( $conf[ 'comment:' . $s['c2'] ] ), 'comment edited on both sides is listed as a conflict' );

	// Compare and "Use live version" work for comments.
	$rows = LivePull_Puller::compare( 'comment:' . $s['c2'] );
	t_ok( ! is_wp_error( $rows ) && in_array( 'Comment', wp_list_pluck( (array) $rows, 'label' ), true ), 'Compare works for a comment conflict' );
	$r = LivePull_Puller::take( 'comment:' . $s['c2'] );
	$two = get_comment( t_local( $s['c2'], 'comment' ) );
	t_ok( ! is_wp_error( $r ) && $two && false !== strpos( $two->comment_content, 'edited on live' ), '"Use live version" works for a comment' );

	// A held-back comment can be pulled later.
	$c4 = t_local( $s['c4'], 'comment' );
	wp_delete_comment( $c4, true );
	LivePull_Logger::insert( 'comment', $s['c4'], $c4, 'synced', 'sync' ); // As if it had never been pulled (not a local deletion).
	update_option( LivePull_Puller::HELD, array( 'comment:' . $s['c4'] => array( 'label' => 'comment', 'group' => 'Comments', 'time' => time() ) ), false );
	$state = LivePull_Puller::start( array( 'include_held' => array( 'comment:' . $s['c4'] ), 'skip_backup' => true ) );
	for ( $i = 0; ! is_wp_error( $state ) && ! $state['done'] && $i < 50; $i++ ) {
		$state = LivePull_Puller::step( $state, 20 );
	}
	t_ok( ! is_wp_error( $state ) && t_local( $s['c4'], 'comment' ) > 0, 'a held-back comment comes across when ticked' );

	// Copy everything: live's "list of uploads expired" answer reaches the copy as such,
	// so it lists again instead of skipping the media.
	$res = LivePull_Puller::client()->get( 'files', array( 'offset' => 5, 'limit' => 10, 'token' => wp_generate_password( 20, false ) ) );
	t_eq( is_wp_error( $res ) ? $res->get_error_code() : 'no error', 'livepull_listing_gone', 'an expired uploads list is recognised (so Copy everything lists again)' );

	// Scheduled pulls: a run is recorded; a failure emails once, not every run.
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( (int) $admins[0] ); // Schedules are saved by an administrator, and run as them.
	LivePull_Schedule::save( 'hourly', true );
	wp_set_current_user( 0 ); // Cron runs with no one logged in.
	$mails = 0;
	add_filter( 'pre_wp_mail', function () use ( &$mails ) { $mails++; return true; } );
	$r = LivePull_Schedule::run();
	$h = LivePull_History::all();
	t_ok( ! is_wp_error( $r ) && 'scheduled' === $h[0]['source'], 'a scheduled pull runs and is listed in History' );
	$real = get_option( 'livepull_remote_secret' );
	update_option( 'livepull_remote_secret', str_repeat( 'x', 64 ) );
	LivePull_Schedule::run();
	LivePull_Schedule::run();
	t_eq( $mails, 1, 'a failing schedule emails once, not every run' );
	update_option( 'livepull_remote_secret', $real );
	LivePull_Schedule::run();
	$st = LivePull_Schedule::settings();
	t_ok( empty( $st['failing'] ), 'a working run clears the failure' );
	$s2 = get_option( 'livepull_schedule' );
	$s2['user'] = 0;
	update_option( 'livepull_schedule', $s2 );
	wp_set_current_user( 0 );
	$r = LivePull_Schedule::run();
	t_ok( is_wp_error( $r ) && 'livepull_schedule_user' === $r->get_error_code(), 'a schedule with no administrator doesn\'t pull (HTML would be filtered)' );
	LivePull_Schedule::save( 'off', false );
}
