<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Used-image-size detection ────────────────────────────────────────────────
//
// Best-effort, regex-based scan for which of WordPress's built-in sizes
// (wpturbo_default_size_candidates()) are actually referenced anywhere on the
// site, so wpturbo_limit_image_sizes() can stop generating files for sizes
// nothing displays instead of either generating all of them (safe but wasteful)
// or none of them (the bug this replaced — Elementor and others silently
// falling back to "thumbnail" for a size that no longer exists).
//
// This is heuristic, not exhaustive: it covers the common cases (Elementor
// widget size controls, core image block / classic editor markup, direct
// theme template calls) but can't see every possible way a size could be
// requested — a custom page builder, a JS-driven REST request, a dynamically
// built string. Review the results in the admin UI before saving them, and
// re-run the scan after major theme, content, or page-builder changes.

function wpturbo_scan_used_image_sizes() {
    $candidates = wpturbo_default_size_candidates();
    $found      = []; // size => [ 'source label' => true, ... ]

    $mark = function ( $size, $source ) use ( &$found, $candidates ) {
        if ( ! in_array( $size, $candidates, true ) ) return;
        $found[ $size ][ $source ] = true;
    };

    // ── Post content + Elementor data ──────────────────────────────────────
    $post_types = array_unique( array_merge(
        get_post_types( [ 'public' => true ], 'names' ),
        [ 'wp_template', 'wp_template_part', 'wp_block' ]
    ) );
    $posts = get_posts( [ 'post_type' => $post_types, 'posts_per_page' => -1, 'fields' => 'ids' ] );

    foreach ( $posts as $post_id ) {
        $content = get_post_field( 'post_content', $post_id );

        // Core image blocks / classic editor markup: class="... size-medium ..."
        if ( preg_match_all( '/\bsize-([a-zA-Z0-9_-]+)\b/', $content, $m ) ) {
            foreach ( $m[1] as $size ) $mark( $size, __( 'post content (size-* class)', 'pixrefiner' ) );
        }
        // Gutenberg block comment attributes: "sizeSlug":"medium"
        if ( preg_match_all( '/"sizeSlug"\s*:\s*"([a-zA-Z0-9_-]+)"/', $content, $m ) ) {
            foreach ( $m[1] as $size ) $mark( $size, __( 'post content (block sizeSlug)', 'pixrefiner' ) );
        }

        // Elementor widget size controls are stored as "*_size":"slug" pairs
        // in the _elementor_data JSON postmeta (e.g. "image_size":"medium",
        // "thumbnail_size":"large") — not in post_content at all.
        $elementor_data = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! empty( $elementor_data ) && preg_match_all( '/"[a-zA-Z0-9_]*_size"\s*:\s*"([a-zA-Z0-9_-]+)"/', $elementor_data, $m ) ) {
            foreach ( $m[1] as $size ) $mark( $size, __( 'Elementor data', 'pixrefiner' ) );
        }
    }

    // ── Active theme templates (child + parent) ────────────────────────────
    $theme_dirs = array_unique( array_filter( [ get_stylesheet_directory(), get_template_directory() ] ) );
    $fn_pattern = '/\b(?:the_post_thumbnail|wp_get_attachment_image|wp_get_attachment_image_src|wp_get_attachment_image_url|get_the_post_thumbnail_url)\s*\([^)]*?[\'"]([a-zA-Z0-9_-]+)[\'"]\s*\)/';
    $skip_dirs  = [ 'node_modules', '.git', 'vendor' ];

    foreach ( $theme_dirs as $dir ) {
        if ( ! is_dir( $dir ) ) continue;

        $dir_filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            function ( $current ) use ( $skip_dirs ) {
                return ! $current->isDir() || ! in_array( $current->getFilename(), $skip_dirs, true );
            }
        );
        $iterator = new RecursiveIteratorIterator( $dir_filter );

        foreach ( $iterator as $file_info ) {
            if ( ! $file_info->isFile() || strtolower( $file_info->getExtension() ) !== 'php' ) continue;

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $code = file_get_contents( $file_info->getRealPath() );
            if ( $code === false ) continue;

            if ( preg_match_all( $fn_pattern, $code, $m ) ) {
                foreach ( $m[1] as $size ) {
                    $mark( $size, sprintf(
                        /* translators: %s: theme template filename */
                        __( 'theme template (%s)', 'pixrefiner' ),
                        $file_info->getFilename()
                    ) );
                }
            }
        }
    }

    // Safety net: only keep matches against sizes that are actually
    // registered right now, in case a regex partially matched something
    // unrelated to a real image size.
    $registered = get_intermediate_image_sizes();
    $found      = array_intersect_key( $found, array_flip( $registered ) );

    update_option( 'webp_detected_used_sizes', array_keys( $found ) );
    update_option( 'webp_detected_used_sizes_detail', $found );
    update_option( 'webp_detected_used_sizes_scanned_at', time() );

    $log = get_option( 'webp_conversion_log', [] );
    if ( empty( $found ) ) {
        $log[] = __( 'Size scan complete: no references found for medium, medium_large, large, 1536x1536, or 2048x2048 in post content, Elementor data, or theme templates.', 'pixrefiner' );
    } else {
        foreach ( $found as $size => $sources ) {
            /* translators: %1$s: image size slug, %2$s: comma-separated list of where it was found */
            $log[] = sprintf( __( 'Size scan: "%1$s" referenced via %2$s', 'pixrefiner' ), $size, implode( ', ', array_keys( $sources ) ) );
        }
    }
    update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );

    return $found;
}

