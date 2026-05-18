<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Register AJAX actions inside admin_init so they only fire in admin context.
add_action( 'admin_init', function () {
    add_action( 'wp_ajax_webp_status',                 'wpturbo_webp_conversion_status' );
    add_action( 'wp_ajax_webp_convert_single',         'wpturbo_convert_single_image' );
    add_action( 'wp_ajax_webp_export_media_zip',       'wpturbo_export_media_zip' );
    add_action( 'wp_ajax_webp_add_excluded_image',     'wpturbo_add_excluded_image_ajax' );
    add_action( 'wp_ajax_webp_remove_excluded_image',  'wpturbo_remove_excluded_image_ajax' );
    add_action( 'wp_ajax_convert_post_images_to_webp', 'wpturbo_convert_post_images_to_format' );

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( isset( $_GET['convert_existing_images_to_webp'] ) && current_user_can( 'manage_options' ) ) {
        delete_option( 'webp_conversion_complete' );
    }
} );

// ─── Status ───────────────────────────────────────────────────────────────────

function wpturbo_webp_conversion_status() {
    check_ajax_referer( 'webp_converter_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied', 'pixrefiner' ) );
    }

    $total = wp_count_posts( 'attachment' )->inherit;
    if ( $total < 0 ) {
        wp_send_json_error( __( 'Invalid attachment count', 'pixrefiner' ) );
    }

    $per_page  = 50;
    $converted = 0;
    $skipped   = 0;
    $mime_type = wpturbo_get_use_avif() ? 'image/avif' : 'image/webp';

    for ( $offset = 0; $offset < $total; $offset += $per_page ) {
        $converted += count( get_posts( [
            'post_type'      => 'attachment',
            'post_mime_type' => $mime_type,
            'posts_per_page' => $per_page,
            'offset'         => $offset,
            'fields'         => 'ids',
        ] ) );
    }

    for ( $offset = 0; $offset < $total; $offset += $per_page ) {
        $skipped += count( get_posts( [
            'post_type'      => 'attachment',
            'post_mime_type' => [ 'image/jpeg', 'image/png' ],
            'posts_per_page' => $per_page,
            'offset'         => $offset,
            'fields'         => 'ids',
        ] ) );
    }

    $excluded      = wpturbo_get_excluded_images();
    $excluded_data = [];
    foreach ( $excluded as $id ) {
        $thumb           = wp_get_attachment_image_src( $id, 'thumbnail' );
        $excluded_data[] = [
            'id'        => $id,
            'title'     => get_the_title( $id ),
            'thumbnail' => $thumb ? $thumb[0] : '',
        ];
    }

    $mode       = wpturbo_get_resize_mode();
    $max_values = ( $mode === 'width' ) ? wpturbo_get_max_widths() : wpturbo_get_max_heights();

    wp_send_json( [
        'total'                   => $total,
        'converted'               => $converted,
        'skipped'                 => $skipped,
        'remaining'               => $total - $converted - $skipped,
        'excluded'                => count( $excluded ),
        'excluded_images'         => $excluded_data,
        'log'                     => get_option( 'webp_conversion_log', [] ),
        'complete'                => get_option( 'webp_conversion_complete', false ),
        'resize_mode'             => $mode,
        'max_widths'              => implode( ', ', wpturbo_get_max_widths() ),
        'max_heights'             => implode( ', ', wpturbo_get_max_heights() ),
        'preserve_originals'      => wpturbo_get_preserve_originals(),
        'disable_auto_conversion' => wpturbo_get_disable_auto_conversion(),
        'min_size_kb'             => wpturbo_get_min_size_kb(),
        'use_avif'                => wpturbo_get_use_avif(),
        'quality'                 => wpturbo_get_quality(),
        'batch_size'              => wpturbo_get_batch_size(),
    ] );
}

// ─── Excluded images ──────────────────────────────────────────────────────────

