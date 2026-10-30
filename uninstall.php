<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Deletion is opt-in; media attachments are never removed.
$settings = get_option( 'bap_settings', array() );
if ( empty( $settings['delete_data'] ) ) { return; }
$ids = get_posts( array( 'post_type' => 'bap_banner', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
delete_option( 'bap_settings' );
