<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ALTGENIX_Settings {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'wp_ajax_altgenix_submit_feedback', array( $this, 'submit_feedback' ) );
        
        // Native Media Integration
        add_action( 'admin_footer-upload.php', array( $this, 'add_media_library_button' ) );
        add_action( 'attachment_submitbox_misc_actions', array( $this, 'add_edit_media_button' ) );
    }

    public function add_admin_menu() {
        // Parent Menu
        add_menu_page( 'AltGenix AI SEO', 'AltGenix AI', 'manage_options', 'altgenix-settings', array( $this, 'create_settings_page' ), 'dashicons-art', 30 );
        
        // Sub Menus
        add_submenu_page( 'altgenix-settings', 'Settings', 'Settings', 'manage_options', 'altgenix-settings', array( $this, 'create_settings_page' ) );
        add_submenu_page( 'altgenix-settings', 'Bulk Optimizer', 'Bulk Optimizer', 'manage_options', 'altgenix-bulk-optimizer', array( $this, 'create_bulk_optimizer_page' ) );
        add_submenu_page( 'altgenix-settings', 'Help & Rate Us', 'Help & Rate Us', 'manage_options', 'altgenix-help', array( $this, 'create_help_page' ) );
    }

    public function register_settings() {
        register_setting( 'altgenix_setting_group', 'altgenix_settings', array( $this, 'sanitize' ) );
    }

    /**
     * Sanitize and validate all plugin settings before saving.
     */
    public function sanitize( $input ) {
        $sanitized = array();
        if ( isset( $input['api_key'] ) ) $sanitized['api_key'] = sanitize_text_field( wp_unslash( $input['api_key'] ) );
        if ( isset( $input['custom_prompt'] ) ) $sanitized['custom_prompt'] = sanitize_textarea_field( wp_unslash( $input['custom_prompt'] ) );
        
        $allowed_modes = array( 'fallback', 'ai' );
        $sanitized['mode'] = isset( $input['mode'] ) && in_array( $input['mode'], $allowed_modes, true ) ? $input['mode'] : 'fallback';
        
        $toggles = array('gen_alt', 'gen_title', 'gen_caption', 'gen_desc', 'rename_file');
        foreach($toggles as $toggle) {
            $sanitized[$toggle] = isset( $input[$toggle] ) ? 1 : 0;
        }

        $allowed_lengths = array( 'short', 'medium', 'long' );
        $length_defaults = array(
            'alt_length'     => 'short',
            'title_length'   => 'short',
            'caption_length' => 'short',
            'desc_length'    => 'medium',
        );
        foreach ( $length_defaults as $len_key => $default_val ) {
            $sanitized[ $len_key ] = isset( $input[ $len_key ] ) && in_array( $input[ $len_key ], $allowed_lengths, true ) ? $input[ $len_key ] : $default_val;
        }
        
        update_option( 'altgenix_valid_models', array() ); 
        
        // API Verification & Auto-Revert Logic
        if ( $sanitized['mode'] === 'ai' ) {
            if ( empty( $sanitized['api_key'] ) ) {
                add_settings_error( 'altgenix_setting_group', 'missing_api_key', 'API Key is required for AI Mode. Reverted to Original Filename mode.', 'error' );
                $sanitized['mode'] = 'fallback';
            } else {
                $url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode( $sanitized['api_key'] );
                $response = wp_remote_get( $url, array( 'timeout' => 15 ) );
                
                if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                    add_settings_error( 'altgenix_setting_group', 'invalid_api_key', 'Verification Failed! Invalid API Key. Reverted to Original Filename mode.', 'error' );
                    $sanitized['mode'] = 'fallback';
                } else {
                    $body = json_decode( wp_remote_retrieve_body( $response ), true );
                    $valid_models = array();
                    
                    if ( isset( $body['models'] ) && is_array( $body['models'] ) ) {
                        foreach ( $body['models'] as $model ) {
                            if ( isset( $model['supportedGenerationMethods'] ) && in_array( 'generateContent', $model['supportedGenerationMethods'] ) ) {
                                $model_id = str_replace( 'models/', '', $model['name'] );
                                $model_id_lower = strtolower( $model_id );
                                $is_valid = (bool) preg_match( '/^gemini-(1\.5|2\.0|2\.5|3\.0|3)-(flash|pro)/i', $model_id_lower );
                                $is_not_audio = ( strpos( $model_id_lower, 'tts' ) === false && strpos( $model_id_lower, 'audio' ) === false && strpos( $model_id_lower, 'embedding' ) === false );

                                if ( $is_valid && $is_not_audio ) { $valid_models[] = $model_id; }
                            }
                        }
                    }
                    
                    if ( !empty( $valid_models ) ) {
                        update_option( 'altgenix_valid_models', $valid_models ); 
                        add_settings_error( 'altgenix_setting_group', 'valid_api_key', 'API Verified! ' . count($valid_models) . ' Vision Models auto-discovered.', 'success' );
                    } else {
                        add_settings_error( 'altgenix_setting_group', 'no_models', 'No compatible AI models found for this key. Reverted to Original Filename mode.', 'error' );
                        $sanitized['mode'] = 'fallback';
                    }
                }
            }
        }
        return $sanitized;
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( strpos( $hook, 'altgenix' ) === false && $hook !== 'upload.php' && $hook !== 'post.php' ) return;
        
        wp_enqueue_style( 'altgenix-select2-css', ALTGENIX_PLUGIN_URL . 'assets/css/select2.min.css', array(), '4.1.0' );
        wp_enqueue_script( 'altgenix-select2-js', ALTGENIX_PLUGIN_URL . 'assets/js/select2.min.js', array('jquery'), '4.1.0', true );

        wp_enqueue_style( 'altgenix-admin-style', ALTGENIX_PLUGIN_URL . 'assets/css/admin-style.css', array(), ALTGENIX_VERSION );
        wp_enqueue_script( 'altgenix-admin-script', ALTGENIX_PLUGIN_URL . 'assets/js/admin-script.js', array('jquery', 'altgenix-select2-js'), ALTGENIX_VERSION, true );

        wp_enqueue_style( 'dashicons' );
        wp_localize_script( 'altgenix-admin-script', 'altgenix_ajax', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'altgenix_ajax_nonce' ) ));
    }

    public function filter_pending_where( $where ) {
        global $wpdb;
        $where .= $wpdb->prepare( " AND {$wpdb->posts}.post_content NOT LIKE %s", '%AI Error%' );
        return $where;
    }

    public function submit_feedback() {
        check_ajax_referer( 'altgenix_ajax_nonce', 'nonce' );
        $feedback = isset( $_POST['feedback'] ) ? sanitize_textarea_field( wp_unslash( $_POST['feedback'] ) ) : '';
        $rating = isset( $_POST['rating'] ) ? intval( wp_unslash( $_POST['rating'] ) ) : 0;
        
        $to = 'abdulkabeer2530@gmail.com';
        $subject = "AltGenix AI Feedback - $rating Stars";
        $message = "You have received new feedback from the AltGenix AI Image SEO plugin:\n\n";
        $message .= "Rating: $rating Stars\n\n";
        $message .= "Feedback:\n$feedback\n\n";
        
        // Append current user email if available
        $current_user = wp_get_current_user();
        if ( $current_user->exists() ) {
            $message .= "From: " . $current_user->user_email;
        }
        
        wp_mail( $to, $subject, $message );
        
        wp_send_json_success( array( 'message' => 'Feedback received' ) );
    }

    public function add_media_library_button() {
        if ( ! current_user_can( 'upload_files' ) ) return;
        $url = admin_url('admin.php?page=altgenix-bulk-optimizer');
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var btnHtml = '<a href="<?php echo esc_url($url); ?>" class="page-title-action" style="margin-left: 10px; background: #6366f1; border-color: #4f46e5; color: #fff; padding: 4px 12px; display: inline-flex; align-items: center; gap: 6px; font-weight: 500;"><span class="dashicons dashicons-art" style="font-size: 16px; width: 16px; height: 16px; line-height: 1.2;"></span> Optimize with AltGenix</a>';
                if ($('.page-title-action').length) {
                    $('.page-title-action').last().after(btnHtml);
                } else {
                    $('.wrap h1').append(btnHtml);
                }
            });
        </script>
        <?php
    }

    public function add_edit_media_button() {
        global $post;
        if ( ! current_user_can( 'upload_files' ) || ! wp_attachment_is_image( $post->ID ) ) return;
        ?>
        <div class="misc-pub-section misc-pub-altgenix" style="padding-top: 15px; border-top: 1px solid #dcdcde; margin-top: 10px;">
            <button type="button" class="button button-primary button-large altgenix-regenerate-btn" data-id="<?php echo esc_attr( $post->ID ); ?>" style="width: 100%; text-align: center; background: #6366f1 !important; border-color: #4f46e5 !important; color: #ffffff !important; text-shadow: none !important; display: flex; align-items: center; justify-content: center; gap: 6px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <span class="dashicons dashicons-art"></span> <span class="altgenix-btn-text">Auto-Generate AI Tags</span>
            </button>
            <p class="description" style="margin-top: 8px;">Click to let AI analyze and auto-fill Alt Text, Title, Caption, and Description.</p>
        </div>
        <?php
    }

    public function create_settings_page() {
        $options = get_option( 'altgenix_settings', array() );
        $api_key = isset( $options['api_key'] ) ? $options['api_key'] : '';
        $mode = isset( $options['mode'] ) ? $options['mode'] : 'fallback';
        $custom_prompt = isset( $options['custom_prompt'] ) ? $options['custom_prompt'] : '';
        
        $gen_alt = isset( $options['gen_alt'] ) ? $options['gen_alt'] : 1;
        $gen_title = isset( $options['gen_title'] ) ? $options['gen_title'] : 1;
        $gen_caption = isset( $options['gen_caption'] ) ? $options['gen_caption'] : 0;
        $gen_desc = isset( $options['gen_desc'] ) ? $options['gen_desc'] : 0;
        $rename_file = isset( $options['rename_file'] ) ? $options['rename_file'] : 1;

        $lengths = array( 'short' => 'Short (1-5 words)', 'medium' => 'Medium (5-15 words)', 'long' => 'Long (15+ words)' );
        $valid_models = get_option( 'altgenix_valid_models', array() );
        $is_api_verified = !empty($api_key) && !empty($valid_models) && $mode === 'ai';
        ?>
        <div class="altgenix-saas-wrap">
            <div class="altgenix-header">
                <img class="altgenix-logo" src="<?php echo esc_url( ALTGENIX_PLUGIN_URL . 'assets/images/altgenix-logo.png' ); ?>" alt="AltGenix Logo"><h2>AltGenix Settings</h2>
            </div>
            <?php settings_errors( 'altgenix_setting_group' ); ?>
            
            <div class="altgenix-tabs">
                <button class="altgenix-tab-link active" data-tab="tab-general">General Settings</button>
                <button class="altgenix-tab-link" data-tab="tab-controls">Generation Control</button>
                <button class="altgenix-tab-link" data-tab="tab-advanced">Advanced</button>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'altgenix_setting_group' ); ?>
                
                <div class="altgenix-tab-content active" id="tab-general">
                    <div class="altgenix-card">
                        <h3>General Settings</h3>
                        <div class="altgenix-form-row">
                            <label>Processing Mode</label>
                            <select name="altgenix_settings[mode]" id="altgenix_mode" class="altgenix-select2" style="width: 100%; max-width: 400px;">
                                <option value="fallback" <?php selected($mode, 'fallback'); ?>>Original Filename (No API - Fast)</option>
                                <option value="ai" <?php selected($mode, 'ai'); ?>>AI Smart Generator (Uses API)</option>
                            </select>
                            <p class="description">Select how you want to generate tags. "Original Filename" converts your file name (e.g. red-car.jpg) into text.</p>
                        </div>

                        <div class="altgenix-form-row" id="altgenix_api_row" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                            <label>API Key (Google AI Studio)</label>
                            <div class="altgenix-input-wrapper" style="max-width: 400px;">
                                <input type="password" id="altgenix_api_key" name="altgenix_settings[api_key]" value="<?php echo esc_attr( $api_key ); ?>" placeholder="AIzaSy..." <?php echo $is_api_verified ? 'readonly="readonly"' : ''; ?> />
                                <button type="button" class="altgenix-action-btn altgenix-toggle-eye" data-target="altgenix_api_key" title="Show/Hide"><span class="dashicons dashicons-visibility"></span></button>
                                <?php if($is_api_verified): ?>
                                    <button type="button" class="altgenix-action-btn altgenix-edit-btn" data-target="altgenix_api_key" title="Edit Key"><span class="dashicons dashicons-edit"></span></button>
                                <?php endif; ?>
                            </div>
                            <p class="description" style="margin-top: 5px;">
                                <span class="dashicons dashicons-info-outline" style="font-size: 16px; margin-top:2px;"></span> 
                                Don't have an API key? <a href="https://aistudio.google.com/app/apikey" target="_blank" style="text-decoration: none; font-weight: 500;">Get your free API key here</a>.
                            </p>
                            <?php if(!empty($valid_models)): ?>
                            <p style="font-size: 12px; color: #059669; margin-top: 5px;"><strong>Active Models:</strong> <?php echo esc_html( implode(', ', $valid_models) ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="altgenix-tab-content" id="tab-controls">
                    <div class="altgenix-card">
                        <h3>Generation Control</h3>
                        <p class="description">Turn ON the fields you want to automatically generate when an image is uploaded.</p>
                        <table class="form-table altgenix-control-table">
                            <tbody>
                                <tr>
                                    <th scope="row">Rename Physical File</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[rename_file]" value="1" <?php checked(1, $rename_file); ?>><span class="altgenix-slider"></span></label></td>
                                    <td><em style="color:#6c757d;">Renames image1.jpg to seo-friendly-name.jpg (Only on new uploads)</em></td>
                                </tr>
                                <tr>
                                    <th scope="row">Generate Alt Text</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[gen_alt]" value="1" <?php checked(1, $gen_alt); ?>><span class="altgenix-slider"></span></label></td>
                                    <td class="altgenix-length-col" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                                        <select name="altgenix_settings[alt_length]" class="altgenix-select2">
                                            <?php foreach($lengths as $val => $label) { echo '<option value="'.esc_attr($val).'" '.selected(isset($options['alt_length'])?$options['alt_length']:'short', $val, false).'>'.esc_html($label).'</option>'; } ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Generate Title</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[gen_title]" value="1" <?php checked(1, $gen_title); ?>><span class="altgenix-slider"></span></label></td>
                                    <td class="altgenix-length-col" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                                        <select name="altgenix_settings[title_length]" class="altgenix-select2">
                                            <?php foreach($lengths as $val => $label) { echo '<option value="'.esc_attr($val).'" '.selected(isset($options['title_length'])?$options['title_length']:'short', $val, false).'>'.esc_html($label).'</option>'; } ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Generate Caption</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[gen_caption]" value="1" <?php checked(1, $gen_caption); ?>><span class="altgenix-slider"></span></label></td>
                                    <td class="altgenix-length-col" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                                        <select name="altgenix_settings[caption_length]" class="altgenix-select2">
                                            <?php foreach($lengths as $val => $label) { echo '<option value="'.esc_attr($val).'" '.selected(isset($options['caption_length'])?$options['caption_length']:'short', $val, false).'>'.esc_html($label).'</option>'; } ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Generate Description</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[gen_desc]" value="1" <?php checked(1, $gen_desc); ?>><span class="altgenix-slider"></span></label></td>
                                    <td class="altgenix-length-col" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                                        <select name="altgenix_settings[desc_length]" class="altgenix-select2">
                                            <?php foreach($lengths as $val => $label) { echo '<option value="'.esc_attr($val).'" '.selected(isset($options['desc_length'])?$options['desc_length']:'medium', $val, false).'>'.esc_html($label).'</option>'; } ?>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="altgenix-tab-content" id="tab-advanced">
                    <div class="altgenix-card">
                        <h3>Advanced Options</h3>
                        <div class="altgenix-form-row">
                            <label>Custom Prompt Context</label>
                            <textarea name="altgenix_settings[custom_prompt]" class="regular-text" rows="4" style="width: 100%; max-width: 600px;" placeholder="E.g., Keep it professional. Use brand name 'Acme Corp'. Focus on e-commerce aspects."><?php echo esc_textarea( $custom_prompt ); ?></textarea>
                            <p class="description">Add extra instructions for the AI to follow when generating text. (AI Mode only)</p>
                        </div>
                    </div>
                </div>

                <div class="altgenix-form-actions"><button type="submit" class="altgenix-btn-primary">Save Settings</button></div>
            </form>
        </div>
        <?php
    }

    public function create_bulk_optimizer_page() {
        $options = get_option( 'altgenix_settings', array() );
        $mode = isset( $options['mode'] ) ? $options['mode'] : 'fallback';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status_filter = isset( $_GET['altgenix_status'] ) ? sanitize_text_field( wp_unslash( $_GET['altgenix_status'] ) ) : 'all';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $paged = isset( $_GET['paged'] ) ? max( 1, intval( wp_unslash( $_GET['paged'] ) ) ) : 1;
        
        ?>
        <div class="altgenix-saas-wrap">
            <div class="altgenix-header">
                <img class="altgenix-logo" src="<?php echo esc_url( ALTGENIX_PLUGIN_URL . 'assets/images/altgenix-logo.png' ); ?>" alt="AltGenix Logo"><h2>Bulk Optimizer</h2>
            </div>
            
            <div class="altgenix-card altgenix-table-card">
                <div class="altgenix-table-toolbar">
                    <div class="altgenix-table-filters">
                        <select id="altgenix-status-filter" class="altgenix-select2">
                            <option value="all" <?php selected($status_filter, 'all'); ?>>All Status</option>
                            <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending</option>
                            <option value="processed" <?php selected($status_filter, 'processed'); ?>>Processed</option>
                            <option value="failed" <?php selected($status_filter, 'failed'); ?>>Failed</option>
                        </select>
                        <button id="altgenix-apply-filter" class="altgenix-btn-outline" style="margin-left: 10px;">Apply</button>
                    </div>
                    
                    <?php 
                    $supported_mimes = array( 'image/jpeg', 'image/png', 'image/webp' );
                    $total_images_query = new WP_Query( array( 'post_type' => 'attachment', 'post_mime_type' => $supported_mimes, 'post_status' => 'inherit', 'posts_per_page' => 1 ) );
                    
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    $pending_check_query = new WP_Query( array( 'post_type' => 'attachment', 'post_mime_type' => $supported_mimes, 'post_status' => 'inherit', 'posts_per_page' => 1, 'meta_query' => array( array( 'key' => '_altgenix_processed', 'compare' => 'NOT EXISTS' ) ) ));

                    $has_images = $total_images_query->have_posts();
                    $has_pending = $pending_check_query->have_posts();
                    wp_reset_postdata();

                    if ( $has_images && $has_pending ) : 
                    ?>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <button id="altgenix-auto-tag-btn" class="altgenix-btn-secondary"><span class="dashicons dashicons-update"></span> Auto-Tag Pending</button>
                            <button id="altgenix-mark-processed-btn" class="altgenix-btn-outline" title="Mark all old images as processed to hide them from pending list.">
                                <span class="dashicons dashicons-yes"></span> Mark Old Media as Processed
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Progress Bar Container -->
                <div id="altgenix-progress-container" class="altgenix-progress-container" style="display: none;">
                    <div class="altgenix-progress-bar">
                        <div id="altgenix-progress-fill" class="altgenix-progress-fill"></div>
                    </div>
                    <div class="altgenix-progress-text">
                        <span id="altgenix-progress-percentage">0%</span> - <span id="altgenix-progress-status">Processing...</span>
                    </div>
                </div>
                
                <table class="altgenix-table">
                    <thead><tr><th>Image</th><th>File Name</th><th>Title / Error</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php
                        $args = array( 'post_type' => 'attachment', 'post_mime_type' => $supported_mimes, 'post_status' => 'inherit', 'posts_per_page' => 10, 'paged' => $paged );
                        
                        if ( $status_filter === 'processed' ) { $args['meta_query'] = array( array( 'key' => '_altgenix_processed', 'value' => '1', 'compare' => '=' ) ); } 
                        elseif ( $status_filter === 'pending' ) { 
                            $args['meta_query'] = array( array( 'key' => '_altgenix_processed', 'compare' => 'NOT EXISTS' ) ); 
                            add_filter( 'posts_where', array( $this, 'filter_pending_where' ) ); 
                        } 
                        elseif ( $status_filter === 'failed' ) { $args['s'] = 'AI Error'; }

                        $query = new WP_Query( $args );
                        if ( $status_filter === 'pending' ) { remove_filter( 'posts_where', array( $this, 'filter_pending_where' ) ); }

                        if ( $query->have_posts() ) :
                            while ( $query->have_posts() ) : $query->the_post();
                                $id = get_the_ID(); 
                                $is_processed = get_post_meta( $id, '_altgenix_processed', true ); 
                                $desc = get_the_content(); 
                                $thumb = wp_get_attachment_image( $id, array(40, 40) );
                                $title = get_the_title();
                                
                                if ( strpos( $desc, 'AI Error' ) !== false ) { $stat = 'Failed'; $bg = 'altgenix-badge-danger'; $text = wp_trim_words($desc, 8); }
                                elseif ( $is_processed ) { $stat = 'Processed'; $bg = 'altgenix-badge-success'; $text = wp_trim_words($title, 10); }
                                else { $stat = 'Pending'; $bg = 'altgenix-badge-warning'; $text = 'Awaiting Action...'; }
                                ?>
                                <tr>
                                    <td><div class="altgenix-img-thumb"><?php echo $thumb ? wp_kses_post( $thumb ) : '<span class="dashicons dashicons-format-image"></span>'; ?></div></td>
                                    <td><strong><?php echo esc_html( wp_basename( get_attached_file( $id ) ) ); ?></strong></td>
                                    <td class="altgenix-text-muted"><?php echo esc_html( $text ); ?></td>
                                    <td><span class="altgenix-badge <?php echo esc_attr( $bg ); ?>"><?php echo esc_html( $stat ); ?></span></td>
                                    <td><?php echo esc_html( get_the_date( 'M j' ) ); ?></td>
                                    <td>
                                        <?php if ( $mode !== 'fallback' ) : ?>
                                            <button class="altgenix-action-icon altgenix-regenerate-btn" data-id="<?php echo esc_attr($id); ?>" title="Regenerate AI Tags"><span class="dashicons dashicons-image-rotate"></span></button>
                                        <?php endif; ?>
                                        <a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>" target="_blank" class="altgenix-action-icon" title="Edit Image"><span class="dashicons dashicons-edit"></span></a>
                                    </td>
                                </tr>
                                <?php
                            endwhile;
                        else : echo '<tr class="altgenix-empty-row"><td colspan="6" style="text-align:center; padding: 30px; color: #6c757d;">No images found.</td></tr>'; endif;
                        ?>
                    </tbody>
                </table>
                <?php if ( $query->max_num_pages > 1 ) { echo '<div class="altgenix-pagination">'; echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $query->max_num_pages, 'prev_text' => '&laquo; Prev', 'next_text' => 'Next &raquo;' ) ) ); echo '</div>'; } wp_reset_postdata(); ?>
            </div>
            
            <div id="altgenix-modal" class="altgenix-modal-overlay">
                <div class="altgenix-modal-box"><h3 id="altgenix-modal-title">Confirm</h3><p id="altgenix-modal-text">Proceed?</p>
                    <div class="altgenix-modal-actions"><button id="altgenix-modal-cancel" class="altgenix-btn-outline">Cancel</button><button id="altgenix-modal-confirm" class="altgenix-btn-primary">Yes</button></div>
                </div>
            </div>
        </div>
        <?php
    }

    public function create_help_page() {
        ?>
        <div class="altgenix-saas-wrap">
            <div class="altgenix-header">
                <img class="altgenix-logo" src="<?php echo esc_url( ALTGENIX_PLUGIN_URL . 'assets/images/altgenix-logo.png' ); ?>" alt="AltGenix Logo"><h2>Help & Rate Us</h2>
            </div>
            
            <div class="altgenix-card" style="max-width: 600px; text-align: center; margin: 40px auto;">
                <h3>Enjoying AltGenix AI Image SEO?</h3>
                <p class="description" style="font-size: 16px; margin-bottom: 20px;">Your feedback helps us improve and build better features. Please rate your experience!</p>
                
                <div class="altgenix-star-rating" id="altgenix-star-rating">
                    <span class="dashicons dashicons-star-empty" data-rating="1"></span>
                    <span class="dashicons dashicons-star-empty" data-rating="2"></span>
                    <span class="dashicons dashicons-star-empty" data-rating="3"></span>
                    <span class="dashicons dashicons-star-empty" data-rating="4"></span>
                    <span class="dashicons dashicons-star-empty" data-rating="5"></span>
                </div>

                <div id="altgenix-rating-feedback" style="display: none; margin-top: 25px;">
                    <div class="altgenix-rating-low" style="display: none;">
                        <h4>We're sorry to hear that! 😔</h4>
                        <p class="description">How can we improve? Please let our support team know so we can fix it.</p>
                        <textarea id="altgenix-feedback-text" class="regular-text" rows="4" style="width: 100%; margin-bottom: 10px;" placeholder="Tell us what went wrong..."></textarea>
                        <button id="altgenix-submit-feedback" class="altgenix-btn-primary">Submit Feedback</button>
                    </div>
                    
                    <div class="altgenix-rating-high" style="display: none;">
                        <h4>Awesome! 🎉</h4>
                        <p class="description">Could you do us a huge favor and leave a 5-star review on WordPress.org? It takes just 1 minute!</p>
                        <a href="https://wordpress.org/support/plugin/altgenix-ai-image-seo/reviews/#new-post" target="_blank" class="altgenix-btn-primary" style="text-decoration: none; display: inline-block;">Leave a Review on WP.org</a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}