function wpturbo_add_excluded_image_ajax() {
    check_ajax_referer( 'webp_converter_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['attachment_id'] ) ) {
        wp_send_json_error( __( 'Permission denied or invalid attachment ID', 'pixrefiner' ) );
    }
    $id = absint( $_POST['attachment_id'] );
    if ( wpturbo_add_excluded_image( $id ) ) {
        wp_send_json_success( [ 'message' => __( 'Image excluded successfully', 'pixrefiner' ) ] );
    } else {
        wp_send_json_error( __( 'Image already excluded or invalid ID', 'pixrefiner' ) );
    }
}

function wpturbo_remove_excluded_image_ajax() {
    check_ajax_referer( 'webp_converter_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['attachment_id'] ) ) {
        wp_send_json_error( __( 'Permission denied or invalid attachment ID', 'pixrefiner' ) );
    }
    $id = absint( $_POST['attachment_id'] );
    if ( wpturbo_remove_excluded_image( $id ) ) {
        wp_send_json_success( [ 'message' => __( 'Image removed from exclusion list', 'pixrefiner' ) ] );
    } else {
        wp_send_json_error( __( 'Image not in exclusion list', 'pixrefiner' ) );
    }
}

// ─── Fix post content URLs ────────────────────────────────────────────────────

function wpturbo_convert_post_images_to_format() {
    check_ajax_referer( 'webp_converter_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied', 'pixrefiner' ) );
    }

    $use_avif  = wpturbo_get_use_avif();
    $extension = $use_avif ? 'avif' : 'webp';
    /* translators: %s: output format name, either "AVIF" or "WebP" */
    wpturbo_add_log_entry( sprintf( __( 'Starting post/page/FSE-template image conversion to %s...', 'pixrefiner' ), $use_avif ? 'AVIF' : 'WebP' ) );

    $post_types = array_unique( array_merge(
        get_post_types( [ 'public' => true ], 'names' ),
        [ 'wp_template', 'wp_template_part', 'wp_block' ]
    ) );

    $posts = get_posts( [ 'post_type' => $post_types, 'posts_per_page' => -1, 'fields' => 'ids' ] );

    if ( ! $posts ) {
        wpturbo_add_log_entry( __( 'No posts/pages/FSE-templates found', 'pixrefiner' ) );
        wp_send_json_success( [ 'message' => __( 'No posts/pages/FSE-templates found', 'pixrefiner' ) ] );
    }

    $upload_dir     = wp_upload_dir();
    $upload_baseurl = $upload_dir['baseurl'];
    $upload_basedir = $upload_dir['basedir'];
    $updated_count  = 0;
    $checked_images = 0;
    $changed_links  = 0;

    foreach ( $posts as $post_id ) {
        $type      = get_post_type( $post_id );
        $title_raw = get_the_title( $post_id );
        $title     = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( preg_replace( '/<\/?br\s*\/?>/i', ' ', $title_raw ) ) ) );

        if ( strpos( $type, 'elementor_' ) === 0 ) {
            wpturbo_add_log_entry( "⏭️ Skipped Elementor-type post: {$type} (ID: {$post_id})" );
            continue;
        }

        $original_content = get_post_field( 'post_content', $post_id );
        $content          = $original_content;
        wpturbo_add_log_entry( "🔧 {$type}: {$title} (ID: {$post_id})" );

        $content_array = json_decode( $content, true );
        if ( json_last_error() === JSON_ERROR_NONE && is_array( $content_array ) ) {
            $content_array = wpturbo_replace_urls_in_elementor_urls( $content_array, $upload_baseurl, $upload_basedir, $extension, $checked_images );
            $content       = json_encode( $content_array, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        } else {
            // Replace <img src>
            $content = preg_replace_callback(
                '/<img[^>]+src=["\']([^"\']+\.(?:jpg|jpeg|png))["\'][^>]*>/i',
                function ( $matches ) use ( &$checked_images, $upload_baseurl, $upload_basedir, $extension ) {
                    $original_url = $matches[1];
                    if ( strpos( $original_url, $upload_baseurl ) !== 0 ) return $matches[0];
                    $checked_images++;
                    return wpturbo_replace_img_url( $matches[0], $original_url, $upload_baseurl, $upload_basedir, $extension );
                },
                $content
            );

            // Replace <a href>
            $content = preg_replace_callback(
                '/<a[^>]+href=["\']([^"\']+\.(?:jpg|jpeg|png))["\'][^>]*>/i',
                function ( $matches ) use ( &$checked_images, &$changed_links, $upload_baseurl, $upload_basedir, $extension ) {
                    $original_url = $matches[1];
                    if ( strpos( $original_url, $upload_baseurl ) !== 0 ) return $matches[0];
                    $checked_images++;
                    $result = wpturbo_replace_img_url( $matches[0], $original_url, $upload_baseurl, $upload_basedir, $extension );
                    if ( $result !== $matches[0] ) $changed_links++;
                    return $result;
                },
                $content
            );

            // Replace srcset
            $content = preg_replace_callback(
                '/srcset=["\']([^"\']+)["\']/',
                function ( $matches ) use ( &$checked_images, $upload_baseurl, $upload_basedir, $extension ) {
                    $srcset     = $matches[1];
                    $new_srcset = preg_replace_callback(
                        '/([^\s,]+\.(?:jpg|jpeg|png))(\s+\d+[wx])?/i',
                        function ( $src_m ) use ( &$checked_images, $upload_baseurl, $upload_basedir, $extension ) {
                            $original_url = $src_m[1];
                            if ( strpos( $original_url, $upload_baseurl ) !== 0 ) return $src_m[0];
                            $checked_images++;
                            $replaced = wpturbo_replace_url_string( $original_url, $upload_baseurl, $upload_basedir, $extension );
                            return $replaced . ( $src_m[2] ?? '' );
                        },
                        $srcset
                    );
                    return 'srcset="' . $new_srcset . '"';
                },
                $content
            );
        }

        if ( $content !== $original_content ) {
            wp_update_post( [ 'ID' => $post_id, 'post_content' => $content ] );
            $updated_count++;
        }
    }

    /* translators: %1$d: number of posts updated, %2$d: number of images checked, %3$d: number of links changed */
    wpturbo_add_log_entry( sprintf( __( 'URL fix complete. Posts updated: %1$d, Images checked: %2$d, Links changed: %3$d', 'pixrefiner' ), $updated_count, $checked_images, $changed_links ) );
    /* translators: %1$d: number of posts updated, %2$d: number of images checked */
    wp_send_json_success( [ 'message' => sprintf( __( 'URL fix complete. %1$d posts updated, %2$d images checked.', 'pixrefiner' ), $updated_count, $checked_images ) ] );
}

