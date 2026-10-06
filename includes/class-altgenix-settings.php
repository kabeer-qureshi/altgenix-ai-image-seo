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
        // Store keys in the same order get_settings() rebuilds them in. A key added
        // for a provider earlier in the list used to be appended at the end, and the
        // strict comparison in ajax_save_settings() then reported a successful save
        // as "Could not persist settings".
        $ordered_keys = array();
        foreach ( ALTGENIX_API::supported_providers() as $supported ) {
            if ( isset( $clean['provider_keys'][ $supported ] ) ) { $ordered_keys[ $supported ] = $clean['provider_keys'][ $supported ]; }
        }
        $clean['provider_keys'] = $ordered_keys;
        $candidate = $clean;
        $candidate['api_key'] = $key;
        if ( $key !== '' ) { $candidate['provider_keys'][ $provider ] = $key; }
        $models = ALTGENIX_API::verified_models( $candidate );
        $details = $models ? ALTGENIX_API::model_details( $candidate ) : array();
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
                    $details = isset( $preview['details'] ) && is_array( $preview['details'] ) ? $preview['details'] : array();
                }
            }
            if ( ! $models ) {
                $verified = ALTGENIX_API::verify_key( $provider, $key );
                if ( empty( $verified['valid'] ) ) {
                    add_settings_error( 'altgenix_setting_group', 'verification', $verified['message'], 'error' );
                    return $previous;
                }
                $models = $verified['models'];
                $details = isset( $verified['details'] ) && is_array( $verified['details'] ) ? $verified['details'] : array();
            }
        } elseif ( ! $unchanged ) { $models = array(); $details = array(); }
        // A chosen model the key has lost stays chosen until someone picks another.
        // Turning it into Automatic on an unrelated save would be the silent swap that
        // processing refuses to make; Settings shows it as no longer available instead.
        $kept_missing = $clean['model'] !== '' && $clean['model'] === $previous['model'] && $unchanged && $models;
        if ( ! in_array( $clean['model'], $models, true ) && ! $kept_missing ) { $clean['model'] = ''; }
        ALTGENIX_API::save_verified_models( $provider, $key, $models, $details );
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
        // WordPress 7.0 moved the admin accent from #2271b1 to #3858e9, read through
        // --wp-admin-theme-color. The plugin's buttons take whichever this site's own
        // buttons use, so the two never sit side by side in different blues.
        $modern_admin = version_compare( (string) get_bloginfo( 'version' ), '7.0-alpha', '>=' );
        wp_add_inline_style( 'altgenix-admin-style', sprintf( 'body{--agx-accent:var(--wp-admin-theme-color,%1$s);--agx-accent-dark:var(--wp-admin-theme-color-darker-10,%2$s);}', $modern_admin ? '#3858e9' : '#2271b1', $modern_admin ? '#2145e6' : '#135e96' ) );
        wp_enqueue_script( 'altgenix-admin-script', ALTGENIX_PLUGIN_URL . 'assets/js/admin-script.js', array( 'jquery' ), $this->asset_version( 'assets/js/admin-script.js' ), true );
        wp_enqueue_style( 'dashicons' );
        $config = array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'altgenix_ajax_nonce' ), 'auto_queue' => current_user_can( 'upload_files' ) && in_array( $hook, $media, true ) );
        if ( current_user_can( 'manage_options' ) ) {
            $config['bulk_url'] = $hook === 'upload.php' ? admin_url( 'admin.php?page=altgenix-bulk-optimizer' ) : '';
            if ( strpos( $hook, 'altgenix-settings' ) !== false ) {
                $options = ALTGENIX_Core::get_settings();
                $config['saved_keys'] = array_map( function ( $key ) { return $key !== ''; }, $options['provider_keys'] );
                // Not tied to the current mode: a page opened in Filename mode must still
                // know the saved key's models, or switching to AI shows "Verify API key
                // to load models" for a key that is already verified.
                $config['verified'] = $options['api_key'] !== '' && (bool) ALTGENIX_API::verified_models( $options );
            }
        }
        // Secrets never cross into page source or localized JavaScript, even for admins.
        wp_localize_script( 'altgenix-admin-script', 'altgenix_ajax', $config );
    }

    /** How many legacy rows one admin page load is allowed to convert. */
    private const LEGACY_MIGRATION_BATCH = 50;

    /**
     * The data version this release's migration brings a site to. Not the plugin
     * version: it only moves when stored data has to change.
     */
    private const DATA_VERSION = '1.2.2';

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
     * 1.2.0 and 1.2.1 shipped this migration without the emptying step: the error was
     * copied to meta but the text stayed in the Description, where attachment pages
     * and galleries still showed it. Rows those releases examined carry
     * `_altgenix_legacy_skipped` = '1', so this pass reads them once more. Every row
     * this pass has examined is marked '2'.
     *
     * Runs in small batches across page loads: a site with thousands of failed rows
     * must not turn one admin request into a timeout, and the version marker is only
     * written once there is genuinely nothing left, so an interrupted run resumes
     * instead of being recorded as complete.
     */
    public function maybe_migrate_legacy_errors() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $data_version = (string) get_option( 'altgenix_data_version', '0' );
        if ( version_compare( $data_version, self::DATA_VERSION, '>=' ) ) { return; }

        global $wpdb;
        $like = $wpdb->esc_like( 'AI Error: ' ) . '%';

        // Rows this pass has already examined are excluded, otherwise a batch full
        // of them would be re-read on every page load and the migration would never
        // reach the end.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_content, pm.meta_value AS examined
                   FROM {$wpdb->posts} p
                   LEFT JOIN {$wpdb->postmeta} pm
                     ON pm.post_id = p.ID AND pm.meta_key = %s
                  WHERE p.post_type = 'attachment'
                    AND p.post_content LIKE %s
                    AND ( pm.post_id IS NULL OR pm.meta_value <> %s )
                  LIMIT %d",
                '_altgenix_legacy_skipped',
                $like,
                '2',
                self::LEGACY_MIGRATION_BATCH
            )
        );

        if ( ! is_array( $rows ) ) { return; }
        if ( empty( $rows ) ) {
            update_option( 'altgenix_data_version', self::DATA_VERSION, false );
            return;
        }

        foreach ( $rows as $row ) {
            $attachment_id = isset( $row->ID ) ? (int) $row->ID : 0;
            if ( ! $attachment_id ) { continue; }

            $content = (string) $row->post_content;

            if ( ! $this->looks_like_legacy_error( $content ) ) {
                // Somebody's own words. Leave them alone — but mark the row so the
                // next batch moves past it instead of reading it forever.
                update_post_meta( $attachment_id, '_altgenix_legacy_skipped', '2' );
                continue;
            }

            // A row an earlier release already migrated has its error in meta, and may
            // have been regenerated successfully since. Only its Description is left.
            if ( empty( $row->examined ) ) {
                if ( ! metadata_exists( 'post', $attachment_id, '_altgenix_error' ) ) {
                    $message = substr( $content, strlen( 'AI Error: ' ) );
                    update_post_meta( $attachment_id, '_altgenix_error', sanitize_text_field( $message ) );
                }
                delete_post_meta( $attachment_id, '_altgenix_processed' );
            }

            wp_update_post( array( 'ID' => $attachment_id, 'post_content' => '' ) );
            update_post_meta( $attachment_id, '_altgenix_legacy_skipped', '2' );
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
        if ( get_transient( $rate_key ) ) { wp_send_json_error( array( 'message' => 'Please wait a moment before sending again.' ), 429 ); }
        $delivered = (bool) wp_mail( $to, $subject, $message, $headers );
        // The limit still applies to a failed send — the attempt was made — but a
        // full minute's wait for a message that never left the site punishes the
        // user for the host's mail setup rather than for sending too often.
        set_transient( $rate_key, 1, $delivered ? MINUTE_IN_SECONDS : 15 );

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
        $options = ALTGENIX_Core::get_settings();
        $mode = $options['mode'];
        $btn_text = $mode === 'ai' ? 'Generate with AI' : 'Generate from filename';
        // Name the fields that will actually be written, not all four regardless.
        $fields = $this->enabled_fields_sentence( $options );
        if ( $fields === '' ) {
            $desc_text = 'Every field is switched off in AltGenix AI > Settings > Generation Control.';
        } else {
            $desc_text = $mode === 'ai' ? 'Writes the ' . $fields . ' using AI. You choose the fields before it runs.' : 'Writes the ' . $fields . ' from the filename. You choose the fields before it runs.';
        }
        ?>
        <div class="misc-pub-section misc-pub-altgenix" style="padding-top: 15px; border-top: 1px solid #dcdcde; margin-top: 10px;">
            <button type="button" class="button button-primary button-large altgenix-regenerate-btn altgenix-media-btn altgenix-media-btn-block" data-id="<?php echo esc_attr( $post->ID ); ?>" data-ai-mode="<?php echo $mode === 'ai' ? '1' : '0'; ?>" data-can-rename="<?php echo current_user_can( 'manage_options' ) ? '1' : '0'; ?>" data-rename-default="<?php echo ! empty( $options['rename_file'] ) ? '1' : '0'; ?>" <?php echo $this->generation_button_data_attributes( $options ); ?>>
                <span class="dashicons dashicons-art"></span> <span class="altgenix-btn-text"><?php echo esc_html($btn_text); ?></span>
            </button>
            <p class="description"><?php echo esc_html($desc_text); ?></p>
        </div>
        <?php
    }

    public function add_media_modal_button( $form_fields, $post ) {
        if ( ! $post || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post->ID ) || ! wp_attachment_is_image( $post->ID ) ) return $form_fields;
        // The attachment edit screen renders these fields too, below the content, and
        // already has the button in its Save box. The media modal loads them over AJAX.
        global $pagenow;
        if ( $pagenow === 'post.php' ) return $form_fields;
        $options = ALTGENIX_Core::get_settings();
        $mode = $options['mode'];
        $label_text = 'AltGenix';
        $btn_text = $mode === 'ai' ? 'Generate with AI' : 'Generate from filename';
        
        $form_fields['altgenix_generate'] = array(
            'label' => $label_text,
            'input' => 'html',
            'html'  => '<button type="button" class="button button-primary altgenix-regenerate-btn altgenix-media-btn" data-id="' . esc_attr( $post->ID ) . '" data-ai-mode="' . ( $mode === 'ai' ? '1' : '0' ) . '" data-can-rename="' . ( current_user_can( 'manage_options' ) ? '1' : '0' ) . '" data-rename-default="' . ( ! empty( $options['rename_file'] ) ? '1' : '0' ) . '" ' . $this->generation_button_data_attributes( $options ) . '><span class="dashicons dashicons-art"></span> <span>' . esc_html($btn_text) . '</span></button>',
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
        $details = isset( $verified['details'] ) && is_array( $verified['details'] ) ? $verified['details'] : array();
        $context = ALTGENIX_API::context( $provider, $key );
        set_transient( $this->verification_preview_key(), array( 'context' => $context, 'models' => $models, 'details' => $details ), 10 * MINUTE_IN_SECONDS );

        // Refresh the live model cache only when verifying the already-saved active key.
        // Previewing a new/unsaved key must never disturb the currently active configuration.
        $uses_saved_key = $typed_key === '' && $saved_key !== '';
        $is_active_saved_key = $uses_saved_key && $options['provider'] === $provider && $options['api_key'] === $saved_key;
        if ( $is_active_saved_key ) {
            ALTGENIX_API::save_verified_models( $provider, $key, $models, $details );
            // A fresh look at what the key can use. Models hidden after failing (no
            // quota on a free key, say) get another chance, which is what someone who
            // has just upgraded their key wants from this button.
            delete_option( 'altgenix_unavailable_models' );
        }

        wp_send_json_success( array(
            'message' => sprintf( '%d model%s available.', count( $models ), count( $models ) === 1 ? '' : 's' ),
            'valid_models' => $models,
            'model_labels' => (object) ALTGENIX_API::model_labels( $models, $details ),
            'note' => isset( $verified['note'] ) && is_string( $verified['note'] ) ? $verified['note'] : '',
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
            ALTGENIX_API::save_verified_models( $provider, '', array() );
        }
        // update_option() runs sanitize(), which rebuilds the keys from the saved
        // settings and reads a blank key as "keep the saved one" — so the removal was
        // silently undone while this handler reported success. $options came from
        // get_settings() and only lost entries, so it is marked as already clean.
        $this->last_sanitized = $options;
        update_option( 'altgenix_settings', $options, false );
        $this->last_sanitized = null;
        $check = ALTGENIX_Core::get_settings();
        if ( isset( $check['provider_keys'][ $provider ] ) ) {
            wp_send_json_error( array( 'message' => 'The saved API key could not be removed. Please reload the page and try again.' ) );
        }
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
        $labels = ALTGENIX_API::model_labels( $models, ALTGENIX_API::model_details( $options ) );
        $present = array_map( function ( $key ) { return $key !== ''; }, $options['provider_keys'] );
        unset( $options['api_key'], $options['provider_keys'] );
        wp_send_json_success( array( 'message' => 'Settings saved.', 'saved' => true, 'settings' => $options, 'valid_models' => $models, 'model_labels' => (object) $labels, 'saved_keys' => $present, 'is_verified' => $options['mode'] === 'ai' && (bool) $models ) );
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
        $model_labels = ALTGENIX_API::model_labels( $valid_models, ALTGENIX_API::model_details( $options ) );

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
            <div class="altgenix-tabs" role="tablist" aria-label="AltGenix settings sections">
                <button type="button" class="altgenix-tab-link active" role="tab" id="tab-general-label" aria-controls="tab-general" aria-selected="true" data-tab="tab-general">General Settings</button>
                <button type="button" class="altgenix-tab-link" role="tab" id="tab-controls-label" aria-controls="tab-controls" aria-selected="false" data-tab="tab-controls">Generation Control</button>
                <button type="button" class="altgenix-tab-link" role="tab" id="tab-advanced-label" aria-controls="tab-advanced" aria-selected="false" data-tab="tab-advanced">Advanced</button>
            </div>

            <form method="post" id="altgenix-settings-form" action="options.php">
                <?php settings_fields( 'altgenix_setting_group' ); ?>
                
                <div class="altgenix-tab-content active" id="tab-general" role="tabpanel" aria-labelledby="tab-general-label">
                    <div class="altgenix-card altgenix-form-grid">
                        <h3>General Settings</h3>
                        <div class="altgenix-form-row altgenix-form-row-wide">
                            <label>Processing Mode
                                <span class="altgenix-tip">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                    <div class="altgenix-tip-content">Where the text comes from. <strong>Filename</strong> turns red-car.jpg into "Red Car" &mdash; free, no account needed. <strong>AI</strong> sends the image to the provider you choose below and describes what is in it.</div>
                                </span>
                            </label>
                            <select name="altgenix_settings[mode]" id="altgenix_mode" class="altgenix-select" style="width: 100%; max-width: 400px;">
                                <option value="fallback" <?php selected($mode, 'fallback'); ?>>Filename (free, no API key)</option>
                                <option value="ai" <?php selected($mode, 'ai'); ?>>AI (uses your API key)</option>
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
                        </div>

                        <div class="altgenix-form-row" id="altgenix_model_row" style="<?php echo $mode === 'fallback' ? 'display:none;' : ''; ?>">
                            <label>AI Model
                                <span class="altgenix-tip">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                    <div class="altgenix-tip-content">Models are listed cheapest first, and Automatic uses the first one. Pick a larger model only if the descriptions are not good enough &mdash; it costs more per image.</div>
                                </span>
                            </label>
                            <div class="altgenix-model-control">
                                <select name="altgenix_settings[model]" id="altgenix_model" class="altgenix-select" style="width: 100%; max-width: 400px;" <?php disabled( empty( $valid_models ) ); ?>>
                                    <option value="" <?php selected( $chosen_model, '' ); ?>><?php echo esc_html( empty( $valid_models ) ? __( 'Verify API key to load models', 'altgenix-ai-image-seo' ) : __( 'Automatic (cheapest available)', 'altgenix-ai-image-seo' ) ); ?></option>
                                    <?php foreach ( $valid_models as $model_id ) {
                                        echo '<option value="' . esc_attr( $model_id ) . '" ' . selected( $chosen_model, $model_id, false ) . '>' . esc_html( isset( $model_labels[ $model_id ] ) ? $model_labels[ $model_id ] : $model_id ) . '</option>';
                                    }
                                    // Show what is really chosen, not "Automatic", while the chosen model is gone.
                                    if ( $chosen_model !== '' && $valid_models && ! in_array( $chosen_model, $valid_models, true ) ) {
                                        echo '<option value="' . esc_attr( $chosen_model ) . '" class="altgenix-missing-model" selected>' . esc_html( sprintf( '%s (no longer available)', $chosen_model ) ) . '</option>';
                                    } ?>
                                </select>
                                <button type="button" id="altgenix-verify-models" class="altgenix-btn-secondary altgenix-verify-models-btn"><span class="dashicons dashicons-update" aria-hidden="true"></span><span>Verify &amp; Refresh Models</span></button>
                            </div>
                            <p id="altgenix_verified_note" class="altgenix-model-status <?php echo empty( $valid_models ) ? 'is-neutral' : 'is-success'; ?>" role="status" aria-live="polite"><?php echo empty( $valid_models ) ? esc_html__( 'Verify the API key to load available models.', 'altgenix-ai-image-seo' ) : esc_html( sprintf( _n( 'Key verified · %d model available.', 'Key verified · %d models available.', count( $valid_models ), 'altgenix-ai-image-seo' ), count( $valid_models ) ) ); ?></p>
                            <?php // The chosen model is gone (retired, or no quota on this key). Processing reports it on every image until another is chosen; say so here, where it is fixed. ?>
                            <?php if ( $chosen_model !== '' && $valid_models && ! in_array( $chosen_model, $valid_models, true ) ) : ?>
                                <p id="altgenix_model_missing_note" class="altgenix-model-status is-error">The model you chose, <?php echo esc_html( $chosen_model ); ?>, is no longer available to this API key, so no images are being processed. Choose another model above and save.</p>
                            <?php endif; ?>
                            <p class="description altgenix-provider-note" data-provider="openrouter" style="<?php echo $provider === 'openrouter' ? '' : 'display:none;'; ?>">
                                OpenRouter lists every model that can read images, cheapest first, with its price per million input / output tokens; one image is roughly 1,300 input tokens. Free models are limited by OpenRouter to 50 requests a day, or 1,000 after a one-time $10 credit purchase, so for a large library choose a low-cost paid model.
                            </p>
                        </div>

                        <div class="altgenix-form-row" id="altgenix_escalation_row" style="<?php echo empty( $valid_models ) || $mode === 'fallback' ? 'display:none;' : ''; ?>">
                            <label>Try another model when busy
                                <span class="altgenix-tip">
                                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                    <div class="altgenix-tip-content">
                                        <strong>Off (recommended):</strong> retries the same model for a few seconds. If it is still busy, the image is marked Failed, nothing is changed, and you can retry it later.<br>
                                        <strong>On:</strong> may move on to up to two other models from the list. Those can cost more per image.
                                    </div>
                                </span>
                            </label>
                            <label class="altgenix-switch" style="margin-top: 4px;"><input type="checkbox" name="altgenix_settings[allow_escalation]" value="1" aria-label="Try another model when busy" <?php checked(1, $allow_escalation); ?>><span class="altgenix-slider"></span></label>
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

                <div class="altgenix-tab-content" id="tab-controls" role="tabpanel" aria-labelledby="tab-controls-label">
                    <div class="altgenix-card">
                        <h3>Generation Control</h3>
                        <p class="description">Choose which fields AltGenix writes. The same choices are used for new uploads and for &ldquo;Process all remaining&rdquo;, and they come pre-ticked when you regenerate a single image.</p>
                        <table class="form-table altgenix-control-table">
                            <tbody>
                                <tr>
                                    <th scope="row">Process New Uploads</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[auto_upload]" value="1" aria-label="Process new uploads automatically" <?php checked( 1, $options['auto_upload'] ); ?>><span class="altgenix-slider"></span></label></td>
                                    <td><em style="color:#646970;">Runs automatically a few seconds after each image finishes uploading.</em></td>
                                </tr>
                                <tr id="altgenix_rename_file_row" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                                    <th scope="row">Rename File</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[rename_file]" value="1" aria-label="Rename new uploads" <?php checked(1, $rename_file); ?>><span class="altgenix-slider"></span></label></td>
                                    <td><em style="color:#646970;">Gives new uploads a descriptive filename written by the AI. The old files are kept so existing links keep working, which uses extra disk space.</em></td>
                                </tr>
                                <tr>
                                    <th scope="row">Generate Alt Text</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[gen_alt]" value="1" aria-label="Generate alt text" <?php checked(1, $gen_alt); ?>><span class="altgenix-slider"></span></label></td>
                                    <td class="altgenix-length-col" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                                        <select name="altgenix_settings[alt_length]" class="altgenix-select">
                                            <?php foreach($lengths as $val => $label) { echo '<option value="'.esc_attr($val).'" '.selected(isset($options['alt_length'])?$options['alt_length']:'medium', $val, false).'>'.esc_html($label).'</option>'; } ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Generate Title</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[gen_title]" value="1" aria-label="Generate title" <?php checked(1, $gen_title); ?>><span class="altgenix-slider"></span></label></td>
                                    <td class="altgenix-length-col" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                                        <select name="altgenix_settings[title_length]" class="altgenix-select">
                                            <?php foreach($lengths as $val => $label) { echo '<option value="'.esc_attr($val).'" '.selected(isset($options['title_length'])?$options['title_length']:'short', $val, false).'>'.esc_html($label).'</option>'; } ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Generate Caption</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[gen_caption]" value="1" aria-label="Generate caption" <?php checked(1, $gen_caption); ?>><span class="altgenix-slider"></span></label></td>
                                    <td class="altgenix-length-col" <?php if($mode === 'fallback') echo 'style="display:none;"'; ?>>
                                        <select name="altgenix_settings[caption_length]" class="altgenix-select">
                                            <?php foreach($lengths as $val => $label) { echo '<option value="'.esc_attr($val).'" '.selected(isset($options['caption_length'])?$options['caption_length']:'short', $val, false).'>'.esc_html($label).'</option>'; } ?>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Generate Description</th>
                                    <td><label class="altgenix-switch"><input type="checkbox" name="altgenix_settings[gen_desc]" value="1" aria-label="Generate description" <?php checked(1, $gen_desc); ?>><span class="altgenix-slider"></span></label></td>
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

                <div class="altgenix-tab-content" id="tab-advanced" role="tabpanel" aria-labelledby="tab-advanced-label">
                    <div class="altgenix-card">
                        <h3>Advanced Options</h3>
                        <div class="altgenix-form-row">
                            <label>Custom Prompt Context</label>
                            <textarea id="altgenix_custom_prompt" name="altgenix_settings[custom_prompt]" class="regular-text" rows="4" style="width: 100%; max-width: 600px;" placeholder="E.g., Keep it professional. Use brand name 'Acme Corp'. Focus on e-commerce aspects." <?php echo $mode === 'fallback' ? 'readonly' : ''; ?>><?php echo esc_textarea( $custom_prompt ); ?></textarea>
                            <p class="description">Add extra instructions for the AI to follow when generating text. (AI Mode only)</p>
                            <div id="altgenix_prompt_fallback_warning" class="altgenix-inline-note is-warning" style="<?php if ( $mode !== 'fallback' ) echo 'display:none;'; ?>">
                                <p><strong>Not used in Filename mode.</strong> This prompt is only sent to the AI. To use it, set Processing Mode to <strong>AI</strong> on the General Settings tab.</p>
                            </div>
                            <div class="altgenix-inline-note altgenix-ai-only-row" style="<?php if ( $mode === 'fallback' ) echo 'display:none;'; ?>">
                                <p><strong>Tip:</strong> If your prompt asks for specific details such as brand names or product categories, set the field lengths to <strong>Medium</strong> or <strong>Long</strong> in Generation Control. <em>Short (1-5 words)</em> leaves the AI too little room to follow it.</p>
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

    /**
     * "alt text and title", "alt text, title and caption", or '' when all are off.
     */
    private function enabled_fields_sentence( $options ) {
        $names = array();
        foreach ( array( 'gen_alt' => 'alt text', 'gen_title' => 'title', 'gen_caption' => 'caption', 'gen_desc' => 'description' ) as $key => $name ) {
            if ( ! empty( $options[ $key ] ) ) { $names[] = $name; }
        }
        if ( count( $names ) < 2 ) { return implode( '', $names ); }
        $last = array_pop( $names );
        return implode( ', ', $names ) . ' and ' . $last;
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

    /**
     * Rows per page on the queue table.
     *
     * Ten was hardcoded, and since a selection only covers the rows on screen that
     * put a hard ceiling of ten images on every "Regenerate Selected". The whole
     * library is still the "Process all remaining" button's job; this is for working through a
     * known set by hand.
     */
    private const PER_PAGE_CHOICES = array( 10, 25, 50, 100 );

    /**
     * Months that have image uploads, newest first, for the date filter.
     *
     * The same question the Media Library's own date filter asks, ordered by the
     * selected columns so it also runs under ONLY_FULL_GROUP_BY.
     *
     * @param string $selected YYYYMM already in the URL; kept as a choice even if no image is left in it.
     * @return array<string,string> YYYYMM => "September 2025".
     */
    private function upload_months( $selected = '' ) {
        global $wpdb, $wp_locale;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT YEAR( post_date ) AS year, MONTH( post_date ) AS month
               FROM {$wpdb->posts}
              WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type LIKE %s
              ORDER BY year DESC, month DESC",
            $wpdb->esc_like( 'image/' ) . '%'
        ) );
        $label = function ( $year, $month ) use ( $wp_locale ) {
            $name = is_object( $wp_locale ) ? $wp_locale->get_month( $month ) : gmdate( 'F', gmmktime( 0, 0, 0, (int) $month, 1, 2000 ) );
            return $name . ' ' . $year;
        };
        $months = array();
        foreach ( is_array( $rows ) ? $rows : array() as $row ) {
            if ( empty( $row->year ) || empty( $row->month ) ) { continue; }
            $months[ sprintf( '%04d%02d', $row->year, $row->month ) ] = $label( (int) $row->year, (int) $row->month );
        }
        if ( $selected !== '' && ! isset( $months[ $selected ] ) ) {
            $months[ $selected ] = $label( (int) substr( $selected, 0, 4 ), (int) substr( $selected, 4, 2 ) );
            krsort( $months, SORT_STRING );
        }
        return $months;
    }

    public function create_bulk_optimizer_page() {
        $options = ALTGENIX_Core::get_settings();
        $mode = $options['mode'];
        // Read-only view filters, every value whitelisted by queue_filters().
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $filters = ALTGENIX_Core::queue_filters( wp_unslash( $_GET ) );
        $status_filter = $filters['status'];
        $filtered = ALTGENIX_Core::queue_filters_active( $filters );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $paged = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? max( 1, intval( wp_unslash( $_GET['paged'] ) ) ) : 1;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $per_page = isset( $_GET['altgenix_per_page'] ) && is_scalar( $_GET['altgenix_per_page'] ) ? intval( wp_unslash( $_GET['altgenix_per_page'] ) ) : 25;
        if ( ! in_array( $per_page, self::PER_PAGE_CHOICES, true ) ) { $per_page = 25; }
        // The table and "Process" both come from queue_query_args(), so a run works
        // through exactly the images the table shows, minus those already processed.
        $query = new WP_Query( array_merge( ALTGENIX_Core::queue_query_args( $filters ), array( 'posts_per_page' => $per_page, 'paged' => $paged ) ) );
        $months = $this->upload_months( $filters['month'] );
        $clear_url = remove_query_arg( array( 'altgenix_status', 'altgenix_month', 'altgenix_alt', 'altgenix_search', 'paged' ) );
        ?>
        <div class="altgenix-saas-wrap">
            <div class="altgenix-header">
                <img class="altgenix-logo" src="<?php echo esc_url( ALTGENIX_PLUGIN_URL . 'assets/images/altgenix-logo.png' ); ?>" alt="AltGenix Logo"><h2>Bulk Optimizer</h2>
            </div>

            <?php $this->render_services_banner(); ?>

            <div class="altgenix-card altgenix-table-card">
                <div class="altgenix-table-toolbar">
                    <div class="altgenix-table-filters">
                        <?php // Each select applies on change, like the per-page control; search applies on Enter or its button. ?>
                        <select id="altgenix-status-filter" class="altgenix-select altgenix-filter-select" data-param="altgenix_status" aria-label="Show images by status">
                            <option value="all" <?php selected($status_filter, 'all'); ?>>All images</option>
                            <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending</option>
                            <option value="processed" <?php selected($status_filter, 'processed'); ?>>Processed</option>
                            <option value="failed" <?php selected($status_filter, 'failed'); ?>>Failed</option>
                        </select>
                        <select id="altgenix-month-filter" class="altgenix-select altgenix-filter-select" data-param="altgenix_month" aria-label="Filter by upload date">
                            <option value="" <?php selected( $filters['month'], '' ); ?>>All dates</option>
                            <?php foreach ( $months as $month_value => $month_label ) {
                                echo '<option value="' . esc_attr( $month_value ) . '" ' . selected( $filters['month'], (string) $month_value, false ) . '>' . esc_html( $month_label ) . '</option>';
                            } ?>
                        </select>
                        <select id="altgenix-alt-filter" class="altgenix-select altgenix-filter-select" data-param="altgenix_alt" aria-label="Filter by alt text">
                            <option value="any" <?php selected( $filters['alt'], 'any' ); ?>>Any alt text</option>
                            <option value="missing" <?php selected( $filters['alt'], 'missing' ); ?>>Missing alt text</option>
                            <option value="present" <?php selected( $filters['alt'], 'present' ); ?>>Has alt text</option>
                        </select>
                        <form id="altgenix-search-form" class="altgenix-search-form" role="search">
                            <input type="search" id="altgenix-search" class="altgenix-search-input" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="File name, title or alt text" aria-label="Search images by file name, title or alt text">
                            <button type="submit" class="altgenix-btn-outline altgenix-search-btn">Search</button>
                        </form>
                        <select id="altgenix-per-page" class="altgenix-select altgenix-per-page" aria-label="Images per page">
                            <?php foreach ( self::PER_PAGE_CHOICES as $choice ) {
                                /* translators: %d: number of rows shown per page. */
                                echo '<option value="' . esc_attr( $choice ) . '" ' . selected( $per_page, $choice, false ) . '>' . esc_html( sprintf( '%d per page', $choice ) ) . '</option>';
                            } ?>
                        </select>
                    </div>

                    <?php
                    $supported_mimes = ALTGENIX_Core::queue_mime_types();
                    $total_images_query = new WP_Query( array( 'post_type' => 'attachment', 'post_mime_type' => $supported_mimes, 'post_status' => 'inherit', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true ) );

                    $has_images = $total_images_query->have_posts();
                    // Shown on the button, so nobody has to guess how big a run is
                    // before starting it. Pending and failed together, as the run is —
                    // otherwise the button switches itself off while there is still
                    // work it would happily retry. With filters, only what they select.
                    $remaining_count = ALTGENIX_Core::remaining_count( $filters );
                    $has_pending = $remaining_count > 0;
                    unset( $total_images_query );
                    if ( $filtered ) {
                        $process_label = $has_pending ? sprintf( 'Process filtered (%d)', $remaining_count ) : 'Nothing to process in this view';
                        $process_title = 'Generate text for every image in this filtered view that is pending or failed, using your saved settings.';
                    } else {
                        $process_label = $has_pending ? sprintf( 'Process all remaining (%d)', $remaining_count ) : 'Nothing left to process';
                        $process_title = 'Generate text for every image that is pending or failed, using your saved settings.';
                    }

                    if ( $has_images ) :
                        $button_data = $this->generation_button_data_attributes( $options );
                    ?>
                        <div class="altgenix-bulk-actions">
                            <div class="altgenix-bulk-actions-buttons">
                                <?php // The one action most visitors came for leads, and says how big it is. The filters travel with it, so the run and the count cover the same images. ?>
                                <button id="altgenix-auto-tag-btn" class="altgenix-btn-primary" <?php disabled( ! $has_pending ); ?> data-count="<?php echo esc_attr( $remaining_count ); ?>" data-filtered="<?php echo $filtered ? '1' : '0'; ?>" data-filters="<?php echo esc_attr( wp_json_encode( $filters ) ); ?>" data-ai-mode="<?php echo $mode === 'ai' ? '1' : '0'; ?>" <?php echo $button_data; ?> title="<?php echo esc_attr( $process_title ); ?>"><span class="dashicons dashicons-update"></span> <span class="altgenix-bulk-btn-label"><?php echo esc_html( $process_label ); ?></span></button>
                                <button id="altgenix-bulk-regenerate-btn" class="altgenix-btn-secondary" disabled title="Choose fields and regenerate them for the checked images." data-ai-mode="<?php echo $mode === 'ai' ? '1' : '0'; ?>" data-can-rename="<?php echo current_user_can( 'manage_options' ) ? '1' : '0'; ?>" data-rename-default="<?php echo ! empty( $options['rename_file'] ) ? '1' : '0'; ?>" <?php echo $button_data; ?>>
                                    <span class="dashicons dashicons-image-rotate"></span> <span class="altgenix-bulk-btn-label">Regenerate selected (0)</span>
                                </button>
                                <button id="altgenix-mark-processed-btn" class="altgenix-btn-outline" disabled title="Keep the checked images as they are and stop listing them as pending.">
                                    <span class="dashicons dashicons-yes"></span> <span class="altgenix-bulk-btn-label">Mark as done (0)</span>
                                </button>
                            </div>
                            <div class="altgenix-bulk-actions-help">Tick images in the table to regenerate them, or to mark ones you have already written as done.</div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ( $filtered ) : ?>
                    <p class="altgenix-filter-summary" role="status">
                        <?php echo esc_html( sprintf( 1 === (int) $query->found_posts ? '%d image matches these filters.' : '%d images match these filters.', (int) $query->found_posts ) ); ?>
                        <a href="<?php echo esc_url( $clear_url ); ?>">Clear filters</a>
                    </p>
                <?php endif; ?>

                <!-- Progress Bar Container -->
                <div id="altgenix-progress-container" class="altgenix-progress-container" style="display: none;">
                    <div class="altgenix-progress-bar">
                        <div id="altgenix-progress-fill" class="altgenix-progress-fill"></div>
                    </div>
                    <div class="altgenix-progress-footer">
                        <div class="altgenix-progress-text">
                            <span id="altgenix-progress-percentage">0%</span> - <span id="altgenix-progress-status">Processing...</span>
                        </div>
                        <?php // A long run is otherwise only escapable by closing the tab. ?>
                        <button type="button" id="altgenix-stop-run" class="altgenix-btn-outline altgenix-stop-run">Stop</button>
                    </div>
                </div>
                
                <?php // Seven columns do not fit a phone. Scrolling the table beats breaking the page. ?>
                <div class="altgenix-table-scroll">
                <table class="altgenix-table">
                    <thead><tr><th class="altgenix-col-select"><input type="checkbox" id="altgenix-select-all" aria-label="Select all visible images"></th><th>Image</th><th>File Name</th><th>Alt Text</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php

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
                                // Alt text is what this plugin is for, and on a pending row it
                                // shows at a glance whether someone already wrote it by hand
                                // before "Process all remaining" replaces it.
                                $alt = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
                                $alt_text = $alt !== '' ? wp_trim_words( $alt, 10 ) : 'No alt text';

                                $title_attr = $alt !== '' ? ' title="' . esc_attr( wp_strip_all_tags( $alt ) ) . '"' : '';
                                if ( $error !== '' ) { $stat = 'Failed'; $bg = 'altgenix-badge-danger'; $text = wp_trim_words( $error, 8 ); $title_attr = ' title="' . esc_attr( wp_strip_all_tags( $error ) ) . '" style="cursor: help;"'; }
                                elseif ( $is_processed ) { $stat = 'Processed'; $bg = 'altgenix-badge-success'; $text = $alt_text; }
                                else { $stat = 'Pending'; $bg = 'altgenix-badge-warning'; $text = $alt_text; }
                                ?>
                                <tr data-image-id="<?php echo esc_attr( $id ); ?>">
                                    <td class="altgenix-col-select"><?php if ( current_user_can( 'edit_post', $id ) ) : ?><input type="checkbox" class="altgenix-row-select" value="<?php echo esc_attr( $id ); ?>" aria-label="Select image <?php echo esc_attr( wp_basename( get_attached_file( $id ) ) ); ?>"><?php endif; ?></td>
                                    <td><div class="altgenix-img-thumb"><?php echo $thumb ? wp_kses_post( $thumb ) : '<span class="dashicons dashicons-format-image"></span>'; ?></div></td>
                                    <td class="altgenix-filename-cell"><strong><?php echo esc_html( wp_basename( get_attached_file( $id ) ) ); ?></strong></td>
                                    <?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $title_attr is assembled from esc_attr() above. ?>
                                    <td class="altgenix-text-muted"<?php echo $title_attr; ?>><?php echo esc_html( $text ); ?></td>
                                    <td><span class="altgenix-badge altgenix-status-badge <?php echo esc_attr( $bg ); ?>"><?php echo esc_html( $stat ); ?></span></td>
                                    <td><?php echo esc_html( get_the_date( 'M j, Y' ) ); ?></td>
                                    <td>
                                        <?php $show_regenerate = current_user_can( 'edit_post', $id ) && ! ( $mode === 'fallback' && $is_processed ); ?>
                                        <?php if ( $show_regenerate ) : ?>
                                            <button class="altgenix-action-icon altgenix-regenerate-btn" data-id="<?php echo esc_attr($id); ?>" data-ai-mode="<?php echo $mode === 'ai' ? '1' : '0'; ?>" data-can-rename="<?php echo current_user_can( 'manage_options' ) ? '1' : '0'; ?>" data-rename-default="<?php echo ! empty( $options['rename_file'] ) ? '1' : '0'; ?>" <?php echo $this->generation_button_data_attributes( $options ); ?> title="<?php echo ( $mode === 'fallback' ) ? 'Generate from filename' : 'Regenerate with AI'; ?>" aria-label="<?php echo ( $mode === 'fallback' ) ? 'Generate from filename' : 'Regenerate with AI'; ?>"><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span></button>
                                        <?php endif; ?>
                                        <?php if ( $pending_filename && $mode === 'ai' && current_user_can( 'manage_options' ) && current_user_can( 'edit_post', $id ) ) : ?>
                                            <button class="altgenix-action-icon altgenix-rename-btn" data-id="<?php echo esc_attr($id); ?>" title="Retry saved filename (no AI request)" aria-label="Retry saved filename (no AI request)"><span class="dashicons dashicons-update" aria-hidden="true"></span></button>
                                        <?php endif; ?>
                                        <a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>" target="_blank" class="altgenix-action-icon" title="Edit image (opens in a new tab)" aria-label="Edit image (opens in a new tab)"><span class="dashicons dashicons-edit" aria-hidden="true"></span></a>
                                    </td>
                                </tr>
                                <?php
                            endwhile;
                        else :
                            // "No images found" on the Pending filter reads like an error;
                            // there it is the good news.
                            $empty_messages = array(
                                'pending'   => 'Nothing pending. Every image has been processed.',
                                'processed' => 'No processed images yet.',
                                'failed'    => 'No failed images.',
                            );
                            $empty_text = isset( $empty_messages[ $status_filter ] ) ? $empty_messages[ $status_filter ] : 'No images in the Media Library yet.';
                            // With a date, alt text or search filter on, "nothing pending" would claim more than is known.
                            if ( $filters['month'] !== '' || $filters['alt'] !== 'any' || $filters['search'] !== '' ) { $empty_text = 'No images match these filters.'; }
                            echo '<tr class="altgenix-empty-row"><td colspan="7" style="text-align:center; padding: 30px; color: #646970;">' . esc_html( $empty_text ) . '</td></tr>';
                        endif;
                        ?>
                    </tbody>
                </table>
                </div>
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
            <button type="button" class="altgenix-banner-dismiss" id="altgenix-dismiss-banner" aria-label="Dismiss this note">
                <span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
            </button>
            <div class="altgenix-service-banner-body">
                <p class="altgenix-service-banner-text">
                    <strong>Hi, I'm Abdul Kabeer, I built AltGenix.</strong>
                    I also fix and build WordPress sites: speed, technical SEO, WooCommerce and custom plugins.
                    Send me your site's address and I'll reply with what I would fix first, free of charge.
                </p>
            </div>
            <div class="altgenix-service-banner-actions">
                <a class="altgenix-btn-secondary" href="<?php echo esc_url( $links['whatsapp'] ); ?>" target="_blank" rel="noopener">
                    <span class="dashicons dashicons-whatsapp" aria-hidden="true"></span> WhatsApp me
                </a>
                <a class="altgenix-service-banner-alt" href="<?php echo esc_url( $links['email'] ); ?>">or send an email</a>
            </div>
        </div>
        <?php
    }

    private function render_services_card() {
        $links = $this->service_links();
        ?>
        <div class="altgenix-card altgenix-services" style="max-width: 600px; margin: 0 auto 20px;">
            <h3 style="margin-top: 0;">WordPress help from the developer of AltGenix</h3>
            <p class="description" style="margin-bottom: 16px;">
                I'm <strong>Abdul Kabeer</strong>. Apart from this plugin, this is the work I take on:
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
                <strong>Not sure where to start?</strong> Send me your site's address and I'll reply with a short list
                of what I would fix first and roughly what it takes. It's free, and you can hand the list to your own
                developer if you prefer.
            </div>

            <div class="altgenix-services-actions" style="margin-top: 16px;">
                <a class="altgenix-btn-primary altgenix-btn-lg" href="<?php echo esc_url( $links['whatsapp'] ); ?>" target="_blank" rel="noopener">
                    <span class="dashicons dashicons-whatsapp" aria-hidden="true"></span> WhatsApp me
                </a>
                <a class="altgenix-btn-outline altgenix-btn-lg" href="<?php echo esc_url( $links['email'] ); ?>">Send an email</a>
                <a class="altgenix-btn-outline altgenix-btn-lg" href="<?php echo esc_url( $links['linkedin'] ); ?>" target="_blank" rel="noopener">LinkedIn</a>
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
            <p class="altgenix-service-notice-title">A note from the developer of AltGenix</p>
            <p class="altgenix-service-notice-text">
                I'm <strong>Abdul Kabeer</strong>. Besides this plugin I fix and build WordPress sites: speed, technical
                SEO, WooCommerce. If the rest of your site needs work, send me its address and I'll reply with what I
                would fix first, free of charge.
            </p>
            <p class="altgenix-service-notice-actions">
                <a class="button button-primary" href="<?php echo esc_url( $links['whatsapp'] ); ?>" target="_blank" rel="noopener">WhatsApp me</a>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=altgenix-help' ) ); ?>">See what I do</a>
            </p>
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

            <div class="altgenix-card" style="max-width: 600px; margin: 0 auto 20px;">
                <h3>Need a hand?</h3>
                <p class="description" style="margin-bottom: 15px;">If something is not working, these are the quickest places to look.</p>
                <ul style="margin: 0 0 5px 18px; list-style: disc; line-height: 1.9;">
                    <li>Nothing happens after an upload? Check that <strong>Process New Uploads</strong> is on in <strong>Settings &gt; Generation Control</strong>. New uploads are handled a few seconds later in the background.</li>
                    <li>Using AI? The <strong>Settings</strong> screen should show <em>Key verified</em> under AI Model, and your provider account needs credit.</li>
                    <li>An image shows <strong>Failed</strong>? Hover over its error in the <strong>Bulk Optimizer</strong>. Words like &ldquo;key&rdquo;, &ldquo;quota&rdquo;, &ldquo;balance&rdquo; or &ldquo;rate limit&rdquo; mean the message came from your AI provider.</li>
                    <li>Text in the wrong language? Set <strong>Output Language</strong> explicitly instead of leaving it on Auto-detect.</li>
                    <li>Still stuck? <a href="<?php echo esc_url( self::SUPPORT_URL ); ?>" target="_blank" rel="noopener">Ask on the support forum</a> and include your AI provider and the exact error.</li>
                </ul>
            </div>

            <?php $this->render_services_card(); ?>

            <?php if ( $has_rated ) : ?>
            <div class="altgenix-card" id="altgenix-already-rated" style="max-width: 600px; text-align: center; margin: 0 auto 20px;">
                <h3>Thanks for rating AltGenix <?php echo esc_html( str_repeat( '★', max( 1, min( 5, $past ) ) ) ); ?></h3>
                <p class="description" style="font-size: 15px;">
                    <?php if ( isset( $recorded['delivered'] ) && ! $recorded['delivered'] ) : ?>
                        Your last message could not be emailed from this site. If it still matters, please
                        <a href="<?php echo esc_url( self::SUPPORT_URL ); ?>" target="_blank" rel="noopener">post it on the support forum</a>.
                    <?php else : ?>
                        We appreciate it — that is genuinely what shapes what gets built next.
                    <?php endif; ?>
                </p>
                <button type="button" class="altgenix-btn-outline" id="altgenix-rate-again" style="margin-top: 10px;">Send more feedback</button>
            </div>
            <?php endif; ?>

            <div class="altgenix-card" id="altgenix-rating-card" style="max-width: 600px; text-align: center; margin: 0 auto 20px;<?php echo $has_rated ? ' display:none;' : ''; ?>">
                <h3>How is AltGenix working for you?</h3>
                <p class="description" style="font-size: 14px; margin-bottom: 16px;">Your rating and feedback decide what gets built next.</p>

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
        // A promotion that reappears every morning on the screen you use to do your
        // work is the thing people actually resent. Thirty days is the shortest
        // interval that still reads as "dismissed" rather than "snoozed".
        set_transient( 'altgenix_banner_dismissed_' . $user_id, 1, 30 * DAY_IN_SECONDS );
        wp_send_json_success();
    }

}