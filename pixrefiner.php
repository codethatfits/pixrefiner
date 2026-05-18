<?php
/**
 * Plugin Name: PixRefiner
 * Plugin URI:  https://codethatfits.com
 * Description: Convert, resize, and optimise media to WebP or AVIF with fine-grained control over sizes, quality, and batch processing.
 * Version:     3.5
 * Author:      CodeThatFits.com
 * Author URI:  https://codethatfits.com
 * License:     GPL-2.0-or-later
 * Text Domain: pixrefiner
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PIXREFINER_VERSION',     '3.5' );
define( 'PIXREFINER_PLUGIN_FILE', __FILE__ );
define( 'PIXREFINER_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );

require_once PIXREFINER_PLUGIN_DIR . 'includes/helpers.php';
require_once PIXREFINER_PLUGIN_DIR . 'includes/settings.php';
require_once PIXREFINER_PLUGIN_DIR . 'includes/conversion.php';
require_once PIXREFINER_PLUGIN_DIR . 'includes/ajax.php';
require_once PIXREFINER_PLUGIN_DIR . 'admin/page.php';

register_activation_hook( __FILE__, 'wpturbo_ensure_mime_types' );
add_action( 'update_option_webp_use_avif', 'wpturbo_ensure_mime_types' );
