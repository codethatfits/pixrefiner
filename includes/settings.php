<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Getters ──────────────────────────────────────────────────────────────────

function wpturbo_get_max_widths() {
    $value  = get_option( 'webp_max_widths', '1920,1200,600,300' );
    $widths = array_map( 'absint', array_filter( explode( ',', $value ) ) );
    $widths = array_filter( $widths, fn( $w ) => $w > 0 && $w <= 9999 );
    return array_slice( $widths, 0, 4 );
}

function wpturbo_get_max_heights() {
    $value   = get_option( 'webp_max_heights', '1080,720,480,360' );
    $heights = array_map( 'absint', array_filter( explode( ',', $value ) ) );
    $heights = array_filter( $heights, fn( $h ) => $h > 0 && $h <= 9999 );
    return array_slice( $heights, 0, 4 );
}

function wpturbo_get_resize_mode() {
    return get_option( 'webp_resize_mode', 'width' );
}

function wpturbo_get_quality() {
    return (int) get_option( 'webp_quality', 80 );
}

function wpturbo_get_batch_size() {
    return (int) get_option( 'webp_batch_size', 5 );
}

function wpturbo_get_preserve_originals() {
    return (bool) get_option( 'webp_preserve_originals', false );
}

function wpturbo_get_disable_auto_conversion() {
    return (bool) get_option( 'webp_disable_auto_conversion', false );
}

function wpturbo_get_min_size_kb() {
    return (int) get_option( 'webp_min_size_kb', 0 );
}

function wpturbo_get_use_avif() {
    return (bool) get_option( 'webp_use_avif', false );
}

function wpturbo_get_excluded_images() {
    $excluded = get_option( 'webp_excluded_images', [] );
    return is_array( $excluded ) ? array_map( 'absint', $excluded ) : [];
}

// ─── Exclusion helpers ────────────────────────────────────────────────────────

function wpturbo_add_excluded_image( $attachment_id ) {
    $attachment_id = absint( $attachment_id );
    $excluded      = wpturbo_get_excluded_images();
    if ( in_array( $attachment_id, $excluded ) ) return false;

    $excluded[] = $attachment_id;
    update_option( 'webp_excluded_images', array_unique( $excluded ) );
    $log   = get_option( 'webp_conversion_log', [] );
    /* translators: %d: attachment/image ID */
    $log[] = sprintf( __( 'Excluded image added: Attachment ID %d', 'pixrefiner' ), $attachment_id );
    update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    return true;
}

function wpturbo_remove_excluded_image( $attachment_id ) {
    $attachment_id = absint( $attachment_id );
    $excluded      = wpturbo_get_excluded_images();
    $index         = array_search( $attachment_id, $excluded );
    if ( $index === false ) return false;

    unset( $excluded[ $index ] );
    update_option( 'webp_excluded_images', array_values( $excluded ) );
    $log   = get_option( 'webp_conversion_log', [] );
    /* translators: %d: attachment/image ID */
    $log[] = sprintf( __( 'Excluded image removed: Attachment ID %d', 'pixrefiner' ), $attachment_id );
    update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    return true;
}

// ─── Shared nonce check (used by conversion.php cleanup handler) ──────────────

function wpturbo_check_settings_nonce() {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    return current_user_can( 'manage_options' )
        && wp_verify_nonce( $nonce, 'pixrefiner_settings' );
}

// ─── Setters (called via GET from admin page JS fetches) ──────────────────────
// Each setter calls wp_verify_nonce directly so PHPCS can track nonce
// verification within the function scope before any other $_GET access.

function wpturbo_clear_log() {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pixrefiner_settings' ) || ! current_user_can( 'manage_options' ) ) return false;
    if ( ! isset( $_GET['clear_log'] ) ) return false;
    update_option( 'webp_conversion_log', [ __( 'Log cleared', 'pixrefiner' ) ] );
    return true;
}