function wpturbo_replace_url_string( $original_url, $baseurl, $basedir, $extension ) {
    $dirname      = pathinfo( $original_url, PATHINFO_DIRNAME );
    $filename     = pathinfo( $original_url, PATHINFO_FILENAME );
    $new_url      = $dirname . '/' . $filename . '.' . $extension;
    $scaled_url   = $dirname . '/' . $filename . '-scaled.' . $extension;
    $new_path     = str_replace( $baseurl, $basedir, $new_url );
    $scaled_path  = str_replace( $baseurl, $basedir, $scaled_url );

    if ( file_exists( $scaled_path ) ) return $scaled_url;
    if ( file_exists( $new_path ) )    return $new_url;

    $base_name       = preg_replace( '/(-\d+x\d+|-scaled)$/', '', $filename );
    $fallback_url    = $dirname . '/' . $base_name . '.' . $extension;
    $fallback_scaled = $dirname . '/' . $base_name . '-scaled.' . $extension;
    if ( file_exists( str_replace( $baseurl, $basedir, $fallback_scaled ) ) ) return $fallback_scaled;
    if ( file_exists( str_replace( $baseurl, $basedir, $fallback_url ) ) )    return $fallback_url;

    return $original_url;
}

function wpturbo_replace_img_url( $tag, $original_url, $baseurl, $basedir, $extension ) {
    $new_url = wpturbo_replace_url_string( $original_url, $baseurl, $basedir, $extension );
    if ( $new_url !== $original_url ) return str_replace( $original_url, $new_url, $tag );
    return $tag;
}

// ─── Export media ZIP ─────────────────────────────────────────────────────────

