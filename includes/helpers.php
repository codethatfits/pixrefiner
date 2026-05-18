<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wpturbo_add_log_entry( $message ) {
    $log   = get_option( 'webp_conversion_log', [] );
    $log[] = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message;
    update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
}

function wpturbo_ensure_mime_types() {
    $htaccess_file = ABSPATH . '.htaccess';
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
    if ( ! file_exists( $htaccess_file ) || ! is_writable( $htaccess_file ) ) {
        return false;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    $content   = file_get_contents( $htaccess_file );
    $webp_mime = 'AddType image/webp .webp';
    $avif_mime = 'AddType image/avif .avif';

    if ( strpos( $content, $webp_mime ) !== false && strpos( $content, $avif_mime ) !== false ) {
        return true;
    }

    $new_content = "# BEGIN WebP Converter MIME Types\n";
    if ( strpos( $content, $webp_mime ) === false ) {
        $new_content .= "$webp_mime\n";
    }
    if ( strpos( $content, $avif_mime ) === false ) {
        $new_content .= "$avif_mime\n";
    }
    $new_content .= "# END WebP Converter MIME Types\n";

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    file_put_contents( $htaccess_file, $content . "\n" . $new_content );
    return true;
}

add_action( 'wp_delete_attachment', 'wpturbo_delete_attachment_files', 10, 1 );
function wpturbo_delete_attachment_files( $attachment_id ) {
    if ( in_array( $attachment_id, wpturbo_get_excluded_images() ) ) return;

    $file = get_attached_file( $attachment_id );
    if ( $file && file_exists( $file ) ) {
        wp_delete_file( $file );
    }

    $metadata = wp_get_attachment_metadata( $attachment_id );
    if ( $metadata && isset( $metadata['sizes'] ) ) {
        $upload_dir = wp_upload_dir()['basedir'];
        foreach ( $metadata['sizes'] as $size ) {
            $size_file = $upload_dir . '/' . dirname( $metadata['file'] ) . '/' . $size['file'];
            if ( file_exists( $size_file ) ) {
                wp_delete_file( $size_file );
            }
        }
    }
}

function wpturbo_replace_urls_in_elementor_urls( $data, $baseurl, $basedir, $extension, &$checked_images ) {
    foreach ( $data as $key => &$value ) {
        if ( is_array( $value ) ) {
            $value = wpturbo_replace_urls_in_elementor_urls( $value, $baseurl, $basedir, $extension, $checked_images );
        } elseif ( is_string( $value ) && preg_match( '/\.(jpg|jpeg|png)$/i', $value ) ) {
            $original_url = $value;
            if ( strpos( $original_url, $baseurl ) === false ) continue;

            $checked_images++;

            $dirname  = pathinfo( $original_url, PATHINFO_DIRNAME );
            $filename = pathinfo( $original_url, PATHINFO_FILENAME );

            $new_url             = $dirname . '/' . $filename . '.' . $extension;
            $scaled_url          = $dirname . '/' . $filename . '-scaled.' . $extension;
            $new_path            = str_replace( $baseurl, $basedir, $new_url );
            $scaled_path         = str_replace( $baseurl, $basedir, $scaled_url );

            if ( file_exists( $scaled_path ) ) {
                wpturbo_add_log_entry( "Replacing JSON: {$original_url} → {$scaled_url}" );
                $value = $scaled_url;
            } elseif ( file_exists( $new_path ) ) {
                wpturbo_add_log_entry( "Replacing JSON: {$original_url} → {$new_url}" );
                $value = $new_url;
            } else {
                $base_name           = preg_replace( '/(-\d+x\d+|-scaled)$/', '', $filename );
                $fallback_url        = $dirname . '/' . $base_name . '.' . $extension;
                $fallback_scaled_url = $dirname . '/' . $base_name . '-scaled.' . $extension;
                $fallback_path       = str_replace( $baseurl, $basedir, $fallback_url );
                $fallback_scaled_path = str_replace( $baseurl, $basedir, $fallback_scaled_url );

                if ( file_exists( $fallback_scaled_path ) ) {
                    wpturbo_add_log_entry( "Replacing JSON: {$original_url} → {$fallback_scaled_url}" );
                    $value = $fallback_scaled_url;
                } elseif ( file_exists( $fallback_path ) ) {
                    wpturbo_add_log_entry( "Replacing JSON: {$original_url} → {$fallback_url}" );
                    $value = $fallback_url;
                }
            }
        }
    }
    return $data;
}

add_filter( 'image_size_names_choose', 'wpturbo_disable_default_sizes', 999 );
function wpturbo_disable_default_sizes( $sizes ) {
    $mode       = wpturbo_get_resize_mode();
    $max_values = ( $mode === 'width' ) ? wpturbo_get_max_widths() : wpturbo_get_max_heights();
    $custom_sizes = [ 'thumbnail' => __( 'Thumbnail (150x150)', 'pixrefiner' ) ];
    foreach ( array_slice( $max_values, 1, 3 ) as $value ) {
        if ( $mode === 'width' ) {
            /* translators: %d: image width in pixels */
            $custom_sizes["custom-$value"] = sprintf( __( 'Custom %dpx Width', 'pixrefiner' ), $value );
        } else {
            /* translators: %d: image height in pixels */
            $custom_sizes["custom-$value"] = sprintf( __( 'Custom %dpx Height', 'pixrefiner' ), $value );
        }
    }
    return $custom_sizes;
}

add_filter( 'big_image_size_threshold', '__return_false', 999 );
