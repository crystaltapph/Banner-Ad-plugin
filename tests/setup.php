<?php
/** Local disposable Compose site only. Never run on a production WordPress site. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
define( 'WP_INSTALLING', true );
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
if ( ! is_blog_installed() ) {
	wp_install( 'Banner Studio Test Site', 'banner-tester', 'test@example.test', true, '', 'local-ui-test-only' );
}
update_option( 'siteurl', 'http://127.0.0.1:8080' );
update_option( 'home', 'http://127.0.0.1:8080' );
update_option( 'timezone_string', 'Asia/Manila' );
activate_plugin( 'banner-studio/banner-ad-plugin.php' );
wp_set_current_user( get_user_by( 'login', 'banner-tester' )->ID );
$fixture = get_option( 'bap_test_fixture' );
if ( ! $fixture ) {
	$fixture = array();
	foreach ( array( 'desktop' => array( 970, 250 ), 'mobile' => array( 320, 100 ) ) as $device => $size ) {
		$image = imagecreatetruecolor( $size[0], $size[1] );
		$green = imagecolorallocate( $image, 22, 61, 51 );
		$cream = imagecolorallocate( $image, 216, 239, 156 );
		imagefill( $image, 0, 0, $green );
		imagestring( $image, 5, 24, 30, 'YOUR NEXT BIG THING.  ' . strtoupper( $device ), $cream );
		ob_start(); imagepng( $image ); $bytes = ob_get_clean(); imagedestroy( $image );
		$upload = wp_upload_bits( 'banner-test-' . $device . '.png', null, $bytes );
		$id = wp_insert_attachment( array( 'post_title' => 'Test ' . $device . ' artwork', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), $upload['file'] );
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		$fixture[ $device ] = $id;
	}
	$content = '<h2>Welcome to our publication</h2>';
	for ( $i = 1; $i <= 24; $i++ ) { $content .= '<p>Paragraph ' . $i . '. ' . str_repeat( 'Thoughtful content deserves room to breathe. ', 9 ) . '</p>'; }
	$fixture['post'] = wp_insert_post( array( 'post_title' => 'A thoughtful article', 'post_content' => $content, 'post_type' => 'post', 'post_status' => 'publish' ) );
	$fixture['page'] = wp_insert_post( array( 'post_title' => 'A separate page', 'post_content' => $content, 'post_type' => 'page', 'post_status' => 'publish' ) );
	update_option( 'bap_test_fixture', $fixture );
}
echo wp_json_encode( $fixture ) . PHP_EOL;
