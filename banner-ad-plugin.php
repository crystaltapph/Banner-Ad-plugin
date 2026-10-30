<?php
/**
 * Plugin Name: Banner Studio
 * Description: Responsive, self-managed image banners with thoughtful placements and a clean dashboard.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: CrystalTapph
 * License: GPL-2.0-or-later
 * Text Domain: banner-studio
 */

defined( 'ABSPATH' ) || exit;
define( 'BAP_VERSION', '1.0.0' );
define( 'BAP_FILE', __FILE__ );
define( 'BAP_URL', plugin_dir_url( __FILE__ ) );
require_once __DIR__ . '/includes/class-bap-plugin.php';
require_once __DIR__ . '/includes/class-bap-admin.php';
BAP_Plugin::boot();
if ( is_admin() ) {
	BAP_Admin::boot();
}
