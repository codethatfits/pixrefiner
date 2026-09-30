<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Admin menu ───────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
    add_media_page(
        __( 'PixRefiner', 'pixrefiner' ),
        __( 'PixRefiner', 'pixrefiner' ),
        'manage_options',
        'webp-converter',
        'wpturbo_webp_converter_page'
    );
} );

// ─── Settings actions (GET-triggered, nonce verified inside each setter) ──────
// Memoized per-request so admin_notices and the page callback (which both need
// to know the outcome) trigger each setter at most once, avoiding duplicate
// option writes and duplicate log entries.

function wpturbo_process_settings_actions() {
    static $results = null;
    if ( $results !== null ) return $results;

    $results = [];
    // phpcs:disable WordPress.Security.NonceVerification.Recommended
    if ( isset( $_GET['set_max_width'] ) )               $results['set_max_width']               = wpturbo_set_max_widths();
    if ( isset( $_GET['set_max_height'] ) )               $results['set_max_height']              = wpturbo_set_max_heights();
    if ( isset( $_GET['set_resize_mode'] ) )              $results['set_resize_mode']             = wpturbo_set_resize_mode();
    if ( isset( $_GET['set_quality'] ) )                  $results['set_quality']                 = wpturbo_set_quality();
    if ( isset( $_GET['set_batch_size'] ) )               $results['set_batch_size']              = wpturbo_set_batch_size();
    if ( isset( $_GET['set_preserve_originals'] ) )       $results['set_preserve_originals']      = wpturbo_set_preserve_originals();
    if ( isset( $_GET['set_disable_auto_conversion'] ) )  $results['set_disable_auto_conversion'] = wpturbo_set_disable_auto_conversion();
    if ( isset( $_GET['set_min_size_kb'] ) )              $results['set_min_size_kb']             = wpturbo_set_min_size_kb();
    if ( isset( $_GET['set_use_avif'] ) )                 $results['set_use_avif']                = wpturbo_set_use_avif();
    if ( isset( $_GET['set_kept_default_sizes'] ) )       $results['set_kept_default_sizes']      = wpturbo_set_kept_default_sizes();
    if ( isset( $_GET['prune_unused_default_sizes'] ) )   $results['prune_unused_default_sizes']  = wpturbo_prune_unused_default_sizes();
    if ( isset( $_GET['cleanup_leftover_originals'] ) )   $results['cleanup_leftover_originals']  = wpturbo_cleanup_leftover_originals();
    if ( isset( $_GET['clear_log'] ) )                    $results['clear_log']                   = wpturbo_clear_log();
    if ( isset( $_GET['reset_defaults'] ) )               $results['reset_defaults']              = wpturbo_reset_defaults();
    // phpcs:enable WordPress.Security.NonceVerification.Recommended

    return $results;
}

// ─── Admin notices ────────────────────────────────────────────────────────────

add_action( 'admin_notices', function () {
    $results = wpturbo_process_settings_actions();

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( isset( $_GET['convert_existing_images_to_webp'] ) ) {
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Conversion started. Monitor progress in Media.', 'pixrefiner' ) . '</p></div>';
    }
    if ( ! empty( $results['set_max_width'] ) ) {
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Max widths updated.', 'pixrefiner' ) . '</p></div>';
    }
    if ( ! empty( $results['set_max_height'] ) ) {
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Max heights updated.', 'pixrefiner' ) . '</p></div>';
    }
    if ( ! empty( $results['reset_defaults'] ) ) {
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings reset to defaults.', 'pixrefiner' ) . '</p></div>';
    }
    if ( ! empty( $results['set_min_size_kb'] ) ) {
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Minimum size threshold updated.', 'pixrefiner' ) . '</p></div>';
    }
    if ( ! empty( $results['set_use_avif'] ) ) {
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Conversion format updated. Please reconvert all images.', 'pixrefiner' ) . '</p></div>';
    }
} );

// ─── Stamp patch (via ?patch_pixrefiner_stamp=1) ──────────────────────────────

add_action( 'admin_init', function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['patch_pixrefiner_stamp'] ) ) return;

    $attachments = get_posts( [
        'post_type'      => 'attachment',
        'post_mime_type' => [ 'image/webp', 'image/avif' ],
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ] );

    $expected_stamp = [
        'format'      => wpturbo_get_use_avif() ? 'avif' : 'webp',
        'quality'     => wpturbo_get_quality(),
        'resize_mode' => wpturbo_get_resize_mode(),
        'max_values'  => ( wpturbo_get_resize_mode() === 'width' ) ? wpturbo_get_max_widths() : wpturbo_get_max_heights(),
    ];

    foreach ( $attachments as $id ) {
        $meta = wp_get_attachment_metadata( $id );
        if ( empty( $meta['pixrefiner_stamp'] ) ) {
            $meta['pixrefiner_stamp'] = $expected_stamp;
            wp_update_attachment_metadata( $id, $meta );
        }
    }

    echo "<div class='notice notice-success'><p>" . esc_html__( 'PixRefiner stamp patch complete.', 'pixrefiner' ) . '</p></div>';
} );

// ─── Admin page callback ──────────────────────────────────────────────────────

