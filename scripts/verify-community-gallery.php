<?php
/** Read-only gallery contract; builds its fixture from an existing image. */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'Kuka_Island_Core_Community_Gallery' ) ) { throw new RuntimeException( 'WordPress and Core are required.' ); }
$gallery_checks = 0;
$gallery_assert = static function ( bool $condition, string $name ) use ( &$gallery_checks ): void {
	if ( ! $condition ) { throw new RuntimeException( 'COMMUNITY_FAIL=' . $name ); }
	++$gallery_checks;
	echo 'COMMUNITY_' . $name . "=PASS\n";
};
$gallery_store_before = Kuka_Island_Core_Community_Gallery::get();
$gallery_image_ids = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'fields' => 'ids', 'posts_per_page' => 1 ) );
$gallery_assert( ! empty( $gallery_image_ids ), 'HAS_IMAGE_FIXTURE' );
$gallery_data = Kuka_Island_Core_Community_Gallery::defaults();
$gallery_data['items'][] = array( 'image_id' => (int) $gallery_image_ids[0], 'status' => 'published', 'home' => true, 'product_url' => '' );
$gallery_valid = Kuka_Island_Core_Community_Gallery::sanitize( $gallery_data );
$gallery_assert( ! is_wp_error( $gallery_valid ), 'VALID_RECORDS' );
$gallery_bad = $gallery_data;
$gallery_bad['items'][0]['image_id'] = 0;
$gallery_assert( is_wp_error( Kuka_Island_Core_Community_Gallery::sanitize( $gallery_bad ) ), 'MISSING_IMAGE_REFUSED' );
$gallery_bad = $gallery_data;
$gallery_bad['items'][0]['product_url'] = array( 'malformed' );
$gallery_assert( is_wp_error( Kuka_Island_Core_Community_Gallery::sanitize( $gallery_bad ) ), 'NESTED_INPUT_REFUSED' );
$gallery_bad = $gallery_data;
$gallery_bad['items'] = array_fill( 0, 41, $gallery_data['items'][0] );
$gallery_assert( is_wp_error( Kuka_Island_Core_Community_Gallery::sanitize( $gallery_bad ) ), 'OVERSIZE_FORM_REFUSED' );
$gallery_bad = $gallery_data;
$gallery_bad['items'][0]['product_url'] = 'javascript:alert(1)';
$gallery_assert( is_wp_error( Kuka_Island_Core_Community_Gallery::sanitize( $gallery_bad ) ), 'UNSAFE_LINK_REFUSED' );
$gallery_bad['items'][0]['product_url'] = 'not a url';
$gallery_assert( is_wp_error( Kuka_Island_Core_Community_Gallery::sanitize( $gallery_bad ) ), 'INVALID_LINK_REFUSED' );
$gallery_bad['items'][0]['product_url'] = home_url( '/product/example/' );
$gallery_clean = Kuka_Island_Core_Community_Gallery::sanitize( $gallery_bad );
$gallery_assert( ! is_wp_error( $gallery_clean ) && $gallery_clean['items'][0]['product_url'] === home_url( '/product/example/' ), 'PRODUCT_LINK_PRESERVED' );
$gallery_bad['items'][0]['product_url'] = '';
$gallery_clean = Kuka_Island_Core_Community_Gallery::sanitize( $gallery_bad );
$gallery_assert( ! is_wp_error( $gallery_clean ) && '' === $gallery_clean['items'][0]['product_url'], 'UNLINKED_PHOTO_ALLOWED' );
$gallery_bad['items'][0]['permission_note'] = 'private';
$gallery_bad['items'][0]['caption'] = 'removed';
$gallery_clean = Kuka_Island_Core_Community_Gallery::sanitize( $gallery_bad );
$gallery_assert( ! isset( $gallery_clean['items'][0]['permission_note'], $gallery_clean['items'][0]['caption'] ), 'REMOVED_FIELDS_NOT_STORED' );
$gallery_fixture = $gallery_valid;
$gallery_fixture['items'] = array_fill( 0, 4, $gallery_valid['items'][0] );
$gallery_fixture['items'][0]['status'] = 'published';
$gallery_fixture['items'][0]['home'] = true;
$gallery_fixture['items'][1]['status'] = 'draft';
$gallery_fixture['items'][2]['status'] = 'archived';
$gallery_fixture['items'][3]['status'] = 'published';
$gallery_fixture['items'][3]['home'] = false;
$gallery_filter = static fn() => $gallery_fixture;
add_filter( 'pre_option_' . Kuka_Island_Core_Community_Gallery::OPTION, $gallery_filter );
try {
	$gallery_assert( 1 === count( Kuka_Island_Core_Community_Gallery::published_items() ), 'ONLY_PUBLISHED_HOME_IMAGES' );
} finally {
	remove_filter( 'pre_option_' . Kuka_Island_Core_Community_Gallery::OPTION, $gallery_filter );
}
$gallery_original_user = get_current_user_id();
$gallery_original_post = $_POST;
$gallery_original_request = $_REQUEST;
$gallery_die = static function () { return static function ( $message ) { throw new RuntimeException( 'refused' ); }; };
add_filter( 'wp_die_handler', $gallery_die );
try {
	wp_set_current_user( 0 );
	$refused = false;
	try { ( new Kuka_Island_Core_Community_Gallery() )->save(); } catch ( RuntimeException $e ) { $refused = true; }
	$gallery_assert( $refused, 'UNAUTHORIZED_SAVE_REFUSED' );
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( (int) $admins[0] );
	$_POST = array( '_wpnonce' => 'invalid' );
	$_REQUEST['_wpnonce'] = 'invalid';
	$refused = false;
	try { ( new Kuka_Island_Core_Community_Gallery() )->save(); } catch ( RuntimeException $e ) { $refused = true; }
	$gallery_assert( $refused, 'INVALID_NONCE_REFUSED' );
	$_POST['_wpnonce'] = wp_create_nonce( 'kuka_save_community' );
	$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
	$refused = false;
	try { ( new Kuka_Island_Core_Community_Gallery() )->save(); } catch ( RuntimeException $e ) { $refused = true; }
	$gallery_assert( $refused, 'TRUNCATED_FORM_REFUSED' );
	$_POST['gallery'] = $gallery_data;
	$_POST['kuka_complete'] = 'complete';
	$_POST['revision'] = 'stale';
	$refused = false;
	try { ( new Kuka_Island_Core_Community_Gallery() )->save(); } catch ( RuntimeException $e ) { $refused = true; }
	$gallery_assert( $refused, 'STALE_EDITOR_REFUSED' );
} finally {
	remove_filter( 'wp_die_handler', $gallery_die );
	wp_set_current_user( $gallery_original_user );
	$_POST = $gallery_original_post;
	$_REQUEST = $gallery_original_request;
}
$gallery_assert( $gallery_store_before === Kuka_Island_Core_Community_Gallery::get(), 'STORE_DATA_UNCHANGED' );
echo 'COMMUNITY_CHECKS=' . $gallery_checks . "\n";