// ─── Prune already-generated files for dropped default sizes ──────────────────
//
// The kept-sizes choice above (wpturbo_get_kept_default_sizes()) only changes
// which sizes get generated for *new* conversions — it doesn't retroactively
// touch files WordPress already generated for existing media. This walks every
// already-converted attachment and, for each default size that's no longer
// kept, deletes its file and drops it from the attachment's metadata.

function wpturbo_prune_unused_default_sizes() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! isset( $_GET['prune_unused_default_sizes'] ) || ! wpturbo_check_settings_nonce() ) return false;

    $kept = wpturbo_get_kept_default_sizes();
    if ( $kept === null ) {
        wpturbo_add_log_entry( __( 'Prune skipped: no kept-sizes choice has been saved yet. Run "Scan Site for Used Sizes" and "Save Kept Sizes" first.', 'pixrefiner' ) );
        return false;
    }

    $to_prune = array_diff( wpturbo_default_size_candidates(), $kept );
    if ( empty( $to_prune ) ) {
        wpturbo_add_log_entry( __( 'Prune skipped: every default size is currently kept, nothing to remove.', 'pixrefiner' ) );
        return true;
    }

    wp_raise_memory_limit( 'admin' );
    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
    set_time_limit( 0 );

    $attachments = get_posts( [
        'post_type'      => 'attachment',
        'post_mime_type' => [ 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ],
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    $files_deleted        = 0;
    $attachments_touched  = 0;

    foreach ( $attachments as $attachment_id ) {
        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( ! $meta || empty( $meta['sizes'] ) ) continue;

        $file    = get_attached_file( $attachment_id );
        $dirname = $file ? dirname( $file ) : '';
        $changed = false;

        foreach ( $to_prune as $size ) {
            if ( ! isset( $meta['sizes'][ $size ] ) ) continue;

            if ( $dirname && ! empty( $meta['sizes'][ $size ]['file'] ) ) {
                $size_file = $dirname . '/' . $meta['sizes'][ $size ]['file'];
                if ( file_exists( $size_file ) ) {
                    wp_delete_file( $size_file );
                    if ( ! file_exists( $size_file ) ) {
                        $files_deleted++;
                        /* translators: %1$s: image size slug, %2$s: filename, %3$d: attachment ID */
                        wpturbo_add_log_entry( sprintf( __( 'Pruned "%1$s" size: %2$s (Attachment ID %3$d)', 'pixrefiner' ), $size, basename( $size_file ), $attachment_id ) );
                    }
                }
            }
            unset( $meta['sizes'][ $size ] );
            $changed = true;
        }

        if ( $changed ) {
            wp_update_attachment_metadata( $attachment_id, $meta );
            $attachments_touched++;
        }
    }

    /* translators: %1$d: number of files removed, %2$d: number of attachments updated, %3$s: comma-separated list of pruned size slugs */
    wpturbo_add_log_entry( sprintf( __( 'Prune complete: %1$d files removed across %2$d attachments (sizes: %3$s).', 'pixrefiner' ), $files_deleted, $attachments_touched, implode( ', ', $to_prune ) ) );

    return true;
}