function wpturbo_webp_converter_page() {
    wp_enqueue_media();
    wp_enqueue_script( 'media-upload' );
    wp_enqueue_style( 'media' );

    // Process settings passed via GET (nonce verified inside each setter).
    // Memoized in wpturbo_process_settings_actions() — admin_notices already
    // triggered this for the current request, so this call is a cache hit.
    wpturbo_process_settings_actions();

    $has_image_library = extension_loaded( 'imagick' ) || extension_loaded( 'gd' );
    $has_avif_support  = ( extension_loaded( 'imagick' ) && in_array( 'AVIF', Imagick::queryFormats() ) )
                      || ( extension_loaded( 'gd' ) && function_exists( 'imageavif' ) );
    $mime_configured   = true; // suppressed - handled server-side on activation

    $settings_nonce = wp_create_nonce( 'pixrefiner_settings' );
    $ajax_nonce     = wp_create_nonce( 'webp_converter_nonce' );

    $default_size_labels = [
        'medium'       => sprintf( __( 'Medium (%1$dx%2$d)', 'pixrefiner' ), (int) get_option( 'medium_size_w' ), (int) get_option( 'medium_size_h' ) ),
        'medium_large' => sprintf( __( 'Medium Large (%dpx wide)', 'pixrefiner' ), (int) get_option( 'medium_large_size_w' ) ),
        'large'        => sprintf( __( 'Large (%1$dx%2$d)', 'pixrefiner' ), (int) get_option( 'large_size_w' ), (int) get_option( 'large_size_h' ) ),
        '1536x1536'    => __( '1536×1536 (WordPress scaled)', 'pixrefiner' ),
        '2048x2048'    => __( '2048×2048 (WordPress scaled)', 'pixrefiner' ),
    ];
    ?>
    <div class="wrap" style="padding: 0; font-size: 14px;">
        <div style="display: flex; gap: 10px; align-items: flex-start;">

            <!-- Column 1: Controls, Excluded Images, How It Works -->
            <div style="width: 38%; display: flex; flex-direction: column; gap: 10px;">

                <!-- Pane 1: Controls -->
                <div style="background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h1 style="font-size: 20px; font-weight: bold; color: #333; margin: -5px 0 15px 0;"><?php esc_html_e( 'PixRefiner - Image Optimization - v4.0', 'pixrefiner' ); ?></h1>

                    <?php if ( ! $has_image_library ) : ?>
                        <div class="notice notice-error" style="margin-bottom: 20px;">
                            <p><?php esc_html_e( 'Warning: No image processing libraries (Imagick or GD) available. Conversion requires one of these.', 'pixrefiner' ); ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ( wpturbo_get_use_avif() && ! $has_avif_support ) : ?>
                        <div class="notice notice-warning" style="margin-bottom: 20px;">
                            <p><?php esc_html_e( 'Warning: AVIF support is not detected on this server. Enable Imagick with AVIF or GD with AVIF support to use this option.', 'pixrefiner' ); ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ( current_user_can( 'manage_options' ) ) : ?>
                        <div style="margin-bottom: 20px;">
                            <label for="resize-mode" style="font-weight: bold;"><?php esc_html_e( 'Resize Mode:', 'pixrefiner' ); ?></label><br>
                            <select id="resize-mode" style="width: 100px; margin-right: 10px; padding: 0px 0px 0px 5px;">
                                <option value="width"  <?php selected( wpturbo_get_resize_mode(), 'width' ); ?>><?php esc_html_e( 'Width', 'pixrefiner' ); ?></option>
                                <option value="height" <?php selected( wpturbo_get_resize_mode(), 'height' ); ?>><?php esc_html_e( 'Height', 'pixrefiner' ); ?></option>
                            </select>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="max-width-input" style="font-weight: bold;"><?php esc_html_e( 'Max Widths (up to 4, e.g., 1920, 1200, 600, 300) - 150 is set automatically:', 'pixrefiner' ); ?></label><br>
                            <input type="text" id="max-width-input" value="<?php echo esc_attr( implode( ', ', wpturbo_get_max_widths() ) ); ?>" style="width: 200px; margin-right: 10px; padding: 5px;" placeholder="1920,1200,600,300">
                            <button id="set-max-width" class="button"><?php esc_html_e( 'Set Widths', 'pixrefiner' ); ?></button>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="max-height-input" style="font-weight: bold;"><?php esc_html_e( 'Max Heights (up to 4, e.g., 1080, 720, 480, 360) - 150 is set automatically:', 'pixrefiner' ); ?></label><br>
                            <input type="text" id="max-height-input" value="<?php echo esc_attr( implode( ', ', wpturbo_get_max_heights() ) ); ?>" style="width: 200px; margin-right: 10px; padding: 5px;" placeholder="1080,720,480,360">
                            <button id="set-max-height" class="button"><?php esc_html_e( 'Set Heights', 'pixrefiner' ); ?></button>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="min-size-kb" style="font-weight: bold;"><?php esc_html_e( 'Min Size for Conversion (KB, Set to 0 to disable):', 'pixrefiner' ); ?></label><br>
                            <input type="number" id="min-size-kb" value="<?php echo esc_attr( wpturbo_get_min_size_kb() ); ?>" min="0" style="width: 50px; margin-right: 10px; padding: 5px;" placeholder="0">
                            <button id="set-min-size-kb" class="button"><?php esc_html_e( 'Set Min Size', 'pixrefiner' ); ?></button>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label for="quality-slider" style="font-weight: bold;"><?php esc_html_e( 'Output Quality:', 'pixrefiner' ); ?> <span id="quality-value"><?php echo (int) wpturbo_get_quality(); ?></span></label><br>
                            <input type="range" id="quality-slider" min="0" max="100" value="<?php echo esc_attr( wpturbo_get_quality() ); ?>" style="width: 200px; margin-right: 10px; vertical-align: middle;">
                            <span style="font-size: 12px; color: #666;"><?php esc_html_e( '(WebP: 75–85 recommended, AVIF: 50–70)', 'pixrefiner' ); ?></span>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label><input type="checkbox" id="use-avif" <?php checked( wpturbo_get_use_avif() ); ?>> <?php esc_html_e( 'Set to AVIF Conversion (not WebP)', 'pixrefiner' ); ?></label>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label><input type="checkbox" id="preserve-originals" <?php checked( wpturbo_get_preserve_originals() ); ?>> <?php esc_html_e( 'Preserve Original Files', 'pixrefiner' ); ?></label>
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label><input type="checkbox" id="disable-auto-conversion" <?php checked( wpturbo_get_disable_auto_conversion() ); ?>> <?php esc_html_e( 'Disable Auto-Conversion on Upload', 'pixrefiner' ); ?></label>
                        </div>
                        <div style="margin-bottom: 20px; display: flex; gap: 10px;">
                            <button id="start-conversion"  class="button"><?php esc_html_e( '1. Convert/Scale', 'pixrefiner' ); ?></button>
                            <button id="cleanup-originals" class="button"><?php esc_html_e( '2. Cleanup Images', 'pixrefiner' ); ?></button>
                            <button id="convert-post-images" class="button"><?php esc_html_e( '3. Fix URLs', 'pixrefiner' ); ?></button>
                            <button id="run-all"           class="button button-primary"><?php esc_html_e( 'Run All (1-3)', 'pixrefiner' ); ?></button>
                            <button id="stop-conversion"   class="button" style="display: none;"><?php esc_html_e( 'Stop', 'pixrefiner' ); ?></button>
                        </div>
                        <div style="margin-bottom: 20px; display: flex; gap: 10px;">
                            <button id="clear-log"      class="button"><?php esc_html_e( 'Clear Log', 'pixrefiner' ); ?></button>
                            <button id="reset-defaults" class="button"><?php esc_html_e( 'Reset Defaults', 'pixrefiner' ); ?></button>
                            <button id="export-media-zip" class="button"><?php esc_html_e( 'Export Media as ZIP', 'pixrefiner' ); ?></button>
                        </div>
                    <?php else : ?>
                        <p><?php esc_html_e( 'You need manage_options permission to use this tool.', 'pixrefiner' ); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Pane 1b: Default Image Sizes -->
                <div style="background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h2 style="font-size: 16px; margin: 0 0 10px 0;"><?php esc_html_e( 'Default Image Sizes', 'pixrefiner' ); ?></h2>
                    <?php if ( current_user_can( 'manage_options' ) ) : ?>
                        <p style="font-size: 12px; color: #666; line-height: 1.4; margin: 0 0 15px 0;">
                            <?php esc_html_e( "WordPress's own Medium/Large/etc. sizes are generated for every converted image unless turned off here. Scan the site to see which ones Elementor, your theme, and your content actually reference, then uncheck the rest to save disk space and conversion time. Applies to new conversions only \u{2014} re-run \u{201c}Convert/Scale\u{201d} with force_reconvert=1 in the URL to backfill existing media.", 'pixrefiner' ); ?>
                        </p>
                        <button id="scan-used-sizes" class="button" style="margin-bottom: 15px;"><?php esc_html_e( 'Scan Site for Used Sizes', 'pixrefiner' ); ?></button>
                        <div id="scan-status" style="font-size: 12px; color: #666; margin-bottom: 10px;"></div>
                        <div id="default-size-checkboxes">
                            <?php foreach ( $default_size_labels as $slug => $label ) : ?>
                                <div style="margin-bottom: 10px;">
                                    <label>
                                        <input type="checkbox" class="keep-default-size" data-size="<?php echo esc_attr( $slug ); ?>" checked>
                                        <?php echo esc_html( $label ); ?>
                                    </label>
                                    <div class="size-source-note" data-size="<?php echo esc_attr( $slug ); ?>" style="font-size: 11px; color: #999; margin-left: 24px;"></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button id="save-kept-sizes" class="button button-primary" style="margin-top: 5px;"><?php esc_html_e( 'Save Kept Sizes', 'pixrefiner' ); ?></button>
                        <button id="prune-unused-sizes" class="button" style="margin-top: 5px;"><?php esc_html_e( 'Prune Existing Files for Dropped Sizes', 'pixrefiner' ); ?></button>
                        <p style="font-size: 11px; color: #999; margin: 8px 0 0 0;">
                            <?php esc_html_e( 'Save Kept Sizes only affects new conversions. Prune deletes already-generated files for any default size you unchecked above, for every already-converted image.', 'pixrefiner' ); ?>
                        </p>
                    <?php else : ?>
                        <p><?php esc_html_e( 'You need manage_options permission to use this tool.', 'pixrefiner' ); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Pane 2: Exclude Images -->
                <div style="background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h2 style="font-size: 16px; margin: 0 0 15px 0;"><?php esc_html_e( 'Exclude Images', 'pixrefiner' ); ?></h2>
                    <button id="open-media-library" class="button" style="margin-bottom: 20px;"><?php esc_html_e( 'Add from Media Library', 'pixrefiner' ); ?></button>
                    <div id="excluded-images">
                        <h3 style="font-size: 14px; margin: 0 0 10px 0;"><?php esc_html_e( 'Excluded Images', 'pixrefiner' ); ?></h3>
                        <ul id="excluded-images-list" style="list-style: none; padding: 0; max-height: 300px; overflow-y: auto;"></ul>
                    </div>
                </div>

                <!-- Pane 3: How It Works -->
                <div style="background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                    <h2 style="font-size: 16px; margin: 0 0 15px 0;"><?php esc_html_e( 'How It Works', 'pixrefiner' ); ?></h2>
                    <p style="line-height: 1.5;">
                        <?php esc_html_e( 'Refine images to WebP or AVIF, and remove excess files to save space.', 'pixrefiner' ); ?><br><br>
                        <b><?php esc_html_e( 'Set Auto-Conversion for New Uploads:', 'pixrefiner' ); ?></b><br>
                        <b>1. Resize Mode:</b> <?php esc_html_e( 'Pick if images shrink by width or height.', 'pixrefiner' ); ?><br>
                        <b>2. Set Max Sizes:</b> <?php esc_html_e( 'Choose up to 4 sizes (150x150 thumbnail is automatic).', 'pixrefiner' ); ?><br>
                        <b>3. Min Size for Conversion:</b> <?php esc_html_e( 'Sizes below the min are not affected. Default is 0.', 'pixrefiner' ); ?><br>
                        <b>4. Conversion Format:</b> <?php esc_html_e( 'Check to use AVIF. WebP is default.', 'pixrefiner' ); ?><br>
                        <b>5. Preserve Originals:</b> <?php esc_html_e( 'Check to stop original files from converting/deleting.', 'pixrefiner' ); ?><br>
                        <b>6. Disable Auto-Conversion:</b> <?php esc_html_e( 'Images will convert on upload unless this is ticked.', 'pixrefiner' ); ?><br>
                        <b>7. Upload:</b> <?php esc_html_e( 'Upload to Media Library or via elements/widgets.', 'pixrefiner' ); ?><br><br>
                        <b><?php esc_html_e( 'Apply for Existing Images:', 'pixrefiner' ); ?></b><br>
                        <b>1. Repeat:</b> <?php esc_html_e( 'Set up steps 1-6 above.', 'pixrefiner' ); ?><br>
                        <b>2. Run All:</b> <?php esc_html_e( 'Hit "Run All" to do everything at once.', 'pixrefiner' ); ?><br><br>
                        <b><?php esc_html_e( 'Apply Manually for Existing Images:', 'pixrefiner' ); ?></b><br>
                        <b>1. Repeat:</b> <?php esc_html_e( 'Set up steps 1-6 above.', 'pixrefiner' ); ?><br>
                        <b>2. Convert:</b> <?php esc_html_e( 'Change image sizes and format.', 'pixrefiner' ); ?><br>
                        <b>3. Cleanup:</b> <?php esc_html_e( 'Delete old formats/sizes (if not preserved).', 'pixrefiner' ); ?><br>
                        <b>4. Fix Links:</b> <?php esc_html_e( 'Update image links to the new format.', 'pixrefiner' ); ?><br><br>
                        <b><?php esc_html_e( 'IMPORTANT:', 'pixrefiner' ); ?></b><br>
                        <b>a) Usability:</b> <?php esc_html_e( 'This tool is ideal for New Sites. Using with Legacy Sites must be done with care as variation due to methods, systems, sizes, can affect the outcome. Please use this tool carefully and at your own risk, as I cannot be held responsible for any issues that may arise from its use.', 'pixrefiner' ); ?><br>
                        <b>b) Backups:</b> <?php esc_html_e( 'Use a strong backup tool like All-in-One WP Migration before using this tool. Check if your host saves backups - as some charge a fee to restore.', 'pixrefiner' ); ?><br>
                        <b>c) Export Media:</b> <?php esc_html_e( 'Export images as a Zipped Folder prior to running.', 'pixrefiner' ); ?><br>
                        <b>d) Reset Defaults:</b> <?php esc_html_e( 'Resets all Settings 1-6.', 'pixrefiner' ); ?><br>
                        <b>e) Speed:</b> <?php esc_html_e( 'Bigger sites take longer to run. This depends on your server.', 'pixrefiner' ); ?><br>
                        <b>f) Log Wait:</b> <?php esc_html_e( 'Updates show every 50 images.', 'pixrefiner' ); ?><br>
                        <b>g) Stop Anytime:</b> <?php esc_html_e( 'Click "Stop" to pause.', 'pixrefiner' ); ?><br>
                        <b>h) AVIF Needs:</b> <?php esc_html_e( 'Your server must support AVIF. Check logs if it fails.', 'pixrefiner' ); ?><br>
                        <b>i) Old Browsers:</b> <?php esc_html_e( 'AVIF might not work on older browsers, WebP is safer.', 'pixrefiner' ); ?><br>
                        <b>j) MIME Types:</b> <?php esc_html_e( 'Server must support WebP/AVIF MIME (check with host).', 'pixrefiner' ); ?><br>
                        <b>k) Rollback:</b> <?php esc_html_e( 'If conversion fails, then rollback occurs, and prevents deletion of the original, regardless of whether the Preserve Originals is checked or not.', 'pixrefiner' ); ?>
                    </p>
                </div>

            </div><!-- /Column 1 -->

            <!-- Column 2: Log -->
            <div style="width: 62%; min-height: 100vh; background: #FFFFFF; padding: 20px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); display: flex; flex-direction: column;">
                <h3 style="font-size: 16px; margin: 0 0 10px 0;"><?php esc_html_e( 'Log (Last 500 Entries)', 'pixrefiner' ); ?></h3>
                <pre id="log" style="background: #f9f9f9; padding: 15px; flex: 1; overflow-y: auto; border: 1px solid #ddd; border-radius: 5px; font-size: 13px;"></pre>
            </div>

        </div>
    </div>

    <style>
    #quality-slider {
        -webkit-appearance: none;
        appearance: none;
        height: 6px;
        border-radius: 3px;
        background: #ddd;
        outline: none;
        cursor: pointer;
    }
    #quality-slider::-webkit-slider-thumb {
        -webkit-appearance: none;
        width: 16px;
        height: 16px;
        background: #FF0050;
        border-radius: 50%;
        cursor: pointer;
    }
    #quality-slider::-moz-range-thumb {
        width: 16px;
        height: 16px;
        background: #FF0050;
        border-radius: 50%;
        border: none;
        cursor: pointer;
    }
    .button.button-primary {
        background: #FF0050;
        color: #fff;
        padding: 2px 10px;
        height: 30px;
        line-height: 26px;
        transition: all 0.2s;
        font-size: 14px;
        font-weight: 600;
        border: none;
    }
    .button.button-primary:hover { background: #444444; }
    .button:not(.button-primary) {
        background: #dbe2e9;
        color: #444444;
        padding: 2px 10px;
        height: 30px;
        line-height: 26px;
        transition: all 0.2s;
        border: none;
    }
    .button:not(.button-primary):hover { background: #444444; color: #FFF; }
    #excluded-images-list li { display: flex; align-items: center; margin-bottom: 10px; }
    #excluded-images-list img { max-width: 50px; margin-right: 10px; }
    input[type="text"], input[type="number"], select { padding: 2px; height: 30px; box-sizing: border-box; }
    @media screen and (max-width: 782px) {
        div[style*="width: 55%"] { height: calc(100vh - 46px) !important; }
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        let isConverting = false;

        const ajaxUrl      = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
        const adminPageUrl = '<?php echo esc_js( admin_url( 'admin.php?page=webp-converter' ) ); ?>';
        const ajaxNonce    = '<?php echo esc_js( $ajax_nonce ); ?>';
        const settingsNonce = '<?php echo esc_js( $settings_nonce ); ?>';
        const batchSize    = <?php echo (int) wpturbo_get_batch_size(); ?>;
        const defaultSizeCandidates = <?php echo wp_json_encode( array_keys( $default_size_labels ) ); ?>;

        function ajaxUrl2( action ) {
            return ajaxUrl + '?action=' + action + '&nonce=' + ajaxNonce;
        }

        function settingsUrl( params ) {
            return adminPageUrl + '&_wpnonce=' + settingsNonce + '&' + params;
        }

        function applyKeptSizesToCheckboxes( keptSizes ) {
            // null = never confirmed via "Save Kept Sizes" yet — default to
            // all checked (matches the plugin's safe "keep everything" default).
            defaultSizeCandidates.forEach( slug => {
                const box = document.querySelector( `.keep-default-size[data-size="${slug}"]` );
                if ( box ) box.checked = ( keptSizes === null || keptSizes.includes( slug ) );
            } );
        }

        function applyDetectionNotes( detectedSizes, detail ) {
            defaultSizeCandidates.forEach( slug => {
                const note = document.querySelector( `.size-source-note[data-size="${slug}"]` );
                if ( ! note ) return;
                if ( detectedSizes === null ) {
                    note.textContent = '<?php echo esc_js( __( 'Not scanned yet.', 'pixrefiner' ) ); ?>';
                } else if ( detail && detail[ slug ] ) {
                    note.textContent = '<?php echo esc_js( __( 'Found via:', 'pixrefiner' ) ); ?> ' + Object.keys( detail[ slug ] ).join( ', ' );
                } else {
                    note.textContent = '<?php echo esc_js( __( 'Not detected — will be dropped if unchecked.', 'pixrefiner' ) ); ?>';
                }
            } );
        }

        function updateStatus() {
            fetch( ajaxUrl2( 'webp_status' ) )
                .then( r => { if ( ! r.ok ) throw new Error( r.statusText ); return r.json(); } )
                .then( data => {
                    document.getElementById( 'log' ).innerHTML = data.log.slice().reverse().join( '<br>' );
                    document.getElementById( 'resize-mode' ).value                     = data.resize_mode;
                    document.getElementById( 'max-width-input' ).value                 = data.max_widths;
                    document.getElementById( 'max-height-input' ).value                = data.max_heights;
                    document.getElementById( 'preserve-originals' ).checked            = data.preserve_originals;
                    document.getElementById( 'disable-auto-conversion' ).checked       = data.disable_auto_conversion;
                    document.getElementById( 'min-size-kb' ).value                     = data.min_size_kb;
                    document.getElementById( 'quality-slider' ).value                  = data.quality;
                    document.getElementById( 'quality-value' ).textContent             = data.quality;
                    document.getElementById( 'use-avif' ).checked                      = data.use_avif;
                    applyKeptSizesToCheckboxes( data.kept_default_sizes );
                    applyDetectionNotes( data.detected_used_sizes, data.detected_used_sizes_detail );
                    updateExcludedImages( data.excluded_images );
                } )
                .catch( err => { console.error( 'updateStatus:', err ); } );
        }

        function updateExcludedImages( list ) {
            const ul = document.getElementById( 'excluded-images-list' );
            ul.innerHTML = '';
            list.forEach( img => {
                // Attachment titles are user-controlled (any role with unfiltered_html,
                // e.g. Editors on single-site installs, can set arbitrary title text).
                // Build the row via DOM APIs so title/id are never parsed as HTML.
                const li = document.createElement( 'li' );

                const thumb = document.createElement( 'img' );
                thumb.decoding = 'async';
                thumb.src = img.thumbnail;
                thumb.alt = img.title;
                li.appendChild( thumb );

                const label = document.createElement( 'span' );
                label.textContent = `${img.title} (ID: ${img.id})`;
                li.appendChild( label );

                const removeBtn = document.createElement( 'button' );
                removeBtn.className = 'remove-excluded button';
                removeBtn.dataset.id = img.id;
                removeBtn.textContent = '<?php echo esc_js( __( 'Remove', 'pixrefiner' ) ); ?>';
                li.appendChild( removeBtn );

                ul.appendChild( li );
            } );
            document.querySelectorAll( '.remove-excluded' ).forEach( btn => {
                btn.addEventListener( 'click', () => {
                    fetch( ajaxUrl2( 'webp_remove_excluded_image' ), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'attachment_id=' + encodeURIComponent( btn.getAttribute( 'data-id' ) )
                    } )
                    .then( r => r.json() )
                    .then( d => { if ( d.success ) updateStatus(); else alert( 'Error: ' + d.data ); } )
                    .catch( err => { console.error( err ); alert( 'Failed to remove excluded image.' ); } );
                } );
            } );
        }

        let retryCounts = {};

        function convertNextImage( offset ) {
            if ( ! isConverting ) return;
            retryCounts = {};
            fetch( ajaxUrl2( 'webp_convert_single' ), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'offset=' + encodeURIComponent( offset )
            } )
            .then( r => { if ( ! r.ok ) throw new Error( r.statusText ); return r.json(); } )
            .then( data => {
                if ( data.success ) {
                    updateStatus();
                    if ( ! data.data.complete && isConverting ) {
                        retryCounts[ offset ] = 0;
                        convertNextImage( data.data.offset );
                    } else {
                        document.getElementById( 'stop-conversion' ).style.display = 'none';
                    }
                } else {
                    retryCounts[ offset ] = ( retryCounts[ offset ] || 0 ) + 1;
                    if ( retryCounts[ offset ] <= 2 ) {
                        setTimeout( () => convertNextImage( offset ), 1000 );
                    } else {
                        if ( isConverting ) convertNextImage( offset + batchSize );
                    }
                }
            } )
            .catch( err => {
                console.error( 'convertNextImage:', err );
                alert( 'Conversion failed: ' + err.message );
                document.getElementById( 'stop-conversion' ).style.display = 'none';
            } );
        }

        <?php if ( current_user_can( 'manage_options' ) ) : ?>

        const mediaFrame = wp.media( {
            title:    '<?php echo esc_js( __( 'Select Images to Exclude', 'pixrefiner' ) ); ?>',
            button:   { text: '<?php echo esc_js( __( 'Add to Excluded List', 'pixrefiner' ) ); ?>' },
            multiple: true,
            library:  { type: 'image' }
        } );
        document.getElementById( 'open-media-library' ).addEventListener( 'click', () => mediaFrame.open() );
        mediaFrame.on( 'select', () => {
            mediaFrame.state().get( 'selection' ).each( attachment => {
                fetch( ajaxUrl2( 'webp_add_excluded_image' ), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'attachment_id=' + encodeURIComponent( attachment.id )
                } )
                .then( r => r.json() )
                .then( d => { if ( d.success ) updateStatus(); } )
                .catch( err => { console.error( err ); alert( 'Failed to add excluded image.' ); } );
            } );
        } );

        document.getElementById( 'set-max-width' ).addEventListener( 'click', () => {
            const v = document.getElementById( 'max-width-input' ).value;
            fetch( settingsUrl( 'set_max_width=1&max_width=' + encodeURIComponent( v ) ) )
                .then( () => updateStatus() )
                .catch( err => alert( 'Failed to set max width: ' + err.message ) );
        } );

        document.getElementById( 'set-max-height' ).addEventListener( 'click', () => {
            const v = document.getElementById( 'max-height-input' ).value;
            fetch( settingsUrl( 'set_max_height=1&max_height=' + encodeURIComponent( v ) ) )
                .then( () => updateStatus() )
                .catch( err => alert( 'Failed to set max height: ' + err.message ) );
        } );

        document.getElementById( 'resize-mode' ).addEventListener( 'change', () => {
            const v = document.getElementById( 'resize-mode' ).value;
            fetch( settingsUrl( 'set_resize_mode=1&resize_mode=' + encodeURIComponent( v ) ) )
                .then( () => updateStatus() )
                .catch( err => alert( 'Failed to set resize mode: ' + err.message ) );
        } );

        document.getElementById( 'preserve-originals' ).addEventListener( 'click', () => {
            const v = document.getElementById( 'preserve-originals' ).checked ? 1 : 0;
            fetch( settingsUrl( 'set_preserve_originals=1&preserve_originals=' + v ) )
                .then( () => updateStatus() )
                .catch( err => alert( 'Failed to set preserve originals: ' + err.message ) );
        } );

        document.getElementById( 'disable-auto-conversion' ).addEventListener( 'click', () => {
            const v = document.getElementById( 'disable-auto-conversion' ).checked ? 1 : 0;
            fetch( settingsUrl( 'set_disable_auto_conversion=1&disable_auto_conversion=' + v ) )
                .then( () => updateStatus() )
                .catch( err => alert( 'Failed to set auto-conversion: ' + err.message ) );
        } );

        document.getElementById( 'set-min-size-kb' ).addEventListener( 'click', () => {
            const v = document.getElementById( 'min-size-kb' ).value;
            fetch( settingsUrl( 'set_min_size_kb=1&min_size_kb=' + encodeURIComponent( v ) ) )
                .then( () => updateStatus() )
                .catch( err => alert( 'Failed to set min size: ' + err.message ) );
        } );

        document.getElementById( 'quality-slider' ).addEventListener( 'input', () => {
            document.getElementById( 'quality-value' ).textContent = document.getElementById( 'quality-slider' ).value;
        } );

        document.getElementById( 'quality-slider' ).addEventListener( 'change', () => {
            const v = document.getElementById( 'quality-slider' ).value;
            fetch( settingsUrl( 'set_quality=1&quality=' + encodeURIComponent( v ) ) )
                .then( () => updateStatus() )
                .catch( err => alert( 'Failed to set quality: ' + err.message ) );
        } );

        document.getElementById( 'use-avif' ).addEventListener( 'click', () => {
            const checked = document.getElementById( 'use-avif' ).checked;
            if ( checked && ! confirm( '<?php echo esc_js( __( 'Switching to AVIF requires reconverting all images for consistency. Continue?', 'pixrefiner' ) ); ?>' ) ) {
                document.getElementById( 'use-avif' ).checked = false;
                return;
            }
            fetch( settingsUrl( 'set_use_avif=1&use_avif=' + ( checked ? 1 : 0 ) ) )
                .then( () => updateStatus() )
                .catch( err => alert( 'Failed to set AVIF option: ' + err.message ) );
        } );

        document.getElementById( 'start-conversion' ).addEventListener( 'click', () => {
            isConverting = true;
            document.getElementById( 'stop-conversion' ).style.display = 'inline-block';
            fetch( settingsUrl( 'convert_existing_images_to_webp=1' ) )
                .then( () => { updateStatus(); convertNextImage( 0 ); } )
                .catch( err => alert( 'Failed to start conversion: ' + err.message ) );
        } );

        document.getElementById( 'cleanup-originals' ).addEventListener( 'click', () => {
            fetch( settingsUrl( 'cleanup_leftover_originals=1' ) )
                .then( () => updateStatus() )
                .catch( err => alert( 'Failed to cleanup originals: ' + err.message ) );
        } );

        document.getElementById( 'convert-post-images' ).addEventListener( 'click', () => {
            if ( ! confirm( '<?php echo esc_js( __( 'Update all post images to the selected format?', 'pixrefiner' ) ); ?>' ) ) return;
            fetch( ajaxUrl2( 'convert_post_images_to_webp' ), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            } )
            .then( r => { if ( ! r.ok ) throw new Error( r.statusText ); return r.json(); } )
            .then( d => { alert( d.success ? d.data.message : 'Error: ' + d.data ); updateStatus(); } )
            .catch( err => alert( 'Failed to convert post images: ' + err.message ) );
        } );

        document.getElementById( 'run-all' ).addEventListener( 'click', () => {
            if ( ! confirm( '<?php echo esc_js( __( 'Run all steps?', 'pixrefiner' ) ); ?>' ) ) return;
            isConverting = true;
            document.getElementById( 'stop-conversion' ).style.display = 'inline-block';

            fetch( settingsUrl( 'convert_existing_images_to_webp=1' ) )
                .then( () => {
                    convertNextImage( 0 );
                    return new Promise( resolve => {
                        const check = setInterval( () => {
                            fetch( ajaxUrl2( 'webp_status' ) )
                                .then( r => r.json() )
                                .then( d => {
                                    updateStatus();
                                    if ( d.complete ) { clearInterval( check ); resolve(); }
                                } )
                                .catch( err => { clearInterval( check ); resolve(); } );
                        }, 1000 );
                    } );
                } )
                .then( () => fetch( ajaxUrl2( 'convert_post_images_to_webp' ), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                } ).then( r => r.json() ).then( d => { updateStatus(); alert( d.success ? d.data.message : 'Error: ' + d.data ); } ) )
                .then( () => fetch( settingsUrl( 'cleanup_leftover_originals=1' ) ) )
                .then( () => {
                    isConverting = false;
                    document.getElementById( 'stop-conversion' ).style.display = 'none';
                    updateStatus();
                    alert( '<?php echo esc_js( __( 'All steps completed!', 'pixrefiner' ) ); ?>' );
                } )
                .catch( err => {
                    console.error( 'Run All:', err );
                    alert( 'Run All failed: ' + err.message );
                    isConverting = false;
                    document.getElementById( 'stop-conversion' ).style.display = 'none';
                } );
        } );

        document.getElementById( 'stop-conversion' ).addEventListener( 'click', () => {
            isConverting = false;
            document.getElementById( 'stop-conversion' ).style.display = 'none';
        } );

        document.getElementById( 'clear-log' ).addEventListener( 'click', () => {
            fetch( settingsUrl( 'clear_log=1' ) )
                .then( () => updateStatus() )
                .catch( err => alert( 'Failed to clear log: ' + err.message ) );
        } );

        document.getElementById( 'reset-defaults' ).addEventListener( 'click', () => {
            if ( ! confirm( '<?php echo esc_js( __( 'Reset all settings to defaults?', 'pixrefiner' ) ); ?>' ) ) return;
            fetch( settingsUrl( 'reset_defaults=1' ) )
                .then( () => updateStatus() )
                .catch( err => alert( 'Failed to reset defaults: ' + err.message ) );
        } );

        document.getElementById( 'export-media-zip' ).addEventListener( 'click', () => {
            if ( confirm( '<?php echo esc_js( __( 'Export all media as a ZIP file?', 'pixrefiner' ) ); ?>' ) ) {
                window.location.href = ajaxUrl2( 'webp_export_media_zip' );
            }
        } );

        document.getElementById( 'scan-used-sizes' ).addEventListener( 'click', () => {
            const btn      = document.getElementById( 'scan-used-sizes' );
            const statusEl = document.getElementById( 'scan-status' );
            btn.disabled = true;
            statusEl.textContent = '<?php echo esc_js( __( 'Scanning posts, Elementor data, and theme templates — this can take a moment on larger sites…', 'pixrefiner' ) ); ?>';
            fetch( ajaxUrl2( 'webp_scan_used_sizes' ), { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' } } )
                .then( r => { if ( ! r.ok ) throw new Error( r.statusText ); return r.json(); } )
                .then( d => {
                    btn.disabled = false;
                    if ( ! d.success ) { statusEl.textContent = 'Error: ' + d.data; return; }
                    statusEl.textContent = d.data.detected.length
                        ? '<?php echo esc_js( __( 'Scan complete. Checkboxes below reflect what was found — review, then click "Save Kept Sizes".', 'pixrefiner' ) ); ?>'
                        : '<?php echo esc_js( __( 'Scan complete. Nothing referenced any default size — double-check before saving, this can be a false negative for dynamic page builders.', 'pixrefiner' ) ); ?>';
                    // Suggest checkbox state from the scan; not saved until "Save Kept Sizes".
                    defaultSizeCandidates.forEach( slug => {
                        const box = document.querySelector( `.keep-default-size[data-size="${slug}"]` );
                        if ( box ) box.checked = d.data.detected.includes( slug );
                    } );
                    applyDetectionNotes( d.data.detected, d.data.detail );
                } )
                .catch( err => {
                    btn.disabled = false;
                    statusEl.textContent = 'Scan failed: ' + err.message;
                } );
        } );

        document.getElementById( 'save-kept-sizes' ).addEventListener( 'click', () => {
            const chosen = Array.from( document.querySelectorAll( '.keep-default-size' ) )
                .filter( cb => cb.checked )
                .map( cb => cb.dataset.size );
            fetch( settingsUrl( 'set_kept_default_sizes=1&kept_default_sizes=' + encodeURIComponent( chosen.join( ',' ) ) ) )
                .then( () => updateStatus() )
                .catch( err => alert( 'Failed to save kept sizes: ' + err.message ) );
        } );

        document.getElementById( 'prune-unused-sizes' ).addEventListener( 'click', () => {
            if ( ! confirm( '<?php echo esc_js( __( 'Delete already-generated files for every unchecked default size, across all converted media? Save Kept Sizes first if you just changed the checkboxes. This cannot be undone short of reconverting.', 'pixrefiner' ) ); ?>' ) ) return;
            fetch( settingsUrl( 'prune_unused_default_sizes=1' ) )
                .then( () => updateStatus() )
                .catch( err => alert( 'Failed to prune sizes: ' + err.message ) );
        } );

        <?php endif; ?>

        updateStatus();
    } );
    </script>
    <?php
}