function wpturbo_reset_defaults() {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pixrefiner_settings' ) || ! current_user_can( 'manage_options' ) ) return false;
    if ( ! isset( $_GET['reset_defaults'] ) ) return false;
    update_option( 'webp_max_widths', '1920,1200,600,300' );
    update_option( 'webp_max_heights', '1080,720,480,360' );
    update_option( 'webp_resize_mode', 'width' );
    update_option( 'webp_quality', 80 );
    update_option( 'webp_batch_size', 5 );
    update_option( 'webp_preserve_originals', false );
    update_option( 'webp_disable_auto_conversion', false );
    update_option( 'webp_min_size_kb', 0 );
    update_option( 'webp_use_avif', false );
    $log   = get_option( 'webp_conversion_log', [] );
    $log[] = __( 'Settings reset to defaults', 'pixrefiner' );
    update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    return true;
}

function wpturbo_set_max_widths() {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pixrefiner_settings' ) || ! current_user_can( 'manage_options' ) ) return false;
    if ( ! isset( $_GET['set_max_width'], $_GET['max_width'] ) ) return false;
    $raw         = wp_unslash( $_GET['max_width'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $width_array = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( $raw ) ) ) );
    $width_array = array_filter( $width_array, fn( $w ) => $w > 0 && $w <= 9999 );
    $width_array = array_slice( $width_array, 0, 4 );
    if ( empty( $width_array ) ) return false;
    update_option( 'webp_max_widths', implode( ',', $width_array ) );
    $log   = get_option( 'webp_conversion_log', [] );
    /* translators: %s: comma-separated list of pixel widths */
    $log[] = sprintf( __( 'Max widths set to: %spx', 'pixrefiner' ), implode( ', ', $width_array ) );
    update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    return true;
}

function wpturbo_set_max_heights() {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pixrefiner_settings' ) || ! current_user_can( 'manage_options' ) ) return false;
    if ( ! isset( $_GET['set_max_height'], $_GET['max_height'] ) ) return false;
    $raw          = wp_unslash( $_GET['max_height'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $height_array = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( $raw ) ) ) );
    $height_array = array_filter( $height_array, fn( $h ) => $h > 0 && $h <= 9999 );
    $height_array = array_slice( $height_array, 0, 4 );
    if ( empty( $height_array ) ) return false;
    update_option( 'webp_max_heights', implode( ',', $height_array ) );
    $log   = get_option( 'webp_conversion_log', [] );
    /* translators: %s: comma-separated list of pixel heights */
    $log[] = sprintf( __( 'Max heights set to: %spx', 'pixrefiner' ), implode( ', ', $height_array ) );
    update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    return true;
}

function wpturbo_set_resize_mode() {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pixrefiner_settings' ) || ! current_user_can( 'manage_options' ) ) return false;
    if ( ! isset( $_GET['set_resize_mode'], $_GET['resize_mode'] ) ) return false;
    $mode = sanitize_text_field( wp_unslash( $_GET['resize_mode'] ) );
    if ( ! in_array( $mode, [ 'width', 'height' ] ) ) return false;
    if ( get_option( 'webp_resize_mode', 'width' ) !== $mode ) {
        update_option( 'webp_resize_mode', $mode );
        $log   = get_option( 'webp_conversion_log', [] );
        /* translators: %s: resize mode, either "width" or "height" */
        $log[] = sprintf( __( 'Resize mode set to: %s', 'pixrefiner' ), $mode );
        update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    }
    return true;
}

function wpturbo_set_quality() {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pixrefiner_settings' ) || ! current_user_can( 'manage_options' ) ) return false;
    if ( ! isset( $_GET['set_quality'], $_GET['quality'] ) ) return false;
    $quality = absint( $_GET['quality'] );
    if ( $quality < 0 || $quality > 100 ) return false;
    if ( (int) get_option( 'webp_quality', 80 ) !== $quality ) {
        update_option( 'webp_quality', $quality );
        $log   = get_option( 'webp_conversion_log', [] );
        /* translators: %d: quality value 0-100 */
        $log[] = sprintf( __( 'Quality set to: %d', 'pixrefiner' ), $quality );
        update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    }
    return true;
}

