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
	$data = array( 'post' => t_sid( $post ), 'c1' => t_sid( $c1, 'comment' ), 'c2' => t_sid( $c2, 'comment' ), 'c3' => t_sid( $c3, 'comment' ) );

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
	t_eq( t_local( $s['c3'], 'comment' ), 0, 'spam is not pulled' );
	t_eq( (int) get_comments_number( $post ), 2, 'comment count on the post' );

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
	LivePull_Schedule::save( 'off', false );
	t_eq( LivePull_Schedule::next(), 0, 'turning scheduled pulls off removes them' );

	$img  = t_local( $s['img'] );
	$file = $img ? get_attached_file( $img ) : '';
	t_ok( $file && file_exists( $file ) && filesize( $file ) > 0, 'offloaded media downloaded from the fallback address' );
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
}
