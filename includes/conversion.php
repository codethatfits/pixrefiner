<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Image size registration ──────────────────────────────────────────────────

add_filter( 'intermediate_image_sizes_advanced', 'wpturbo_limit_image_sizes', 99 );
function wpturbo_limit_image_sizes( $sizes ) {
    if ( wpturbo_get_disable_auto_conversion() ) return $sizes;

    // Suppress our own plugin-registered custom-XXX breakpoints (handled
    // directly by wpturbo_convert_to_format() below, not WP's own subsize
    // machinery). Third-party/theme-registered sizes (WooCommerce, theme
    // sizes) are always left alone — this plugin doesn't get to decide
    // whether another plugin's own sizes are needed.
    //
    // WordPress's own default sizes (medium, medium_large, large, 1536x1536,
    // 2048x2048) are kept or dropped based on wpturbo_get_kept_default_sizes():
    // null (the scan in includes/detection.php has never been confirmed) means
    // keep all of them — the safe default, so nothing on the site (Elementor
    // widgets, theme templates) referencing a default size by name silently
    // falls back to a mismatched size (Elementor in particular falls back to
    // "thumbnail" when a widget's configured size can't be resolved). Once
    // confirmed via the admin UI, only the chosen sizes are generated.
    $kept_defaults = wpturbo_get_kept_default_sizes();
    if ( $kept_defaults !== null ) {
        foreach ( wpturbo_default_size_candidates() as $key ) {
            if ( ! in_array( $key, $kept_defaults, true ) ) {
                unset( $sizes[ $key ] );
            }
        }
    }

    $mode       = wpturbo_get_resize_mode();
    $max_values = ( $mode === 'width' ) ? wpturbo_get_max_widths() : wpturbo_get_max_heights();
    foreach ( array_slice( $max_values, 1, 3 ) as $v ) {
        unset( $sizes[ "custom-$v" ] );
    }

    if ( ! isset( $sizes['thumbnail'] ) ) {
        $sizes['thumbnail'] = [ 'width' => 150, 'height' => 150, 'crop' => true ];
    }

    return $sizes;
}

add_action( 'after_setup_theme', 'wpturbo_register_custom_sizes' );
function wpturbo_register_custom_sizes() {
    $mode       = wpturbo_get_resize_mode();
    $max_values = ( $mode === 'width' ) ? wpturbo_get_max_widths() : wpturbo_get_max_heights();
    foreach ( array_slice( $max_values, 1, 3 ) as $value ) {
        if ( $mode === 'width' ) {
            add_image_size( "custom-$value", $value, 0, false );
        } else {
            add_image_size( "custom-$value", 0, $value, false );
        }
    }
}

// ─── Core format conversion ───────────────────────────────────────────────────

function wpturbo_convert_to_format( $file_path, $dimension, &$log = null, $attachment_id = null, $suffix = '', $out_base = '' ) {
    $use_avif  = wpturbo_get_use_avif();
    $format    = $use_avif ? 'image/avif' : 'image/webp';
    $extension = $use_avif ? '.avif' : '.webp';
    $path_info = pathinfo( $file_path );
    $out_base  = ( $out_base !== '' ) ? $out_base : $path_info['filename'];
    $out_path  = $path_info['dirname'] . '/' . $out_base . $suffix . $extension;
    $quality   = wpturbo_get_quality();
    $mode      = wpturbo_get_resize_mode();

    if ( ! ( extension_loaded( 'imagick' ) || extension_loaded( 'gd' ) ) ) {
        /* translators: %s: image filename */
        if ( $log !== null ) $log[] = sprintf( __( 'Error: No image library (Imagick/GD) available for %s', 'pixrefiner' ), basename( $file_path ) );
        return false;
    }

    $has_avif = ( extension_loaded( 'imagick' ) && in_array( 'AVIF', Imagick::queryFormats() ) )
             || ( extension_loaded( 'gd' ) && function_exists( 'imageavif' ) );
    if ( $use_avif && ! $has_avif ) {
        /* translators: %s: image filename */
        if ( $log !== null ) $log[] = sprintf( __( 'Error: AVIF not supported on this server for %s', 'pixrefiner' ), basename( $file_path ) );
        return false;
    }

    $editor = wp_get_image_editor( $file_path );
    if ( is_wp_error( $editor ) ) {
        /* translators: %1$s: image filename, %2$s: error message */
        if ( $log !== null ) $log[] = sprintf( __( 'Error: Image editor failed for %1$s - %2$s', 'pixrefiner' ), basename( $file_path ), $editor->get_error_message() );
        return false;
    }

    $dims = $editor->get_size();
    if ( $mode === 'width' && $dims['width'] > $dimension ) {
        $editor->resize( $dimension, null, false );
        $resized = true;
    } elseif ( $mode === 'height' && $dims['height'] > $dimension ) {
        $editor->resize( null, $dimension, false );
        $resized = true;
    } else {
        $resized = false;
    }

    $result = $editor->save( $out_path, $format, [ 'quality' => $quality ] );
    if ( is_wp_error( $result ) ) {
        /* translators: %1$s: image filename, %2$s: error message */
        if ( $log !== null ) $log[] = sprintf( __( 'Error: Conversion failed for %1$s - %2$s', 'pixrefiner' ), basename( $file_path ), $result->get_error_message() );
        return false;
    }

    if ( $log !== null ) {
        if ( $resized ) {
            /* translators: %1$s: original filename, %2$s: output filename, %3$d: dimension in pixels, %4$s: resize mode (width or height), %5$d: quality value */
            $log[] = sprintf( __( 'Converted: %1$s → %2$s (resized to %3$dpx %4$s, quality %5$d)', 'pixrefiner' ), basename( $file_path ), basename( $out_path ), $dimension, $mode, $quality );
        } else {
            /* translators: %1$s: original filename, %2$s: output filename, %3$d: quality value */
            $log[] = sprintf( __( 'Converted: %1$s → %2$s (quality %3$d)', 'pixrefiner' ), basename( $file_path ), basename( $out_path ), $quality );
        }
    }

    return $out_path;
}

