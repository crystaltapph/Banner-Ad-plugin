<?php
/** Run against the disposable Compose site after tests/setup.php. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 403 ); exit; }
$_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
require '/var/www/html/wp-load.php';
require_once __DIR__ . '/../includes/class-bap-admin.php';
$fixture = get_option( 'bap_test_fixture' );
if ( ! $fixture ) { fwrite( STDERR, "Run tests/setup.php first.\n" ); exit( 1 ); }
$created = array();
$original = get_option( 'bap_settings', array() );
$checks = 0;
$existing = get_posts( array( 'post_type' => 'bap_banner', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
foreach ( $existing as $existing_id ) { wp_update_post( array( 'ID' => $existing_id, 'post_status' => 'draft' ) ); }
BAP_Plugin::reset_cache();
function check( bool $condition, string $message ): void {
	global $checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	++$checks; echo "PASS $message\n";
}
function context( int $ad_id ): void {
	global $wp_query, $wp_the_query;
	$wp_query = new WP_Query( array( 'p' => $ad_id, 'post_type' => get_post_type( $ad_id ) ) );
	$wp_the_query = $wp_query;
	$wp_query->the_post();
	BAP_Plugin::reset_cache();
}
function banner( array $overrides = array() ): int {
	global $fixture, $created;
	$ad_id = wp_insert_post( array( 'post_type' => 'bap_banner', 'post_status' => 'publish', 'post_title' => 'Integration banner' ) );
	$created[] = $ad_id;
	update_post_meta( $ad_id, '_bap_data', array_merge( BAP_Plugin::banner_defaults(), array( 'desktop_image' => $fixture['desktop'], 'mobile_image' => $fixture['mobile'] ), $overrides ) );
	BAP_Plugin::reset_cache();
	return $ad_id;
}
function change( int $ad_id, array $overrides ): void {
	update_post_meta( $ad_id, '_bap_data', array_merge( BAP_Plugin::data( $ad_id ), $overrides ) );
	BAP_Plugin::reset_cache();
}
function rejects( callable $operation ): bool {
	$handler = static fn() => static function () { throw new InvalidArgumentException( 'Validation rejected the input.' ); };
	add_filter( 'wp_die_handler', $handler );
	try { $operation(); return false; }
	catch ( InvalidArgumentException $error ) { return true; }
	finally { remove_filter( 'wp_die_handler', $handler ); }
}
try {
	update_option( 'bap_settings', array_merge( BAP_Plugin::defaults(), array( 'min_words' => 0, 'max' => 10 ) ) );
	context( $fixture['post'] );
	$ad_id = banner( array( 'position' => 2, 'url' => 'https://example.test/offer', 'new_tab' => 1, 'alt' => 'A "special" offer', 'label' => '<script>alert(1)</script>' ) );
	$html = BAP_Plugin::content( '<h2>Title</h2><p>One</p><p>Two</p><p>Three</p>' );
	check( str_contains( $html, 'Two</p><aside' ) || preg_match( '#Two</p>\s*<aside#', $html ) === 1, 'Banner inserts after chosen paragraph' );
	check( str_contains( $html, 'rel="sponsored noopener"' ), 'Paid and new-tab link attributes are present' );
	check( ! str_contains( $html, '<script>' ) && str_contains( $html, '&lt;script&gt;' ), 'Ad label is escaped' );
	$parsed = new DOMDocument();
	$previous = libxml_use_internal_errors( true ); $parsed->loadHTML( $html ); libxml_clear_errors(); libxml_use_internal_errors( $previous );
	check( 'A "special" offer' === $parsed->getElementsByTagName( 'img' )->item( 0 )->getAttribute( 'alt' ), 'Quoted alternative text survives HTML parsing safely' );
	check( str_contains( $html, '<picture>' ) && str_contains( $html, 'banner-test-mobile.png' ), 'Responsive desktop and mobile sources are rendered' );
	check( BAP_Plugin::content( $html ) === $html, 'Processed content is not inserted twice' );
	check( '' === BAP_Plugin::shortcode( array( 'id' => $ad_id ) ), 'Same banner is deduplicated across automatic and manual placement' );
	BAP_Plugin::reset_cache();
	$html = BAP_Plugin::content( '<table><tr><td><p>Nested</p></td></tr></table><pre><code>&lt;p&gt;Code&lt;/p&gt;</code></pre><p>One</p><p>Two</p>' );
	check( preg_match( '#Two</p>\s*<aside#', $html ) === 1 && ! preg_match( '#Nested</p>\s*<aside#', $html ), 'Nested table and code structures remain intact' );
	change( $ad_id, array( 'position' => 1, 'placement' => 'after_heading' ) );
	check( preg_match( '#Heading</h2>\s*<aside#', BAP_Plugin::content( '<h2>Heading</h2><p>Text</p>' ) ) === 1, 'Heading placement works' );
	change( $ad_id, array( 'placement' => 'manual', 'start' => (string) ( time() + 3600 ) ) );
	check( ! BAP_Plugin::eligible( $ad_id ), 'Future scheduled banner stays hidden' );
	change( $ad_id, array( 'start' => '', 'end' => (string) time() ) );
	check( ! BAP_Plugin::eligible( $ad_id ), 'End timestamp is exclusive' );
	change( $ad_id, array( 'end' => '', 'enabled' => 0 ) );
	check( ! BAP_Plugin::eligible( $ad_id ), 'Paused banner stays hidden' );
	change( $ad_id, array( 'enabled' => 1, 'exclude' => array( $fixture['post'] ) ) );
	check( ! BAP_Plugin::eligible( $ad_id ), 'Explicit post exclusion is respected' );
	change( $ad_id, array( 'exclude' => array(), 'post_types' => array( 'page' ) ) );
	check( ! BAP_Plugin::eligible( $ad_id ), 'Content type targeting is respected' );
	context( $fixture['page'] );
	check( BAP_Plugin::eligible( $ad_id ), 'Page-targeted banner appears on a page' );
	context( $fixture['post'] );
	change( $ad_id, array( 'post_types' => array( 'post' ), 'categories' => array( 999999 ) ) );
	check( ! BAP_Plugin::eligible( $ad_id ), 'Category targeting rejects a nonmatching article' );
	change( $ad_id, array( 'categories' => array(), 'tags' => array( 999999 ) ) );
	check( ! BAP_Plugin::eligible( $ad_id ), 'Tag targeting rejects a nonmatching article' );
	change( $ad_id, array( 'tags' => array() ) );
	check( '' !== BAP_Plugin::shortcode( array( 'id' => $ad_id ) ), 'Shortcode renders a matching banner' );
	BAP_Plugin::reset_cache();
	check( '' !== do_blocks( '<!-- wp:banner-studio/banner {"bannerId":' . $ad_id . '} /-->' ), 'Dynamic Gutenberg block renders a matching banner' );
	BAP_Plugin::reset_cache();
	check( '' === BAP_Plugin::shortcode( array( 'id' => $fixture['post'] ) ), 'Shortcode cannot render an ordinary post as a banner' );
	update_option( 'bap_settings', array_merge( BAP_Plugin::settings(), array( 'max' => 1 ) ) );
	$second = banner();
	BAP_Plugin::render( $ad_id );
	check( '' === BAP_Plugin::render( $second ), 'Page-wide banner cap is enforced' );
	update_option( 'bap_settings', array_merge( BAP_Plugin::settings(), array( 'max' => 10, 'min_words' => 100 ) ) );
	BAP_Plugin::reset_cache();
	check( ! str_contains( BAP_Plugin::content( '<p>Short article.</p>' ), '<aside' ), 'Minimum article length suppresses automatic placement' );
	update_option( 'bap_settings', array_merge( BAP_Plugin::settings(), array( 'min_words' => 0, 'gap' => 2 ) ) );
	change( $ad_id, array( 'placement' => 'after_paragraph', 'position' => 1 ) );
	change( $second, array( 'position' => 2 ) );
	$html = BAP_Plugin::content( '<p>One</p><p>Two</p><p>Three</p>' );
	check( substr_count( $html, '<aside' ) === 1, 'Minimum in-content spacing suppresses adjacent banners' );
	wp_set_current_user( get_user_by( 'login', 'banner-tester' )->ID );
	update_option( 'bap_settings', array_merge( BAP_Plugin::settings(), array( 'hide_admin' => 1 ) ) );
	check( ! BAP_Plugin::allowed(), 'Administrator visibility setting is respected' );
	wp_set_current_user( 0 );
	check( BAP_Plugin::allowed(), 'Anonymous visitors remain eligible' );
	$raw = array_merge( BAP_Plugin::banner_defaults(), array( 'desktop_image' => $fixture['desktop'], 'url' => 'https://example.test/', 'alt' => 'Offer', 'width' => 99999, 'margin' => -10, 'priority' => 0, 'exclude' => '6,6,0,7', 'label' => '<b>Ad</b>' ) );
	$clean = BAP_Admin::sanitize( $raw );
	check( 2400 === $clean['width'] && 0 === $clean['margin'] && 1 === $clean['priority'], 'Numeric settings are bounded server-side' );
	check( 'Ad' === $clean['label'] && array( 6, 7 ) === $clean['exclude'], 'Settings sanitize labels and normalize ID lists' );
	check( '2026-11-01T10:30' === wp_date( 'Y-m-d\TH:i', (int) BAP_Admin::date( '2026-11-01T10:30' ) ), 'Schedules round-trip in the WordPress site timezone' );
	change( $ad_id, array( 'placement' => 'sticky', 'mobile' => 1 ) );
	$sticky = BAP_Plugin::render( $ad_id, true );
	check( str_contains( $sticky, 'bap-close' ) && str_contains( $sticky, 'hidden' ), 'Sticky includes accessible dismissal and starts hidden' );
	BAP_Plugin::reset_cache();
	change( $ad_id, array( 'mobile_image' => 0 ) );
	check( str_contains( BAP_Plugin::render( $ad_id ), 'banner-test-desktop.png' ), 'Missing mobile artwork falls back to desktop artwork' );
	check( count( BAP_Plugin::creatives( BAP_Plugin::data( $ad_id ) ) ) === 1, 'Legacy single-banner records remain readable without migration' );
	$raw['creatives'] = array();
	for ( $i = 1; $i <= 4; ++$i ) {
		$raw['creatives'][] = array( 'desktop_image' => $fixture[ 1 === $i ? 'desktop' : 'desktop' . $i ], 'mobile_image' => $fixture[ 1 === $i ? 'mobile' : 'mobile' . $i ], 'url' => 'https://example.test/creative-' . $i, 'alt' => 'Offer ' . $i );
	}
	$raw['randomize'] = 1;
	$raw['exclude'] = array();
	$raw['post_types'] = array( 'post', 'page' );
	$clean = BAP_Admin::sanitize( $raw );
	check( 4 === count( $clean['creatives'] ) && 1 === $clean['randomize'], 'Four desktop/mobile creatives and random mode are accepted' );
	check( 'https://example.test/creative-4' === $clean['creatives'][3]['url'], 'Each creative retains its own destination link' );
	$random_ad = banner( $clean );
	$html = BAP_Plugin::render( $random_ad );
	check( 4 === substr_count( $html, 'data-bap-creative=' ) && str_contains( $html, '<noscript>' ), 'Random rendering includes four inert choices and a no-JavaScript fallback' );
	check( substr_count( $html, 'data-bap-id=' ) === 1, 'Four alternatives consume only one banner slot' );
	context( $fixture['page'] );
	check( '' !== BAP_Plugin::render( $random_ad ), 'Random campaign supports automatic display on pages as well as posts' );
	context( $fixture['post'] );
	change( $random_ad, array( 'randomize' => 0 ) );
	$html = BAP_Plugin::render( $random_ad );
	check( str_contains( $html, 'creative-1' ) && ! str_contains( $html, 'creative-4' ) && ! str_contains( $html, '<template' ), 'Disabling random mode serves only the first creative' );
	$too_many = $raw; $too_many['creatives'][] = $raw['creatives'][0];
	check( rejects( static fn() => BAP_Admin::sanitize( $too_many ) ), 'More than four creatives are rejected server-side' );
	$unsafe = $raw; $unsafe['creatives'][3]['url'] = 'javascript:alert(1)';
	check( rejects( static fn() => BAP_Admin::sanitize( $unsafe ) ), 'Unsafe links in later creatives are rejected server-side' );
	$invalid = $raw; $invalid['creatives'][2]['desktop_image'] = $fixture['post'];
	check( rejects( static fn() => BAP_Admin::sanitize( $invalid ) ), 'Non-image attachments in later creatives are rejected' );
	$blank = $raw; $blank['creatives'][3] = array();
	check( count( BAP_Admin::sanitize( $blank )['creatives'] ) === 3, 'Unused blank creative slots are omitted when saving' );
	$partial = $blank; $partial['creatives'][3]['url'] = 'https://example.test/missing-image';
	check( rejects( static fn() => BAP_Admin::sanitize( $partial ) ), 'A linked creative without artwork cannot be saved' );
	echo "\n$checks integration checks passed.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	$failed = true;
} finally {
	foreach ( $created as $ad_id ) { wp_delete_post( $ad_id, true ); }
	foreach ( $existing as $existing_id ) { wp_update_post( array( 'ID' => $existing_id, 'post_status' => 'publish' ) ); }
	update_option( 'bap_settings', $original );
	BAP_Plugin::reset_cache();
}
exit( empty( $failed ) ? 0 : 1 );