function wpturbo_set_batch_size() {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pixrefiner_settings' ) || ! current_user_can( 'manage_options' ) ) return false;
    if ( ! isset( $_GET['set_batch_size'], $_GET['batch_size'] ) ) return false;
    $batch_size = absint( $_GET['batch_size'] );
    if ( $batch_size < 1 || $batch_size > 50 ) return false;
    update_option( 'webp_batch_size', $batch_size );
    $log   = get_option( 'webp_conversion_log', [] );
    /* translators: %d: number of images per batch */
    $log[] = sprintf( __( 'Batch size set to: %d', 'pixrefiner' ), $batch_size );
    update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    return true;
}

function wpturbo_set_preserve_originals() {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pixrefiner_settings' ) || ! current_user_can( 'manage_options' ) ) return false;
    if ( ! isset( $_GET['set_preserve_originals'], $_GET['preserve_originals'] ) ) return false;
    $preserve = rest_sanitize_boolean( wp_unslash( $_GET['preserve_originals'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    if ( wpturbo_get_preserve_originals() !== $preserve ) {
        update_option( 'webp_preserve_originals', $preserve );
        $log   = get_option( 'webp_conversion_log', [] );
        /* translators: %s: "Yes" or "No" */
        $log[] = sprintf( __( 'Preserve originals set to: %s', 'pixrefiner' ), $preserve ? __( 'Yes', 'pixrefiner' ) : __( 'No', 'pixrefiner' ) );
        update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    }
    return true;
}

function wpturbo_set_disable_auto_conversion() {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pixrefiner_settings' ) || ! current_user_can( 'manage_options' ) ) return false;
    if ( ! isset( $_GET['set_disable_auto_conversion'], $_GET['disable_auto_conversion'] ) ) return false;
    $disable = rest_sanitize_boolean( wp_unslash( $_GET['disable_auto_conversion'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    if ( wpturbo_get_disable_auto_conversion() !== $disable ) {
        update_option( 'webp_disable_auto_conversion', $disable );
        $log   = get_option( 'webp_conversion_log', [] );
        /* translators: %s: "Disabled" or "Enabled" */
        $log[] = sprintf( __( 'Auto-conversion on upload set to: %s', 'pixrefiner' ), $disable ? __( 'Disabled', 'pixrefiner' ) : __( 'Enabled', 'pixrefiner' ) );
        update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    }
    return true;
}

function wpturbo_set_min_size_kb() {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pixrefiner_settings' ) || ! current_user_can( 'manage_options' ) ) return false;
    if ( ! isset( $_GET['set_min_size_kb'], $_GET['min_size_kb'] ) ) return false;
    $min_size = absint( $_GET['min_size_kb'] );
    if ( wpturbo_get_min_size_kb() !== $min_size ) {
        update_option( 'webp_min_size_kb', $min_size );
        $log   = get_option( 'webp_conversion_log', [] );
        /* translators: %d: minimum file size in kilobytes */
        $log[] = sprintf( __( 'Minimum size threshold set to: %d KB', 'pixrefiner' ), $min_size );
        update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    }
    return true;
}

function wpturbo_set_use_avif() {
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'pixrefiner_settings' ) || ! current_user_can( 'manage_options' ) ) return false;
    if ( ! isset( $_GET['set_use_avif'], $_GET['use_avif'] ) ) return false;
    $use_avif = rest_sanitize_boolean( wp_unslash( $_GET['use_avif'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    if ( wpturbo_get_use_avif() !== $use_avif ) {
        update_option( 'webp_use_avif', $use_avif );
        wpturbo_ensure_mime_types();
        $log   = get_option( 'webp_conversion_log', [] );
        /* translators: %s: output format name, either "AVIF" or "WebP" */
        $log[] = sprintf( __( 'Conversion format set to: %s', 'pixrefiner' ), $use_avif ? 'AVIF' : 'WebP' );
        $log[] = __( 'Please reconvert all images to ensure consistency after changing formats.', 'pixrefiner' );
        update_option( 'webp_conversion_log', array_slice( (array) $log, -500 ) );
    }
    return true;
}