// WordPress only de-duplicates an upload against files with the *uploaded*
// extension, so "photo.jpg" is accepted as-is even when "photo.webp" (from an
// earlier upload whose original was deleted) is already there. Converting it
// would then overwrite the other attachment's files. Pick a base name whose
// converted output and breakpoint variants are all free, adding -1, -2, …
// the same way WordPress does.
//
// By default any existing file counts as taken (right for a brand-new upload,
// where renaming is harmless). Bulk conversion passes $taken_files instead —
// the files other attachments own — so an attachment's own leftovers from an
// earlier format switch don't push it to a needless -1 name, which would
// also defeat the extension-swap the post URL fixer relies on.
function wpturbo_unique_output_base( $file_path, $extension, $max_values, $taken_files = null ) {
    $dirname  = dirname( $file_path );
    $base     = pathinfo( $file_path, PATHINFO_FILENAME );
    $suffixes = [ '', '-150x150' ];
    foreach ( array_slice( $max_values, 1 ) as $dimension ) {
        $suffixes[] = "-{$dimension}";
    }

    $candidate = $base;
    $number    = 1;
    while ( true ) {
        $taken = false;
        foreach ( $suffixes as $suffix ) {
            $p = "$dirname/$candidate$suffix.$extension";
            $is_taken = ( $taken_files === null )
                ? ( $p !== $file_path && file_exists( $p ) )
                : isset( $taken_files[ $p ] );
            if ( $is_taken ) {
                $taken = true;
                break;
            }
        }
        if ( ! $taken ) return $candidate;
        $candidate = "$base-$number";
        $number++;
    }
}

// Files in $dirname that some attachment other than $attachment_id lists as
// its own (main file, sizes, pre-scaling original), keyed by path.
//
// Only attachments that could collide with names built from $base are
// loaded: those whose base starts with $base (photo-1, photo-1200, …) and
// those whose base is a "-"-separated prefix of it (photo owns
// photo-1200.webp, which "photo-1200.jpg" would otherwise convert onto).
// Loading every attachment in the folder would be far too slow on sites
// that don't use year/month upload folders.
function wpturbo_files_owned_by_other_attachments( $dirname, $base, $attachment_id ) {
    global $wpdb;
    $basedir  = wp_upload_dir()['basedir'];
    $relative = ltrim( substr( $dirname, strlen( $basedir ) ), '/' );
    $prefix   = ( $relative === '' ) ? '' : $relative . '/';

    $where  = [ 'meta_value LIKE %s' ];
    $params = [ $wpdb->esc_like( $prefix . $base ) . '%' ];
    $parts  = explode( '-', $base );
    for ( $i = 1; $i < count( $parts ); $i++ ) {
        $where[]  = 'meta_value LIKE %s';
        $params[] = $wpdb->esc_like( $prefix . implode( '-', array_slice( $parts, 0, $i ) ) . '.' ) . '%';
    }
    $params[] = $attachment_id;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND ( " . implode( ' OR ', $where ) . ' ) AND post_id != %d',
        $params
    ) );
    update_meta_cache( 'post', $ids );

    $owned = [];
    foreach ( $ids as $id ) {
        $file = get_attached_file( $id );
        if ( ! $file || dirname( $file ) !== $dirname ) continue;
        $owned[ $file ] = true;
        $meta = wp_get_attachment_metadata( $id );
        foreach ( $meta['sizes'] ?? [] as $size_data ) {
            if ( ! empty( $size_data['file'] ) ) $owned[ "$dirname/{$size_data['file']}" ] = true;
        }
        if ( ! empty( $meta['original_image'] ) ) $owned[ "$dirname/{$meta['original_image']}" ] = true;
    }
    return $owned;
}

