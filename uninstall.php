<?php
/**
 * Uninstall routine for PixRefiner.
 *
 * Runs only when the plugin is deleted from the Plugins screen (never on
 * deactivation). Removes the options this plugin created and strips the
 * MIME-type block it may have added to .htaccess. Does NOT touch converted
 * media files themselves — deleting those without the site owner's explicit
 * say-so would be far too destructive for an uninstall routine.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

function wpturbo_uninstall_delete_options() {
    $options = [
        'webp_max_widths',
        'webp_max_heights',
        'webp_resize_mode',
        'webp_quality',
        'webp_batch_size',
        'webp_preserve_originals',
        'webp_disable_auto_conversion',
        'webp_min_size_kb',
        'webp_use_avif',
        'webp_excluded_images',
        'webp_conversion_log',
        'webp_conversion_complete',
    ];

    foreach ( $options as $option ) {
        delete_option( $option );
    }
}

if ( is_multisite() ) {
    $site_ids = get_sites( [ 'fields' => 'ids' ] );
    foreach ( $site_ids as $site_id ) {
        switch_to_blog( $site_id );
        wpturbo_uninstall_delete_options();
        restore_current_blog();
    }
} else {
    wpturbo_uninstall_delete_options();
}

// Remove the MIME-type block this plugin adds to .htaccess (see
// wpturbo_ensure_mime_types() in includes/helpers.php), if present. Shared
// across the network, so this only needs to run once.
$htaccess_file = ABSPATH . '.htaccess';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
if ( file_exists( $htaccess_file ) && is_writable( $htaccess_file ) ) {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    $content     = file_get_contents( $htaccess_file );
    $new_content = preg_replace(
        '/# BEGIN WebP Converter MIME Types.*?# END WebP Converter MIME Types\n?/s',
        '',
        $content
    );

    if ( $new_content !== null && $new_content !== $content ) {
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        $wp_filesystem->put_contents( $htaccess_file, $new_content, FS_CHMOD_FILE );
    }
}
