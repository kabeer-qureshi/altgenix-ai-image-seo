<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ALTGENIX_Settings {
    private $last_sanitized = null;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'maybe_migrate_legacy_errors' ), 20 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'wp_ajax_altgenix_submit_feedback', array( $this, 'submit_feedback' ) );
        add_action( 'wp_ajax_altgenix_record_review', array( $this, 'record_review_click' ) );
        add_action( 'wp_ajax_altgenix_review_click', array( $this, 'record_review_click' ) );
        add_action( 'wp_ajax_altgenix_dismiss_services', array( $this, 'ajax_dismiss_services' ) );
        add_action( 'wp_ajax_altgenix_dismiss_banner', array( $this, 'ajax_dismiss_banner' ) );
        add_action( 'wp_ajax_altgenix_save_settings', array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_altgenix_verify_models', array( $this, 'ajax_verify_models' ) );
        add_action( 'wp_ajax_altgenix_remove_provider_key', array( $this, 'ajax_remove_provider_key' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__ ) . '/altgenix-ai-image-seo.php' ), array( $this, 'add_plugin_action_links' ) );
        add_action( 'admin_notices', array( $this, 'maybe_render_service_notice' ) );
        
        // Native Media Integration
        add_action( 'attachment_submitbox_misc_actions', array( $this, 'add_edit_media_button' ) );
        add_filter( 'attachment_fields_to_edit', array( $this, 'add_media_modal_button' ), 10, 2 );
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
     * Per-admin preview cache used by the Verify & Refresh action.
     * It stores only a key context hash and model IDs, never the API key itself.
     */
    private function verification_preview_key() {
        return 'altgenix_verify_preview_' . get_current_user_id();
    }

    /**
     * Sanitize and validate all plugin settings before saving.
     */
    public function sanitize( $input ) {
        $previous = ALTGENIX_Core::get_settings();
        if ( $this->last_sanitized !== null && $input === $this->last_sanitized ) { return $input; }
        if ( ! is_array( $input ) ) { add_settings_error( 'altgenix_setting_group', 'invalid', 'Invalid settings data.', 'error' ); return $previous; }
        $defaults = ALTGENIX_Core::default_settings();
        foreach ( $defaults as $key => $value ) {
            if ( $key !== 'provider_keys' && isset( $input[ $key ] ) && ! is_scalar( $input[ $key ] ) ) {
                add_settings_error( 'altgenix_setting_group', 'invalid', 'Settings contain an invalid field value.', 'error' );
                return $previous;
            }
        }
        $clean = $previous;
        foreach ( array( 'mode' => array( 'fallback', 'ai' ), 'provider' => ALTGENIX_API::supported_providers(), 'language' => array_merge( array( 'auto' ), array_keys( ALTGENIX_API::supported_languages() ) ) ) as $key => $allowed ) {
            if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) { $clean[ $key ] = $input[ $key ]; }
        }
        foreach ( array( 'gen_alt', 'gen_title', 'gen_caption', 'gen_desc', 'rename_file', 'auto_upload', 'allow_escalation' ) as $key ) {
            $clean[ $key ] = isset( $input[ $key ] ) && in_array( $input[ $key ], array( 1, '1', true ), true ) ? 1 : 0;
        }
        foreach ( array( 'alt_length', 'title_length', 'caption_length', 'desc_length' ) as $key ) {
            if ( isset( $input[ $key ] ) && in_array( $input[ $key ], array( 'short', 'medium', 'long' ), true ) ) { $clean[ $key ] = $input[ $key ]; }
        }
        // Settings API and AJAX already unslash the request. Do not strip again.
        if ( isset( $input['custom_prompt'] ) ) { $clean['custom_prompt'] = sanitize_textarea_field( substr( (string) $input['custom_prompt'], 0, 8000 ) ); }
        $clean['model'] = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : $previous['model'];
        $provider = $clean['provider'];
        $key = isset( $clean['provider_keys'][ $provider ] ) ? $clean['provider_keys'][ $provider ] : '';
        // Blank means retain; deletion is an explicit separate control.
        if ( isset( $input['api_key'] ) && trim( (string) $input['api_key'] ) !== '' ) { $key = sanitize_text_field( trim( (string) $input['api_key'] ) ); }
        if ( isset( $input['remove_api_key'] ) && $input['remove_api_key'] === '1' ) {
            $key = '';
            unset( $clean['provider_keys'][ $provider ] );
            $clean['mode'] = 'fallback';
        }
        $clean['api_key'] = $key;
        if ( $key !== '' ) { $clean['provider_keys'][ $provider ] = $key; }
        $candidate = $clean;
        $candidate['api_key'] = $key;
        if ( $key !== '' ) { $candidate['provider_keys'][ $provider ] = $key; }
        $models = ALTGENIX_API::verified_models( $candidate );
        $unchanged = $provider === $previous['provider'] && $key === $previous['api_key'];
        if ( $clean['mode'] === 'ai' ) {
            if ( $key === '' ) {
                add_settings_error( 'altgenix_setting_group', 'missing_key', 'Enter an API key before enabling AI. Previous settings were preserved.', 'error' );
                return $previous;
            }
            if ( ! $models ) {
                $preview = get_transient( $this->verification_preview_key() );
                $context = ALTGENIX_API::context( $provider, $key );
                if ( is_array( $preview ) && isset( $preview['context'], $preview['models'] ) && is_array( $preview['models'] ) && hash_equals( $context, (string) $preview['context'] ) ) {
                    $models = array_values( array_filter( array_map( function ( $model_id ) {
                        return is_string( $model_id ) ? sanitize_text_field( $model_id ) : '';
                    }, $preview['models'] ) ) );
                }
            }
            if ( ! $models ) {
                $verified = ALTGENIX_API::verify_key( $provider, $key );
                if ( empty( $verified['valid'] ) ) {
                    add_settings_error( 'altgenix_setting_group', 'verification', $verified['message'], 'error' );
                    return $previous;
                }
                $models = $verified['models'];
            }
        } elseif ( ! $unchanged ) { $models = array(); }
        if ( ! in_array( $clean['model'], $models, true ) ) { $clean['model'] = ''; }
        update_option( 'altgenix_valid_models', $models, false );
        update_option( 'altgenix_models_context', ALTGENIX_API::context( $provider, $key ), false );
        delete_transient( $this->verification_preview_key() );
        $this->last_sanitized = $clean;
        add_settings_error( 'altgenix_setting_group', 'saved', 'Settings saved.', 'success' );
        return $clean;
    }

    public function add_plugin_action_links( $links ) {
        array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=altgenix-settings' ) ) . '">' . esc_html__( 'Settings', 'altgenix-ai-image-seo' ) . '</a>' );
        return $links;
    }

    /**
     * Cache-busting version for one of this plugin's own assets.
     *
     * Tied to the file itself rather than the plugin version, because the two can
     * disagree and it is always the file that matters. A normal update changes both
     * and either would do; but a stylesheet edited without a version bump — a patched
     * release, a support fix copied onto a site, a build being worked on — changes
     * only the file, and a version-based query string would leave every browser
     * serving markup from the new PHP with rules from the old CSS. That mismatch is
     * what a broken-looking layout actually is.
     *
     * WordPress rewrites these files on update, so the timestamp moves then too.
     * The plugin version remains the fallback for the case where the file cannot be
     * read at all.
     *
     * @param string $relative_path Path inside the plugin folder.
     * @return string
     */
    private function asset_version( $relative_path ) {
        $file = ALTGENIX_PLUGIN_DIR . $relative_path;

        if ( file_exists( $file ) ) {
            $modified = filemtime( $file );
            if ( $modified ) {
                // Prefixed with the plugin version so the value stays recognisable
                // in a page source rather than being a bare timestamp.
                return ALTGENIX_VERSION . '.' . $modified;
            }
        }

        return ALTGENIX_VERSION;
    }

    public function enqueue_admin_scripts( $hook ) {
        $media = array( 'upload.php', 'media-new.php', 'post.php', 'post-new.php' );
        if ( strpos( $hook, 'altgenix' ) === false && ! in_array( $hook, $media, true ) ) { return; }
        if ( ! current_user_can( 'upload_files' ) && ! current_user_can( 'manage_options' ) ) { return; }
        wp_enqueue_style( 'altgenix-admin-style', ALTGENIX_PLUGIN_URL . 'assets/css/admin-style.css', array(), $this->asset_version( 'assets/css/admin-style.css' ) );
        wp_enqueue_script( 'altgenix-admin-script', ALTGENIX_PLUGIN_URL . 'assets/js/admin-script.js', array( 'jquery' ), $this->asset_version( 'assets/js/admin-script.js' ), true );
        wp_enqueue_style( 'dashicons' );
        $config = array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'altgenix_ajax_nonce' ), 'auto_queue' => current_user_can( 'upload_files' ) && in_array( $hook, $media, true ) );
        if ( current_user_can( 'manage_options' ) ) {
            $config['bulk_url'] = $hook === 'upload.php' ? admin_url( 'admin.php?page=altgenix-bulk-optimizer' ) : '';
            if ( strpos( $hook, 'altgenix-settings' ) !== false ) {
                $options = ALTGENIX_Core::get_settings();
                $config['saved_keys'] = array_map( function ( $key ) { return $key !== ''; }, $options['provider_keys'] );
                $config['verified'] = $options['mode'] === 'ai' && $options['api_key'] !== '' && (bool) ALTGENIX_API::verified_models( $options );
            }
        }
        // Secrets never cross into page source or localized JavaScript, even for admins.
        wp_localize_script( 'altgenix-admin-script', 'altgenix_ajax', $config );
    }

    public function filter_pending_where( $where ) {
        global $wpdb;
        // Releases up to 1.1.2 stored provider failures in post_content. New failures use
        // private post meta, but keep old rows out of Pending until the one-time
        // migration has lifted them out. Same strict prefix the migration matches on.
        $where .= $wpdb->prepare( " AND {$wpdb->posts}.post_content NOT LIKE %s", 'AI Error: %' );
        return $where;
    }

    /** How many legacy rows one admin page load is allowed to convert. */
    private const LEGACY_MIGRATION_BATCH = 50;

    /**
     * Does this description look like something an earlier release wrote, rather than something
     * a person wrote?
     *
     * Releases up to 1.1.2 produced exactly `'AI Error: ' . sanitize_text_field( $message )`, and
     * sanitize_text_field() strips tags and collapses newlines — so the real thing is
     * always a single short line with no markup. Matching only that shape is what
     * keeps this migration away from a Description somebody typed themselves that
     * happens to open with the same words.
     *
     * @param string $content Raw post_content.
     * @return bool
     */
    private function looks_like_legacy_error( $content ) {
        $content = (string) $content;

        if ( strpos( $content, 'AI Error: ' ) !== 0 ) { return false; }
        if ( strlen( $content ) > 300 ) { return false; }
        if ( preg_match( '/[\r\n]/', $content ) ) { return false; }
        if ( wp_strip_all_tags( $content ) !== $content ) { return false; }

        return true;
    }

    /**
     * Lift older releases' failure placeholders out of the attachment Description.
     *
     * Releases up to 1.1.2 recorded provider failures by overwriting post_content, which meant a
     * failed run destroyed whatever Description the image had. The text moves to
     * private meta and the Description is emptied here, in the one place that can
     * still tell a plugin placeholder apart from real content — so nothing later in
     * the plugin ever has to guess, or edit post_content again.
     *
     * Runs in small batches across page loads: a site with thousands of failed rows
     * must not turn one admin request into a timeout, and the version marker is only
     * written once there is genuinely nothing left, so an interrupted run resumes
     * instead of being recorded as complete.
     */
    public function maybe_migrate_legacy_errors() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $data_version = (string) get_option( 'altgenix_data_version', '0' );
        if ( version_compare( $data_version, '1.2.0', '>=' ) ) { return; }

        global $wpdb;
        $like = $wpdb->esc_like( 'AI Error: ' ) . '%';

        // Rows already examined and left alone are excluded, otherwise a batch full
        // of them would be re-read on every page load and the migration would never
        // reach the end.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_content
                   FROM {$wpdb->posts} p
                   LEFT JOIN {$wpdb->postmeta} pm
                     ON pm.post_id = p.ID AND pm.meta_key = %s
                  WHERE p.post_type = 'attachment'
                    AND p.post_content LIKE %s
                    AND pm.post_id IS NULL
                  LIMIT %d",
                '_altgenix_legacy_skipped',
                $like,
                self::LEGACY_MIGRATION_BATCH
            )
        );

        if ( ! is_array( $rows ) ) { return; }
        if ( empty( $rows ) ) {
            update_option( 'altgenix_data_version', '1.2.0', false );
            return;
        }

        foreach ( $rows as $row ) {
            $attachment_id = isset( $row->ID ) ? (int) $row->ID : 0;
            if ( ! $attachment_id ) { continue; }

            $content = (string) $row->post_content;

            if ( ! $this->looks_like_legacy_error( $content ) ) {
                // Somebody's own words. Leave them alone — but mark the row so the
                // next batch moves past it instead of reading it forever.
                update_post_meta( $attachment_id, '_altgenix_legacy_skipped', '1' );
                continue;
            }

            if ( ! metadata_exists( 'post', $attachment_id, '_altgenix_error' ) ) {
                $message = substr( $content, strlen( 'AI Error: ' ) );
                update_post_meta( $attachment_id, '_altgenix_error', sanitize_text_field( $message ) );
            }

            delete_post_meta( $attachment_id, '_altgenix_processed' );
            update_post_meta( $attachment_id, '_altgenix_legacy_skipped', '1' );
        }
    }

    /**
     * Where a user should go when this site cannot deliver the feedback email.
     */
    private const SUPPORT_URL = 'https://wordpress.org/support/plugin/altgenix-ai-image-seo/';

    public function submit_feedback() {
        check_ajax_referer( 'altgenix_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized Access' ) ); }

        $feedback = isset( $_POST['feedback'] ) && is_string( $_POST['feedback'] ) ? sanitize_textarea_field( wp_unslash( $_POST['feedback'] ) ) : '';
        $rating   = isset( $_POST['rating'] ) && is_scalar( $_POST['rating'] ) ? intval( wp_unslash( $_POST['rating'] ) ) : 0;
        $rating   = max( 1, min( 5, $rating ) );

        if ( $feedback === '' || strlen( $feedback ) > 10000 ) {
            wp_send_json_error( array( 'message' => 'Please write between 1 and 10,000 bytes of feedback.' ) );
        }

        $options  = get_option( 'altgenix_settings', array() );
        $provider = isset( $options['provider'] ) ? $options['provider'] : 'gemini';

        $to      = 'abdulkabeer2530@gmail.com';
        $subject = 'AltGenix AI Feedback - ' . $rating . ' Stars';

        $message  = "You have received new feedback from the AltGenix AI Image SEO plugin:\n\n";
        $message .= "Rating: {$rating} Stars\n\n";
        $message .= "Feedback:\n{$feedback}\n\n";

        // Enough context to actually act on a report. Never the API key.
        $message .= "---\n";
        $message .= 'Site: ' . home_url() . "\n";
        $message .= 'Plugin version: ' . ALTGENIX_VERSION . "\n";
        $message .= 'AI provider: ' . $provider . "\n";

        $current_user = wp_get_current_user();
        $headers      = array();
        if ( $current_user->exists() ) {
            $message  .= 'From: ' . $current_user->user_email . "\n";
            $headers[] = 'Reply-To: ' . $current_user->user_email;
        }

        $rate_key = 'altgenix_feedback_sent_' . get_current_user_id();
        if ( get_transient( $rate_key ) ) { wp_send_json_error( array( 'message' => 'Please wait one minute before sending again.' ), 429 ); }
        set_transient( $rate_key, 1, MINUTE_IN_SECONDS );
        $delivered = (bool) wp_mail( $to, $subject, $message, $headers );

        // Plenty of WordPress installs cannot send mail at all. Reporting success
        // anyway would lose the feedback AND tell the user it had arrived, so the
        // failure is handed back with somewhere else to send it.
        $this->remember_rating( $rating, 'feedback', $delivered );

        if ( ! $delivered ) {
            wp_send_json_error(
                array(
                    'message' => 'This site could not send email, so your feedback did not reach us. Please post it on the plugin support forum instead — the link is below.',
                    'support' => self::SUPPORT_URL,
                )
            );
        }

        wp_send_json_success( array( 'message' => 'Feedback received' ) );
    }

    /**
     * Record that this site has already been asked to rate the plugin.
     *
     * Without this the star prompt reappears on every visit to the Help page,
     * including for someone who has already left a review — which is exactly the
     * nagging that makes people dislike a plugin.
     *
     * @param int    $rating    1-5.
     * @param string $via       'feedback' (wrote to us) or 'review' (went to WP.org).
     * @param bool   $delivered Whether the email actually went out.
     */
    private function remember_rating( $rating, $via, $delivered = true ) {
        update_option(
            'altgenix_feedback',
            array(
                'rating'    => (int) $rating,
                'via'       => $via,
                'delivered' => (bool) $delivered,
                'time'      => time(),
            ),
            false
        );
    }

    /**
     * Fired when a happy user clicks through to leave a WordPress.org review, so
     * the plugin stops asking them.
     */
    public function record_review_click() {
        check_ajax_referer( 'altgenix_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized Access' ) ); }

        $rating = isset( $_POST['rating'] ) && is_scalar( $_POST['rating'] ) ? intval( wp_unslash( $_POST['rating'] ) ) : 5;
        $this->remember_rating( max( 1, min( 5, $rating ) ), 'review' );

        wp_send_json_success();
    }

    public function add_edit_media_button() {
        global $post;
        if ( ! $post || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post->ID ) || ! wp_attachment_is_image( $post->ID ) ) return;
        $options = get_option('altgenix_settings', array());
        $mode = isset($options['mode']) ? $options['mode'] : 'fallback';
        $btn_text = $mode === 'ai' ? 'Auto-Generate AI Tags' : 'Generate Tags (Filename)';
        $desc_text = $mode === 'ai' ? 'Click to let AI analyze and auto-fill Alt Text, Title, Caption, and Description.' : 'Click to auto-fill tags using the original filename.';
        ?>
        <div class="misc-pub-section misc-pub-altgenix" style="padding-top: 15px; border-top: 1px solid #dcdcde; margin-top: 10px;">
            <button type="button" class="button button-primary button-large altgenix-regenerate-btn" data-id="<?php echo esc_attr( $post->ID ); ?>" data-ai-mode="<?php echo $mode === 'ai' ? '1' : '0'; ?>" data-can-rename="<?php echo current_user_can( 'manage_options' ) ? '1' : '0'; ?>" data-rename-default="<?php echo ! empty( $options['rename_file'] ) ? '1' : '0'; ?>" <?php echo $this->generation_button_data_attributes( $options ); ?> style="width: 100%; text-align: center; background: #6366f1 !important; border-color: #4f46e5 !important; color: #ffffff !important; text-shadow: none !important; display: flex; align-items: center; justify-content: center; gap: 6px; border-radius: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); padding: 0 12px; height: auto; min-height: 32px;">
                <span class="dashicons dashicons-art" style="display: flex; align-items: center; justify-content: center;"></span> <span class="altgenix-btn-text" style="display: flex; align-items: center;"><?php echo esc_html($btn_text); ?></span>
            </button>
            <p class="description"><?php echo esc_html($desc_text); ?></p>
        </div>
        <?php
    }

    public function add_media_modal_button( $form_fields, $post ) {
        if ( ! $post || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post->ID ) || ! wp_attachment_is_image( $post->ID ) ) return $form_fields;
        $options = get_option('altgenix_settings', array());
        $mode = isset($options['mode']) ? $options['mode'] : 'fallback';
        $label_text = $mode === 'ai' ? 'AI Image SEO' : 'Image SEO';
        $btn_text = $mode === 'ai' ? 'Auto-Generate Tags' : 'Generate (Filename)';
        
        $form_fields['altgenix_generate'] = array(
            'label' => $label_text,
            'input' => 'html',
            'html'  => '<button type="button" class="button button-primary altgenix-regenerate-btn" data-id="' . esc_attr( $post->ID ) . '" data-ai-mode="' . ( $mode === 'ai' ? '1' : '0' ) . '" data-can-rename="' . ( current_user_can( 'manage_options' ) ? '1' : '0' ) . '" data-rename-default="' . ( ! empty( $options['rename_file'] ) ? '1' : '0' ) . '" ' . $this->generation_button_data_attributes( $options ) . ' style="background: #6366f1 !important; border-color: #4f46e5 !important; color: #ffffff !important; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 4px 12px; height: auto; min-height: 32px;"><span class="dashicons dashicons-art" style="display: flex; align-items: center; justify-content: center;"></span> <span style="display: flex; align-items: center;">' . esc_html($btn_text) . '</span></button>',
        );
        return $form_fields;
    }

    public function ajax_verify_models() {
        check_ajax_referer( 'altgenix_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized access.' ), 403 ); }

        $provider = isset( $_POST['provider'] ) && is_scalar( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
        if ( ! in_array( $provider, ALTGENIX_API::supported_providers(), true ) ) { wp_send_json_error( array( 'message' => 'Invalid AI provider.' ), 400 ); }

        $typed_key = isset( $_POST['api_key'] ) && is_scalar( $_POST['api_key'] ) ? sanitize_text_field( trim( wp_unslash( (string) $_POST['api_key'] ) ) ) : '';
        $options = ALTGENIX_Core::get_settings();
        $saved_key = isset( $options['provider_keys'][ $provider ] ) ? $options['provider_keys'][ $provider ] : '';
        $key = $typed_key !== '' ? $typed_key : $saved_key;
        if ( $key === '' ) { wp_send_json_error( array( 'message' => 'Enter an API key before verifying models.' ), 400 ); }

        $verified = ALTGENIX_API::verify_key( $provider, $key );
        if ( empty( $verified['valid'] ) ) {
            $message = isset( $verified['message'] ) ? ALTGENIX_API::redact_error( $verified['message'], $key ) : 'The API key could not be verified.';
            wp_send_json_error( array( 'message' => $message ) );
        }

        $models = isset( $verified['models'] ) && is_array( $verified['models'] ) ? array_values( $verified['models'] ) : array();
        $context = ALTGENIX_API::context( $provider, $key );
        set_transient( $this->verification_preview_key(), array( 'context' => $context, 'models' => $models ), 10 * MINUTE_IN_SECONDS );

        // Refresh the live model cache only when verifying the already-saved active key.
        // Previewing a new/unsaved key must never disturb the currently active configuration.
        $uses_saved_key = $typed_key === '' && $saved_key !== '';
        $is_active_saved_key = $uses_saved_key && $options['provider'] === $provider && $options['api_key'] === $saved_key;
        if ( $is_active_saved_key ) {
            update_option( 'altgenix_valid_models', $models, false );
            update_option( 'altgenix_models_context', $context, false );
        }

        wp_send_json_success( array(
            'message' => sprintf( '%d model%s available.', count( $models ), count( $models ) === 1 ? '' : 's' ),
            'valid_models' => $models,
            'using_saved_key' => $uses_saved_key,
        ) );
    }

    public function ajax_remove_provider_key() {
        check_ajax_referer( 'altgenix_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized access.' ), 403 ); }

        $provider = isset( $_POST['provider'] ) && is_scalar( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
        if ( ! in_array( $provider, ALTGENIX_API::supported_providers(), true ) ) { wp_send_json_error( array( 'message' => 'Invalid AI provider.' ), 400 ); }

        $options = ALTGENIX_Core::get_settings();
        $was_active = $options['provider'] === $provider;
        unset( $options['provider_keys'][ $provider ] );
        if ( $was_active ) {
            $options['api_key'] = '';
            $options['mode'] = 'fallback';
            $options['model'] = '';
            update_option( 'altgenix_valid_models', array(), false );
            update_option( 'altgenix_models_context', ALTGENIX_API::context( $provider, '' ), false );
        }
        update_option( 'altgenix_settings', $options, false );
        delete_transient( $this->verification_preview_key() );

        $present = array_map( function ( $saved ) { return $saved !== ''; }, $options['provider_keys'] );
        wp_send_json_success( array(
            'message' => 'Saved API key removed.',
            'saved_keys' => $present,
            'switched_to_fallback' => $was_active,
        ) );
    }

    public function ajax_save_settings() {
        check_ajax_referer( 'altgenix_setting_group-options', '_wpnonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized access.' ), 403 ); }
        if ( ! isset( $_POST['altgenix_settings'] ) || ! is_array( $_POST['altgenix_settings'] ) ) { wp_send_json_error( array( 'message' => 'Invalid settings data.' ), 400 ); }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The registered sanitize callback validates every setting before persistence.
        update_option( 'altgenix_settings', wp_unslash( $_POST['altgenix_settings'] ), false );
        foreach ( get_settings_errors( 'altgenix_setting_group' ) as $error ) {
            if ( $error['type'] === 'error' ) { wp_send_json_error( array( 'message' => $error['message'], 'saved' => false ) ); }
        }
        $options = ALTGENIX_Core::get_settings();
        if ( $this->last_sanitized !== null && $options !== $this->last_sanitized ) { wp_send_json_error( array( 'message' => 'Could not persist settings. Please retry.', 'saved' => false ) ); }
        $models = ALTGENIX_API::verified_models( $options );
        $present = array_map( function ( $key ) { return $key !== ''; }, $options['provider_keys'] );
        unset( $options['api_key'], $options['provider_keys'] );
        wp_send_json_success( array( 'message' => 'Settings saved.', 'saved' => true, 'settings' => $options, 'valid_models' => $models, 'saved_keys' => $present, 'is_verified' => $options['mode'] === 'ai' && (bool) $models ) );
    }

    public function create_settings_page() {
        // Same source the processor reads, so what these switches show is what an
        // upload will actually do — even before anything has been saved.
        $options = ALTGENIX_Core::get_settings();
        $api_key = $options['api_key'];
        $mode = $options['mode'];
        $custom_prompt = $options['custom_prompt'];
        $language = $options['language'];
        $provider = in_array( $options['provider'], ALTGENIX_API::supported_providers(), true ) ? $options['provider'] : 'gemini';

        $gen_alt = $options['gen_alt'];
        $gen_title = $options['gen_title'];
        $gen_caption = $options['gen_caption'];
        $gen_desc = $options['gen_desc'];
        $rename_file = $options['rename_file'];

        $lengths = array( 'short' => 'Short (1-5 words)', 'medium' => 'Medium (5-15 words)', 'long' => 'Long (15-30 words)' );
        $valid_models = ALTGENIX_API::verified_models( $options );
        if ( ! is_array( $valid_models ) ) { $valid_models = array(); }

        // Empty means "whichever is cheapest", which is the first entry — every
        // stored list is ordered cheapest-first.
        $chosen_model     = isset( $options['model'] ) ? $options['model'] : '';
        $allow_escalation = ! empty( $options['allow_escalation'] );
        $is_api_verified = !empty($api_key) && !empty($valid_models) && $mode === 'ai';
        ?>
        <div class="altgenix-saas-wrap">
            <div class="altgenix-header">
                <img class="altgenix-logo" src="<?php echo esc_url( ALTGENIX_PLUGIN_URL . 'assets/images/altgenix-logo.png' ); ?>" alt="AltGenix Logo"><h2>AltGenix Settings</h2>
            </div>

            <?php $this->render_services_banner(); ?>
            <?php settings_errors( 'altgenix_setting_group' ); ?>
            <div class="altgenix-tabs">
                <button class="altgenix-tab-link active" data-tab="tab-general">General Settings</button>
                <button class="altgenix-tab-link" data-tab="tab-controls">Generation Control</button>
                <button class="altgenix-tab-link" data-tab="tab-advanced">Advanced</button>
            </div>

            <form method="post" id="altgenix-settings-form" action="options.php">
                <?php settings_fields( 'altgenix_setting_group' ); ?>
                
                <div class="altgenix-tab-content active" id="tab-general">
                    <div class="altgenix-card altgenix-form-grid">
                        <h3>General Settings</h3>
                        <div class="altgenix-form-row altgenix-form-row-wide">
                            <label>Processing Mode
                                <span class="altgenix-tip">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                    <div class="altgenix-tip-content">Select how you want to generate tags. "Original Filename" converts your file name (e.g. red-car.jpg) into text.</div>
                                </span>
                            </label>
                            <select name="altgenix_settings[mode]" id="altgenix_mode" class="altgenix-select" style="width: 100%; max-width: 400px;">
                                <option value="fallback" <?php selected($mode, 'fallback'); ?>>Original Filename (No API - Fast)</option>
                                <option value="ai" <?php selected($mode, 'ai'); ?>>AI Smart Generator (Uses API)</option>
                            </select>
                        </div>

                        <div class="altgenix-form-row" id="altgenix_provider_row" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                            <label>AI Provider
                                <span class="altgenix-tip">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                    <div class="altgenix-tip-content">Choose which AI engine analyzes your images. Each provider needs its own API key &mdash; switching providers re-verifies with the key entered below.</div>
                                </span>
                            </label>
                            <select name="altgenix_settings[provider]" id="altgenix_provider" class="altgenix-select" style="width: 100%; max-width: 400px;">
                                <?php foreach ( ALTGENIX_API::provider_labels() as $prov_key => $prov_label ) {
                                    echo '<option value="' . esc_attr( $prov_key ) . '" ' . selected( $provider, $prov_key, false ) . '>' . esc_html( $prov_label ) . '</option>';
                                } ?>
                            </select>
                        </div>

                        <div class="altgenix-form-row" id="altgenix_api_row" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                            <label>API Key</label>
                            <div class="altgenix-input-wrapper" style="max-width: 400px;">
                                <input type="password" id="altgenix_api_key" name="altgenix_settings[api_key]" value="" autocomplete="new-password" placeholder="<?php echo $api_key !== '' ? esc_attr__( '••••••••••••••••', 'altgenix-ai-image-seo' ) : esc_attr__( 'Enter your API key', 'altgenix-ai-image-seo' ); ?>" <?php echo $api_key !== '' ? 'readonly class="altgenix-api-key-saved"' : ''; ?> />
                                <button type="button" class="altgenix-action-btn altgenix-toggle-eye" data-target="altgenix_api_key" title="Show/Hide" style="<?php echo $api_key !== '' ? 'display:none;' : ''; ?>"><span class="dashicons dashicons-visibility"></span></button>
                                <button type="button" class="altgenix-action-btn altgenix-edit-icon" data-target="altgenix_api_key" title="Replace saved key" style="display: <?php echo $api_key !== '' ? 'flex' : 'none'; ?>;"><span class="dashicons dashicons-edit"></span></button>
                            </div>
                            <div class="altgenix-key-meta">
                                <p id="altgenix-api-key-status" class="altgenix-api-key-status" role="status" aria-live="polite" style="<?php echo $api_key === '' ? 'display:none;' : ''; ?>">
                                    <span class="dashicons dashicons-yes-alt altgenix-api-key-status-icon" aria-hidden="true"></span>
                                    <span class="altgenix-api-key-status-text"><?php echo esc_html( $is_api_verified ? __( 'API Key Saved & Verified', 'altgenix-ai-image-seo' ) : __( 'API Key Saved', 'altgenix-ai-image-seo' ) ); ?></span>
                                </p>
                                <button type="button" id="altgenix-remove-key" class="altgenix-link-danger" style="<?php echo $api_key === '' ? 'display:none;' : ''; ?>">Remove Key</button>
                            </div>
                            <p class="description">
                                <span class="dashicons dashicons-info-outline" style="font-size: 16px; margin-top:2px;"></span>
                                Don't have an API key? <a href="https://aistudio.google.com/app/apikey" id="altgenix_key_help_link" target="_blank" style="text-decoration: none; font-weight: 500;">Get your API key here</a>.
                            </p>
                            <p class="description">A blank field keeps the saved key. Enter a new key to replace it.</p>
                        </div>

                        <div class="altgenix-form-row" id="altgenix_model_row" style="<?php echo $mode === 'fallback' ? 'display:none;' : ''; ?>">
                            <label>AI Model
                                <span class="altgenix-tip">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                    <div class="altgenix-tip-content">Models are ordered by a preferred model family. The first available model is the default; check provider pricing before a large run &mdash; only move up if you are not happy with the descriptions you are getting.</div>
                                </span>
                            </label>
                            <div class="altgenix-model-control">
                                <select name="altgenix_settings[model]" id="altgenix_model" class="altgenix-select" style="width: 100%; max-width: 400px;" <?php disabled( empty( $valid_models ) ); ?>>
                                    <option value="" <?php selected( $chosen_model, '' ); ?>><?php echo esc_html( empty( $valid_models ) ? __( 'Verify API key to load models', 'altgenix-ai-image-seo' ) : __( 'Recommended available model', 'altgenix-ai-image-seo' ) ); ?></option>
                                    <?php foreach ( $valid_models as $index => $model_id ) {
                                        $suffix = ( $index === 0 ) ? ' — recommended' : '';
                                        echo '<option value="' . esc_attr( $model_id ) . '" ' . selected( $chosen_model, $model_id, false ) . '>' . esc_html( $model_id . $suffix ) . '</option>';
                                    } ?>
                                </select>
                                <button type="button" id="altgenix-verify-models" class="altgenix-btn-secondary altgenix-verify-models-btn"><span class="dashicons dashicons-update" aria-hidden="true"></span><span>Verify &amp; Refresh Models</span></button>
                            </div>
                            <p id="altgenix_verified_note" class="altgenix-model-status <?php echo empty( $valid_models ) ? 'is-neutral' : 'is-success'; ?>" role="status" aria-live="polite"><?php echo empty( $valid_models ) ? esc_html__( 'Verify the API key to load available models.', 'altgenix-ai-image-seo' ) : esc_html( sprintf( _n( 'Key verified · %d model available.', 'Key verified · %d models available.', count( $valid_models ), 'altgenix-ai-image-seo' ), count( $valid_models ) ) ); ?></p>
                        </div>

                        <div class="altgenix-form-row" id="altgenix_escalation_row" style="<?php echo empty( $valid_models ) || $mode === 'fallback' ? 'display:none;' : ''; ?>">
                            <label>If the model is busy
                                <span class="altgenix-tip">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                    <div class="altgenix-tip-content">
                                        <strong>Off (recommended):</strong> briefly retries a busy model. If it still fails, existing metadata is preserved and the image stays retryable.<br>
                                        <strong>On:</strong> permits up to two alternative available models after a busy or unavailable model. Alternatives may cost more; review provider pricing first.
                                    </div>
                                </span>
                            </label>
                            <label class="altgenix-switch" style="margin-top: 4px;"><input type="checkbox" name="altgenix_settings[allow_escalation]" value="1" <?php checked(1, $allow_escalation); ?>><span class="altgenix-slider"></span></label>
                        </div>

                        <div class="altgenix-form-row altgenix-form-row-wide" id="altgenix_lang_row" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                            <label>Output Language
                                <span class="altgenix-tip">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                    <div class="altgenix-tip-content">Language used for the generated Alt Text, Title, Caption &amp; Description. <strong>Auto-detect</strong> follows your WordPress site language (e.g. a Portuguese site gets Portuguese text).</div>
                                </span>
                            </label>
                            <select name="altgenix_settings[language]" id="altgenix_language" class="altgenix-select" style="width: 100%; max-width: 400px;">
                                <option value="auto" <?php selected($language, 'auto'); ?>>Auto-detect (Site Language)</option>
                                <?php foreach ( ALTGENIX_API::supported_languages() as $lang_code => $lang_name ) {
                                    echo '<option value="' . esc_attr( $lang_code ) . '" ' . selected( $language, $lang_code, false ) . '>' . esc_html( $lang_name ) . '</option>';
                                } ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="altgenix-tab-content" id="tab-controls">
                    <div class="altgenix-card">
                        <h3>Generation Control</h3>
                        <label><input type="checkbox" name="altgenix_settings[auto_upload]" value="1" <?php checked( 1, $options['auto_upload'] ); ?>> Automatically process new uploads</label>
                        <p class="description">Turn ON the fields you want to automatically generate when an image is uploaded.</p>
                        <table class="form-table altgenix-control-table">
                            <tbody>
                                <tr id="altgenix_rename_file_row" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                                    <th scope="row">Rename Physical File</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[rename_file]" value="1" <?php checked(1, $rename_file); ?>><span class="altgenix-slider"></span></label></td>
                                    <td><em style="color:#6c757d;">Uses a new filename for new AI uploads. Old files are retained for existing links, using additional disk space.</em></td>
                                </tr>
                                <tr>
                                    <th scope="row">Generate Alt Text</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[gen_alt]" value="1" <?php checked(1, $gen_alt); ?>><span class="altgenix-slider"></span></label></td>
                                    <td class="altgenix-length-col" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                                        <select name="altgenix_settings[alt_length]" class="altgenix-select">
                                            <?php foreach($lengths as $val => $label) { echo '<option value="'.esc_attr($val).'" '.selected(isset($options['alt_length'])?$options['alt_length']:'medium', $val, false).'>'.esc_html($label).'</option>'; } ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Generate Title</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[gen_title]" value="1" <?php checked(1, $gen_title); ?>><span class="altgenix-slider"></span></label></td>
                                    <td class="altgenix-length-col" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                                        <select name="altgenix_settings[title_length]" class="altgenix-select">
                                            <?php foreach($lengths as $val => $label) { echo '<option value="'.esc_attr($val).'" '.selected(isset($options['title_length'])?$options['title_length']:'short', $val, false).'>'.esc_html($label).'</option>'; } ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Generate Caption</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[gen_caption]" value="1" <?php checked(1, $gen_caption); ?>><span class="altgenix-slider"></span></label></td>
                                    <td class="altgenix-length-col" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                                        <select name="altgenix_settings[caption_length]" class="altgenix-select">
                                            <?php foreach($lengths as $val => $label) { echo '<option value="'.esc_attr($val).'" '.selected(isset($options['caption_length'])?$options['caption_length']:'short', $val, false).'>'.esc_html($label).'</option>'; } ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Generate Description</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[gen_desc]" value="1" <?php checked(1, $gen_desc); ?>><span class="altgenix-slider"></span></label></td>
                                    <td class="altgenix-length-col" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                                        <select name="altgenix_settings[desc_length]" class="altgenix-select">
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
                            <textarea id="altgenix_custom_prompt" name="altgenix_settings[custom_prompt]" class="regular-text" rows="4" style="width: 100%; max-width: 600px;" placeholder="E.g., Keep it professional. Use brand name 'Acme Corp'. Focus on e-commerce aspects." <?php echo $mode === 'fallback' ? 'readonly' : ''; ?>><?php echo esc_textarea( $custom_prompt ); ?></textarea>
                            <p class="description">Add extra instructions for the AI to follow when generating text. (AI Mode only)</p>
                            <div id="altgenix_prompt_fallback_warning" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 10px 14px; margin-top: 10px; border-radius: 4px; <?php if ( $mode !== 'fallback' ) echo 'display:none;'; ?>">
                                <p style="margin: 0; color: #856404; font-size: 13px;"><strong>⚠️ Custom Prompt is inactive.</strong> You are currently in <strong>Original Filename</strong> mode — the AI is not being called, so this prompt has no effect. Switch to <strong>AI Smart Generator</strong> mode in the General tab for this to work.</p>
                            </div>
                            <div style="background: #e8f4fd; border-left: 4px solid #0288d1; padding: 10px 14px; margin-top: 10px; border-radius: 4px; <?php if ( $mode === 'fallback' ) echo 'display:none;'; ?>" class="altgenix-ai-only-row">
                                <p style="margin: 0; color: #01579b; font-size: 13px;">💡 <strong>Tip:</strong> If your custom prompt asks for specific details (brand names, product categories, etc.), set the field lengths to <strong>Medium</strong> or <strong>Long</strong> in the Generation Control tab — <em>Short (1-5 words)</em> may be too restrictive for the AI to follow your instructions.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="altgenix-form-actions" id="altgenix-save-bar">
                    <span class="altgenix-save-state" id="altgenix-save-state" hidden>
                        <span class="dashicons dashicons-info-outline"></span> You have unsaved changes
                    </span>
                    <button type="submit" class="altgenix-btn-primary">Save Settings</button>
                </div>
            </form>
        </div>
        <?php
    }

    private function generation_button_data_attributes( $options ) {
        $defaults = array(
            'alt'         => ! empty( $options['gen_alt'] ),
            'title'       => ! empty( $options['gen_title'] ),
            'caption'     => ! empty( $options['gen_caption'] ),
            'description' => ! empty( $options['gen_desc'] ),
            'filename'    => ! empty( $options['rename_file'] ),
        );
        $attrs = array();
        foreach ( $defaults as $field => $enabled ) {
            $attrs[] = sprintf( 'data-field-%1$s-default="%2$s"', esc_attr( $field ), $enabled ? '1' : '0' );
        }
        return implode( ' ', $attrs );
    }

    public function create_bulk_optimizer_page() {
        $options = get_option( 'altgenix_settings', array() );
        $mode = isset( $options['mode'] ) ? $options['mode'] : 'fallback';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status_filter = isset( $_GET['altgenix_status'] ) && is_string( $_GET['altgenix_status'] ) ? sanitize_text_field( wp_unslash( $_GET['altgenix_status'] ) ) : 'all';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $paged = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? max( 1, intval( wp_unslash( $_GET['paged'] ) ) ) : 1;
        
        ?>
        <div class="altgenix-saas-wrap">
            <div class="altgenix-header">
                <img class="altgenix-logo" src="<?php echo esc_url( ALTGENIX_PLUGIN_URL . 'assets/images/altgenix-logo.png' ); ?>" alt="AltGenix Logo"><h2>Bulk Optimizer</h2>
            </div>

            <?php $this->render_services_banner(); ?>
            
            <div class="altgenix-card altgenix-table-card">
                <div class="altgenix-table-toolbar">
                    <div class="altgenix-table-filters">
                        <select id="altgenix-status-filter" class="altgenix-select">
                            <option value="all" <?php selected($status_filter, 'all'); ?>>All Status</option>
                            <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending</option>
                            <option value="processed" <?php selected($status_filter, 'processed'); ?>>Processed</option>
                            <option value="failed" <?php selected($status_filter, 'failed'); ?>>Failed</option>
                        </select>
                        <button id="altgenix-apply-filter" class="altgenix-btn-outline" style="margin-left: 10px;">Filter</button>
                    </div>
                    
                    <?php 
                    $supported_mimes = ALTGENIX_Core::queue_mime_types();
                    $total_images_query = new WP_Query( array( 'post_type' => 'attachment', 'post_mime_type' => $supported_mimes, 'post_status' => 'inherit', 'posts_per_page' => 1 ) );
                    
                    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    // Matches what the bulk run will actually work through, failed
                    // images included — otherwise the button switches itself off
                    // while there is still work it would happily retry.
                    $pending_check_query = new WP_Query( array(
                        'post_type'      => 'attachment',
                        'post_mime_type' => $supported_mimes,
                        'post_status'    => 'inherit',
                        'posts_per_page' => 1,
                        'meta_query'     => array(
                            array( 'key' => '_altgenix_processed', 'compare' => 'NOT EXISTS' ),
                        ),
                    ) );

                    $has_images = $total_images_query->have_posts();
                    $has_pending = $pending_check_query->have_posts();
                    wp_reset_postdata();
                    // Reset both queries — though only wp_reset_postdata() for the global $post
                    // is needed, explicitly unsetting avoids keeping stale query objects in scope.
                    unset( $total_images_query, $pending_check_query );

                    if ( $has_images ) :
                        $button_data = $this->generation_button_data_attributes( $options );
                    ?>
                        <div class="altgenix-bulk-actions">
                            <div class="altgenix-bulk-actions-buttons">
                                <button id="altgenix-bulk-regenerate-btn" class="altgenix-btn-primary" disabled title="Generate selected fields for the checked images." data-ai-mode="<?php echo $mode === 'ai' ? '1' : '0'; ?>" data-can-rename="<?php echo current_user_can( 'manage_options' ) ? '1' : '0'; ?>" data-rename-default="<?php echo ! empty( $options['rename_file'] ) ? '1' : '0'; ?>" <?php echo $button_data; ?>>
                                    <span class="dashicons dashicons-update"></span> <span class="altgenix-bulk-btn-label">Regenerate Selected (0)</span>
                                </button>
                                <button id="altgenix-auto-tag-btn" class="altgenix-btn-secondary" <?php disabled( ! $has_pending ); ?> title="Generate tags for all pending and failed images using your saved settings."><span class="dashicons dashicons-update"></span> Auto-Tag Pending</button>
                                <button id="altgenix-mark-processed-btn" class="altgenix-btn-outline" disabled title="Mark the checked images as processed without generating tags.">
                                    <span class="dashicons dashicons-yes"></span> <span class="altgenix-bulk-btn-label">Mark Selected as Processed (0)</span>
                                </button>
                            </div>
                            <div class="altgenix-bulk-actions-help">Select one or more images to regenerate chosen fields or mark them as processed.</div>
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
                    <thead><tr><th class="altgenix-col-select"><input type="checkbox" id="altgenix-select-all" aria-label="Select all visible images"></th><th>Image</th><th>File Name</th><th>Title / Error</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php
                        $args = array( 'post_type' => 'attachment', 'post_mime_type' => $supported_mimes, 'post_status' => 'inherit', 'posts_per_page' => 10, 'paged' => $paged );
                        
                        if ( $status_filter === 'processed' ) { $args['meta_query'] = array( array( 'key' => '_altgenix_processed', 'value' => '1', 'compare' => '=' ), array( 'key' => '_altgenix_error', 'compare' => 'NOT EXISTS' ) ); } 
                        elseif ( $status_filter === 'pending' ) { 
                            $args['meta_query'] = array(
                                'relation' => 'AND',
                                array( 'key' => '_altgenix_processed', 'compare' => 'NOT EXISTS' ),
                                array( 'key' => '_altgenix_error', 'compare' => 'NOT EXISTS' ),
                            );

                        } 
                        elseif ( $status_filter === 'failed' ) { $args['meta_query'] = array( array( 'key' => '_altgenix_error', 'compare' => 'EXISTS' ) ); }

                        $query = new WP_Query( $args );


                        if ( $query->have_posts() ) :
                            while ( $query->have_posts() ) : $query->the_post();
                                $id = get_the_ID(); 
                                $is_processed = get_post_meta( $id, '_altgenix_processed', true ); 
                                $desc = get_the_content();
                                $error = get_post_meta( $id, '_altgenix_error', true );
                                $pending_filename = get_post_meta( $id, '_altgenix_pending_filename', true );
                                // Backward compatibility before the one-time migration has run.
                                if ( $error === '' && $this->looks_like_legacy_error( $desc ) && ! get_post_meta( $id, '_altgenix_legacy_skipped', true ) ) {
                                    $error = preg_replace( '/^AI Error:\s*/i', '', ltrim( $desc ), 1 );
                                }
                                $thumb = wp_get_attachment_image( $id, array(40, 40) );
                                $title = get_the_title();
                                
                                $title_attr = '';
                                if ( $error !== '' ) { $stat = 'Failed'; $bg = 'altgenix-badge-danger'; $text = wp_trim_words( $error, 8 ); $title_attr = ' title="' . esc_attr( wp_strip_all_tags( $error ) ) . '" style="cursor: help;"'; }
                                elseif ( $is_processed ) { $stat = 'Processed'; $bg = 'altgenix-badge-success'; $text = wp_trim_words($title, 10); $title_attr = ' title="' . esc_attr( wp_strip_all_tags( $title ) ) . '"'; }
                                else { $stat = 'Pending'; $bg = 'altgenix-badge-warning'; $text = 'Awaiting Action...'; }
                                ?>
                                <tr data-image-id="<?php echo esc_attr( $id ); ?>">
                                    <td class="altgenix-col-select"><?php if ( current_user_can( 'edit_post', $id ) ) : ?><input type="checkbox" class="altgenix-row-select" value="<?php echo esc_attr( $id ); ?>" aria-label="Select image <?php echo esc_attr( wp_basename( get_attached_file( $id ) ) ); ?>"><?php endif; ?></td>
                                    <td><div class="altgenix-img-thumb"><?php echo $thumb ? wp_kses_post( $thumb ) : '<span class="dashicons dashicons-format-image"></span>'; ?></div></td>
                                    <td class="altgenix-filename-cell"><strong><?php echo esc_html( wp_basename( get_attached_file( $id ) ) ); ?></strong></td>
                                    <td class="altgenix-text-muted" title="<?php echo esc_attr( wp_strip_all_tags( $error !== '' ? $error : $title ) ); ?>"><?php echo esc_html( $text ); ?></td>
                                    <td><span class="altgenix-badge altgenix-status-badge <?php echo esc_attr( $bg ); ?>"><?php echo esc_html( $stat ); ?></span></td>
                                    <td><?php echo esc_html( get_the_date( 'M j' ) ); ?></td>
                                    <td>
                                        <?php $show_regenerate = current_user_can( 'edit_post', $id ) && ! ( $mode === 'fallback' && $is_processed ); ?>
                                        <?php if ( $show_regenerate ) : ?>
                                            <button class="altgenix-action-icon altgenix-regenerate-btn" data-id="<?php echo esc_attr($id); ?>" data-ai-mode="<?php echo $mode === 'ai' ? '1' : '0'; ?>" data-can-rename="<?php echo current_user_can( 'manage_options' ) ? '1' : '0'; ?>" data-rename-default="<?php echo ! empty( $options['rename_file'] ) ? '1' : '0'; ?>" <?php echo $this->generation_button_data_attributes( $options ); ?> title="<?php echo ( $mode === 'fallback' ) ? 'Generate Tags' : 'Regenerate AI Tags'; ?>"><span class="dashicons dashicons-image-rotate"></span></button>
                                        <?php endif; ?>
                                        <?php if ( $pending_filename && $mode === 'ai' && current_user_can( 'manage_options' ) && current_user_can( 'edit_post', $id ) ) : ?>
                                            <button class="altgenix-action-icon altgenix-rename-btn" data-id="<?php echo esc_attr($id); ?>" title="Retry saved filename (no AI request)"><span class="dashicons dashicons-update"></span></button>
                                        <?php endif; ?>
                                        <a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>" target="_blank" class="altgenix-action-icon" title="Edit Image"><span class="dashicons dashicons-edit"></span></a>
                                    </td>
                                </tr>
                                <?php
                            endwhile;
                        else : echo '<tr class="altgenix-empty-row"><td colspan="7" style="text-align:center; padding: 30px; color: #6c757d;">No images found.</td></tr>'; endif;
                        ?>
                    </tbody>
                </table>
                <?php if ( $query->max_num_pages > 1 ) { echo '<div class="altgenix-pagination">'; echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $query->max_num_pages, 'prev_text' => '&laquo; Prev', 'next_text' => 'Next &raquo;' ) ) ); echo '</div>'; } wp_reset_postdata(); ?>
            </div>

            <div id="altgenix-modal" class="altgenix-modal-overlay">
                <div class="altgenix-modal-box"><h3 id="altgenix-modal-title">Confirm</h3><p id="altgenix-modal-text">Proceed?</p>
                    <div id="altgenix-modal-extra" class="altgenix-modal-extra" style="display:none;">
                        <div id="altgenix-field-options" class="altgenix-field-options" style="display:none;">
                            <div class="altgenix-field-options-title">Choose what to regenerate</div>
                            <div class="altgenix-field-options-grid">
                                <label class="altgenix-field-option" data-field="alt"><input type="checkbox" id="altgenix-field-alt" value="alt"><span>Alt Text</span></label>
                                <label class="altgenix-field-option" data-field="title"><input type="checkbox" id="altgenix-field-title" value="title"><span>Title</span></label>
                                <label class="altgenix-field-option" data-field="caption"><input type="checkbox" id="altgenix-field-caption" value="caption"><span>Caption</span></label>
                                <label class="altgenix-field-option" data-field="description"><input type="checkbox" id="altgenix-field-description" value="description"><span>Description</span></label>
                                <label class="altgenix-field-option" data-field="filename" style="display:none;"><input type="checkbox" id="altgenix-field-filename" value="filename"><span>File Name</span></label>
                            </div>
                        </div>
                        <label id="altgenix-delete-old-option" class="altgenix-delete-old-option" for="altgenix-delete-old-files" style="display:none;">
                            <input type="checkbox" id="altgenix-delete-old-files" value="1">
                            <span><strong>Delete old files after successful rename</strong><small>Old image URLs may stop working if they are used in existing posts, builders, caches, or external links.</small></span>
                        </label>
                    </div>
                    <div class="altgenix-modal-actions"><button id="altgenix-modal-cancel" class="altgenix-btn-outline">Cancel</button><button id="altgenix-modal-confirm" class="altgenix-btn-primary">Yes</button></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Where service enquiries go. Kept apart from the plugin feedback address on
     * purpose: feedback about the plugin is support, this is work.
     */
    private const SERVICE_EMAIL    = 'kabeer.cmsdeveloper@gmail.com';
    private const SERVICE_PHONE    = '+92 310 3721560';
    private const SERVICE_WHATSAPP = '923103721560';
    private const SERVICE_LINKEDIN = 'https://www.linkedin.com/in/abdulkabeerdeveloper';

    /**
     * Contact links with the enquiry already written for the sender.
     *
     * A message that arrives saying which site it came from is worth far more than
     * one that says "hi" — and the sender reads and sends it themselves, so nothing
     * is transmitted anywhere by the plugin.
     *
     * @return array<string,string>
     */
    private function service_links() {
        $site    = wp_parse_url( home_url(), PHP_URL_HOST );
        $site    = $site ? $site : home_url();
        $message = sprintf(
            'Hi Abdul — I use your AltGenix plugin on %s and I would like to talk about some work on my site.',
            $site
        );

        return array(
            'whatsapp' => 'https://wa.me/' . self::SERVICE_WHATSAPP . '?text=' . rawurlencode( $message ),
            'email'    => 'mailto:' . self::SERVICE_EMAIL
                . '?subject=' . rawurlencode( 'Website enquiry from ' . $site )
                . '&body=' . rawurlencode( $message ),
            'phone'    => 'tel:+' . self::SERVICE_WHATSAPP,
            'linkedin' => self::SERVICE_LINKEDIN,
        );
    }

    /**
     * The "work with the developer" block.
     *
     * Deliberately confined to this plugin's own admin screens — never a dashboard
     * notice, never anything on the front of the site, and nothing is sent anywhere
     * unless the reader clicks a link themselves. That is the line WordPress.org
     * draws around promotion, and staying well inside it is worth more than the
     * extra impressions a nag would buy.
     *
     * @param string $variant 'full' on the Help screen, 'compact' elsewhere.
     */
    /**
     * A banner across the top of this plugin's own screens.
     *
     * Unlike the Media Library notice, this one carries no conditions at all — no
     * usage threshold, no dismissal, no screen test beyond the fact that it is only
     * ever printed from these three pages. It does not need them: a plugin's own
     * settings screens are the one place WordPress.org places no restriction on
     * what it says about itself, because nobody arrives here by accident.
     */
    private function render_services_banner() {
        // If user dismissed within the last 24 hours, don't show
        $user_id = get_current_user_id();
        if ( get_transient( 'altgenix_banner_dismissed_' . $user_id ) ) {
            return;
        }
        $links = $this->service_links();
        ?>
        <div class="altgenix-service-banner" id="altgenix-service-banner">
            <button type="button" class="altgenix-banner-dismiss" id="altgenix-dismiss-banner" aria-label="Dismiss">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
            <span class="altgenix-service-banner-icon dashicons dashicons-superhero-alt"></span>
            <div class="altgenix-service-banner-body">
                <p class="altgenix-service-banner-title">Your alt text is handled. What else is costing you traffic?</p>
                <p class="altgenix-service-banner-text">
                    Slow pages, broken links, thin technical SEO &mdash; the quiet things that cap a site's rankings.
                    I am <strong>Abdul Kabeer</strong>, the developer of this plugin. Send me your site address and I
                    will send back a specific list of what I would fix first. Free, 15 minutes, no sales pitch.
                </p>
            </div>
            <div class="altgenix-service-banner-actions">
                <a class="altgenix-btn-primary altgenix-services-wa altgenix-btn-lg" href="<?php echo esc_url( $links['whatsapp'] ); ?>" target="_blank" rel="noopener">
                    <span class="dashicons dashicons-whatsapp"></span> Get my free site review
                </a>
                <a class="altgenix-service-banner-alt" href="<?php echo esc_url( $links['email'] ); ?>">or email instead</a>
            </div>
        </div>
        <?php
    }

    private function render_services_card() {
        $links = $this->service_links();
        ?>
        <div class="altgenix-card altgenix-services" style="max-width: 600px; margin: 40px auto;">
            <h3 style="margin-top: 0;">Work with the developer who built this</h3>
            <p class="description" style="margin-bottom: 18px;">
                I am <strong>Abdul Kabeer</strong>. You already know how I build things &mdash; you have been using it.
                When a site needs more than image metadata, this is the work I take on:
            </p>

            <ul class="altgenix-services-list">
                <li><span class="dashicons dashicons-editor-code"></span> WordPress &amp; CMS development</li>
                <li><span class="dashicons dashicons-cart"></span> WooCommerce builds &amp; fixes</li>
                <li><span class="dashicons dashicons-search"></span> Technical SEO audits</li>
                <li><span class="dashicons dashicons-performance"></span> Site speed &amp; Core Web Vitals</li>
                <li><span class="dashicons dashicons-migrate"></span> Migrations &amp; site recovery</li>
                <li><span class="dashicons dashicons-admin-plugins"></span> Custom plugin development</li>
            </ul>

            <div class="altgenix-services-offer">
                <strong>Start with a free 15-minute review.</strong> Send your site address and you get back a specific
                list of what I would fix first, in what order, and roughly what it takes. Yours to keep &mdash; act on it
                yourself, hand it to your own developer, or bring me in. No sales pitch either way.
            </div>

            <div class="altgenix-services-actions" style="margin-top: 18px;">
                <a class="altgenix-btn-primary altgenix-services-wa altgenix-btn-lg" href="<?php echo esc_url( $links['whatsapp'] ); ?>" target="_blank" rel="noopener">
                    <span class="dashicons dashicons-whatsapp"></span> Get my free site review
                </a>
                <a class="altgenix-btn-outline" href="<?php echo esc_url( $links['email'] ); ?>">Send an email</a>
                <a class="altgenix-btn-outline" href="<?php echo esc_url( $links['linkedin'] ); ?>" target="_blank" rel="noopener">LinkedIn</a>
            </div>

            <p class="description altgenix-services-meta">
                WhatsApp or call <a href="<?php echo esc_url( $links['phone'] ); ?>"><?php echo esc_html( self::SERVICE_PHONE ); ?></a>
                &middot; <a href="<?php echo esc_url( $links['email'] ); ?>"><?php echo esc_html( self::SERVICE_EMAIL ); ?></a>
            </p>
        </div>
        <?php
    }

    /** Images that must already be tagged before the Media Library notice appears. */
    private const SERVICE_NOTICE_THRESHOLD = 25;

    /**
     * Has this site actually got value out of the plugin yet?
     *
     * The notice is held back until it has, for two reasons. Someone who has tagged
     * a few hundred images has seen the work and is worth talking to; someone who
     * installed it ten minutes ago has not, and an offer at that point is just an
     * advert. Cached, because this runs on a screen people open constantly.
     *
     * @return bool
     */
    private function has_meaningful_usage() {
        $count = get_transient( 'altgenix_processed_count' );

        if ( false === $count ) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $count = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_altgenix_processed' )
            );

            // A count that has already passed the mark cannot go backwards in any way
            // that matters, so it is worth caching for a long time. A count below it
            // is a moving number — somebody in the middle of tagging their library
            // crosses it within minutes, and caching that for half a day would hide
            // the notice from exactly the person it is meant for.
            $ttl = ( $count >= self::SERVICE_NOTICE_THRESHOLD ) ? 12 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
            set_transient( 'altgenix_processed_count', $count, $ttl );
        }

        return (int) $count >= self::SERVICE_NOTICE_THRESHOLD;
    }

    /**
     * A one-off notice on the Media Library, offering the work behind the plugin.
     *
     * Every constraint here is deliberate, and worth keeping if this is ever
     * changed. It appears on the two media screens only — never the Dashboard,
     * never someone else's plugin page, never a screen where it has nothing to do
     * with what the reader is looking at. It waits until the plugin has actually
     * tagged a useful number of images. It is shown only to people who could
     * commission work. And dismissing it is permanent: the same flag that hides the
     * Bulk Optimizer card, so one "no" is taken as the answer everywhere.
     *
     * That is the difference between a notice WordPress.org is happy with and the
     * kind that gets a plugin a warning: not whether it advertises, but whether it
     * follows the reader around.
     */
    public function maybe_render_service_notice() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        if ( get_option( 'altgenix_services_dismissed' ) ) { return; }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || ! in_array( $screen->id, array( 'upload', 'media' ), true ) ) { return; }

        if ( ! $this->has_meaningful_usage() ) { return; }

        $links = $this->service_links();
        ?>
        <div class="notice notice-info is-dismissible altgenix-service-notice">
            <div class="altgenix-service-notice-inner">
                <span class="altgenix-service-notice-icon dashicons dashicons-art"></span>
                <div class="altgenix-service-notice-body">
                    <p class="altgenix-service-notice-title">Your images are handled. What else is costing you traffic?</p>
                    <p class="altgenix-service-notice-text">
                        I am <strong>Abdul Kabeer</strong>, the developer of AltGenix. Send me your site address and I will
                        send back a specific list of what I would fix first &mdash; speed, broken links, technical SEO.
                        Free, 15 minutes, no sales pitch.
                    </p>
                    <p class="altgenix-service-notice-actions">
                        <a class="button button-primary" href="<?php echo esc_url( $links['whatsapp'] ); ?>" target="_blank" rel="noopener">Get my free site review</a>
                        <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=altgenix-help' ) ); ?>">See what I do</a>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler: hide the compact services card for good.
     */
    public function ajax_dismiss_services() {
        check_ajax_referer( 'altgenix_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( 'Unauthorized Access', 403 ); }

        update_option( 'altgenix_services_dismissed', '1', false );
        wp_send_json_success();
    }

    public function create_help_page() {
        $recorded  = get_option( 'altgenix_feedback', array() );
        $has_rated = is_array( $recorded ) && ! empty( $recorded['rating'] );
        $past      = $has_rated ? (int) $recorded['rating'] : 0;
        ?>
        <div class="altgenix-saas-wrap">
            <div class="altgenix-header">
                <img class="altgenix-logo" src="<?php echo esc_url( ALTGENIX_PLUGIN_URL . 'assets/images/altgenix-logo.png' ); ?>" alt="AltGenix Logo"><h2>Help & Rate Us</h2>
            </div>

            <div class="altgenix-card" style="max-width: 600px; margin: 40px auto;">
                <h3>Need a hand?</h3>
                <p class="description" style="margin-bottom: 15px;">Most problems come down to the API key or the AI provider. If something is not working, these are the quickest places to look.</p>
                <ul style="margin: 0 0 5px 18px; list-style: disc; line-height: 1.9;">
                    <li>Images not being tagged? Open <strong>Settings</strong> and confirm Processing Mode is set to <em>AI Smart Generator</em> and the key shows as verified.</li>
                    <li>Seeing "AI Provider Error"? That message comes straight from the AI provider — usually an expired key or an account with no remaining credit.</li>
                    <li>Descriptions in the wrong language? Set <strong>Output Language</strong> explicitly instead of leaving it on Auto-detect.</li>
                    <li>Still stuck? <a href="<?php echo esc_url( self::SUPPORT_URL ); ?>" target="_blank" rel="noopener">Ask on the support forum</a> — please include your AI provider and what the error said.</li>
                </ul>
            </div>

            <?php $this->render_services_card(); ?>

            <?php if ( $has_rated ) : ?>
            <div class="altgenix-card" id="altgenix-already-rated" style="max-width: 600px; text-align: center; margin: 40px auto;">
                <h3>Thanks for rating AltGenix <?php echo esc_html( str_repeat( '★', max( 1, min( 5, $past ) ) ) ); ?></h3>
                <p class="description" style="font-size: 15px;">
                    <?php if ( isset( $recorded['delivered'] ) && ! $recorded['delivered'] ) : ?>
                        Your last message could not be emailed from this site. If it still matters, please
                        <a href="<?php echo esc_url( self::SUPPORT_URL ); ?>" target="_blank" rel="noopener">post it on the support forum</a>.
                    <?php else : ?>
                        We appreciate it — that is genuinely what shapes what gets built next.
                    <?php endif; ?>
                </p>
                <button type="button" class="button" id="altgenix-rate-again" style="margin-top: 10px;">Send more feedback</button>
            </div>
            <?php endif; ?>

            <div class="altgenix-card" id="altgenix-rating-card" style="max-width: 600px; text-align: center; margin: 40px auto;<?php echo $has_rated ? ' display:none;' : ''; ?>">
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
                        <h4>Share your feedback</h4>
                        <p class="description">How can we improve? Please let our support team know so we can fix it.</p>
                        <textarea id="altgenix-feedback-text" class="regular-text" rows="4" style="width: 100%; margin-bottom: 10px;" placeholder="Tell us what went wrong..."></textarea>
                        <p class="description">Sending shares your message, rating, site URL, account email, plugin version and provider with the developer by email.</p>
                        <button id="altgenix-submit-feedback" class="altgenix-btn-primary">Submit Feedback</button>
                    </div>
                    
                    <div class="altgenix-rating-high" style="display: none;">
                        <h4>Leave a public review</h4>
                        <p class="description">You can share an honest review of your experience on WordPress.org.</p>
                        <a href="https://wordpress.org/support/plugin/altgenix-ai-image-seo/reviews/#new-post" target="_blank" rel="noopener" id="altgenix-review-link" class="altgenix-btn-primary" style="text-decoration: none; display: inline-block;">Leave a Review on WP.org</a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Dismiss the services banner for 24 hours.
     */
    public function ajax_dismiss_banner() {
        check_ajax_referer( 'altgenix_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'Unauthorized access.' ), 403 ); }
        $user_id = get_current_user_id();
        set_transient( 'altgenix_banner_dismissed_' . $user_id, 1, DAY_IN_SECONDS );
        wp_send_json_success();
    }

}