// ─── Auto-convert on upload ───────────────────────────────────────────────────

add_filter( 'wp_handle_upload', 'wpturbo_handle_upload_convert_to_format', 10, 1 );
function wpturbo_handle_upload_convert_to_format( $upload ) {
    if ( wpturbo_get_disable_auto_conversion() ) return $upload;

    $file_ext = strtolower( pathinfo( $upload['file'], PATHINFO_EXTENSION ) );
    if ( ! in_array( $file_ext, [ 'jpg', 'jpeg', 'png', 'webp', 'avif' ] ) ) return $upload;

    $use_avif    = wpturbo_get_use_avif();
    $format      = $use_avif ? 'image/avif' : 'image/webp';
    $file_path   = $upload['file'];
    $uploads_dir = dirname( $file_path );
    $log         = get_option( 'webp_conversion_log', [] );

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
    if ( ! is_writable( $uploads_dir ) ) {
        /* translators: %s: directory path */
        $log[] = sprintf( __( 'Error: Uploads directory %s is not writable', 'pixrefiner' ), $uploads_dir );
        update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
        return $upload;
    }

    $file_size_kb = filesize( $file_path ) / 1024;
    $min_size_kb  = wpturbo_get_min_size_kb();
    if ( $min_size_kb > 0 && $file_size_kb < $min_size_kb ) {
        /* translators: %1$s: image filename, %2$s: file size in KB, %3$d: minimum size threshold in KB */
        $log[] = sprintf( __( 'Skipped: %1$s (size %2$s KB < %3$d KB)', 'pixrefiner' ), basename( $file_path ), round( $file_size_kb, 2 ), $min_size_kb );
        update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
        return $upload;
    }

    $mode          = wpturbo_get_resize_mode();
    $max_values    = ( $mode === 'width' ) ? wpturbo_get_max_widths() : wpturbo_get_max_heights();
    $attachment_id = attachment_url_to_postid( $upload['url'] );
    $new_files     = [];

    $editor = wp_get_image_editor( $file_path );
    if ( is_wp_error( $editor ) ) {
        /* translators: %1$s: image filename, %2$s: error message */
        $log[] = sprintf( __( 'Error: Image editor failed for %1$s - %2$s', 'pixrefiner' ), basename( $file_path ), $editor->get_error_message() );
        update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
        return $upload;
    }
    $dims           = $editor->get_size();
    $original_width = $dims['width'];

    $valid_max_values = $max_values;
    if ( $mode === 'width' ) {
        $valid_max_values = array_filter( $max_values, fn( $w, $i ) => $i === 0 || $w <= $original_width, ARRAY_FILTER_USE_BOTH );
    }

    $out_base = wpturbo_unique_output_base( $file_path, $use_avif ? 'avif' : 'webp', $valid_max_values );
    if ( $out_base !== pathinfo( $file_path, PATHINFO_FILENAME ) ) {
        /* translators: %1$s: uploaded filename, %2$s: new base filename */
        $log[] = sprintf( __( 'Renamed: %1$s → %2$s (a file with that name already exists)', 'pixrefiner' ), basename( $file_path ), $out_base );
    }

    $original_upload = $upload;
    foreach ( $valid_max_values as $index => $dimension ) {
        $suffix        = ( $index === 0 ) ? '' : "-{$dimension}";
        $new_file_path = wpturbo_convert_to_format( $file_path, $dimension, $log, $attachment_id, $suffix, $out_base );
        if ( $new_file_path ) {
            if ( $index === 0 ) {
                $upload['file'] = $new_file_path;
                $upload['url']  = str_replace( basename( $file_path ), basename( $new_file_path ), $upload['url'] );
                $upload['type'] = $format;
            }
            $new_files[] = $new_file_path;
        } else {
            // Never delete the source: an upload already in the target format
            // is re-saved in place, so it can appear in $new_files.
            foreach ( $new_files as $f ) {
                if ( $f !== $file_path && file_exists( $f ) ) wp_delete_file( $f );
            }
            /* translators: %s: image filename */
            $log[] = sprintf( __( 'Error: Conversion failed for %s, rolling back', 'pixrefiner' ), basename( $file_path ) );
            /* translators: %s: image filename */
            $log[] = sprintf( __( 'Original preserved: %s', 'pixrefiner' ), basename( $file_path ) );
            update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
            // Hand WordPress the original file, not the converted one just deleted.
            return $original_upload;
        }
    }

    // WordPress generates thumbnail and all registered third-party sizes
    // (WooCommerce, theme sizes, etc.) from the converted file when it calls
    // wp_generate_attachment_metadata after the attachment post is created.
    // wpturbo_fix_format_metadata registers our custom breakpoints in that metadata.

    // Compare paths rather than extensions: an upload already in the target
    // format that had to be renamed above leaves its source file behind too.
    if ( $file_path !== $upload['file'] && file_exists( $file_path ) && ! wpturbo_get_preserve_originals() ) {
        $attempts     = 0;
        $chmod_failed = false;
        while ( $attempts < 5 && file_exists( $file_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
            if ( ! is_writable( $file_path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
                @chmod( $file_path, 0644 );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
                if ( ! is_writable( $file_path ) ) {
                    if ( $chmod_failed ) {
                        /* translators: %s: image filename */
                        $log[] = sprintf( __( 'Error: Cannot make %s writable after retry - skipping deletion', 'pixrefiner' ), basename( $file_path ) );
                        break;
                    }
                    $chmod_failed = true;
                }
            }
            wp_delete_file( $file_path );
            if ( ! file_exists( $file_path ) ) {
                /* translators: %s: image filename */
                $log[] = sprintf( __( 'Deleted original: %s', 'pixrefiner' ), basename( $file_path ) );
                break;
            }
            $attempts++;
            // Brief backoff for transient locks (e.g. AV scan, replication lag) —
            // short enough that 5 retries don't stall the upload response for seconds.
            usleep( 200000 );
        }
        if ( file_exists( $file_path ) ) {
            /* translators: %s: image filename */
            $log[] = sprintf( __( 'Error: Failed to delete original %s after 5 retries', 'pixrefiner' ), basename( $file_path ) );
        }
    }

    update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    return $upload;
}

// ─── Fix metadata for converted images ───────────────────────────────────────

add_filter( 'wp_generate_attachment_metadata', 'wpturbo_fix_format_metadata', 10, 2 );
function wpturbo_fix_format_metadata( $metadata, $attachment_id ) {
    $use_avif  = wpturbo_get_use_avif();
    $extension = $use_avif ? 'avif' : 'webp';
    $format    = $use_avif ? 'image/avif' : 'image/webp';

    $file = get_attached_file( $attachment_id );
    if ( pathinfo( $file, PATHINFO_EXTENSION ) !== $extension ) return $metadata;

    $uploads    = wp_upload_dir();
    $dirname    = dirname( $file );
    $base_name  = pathinfo( basename( $file ), PATHINFO_FILENAME );
    $mode       = wpturbo_get_resize_mode();
    $max_values = ( $mode === 'width' ) ? wpturbo_get_max_widths() : wpturbo_get_max_heights();

    $metadata['file']      = str_replace( $uploads['basedir'] . '/', '', $file );
    $metadata['mime_type'] = $format;

    foreach ( $max_values as $index => $dimension ) {
        if ( $index === 0 ) continue;
        $size_file = "$dirname/$base_name-$dimension.$extension";
        if ( file_exists( $size_file ) ) {
            $size_dims = wp_getimagesize( $size_file );
            $metadata['sizes']["custom-$dimension"] = [
                'file'      => "$base_name-$dimension.$extension",
                'width'     => $size_dims ? $size_dims[0] : ( ( $mode === 'width' ) ? $dimension : 0 ),
                'height'    => $size_dims ? $size_dims[1] : ( ( $mode === 'height' ) ? $dimension : 0 ),
                'mime-type' => $format,
            ];
        }
    }

    $thumb_file = "$dirname/$base_name-150x150.$extension";
    if ( file_exists( $thumb_file ) ) {
        $metadata['sizes']['thumbnail'] = [
            'file'      => "$base_name-150x150.$extension",
            'width'     => 150,
            'height'    => 150,
            'mime-type' => $format,
        ];
    }

    $stamp = [
        'format'      => $use_avif ? 'avif' : 'webp',
        'quality'     => wpturbo_get_quality(),
        'resize_mode' => $mode,
        'max_values'  => $max_values,
    ];
    $metadata['pixrefiner_stamp'] = $stamp;

    $log   = get_option( 'webp_conversion_log', [] );
    $log[] = 'Stamp set via metadata hook for Attachment ID ' . $attachment_id . ': ' . json_encode( $stamp );
    update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );

    return $metadata;
}

// ─── Batch conversion (AJAX handler) ─────────────────────────────────────────

function wpturbo_convert_single_image() {
    check_ajax_referer( 'webp_converter_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['offset'] ) ) {
        wp_send_json_error( __( 'Permission denied or invalid offset', 'pixrefiner' ) );
    }

    $offset       = absint( $_POST['offset'] );
    $batch_size   = wpturbo_get_batch_size();
    $mode         = wpturbo_get_resize_mode();
    $max_values   = ( $mode === 'width' ) ? wpturbo_get_max_widths() : wpturbo_get_max_heights();
    $current_ext  = wpturbo_get_use_avif() ? 'avif' : 'webp';
    $format       = wpturbo_get_use_avif() ? 'image/avif' : 'image/webp';
    $current_qual = wpturbo_get_quality();

    wp_raise_memory_limit( 'image' );
    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    set_time_limit( max( 30, 10 * $batch_size ) );

    $attachments = get_posts( [
        'post_type'      => 'attachment',
        'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ],
        'posts_per_page' => $batch_size,
        'offset'         => $offset,
        'fields'         => 'ids',
        'post__not_in'   => wpturbo_get_excluded_images(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
    ] );

    $log = get_option( 'webp_conversion_log', [] );

    if ( empty( $attachments ) ) {
        update_option( 'webp_conversion_complete', true );
        $log[] = "<strong style='color:#281E5D;'>" . __( 'Conversion Complete', 'pixrefiner' ) . '</strong>: ' . __( 'No more images to process', 'pixrefiner' );
        update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
        wp_send_json_success( [ 'complete' => true ] );
    }

    $expected_stamp = [
        'format'      => $current_ext,
        'quality'     => $current_qual,
        'resize_mode' => $mode,
        'max_values'  => $max_values,
    ];

    foreach ( $attachments as $attachment_id ) {
        $file_path = get_attached_file( $attachment_id );
        if ( ! file_exists( $file_path ) ) continue;

        $meta           = wp_get_attachment_metadata( $attachment_id );
        $existing_stamp = $meta['pixrefiner_stamp'] ?? null;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! empty( $existing_stamp ) && $existing_stamp === $expected_stamp && empty( $_GET['force_reconvert'] ) ) {
            /* translators: %d: attachment/image ID */
            $log[] = sprintf( __( 'Skipped: Already optimized Attachment ID %d', 'pixrefiner' ), $attachment_id );
            continue;
        }

        $dirname   = dirname( $file_path );
        $base_name = pathinfo( $file_path, PATHINFO_FILENAME );
        $ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $new_files = [];
        $success   = true;
        $original_mime = get_post_mime_type( $attachment_id );

        $old_sizes     = $meta['sizes'] ?? [];
        $main_size     = $max_values[0] ?? null;
        $meta['sizes'] = [];

        foreach ( $old_sizes as $size_key => $size_data ) {
            if ( preg_match( '/custom-(\d+)/', $size_key, $m ) ) {
                $old_dim      = (int) $m[1];
                $is_redundant = ! in_array( $old_dim, $max_values ) || ( $main_size && $old_dim === $main_size );
                if ( $is_redundant ) {
                    $old_file = "$dirname/$base_name-$old_dim.$current_ext";
                    // Only delete it if it's this attachment's own size file —
                    // a same-named file can belong to a different attachment.
                    if ( ( $size_data['file'] ?? '' ) === basename( $old_file ) && file_exists( $old_file ) ) {
                        wp_delete_file( $old_file );
                        /* translators: %s: image filename */
                        $log[] = sprintf( __( 'Deleted duplicate or outdated size: %s', 'pixrefiner' ), basename( $old_file ) );
                    }
                } else {
                    $meta['sizes'][ $size_key ] = $size_data;
                }
            } elseif ( $size_key === 'thumbnail' ) {
                $meta['sizes']['thumbnail'] = $size_data;
            }
        }

        $editor = wp_get_image_editor( $file_path );
        if ( is_wp_error( $editor ) ) {
            /* translators: %1$s: image filename, %2$s: error message */
            $log[] = sprintf( __( 'Error: Image editor failed for %1$s - %2$s', 'pixrefiner' ), basename( $file_path ), $editor->get_error_message() );
            continue;
        }
        $dims           = $editor->get_size();
        $original_width = $dims['width'];

        $valid_max_values = $max_values;
        if ( $mode === 'width' ) {
            $valid_max_values = array_filter( $max_values, fn( $w, $i ) => $i === 0 || $w <= $original_width, ARRAY_FILTER_USE_BOTH );
            if ( count( $valid_max_values ) < count( $max_values ) ) {
                $skipped = array_diff( $max_values, $valid_max_values );
                /* translators: %1$s: comma-separated list of skipped pixel sizes, %2$s: image filename, %3$d: original image width in pixels */
                $log[]   = sprintf( __( 'Skipped sizes %1$s for %2$s (original width %3$dpx)', 'pixrefiner' ), implode( ', ', $skipped ), basename( $file_path ), $original_width );
            }
        }

        // This attachment's existing size files; a failed conversion must not
        // roll these back (delete them), as they predate this run.
        $own_files = [];
        foreach ( $old_sizes as $size_data ) {
            if ( ! empty( $size_data['file'] ) ) $own_files[] = "$dirname/{$size_data['file']}";
        }

        // Converting photo.jpg to photo.webp must not overwrite a different
        // attachment that already owns photo.webp (or its breakpoint files).
        $taken_files = wpturbo_files_owned_by_other_attachments( $dirname, $base_name, $attachment_id );
        $out_base    = wpturbo_unique_output_base( $file_path, $current_ext, $valid_max_values, $taken_files );
        if ( $out_base !== $base_name ) {
            /* translators: %1$s: image filename, %2$s: new base filename */
            $log[] = sprintf( __( 'Renamed: %1$s → %2$s (a file with that name already exists)', 'pixrefiner' ), basename( $file_path ), $out_base );
        }

        $main_output = null;
        foreach ( $valid_max_values as $index => $dimension ) {
            $suffix = ( $index === 0 ) ? '' : "-$dimension";
            $output = wpturbo_convert_to_format( $file_path, $dimension, $log, $attachment_id, $suffix, $out_base );
            if ( $output ) {
                if ( $index === 0 ) {
                    $main_output = $output;
                    update_attached_file( $attachment_id, $output );
                    wp_update_post( [ 'ID' => $attachment_id, 'post_mime_type' => $format ] );
                }
                $new_files[] = $output;
            } else {
                $success = false;
                break;
            }
        }

        $thumb_path = "$dirname/$out_base-150x150.$current_ext";
        if ( ! file_exists( $thumb_path ) ) {
            $editor = wp_get_image_editor( $file_path );
            if ( ! is_wp_error( $editor ) ) {
                $editor->resize( 150, 150, true );
                $saved = $editor->save( $thumb_path, $format, [ 'quality' => $current_qual ] );
                if ( ! is_wp_error( $saved ) ) {
                    $new_files[] = $thumb_path;
                    /* translators: %s: thumbnail filename */
                    $log[]       = sprintf( __( 'Generated thumbnail: %s', 'pixrefiner' ), basename( $thumb_path ) );
                } else {
                    $success = false;
                }
            } else {
                $success = false;
            }
        }

        if ( ! $success ) {
            // Re-converting an image already in the target format overwrites
            // its own files in place; deleting those would lose the image.
            foreach ( $new_files as $f ) {
                if ( $f !== $file_path && ! in_array( $f, $own_files, true ) && file_exists( $f ) ) wp_delete_file( $f );
            }
            // Point the attachment back at its original, untouched file.
            if ( ! empty( $new_files ) ) {
                update_attached_file( $attachment_id, $file_path );
                wp_update_post( [ 'ID' => $attachment_id, 'post_mime_type' => $original_mime ] );
            }
            /* translators: %s: image filename */
            $log[] = sprintf( __( 'Error: Conversion failed for %s, rolled back.', 'pixrefiner' ), basename( $file_path ) );
            continue;
        }

        if ( ! empty( $new_files ) ) {
            $meta = wp_generate_attachment_metadata( $attachment_id, $new_files[0] );
            if ( ! is_wp_error( $meta ) ) {
                // WordPress has generated thumbnail and all registered third-party sizes
                // (WooCommerce, theme sizes, etc.) in $meta['sizes'] via the
                // intermediate_image_sizes_advanced filter. Add our custom breakpoints
                // on top without discarding those entries.
                foreach ( $valid_max_values as $index => $dimension ) {
                    if ( $index === 0 ) continue;
                    $size_file = "$dirname/$out_base-$dimension.$current_ext";
                    if ( file_exists( $size_file ) ) {
                        $size_dims = wp_getimagesize( $size_file );
                        $meta['sizes']["custom-$dimension"] = [
                            'file'      => "$out_base-$dimension.$current_ext",
                            'width'     => $size_dims ? $size_dims[0] : ( ( $mode === 'width' ) ? $dimension : 0 ),
                            'height'    => $size_dims ? $size_dims[1] : ( ( $mode === 'height' ) ? $dimension : 0 ),
                            'mime-type' => $format,
                        ];
                    }
                }
                if ( file_exists( $thumb_path ) ) {
                    $meta['sizes']['thumbnail'] = [
                        'file'      => "$out_base-150x150.$current_ext",
                        'width'     => 150,
                        'height'    => 150,
                        'mime-type' => $format,
                    ];
                }
                $meta['webp_quality']     = $current_qual;
                $meta['pixrefiner_stamp'] = $expected_stamp;
                wp_update_attachment_metadata( $attachment_id, $meta );
            }
        }

        // Posts keep linking to the name the image was uploaded with
        // (photo.jpg). Record it on first conversion so the URL fixer can find
        // the image even if a later run renames it (e.g. photo.avif →
        // photo-1.webp after a format switch). add_post_meta( …, true ) never
        // overwrites, so the original upload name is the one kept. A rename
        // of an image converted before this was recorded saves its current
        // name instead, which is the best still known.
        if ( $main_output && ( in_array( $ext, [ 'jpg', 'jpeg', 'png' ], true ) || $out_base !== $base_name ) ) {
            add_post_meta( $attachment_id, '_pixrefiner_source_file', _wp_relative_upload_path( $file_path ), true );
        }

        if ( ! wpturbo_get_preserve_originals() && $main_output && $file_path !== $main_output && file_exists( $file_path ) ) {
            wp_delete_file( $file_path );
            /* translators: %s: image filename */
            $log[] = sprintf( __( 'Deleted original: %s', 'pixrefiner' ), basename( $file_path ) );
        }

        /* translators: %d: attachment/image ID */
        $log[] = sprintf( __( 'Converted Attachment ID %d successfully.', 'pixrefiner' ), $attachment_id );
    }

    update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    wp_send_json_success( [ 'complete' => false, 'offset' => $offset + $batch_size ] );
}

// ─── Cleanup leftover originals ───────────────────────────────────────────────

function wpturbo_cleanup_leftover_originals() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! isset( $_GET['cleanup_leftover_originals'] ) || ! wpturbo_check_settings_nonce() ) return false;

    $log             = get_option( 'webp_conversion_log', [] );
    $uploads_dir     = wp_upload_dir()['basedir'];
    $files           = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $uploads_dir ) );
    $deleted         = 0;
    $failed          = 0;
    $preserve_originals = wpturbo_get_preserve_originals();
    $use_avif        = wpturbo_get_use_avif();
    $current_ext     = $use_avif ? 'avif' : 'webp';
    $alternate_ext   = $use_avif ? 'webp' : 'avif';
    $mode            = wpturbo_get_resize_mode();
    $max_values      = ( $mode === 'width' ) ? wpturbo_get_max_widths() : wpturbo_get_max_heights();
    $excluded_images = wpturbo_get_excluded_images();

    $attachments = get_posts( [
        'post_type'      => 'attachment',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ],
    ] );

    $active_files = [];
    $known_bases  = [];

    foreach ( $attachments as $attachment_id ) {
        $file      = get_attached_file( $attachment_id );
        $metadata  = wp_get_attachment_metadata( $attachment_id );
        $dirname   = dirname( $file );
        $base_name = pathinfo( $file, PATHINFO_FILENAME );

        // Track every attachment's base name per directory, regardless of
        // exclusion status, so the deletion sweep below can confirm a
        // candidate file actually belongs to a tracked attachment.
        $known_bases[ $dirname ][ wpturbo_extract_attachment_base_name( $base_name ) ] = true;

        if ( in_array( $attachment_id, $excluded_images ) ) {
            if ( $file && file_exists( $file ) ) $active_files[ $file ] = true;
            foreach ( [ 'jpg', 'jpeg', 'png', 'webp', 'avif' ] as $ext ) {
                $p = "$dirname/$base_name.$ext";
                if ( file_exists( $p ) ) $active_files[ $p ] = true;
            }
            foreach ( $max_values as $index => $dimension ) {
                $suffix = ( $index === 0 ) ? '' : "-{$dimension}";
                foreach ( [ 'webp', 'avif' ] as $ext ) {
                    $p = "$dirname/$base_name$suffix.$ext";
                    if ( file_exists( $p ) ) $active_files[ $p ] = true;
                }
            }
            foreach ( [ "$dirname/$base_name-150x150.webp", "$dirname/$base_name-150x150.avif" ] as $p ) {
                if ( file_exists( $p ) ) $active_files[ $p ] = true;
            }
            if ( $metadata && isset( $metadata['sizes'] ) ) {
                foreach ( $metadata['sizes'] as $size_data ) {
                    $sf = "$dirname/{$size_data['file']}";
                    if ( file_exists( $sf ) ) $active_files[ $sf ] = true;
                }
            }
            continue;
        }

        if ( $file && file_exists( $file ) ) $active_files[ $file ] = true;

        if ( $preserve_originals ) {
            foreach ( [ 'jpg', 'jpeg', 'png' ] as $ext ) {
                $p = "$dirname/$base_name.$ext";
                if ( file_exists( $p ) ) $active_files[ $p ] = true;
            }
        }

        foreach ( $max_values as $index => $dimension ) {
            $suffix = ( $index === 0 ) ? '' : "-{$dimension}";
            $p      = "$dirname/$base_name$suffix.$current_ext";
            if ( file_exists( $p ) ) $active_files[ $p ] = true;
        }

        foreach ( [ "$dirname/$base_name-150x150.$current_ext" ] as $p ) {
            if ( file_exists( $p ) ) $active_files[ $p ] = true;
        }

        if ( $metadata && isset( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_data ) {
                $sf = "$dirname/{$size_data['file']}";
                if ( file_exists( $sf ) ) $active_files[ $sf ] = true;
            }
        }
    }

    foreach ( $files as $file_info ) {
        if ( ! $file_info->isFile() ) continue;

        $file_path = $file_info->getRealPath();
        $ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        if ( ! in_array( $ext, [ 'jpg', 'jpeg', 'png', 'webp', 'avif' ] ) ) continue;
        if ( isset( $active_files[ $file_path ] ) ) continue;

        // Safety net: only remove files that are clearly a derivative of a
        // media-library attachment PixRefiner tracks. Anything else in the
        // uploads folder (theme assets, other plugins' images, manually
        // placed files) is left alone even though it's "unreferenced" here.
        $candidate_dir  = dirname( $file_path );
        $candidate_base = wpturbo_extract_attachment_base_name( pathinfo( $file_path, PATHINFO_FILENAME ) );
        if ( empty( $known_bases[ $candidate_dir ][ $candidate_base ] ) ) continue;

        $should_delete = false;
        if ( in_array( $ext, [ 'jpg', 'jpeg', 'png' ] ) && ! $preserve_originals ) {
            $should_delete = true;
        } elseif ( $ext === $alternate_ext ) {
            $should_delete = true;
        } elseif ( $ext === $current_ext ) {
            // Any current-format file not in $active_files (checked above) is an orphan.
            $should_delete = true;
        }

        if ( $should_delete ) {
            wp_delete_file( $file_path );
            if ( ! file_exists( $file_path ) ) {
                /* translators: %s: image filename */
                $log[] = sprintf( __( 'Deleted leftover: %s', 'pixrefiner' ), basename( $file_path ) );
                $deleted++;
            } else {
                /* translators: %s: image filename */
                $log[] = sprintf( __( 'Failed to delete: %s', 'pixrefiner' ), basename( $file_path ) );
                $failed++;
            }
        }
    }

    /* translators: %1$d: number of files deleted, %2$d: number of files that failed to delete */
    $log[] = "<span style='font-weight:bold;color:#281E5D;'>" . sprintf( __( 'Cleanup Complete: %1$d deleted, %2$d failed', 'pixrefiner' ), $deleted, $failed ) . '</span>';
    update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    return true;
}