function wpturbo_export_media_zip() {
    check_ajax_referer( 'webp_converter_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( __( 'Permission denied', 'pixrefiner' ) );
    }

    wp_raise_memory_limit( 'admin' );
    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    set_time_limit( 0 );

    $attachments = get_posts( [
        'post_type'      => 'attachment',
        'post_mime_type' => 'image',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    if ( empty( $attachments ) ) {
        wp_send_json_error( __( 'No media files found', 'pixrefiner' ) );
    }

    if ( ! class_exists( 'ZipArchive' ) ) {
        wp_send_json_error( __( 'ZipArchive is not available on this server', 'pixrefiner' ) );
    }

    $temp_file = tempnam( sys_get_temp_dir(), 'webp_media_export_' );
    if ( ! $temp_file ) {
        wp_send_json_error( __( 'Failed to create temporary file', 'pixrefiner' ) );
    }

    $zip = new ZipArchive();
    if ( $zip->open( $temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
        wp_delete_file( $temp_file );
        wp_send_json_error( __( 'Failed to create ZIP archive', 'pixrefiner' ) );
    }

    $upload_dir          = wp_upload_dir()['basedir'];
    $log                 = get_option( 'webp_conversion_log', [] );
    $possible_extensions = [ 'jpg', 'jpeg', 'png', 'webp', 'avif' ];

    foreach ( $attachments as $attachment_id ) {
        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            /* translators: %d: attachment/image ID */
            $log[] = sprintf( __( 'Skipped: Main file not found for Attachment ID %d', 'pixrefiner' ), $attachment_id );
            continue;
        }

        $dirname      = dirname( $file_path );
        $base_name    = pathinfo( $file_path, PATHINFO_FILENAME );
        $relative_dir = str_replace( $upload_dir . '/', '', $dirname );

        $zip->addFile( $file_path, $relative_dir . '/' . basename( $file_path ) );
        /* translators: %1$s: filename, %2$d: attachment/image ID */
        $log[] = sprintf( __( 'Added to ZIP: %1$s (Attachment ID %2$d)', 'pixrefiner' ), basename( $file_path ), $attachment_id );

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( $metadata && isset( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size => $size_data ) {
                $size_file = $dirname . '/' . $size_data['file'];
                if ( file_exists( $size_file ) ) {
                    $zip->addFile( $size_file, $relative_dir . '/' . $size_data['file'] );
                    /* translators: %1$s: filename, %2$s: size key, %3$d: attachment/image ID */
                    $log[] = sprintf( __( 'Added to ZIP: %1$s (size: %2$s, Attachment ID %3$d)', 'pixrefiner' ), $size_data['file'], $size, $attachment_id );
                }
            }
        }

        foreach ( $possible_extensions as $ext ) {
            $related = "$dirname/$base_name.$ext";
            if ( file_exists( $related ) && $related !== $file_path ) {
                $zip->addFile( $related, $relative_dir . '/' . "$base_name.$ext" );
                /* translators: %1$s: filename, %2$d: attachment/image ID */
                $log[] = sprintf( __( 'Added to ZIP: Related file %1$s (Attachment ID %2$d)', 'pixrefiner' ), "$base_name.$ext", $attachment_id );
            }

            $pattern        = "$dirname/$base_name-*.$ext";
            $related_files  = glob( $pattern );
            $metadata_files = array_column( $metadata['sizes'] ?? [], 'file' );
            foreach ( $related_files as $rf ) {
                if ( $rf === $file_path || in_array( basename( $rf ), $metadata_files ) ) continue;
                $zip->addFile( $rf, $relative_dir . '/' . basename( $rf ) );
                /* translators: %1$s: filename, %2$d: attachment/image ID */
                $log[] = sprintf( __( 'Added to ZIP: Related size %1$s (Attachment ID %2$d)', 'pixrefiner' ), basename( $rf ), $attachment_id );
            }
        }
    }

    $zip->close();
    update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );

    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="media_export_' . gmdate( 'Y-m-d_H-i-s' ) . '.zip"' );
    header( 'Content-Length: ' . filesize( $temp_file ) );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
    readfile( $temp_file );
    flush();
    wp_delete_file( $temp_file );
    exit;
}