// ─── Custom srcset ────────────────────────────────────────────────────────────

add_filter( 'wp_calculate_image_srcset', 'wpturbo_custom_srcset', 10, 5 );
function wpturbo_custom_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
    if ( in_array( $attachment_id, wpturbo_get_excluded_images() ) ) return $sources;

    $use_avif   = wpturbo_get_use_avif();
    $extension  = $use_avif ? '.avif' : '.webp';
    $mode       = wpturbo_get_resize_mode();
    $max_values = ( $mode === 'width' ) ? wpturbo_get_max_widths() : wpturbo_get_max_heights();
    $upload_dir = wp_upload_dir();
    $base_path  = $upload_dir['basedir'] . '/' . dirname( $image_meta['file'] );
    $base_name  = pathinfo( $image_meta['file'], PATHINFO_FILENAME );
    $base_url   = $upload_dir['baseurl'] . '/' . dirname( $image_meta['file'] );

    foreach ( $max_values as $index => $dimension ) {
        if ( $index === 0 ) continue;
        $file = "$base_path/$base_name-$dimension$extension";
        if ( ! file_exists( $file ) ) continue;

        $size_key = "custom-$dimension";
        $width    = ( $mode === 'width' ) ? $dimension : ( $image_meta['sizes'][ $size_key ]['width'] ?? 0 );
        if ( $width > 0 ) {
            $sources[ $width ] = [
                'url'        => "$base_url/$base_name-$dimension$extension",
                'descriptor' => 'w',
                'value'      => $width,
            ];
        }
    }

    return $sources;
}
