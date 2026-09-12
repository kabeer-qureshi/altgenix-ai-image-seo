<?php
/** Attachment queues and generation workflows. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ALTGENIX_Core {
    private const AI_TARGET_EDGE = 1024;
    private const AI_MIN_EDGE = 512;
    private const AI_RESIZE_ABOVE = 2048;
    private $ready_at_shutdown = array();

    public static function default_settings() {
        return array(
            'mode' => 'fallback', 'provider' => 'gemini', 'api_key' => '',
            'provider_keys' => array(), 'model' => '', 'allow_escalation' => 0,
            'auto_upload' => 1, 'language' => 'auto', 'custom_prompt' => '',
            'gen_alt' => 1, 'gen_title' => 1, 'gen_caption' => 0, 'gen_desc' => 0,
            'rename_file' => 0, 'alt_length' => 'medium', 'title_length' => 'short',
            'caption_length' => 'short', 'desc_length' => 'medium',
        );
    }

    public static function get_settings() {
        $stored = get_option( 'altgenix_settings', array() );
        $defaults = self::default_settings();
        $options = wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
        foreach ( $defaults as $key => $default ) {
            if ( is_int( $default ) ) {
                $options[ $key ] = in_array( $options[ $key ], array( 1, '1', true ), true ) ? 1 : 0;
            } elseif ( is_string( $default ) && ! is_string( $options[ $key ] ) ) {
                $options[ $key ] = $default;
            }
        }
        $options['mode'] = in_array( $options['mode'], array( 'ai', 'fallback' ), true ) ? $options['mode'] : 'fallback';
        $options['provider'] = in_array( $options['provider'], ALTGENIX_API::supported_providers(), true ) ? $options['provider'] : 'gemini';
        $keys = is_array( $options['provider_keys'] ) ? $options['provider_keys'] : array();
        $options['provider_keys'] = array();
        foreach ( ALTGENIX_API::supported_providers() as $provider ) {
            if ( isset( $keys[ $provider ] ) && is_string( $keys[ $provider ] ) && $keys[ $provider ] !== '' ) {
                $options['provider_keys'][ $provider ] = $keys[ $provider ];
            }
        }
        if ( $options['api_key'] !== '' ) { $options['provider_keys'][ $options['provider'] ] = $options['api_key']; }
        return $options;
    }

    public static function activate() {
        add_option( 'altgenix_settings', self::get_settings(), '', false );
    }

    public static function deactivate( $network_wide = false ) {
        if ( $network_wide && is_multisite() ) {
            $offset = 0;
            do {
                $sites = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $offset ) );
                foreach ( $sites as $site_id ) {
                    switch_to_blog( $site_id );
                    wp_unschedule_hook( 'altgenix_background_process_image' );
                    restore_current_blog();
                }
                $offset += count( $sites );
            } while ( count( $sites ) === 100 );
        } else { wp_unschedule_hook( 'altgenix_background_process_image' ); }
    }

    public function __construct() {
        add_action( 'add_attachment', array( $this, 'schedule_background_processing' ) );
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'attachment_metadata_ready' ), 100, 3 );
        add_action( 'shutdown', array( $this, 'finish_uploads' ) );
        add_action( 'altgenix_background_process_image', array( $this, 'process_new_attachment' ), 10, 1 );
        foreach ( array( 'get_pending', 'process_image', 'mark_all_processed', 'mark_selected_processed', 'get_auto_queue', 'process_auto', 'rename_existing' ) as $action ) {
            add_action( 'wp_ajax_altgenix_' . $action, array( $this, 'ajax_' . $action ) );
        }
    }

    public static function has_generation( $options, $bulk = false ) {
        return ! empty( $options['gen_alt'] ) || ! empty( $options['gen_title'] ) || ! empty( $options['gen_caption'] ) || ! empty( $options['gen_desc'] ) ||
            ( ! $bulk && $options['mode'] === 'ai' && ! empty( $options['rename_file'] ) );
    }

    // Queue all WordPress image types; conversion/fallback decides provider support later.
    public static function queue_mime_types() { return array( 'image' ); }

    public function schedule_background_processing( $attachment_id ) {
        $options = $this->apply_option_overrides( self::get_settings(), $options_override );
        if ( ! wp_attachment_is_image( $attachment_id ) || ! $options['auto_upload'] || ! self::has_generation( $options ) ) { return; }
        update_post_meta( $attachment_id, '_altgenix_auto', 'waiting' );
        $this->ready_at_shutdown[ $attachment_id ] = $attachment_id;
    }

    public function attachment_metadata_ready( $metadata, $attachment_id, $context = 'create' ) {
        if ( get_post_meta( $attachment_id, '_altgenix_auto', true ) ) {
            $this->ready_at_shutdown[ $attachment_id ] = $attachment_id;
        }
        return $metadata;
    }

    public function finish_uploads() {
        foreach ( $this->ready_at_shutdown as $attachment_id ) {
            $meta = wp_get_attachment_metadata( $attachment_id );
            if ( ! is_array( $meta ) || empty( $meta['file'] ) ) { continue; }
            update_post_meta( $attachment_id, '_altgenix_auto', '1' );
            if ( ! wp_next_scheduled( 'altgenix_background_process_image', array( $attachment_id ) ) ) {
                wp_schedule_single_event( time() + 10, 'altgenix_background_process_image', array( $attachment_id ) );
            }
        }
        $this->ready_at_shutdown = array();
    }

    private function authorize( $capability = 'upload_files' ) {
        check_ajax_referer( 'altgenix_ajax_nonce', 'nonce' );
        if ( ! current_user_can( $capability ) ) { wp_send_json_error( array( 'message' => 'You do not have permission for this action.' ), 403 ); }
    }

    private function request_image_id( $key = 'image_id' ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the shared nonce before this private helper is called.
        $id = isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? absint( $_POST[ $key ] ) : 0;
        if ( ! $id || get_post_type( $id ) !== 'attachment' || ! wp_attachment_is_image( $id ) || get_post_field( 'post_status', $id ) !== 'inherit' ) {
            wp_send_json_error( array( 'message' => 'Invalid image attachment.' ), 400 );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) { wp_send_json_error( array( 'message' => 'You are not allowed to edit this image.' ), 403 ); }
        return $id;
    }


    private function request_image_ids( $key = 'image_ids' ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the shared nonce before this private helper is called.
        if ( ! isset( $_POST[ $key ] ) ) {
            return array();
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the shared nonce before this private helper is called.
        $raw = wp_unslash( $_POST[ $key ] );
        if ( ! is_array( $raw ) ) {
            return array();
        }
        $ids = array();
        foreach ( $raw as $candidate ) {
            $id = absint( $candidate );
            if ( ! $id || isset( $ids[ $id ] ) ) {
                continue;
            }
            if ( get_post_type( $id ) !== 'attachment' || ! wp_attachment_is_image( $id ) || get_post_field( 'post_status', $id ) !== 'inherit' ) {
                continue;
            }
            if ( ! current_user_can( 'edit_post', $id ) ) {
                continue;
            }
            $ids[ $id ] = $id;
        }
        return array_values( $ids );
    }

    private function generation_overrides_from_request( $allow_filename = false ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the shared nonce before this private helper is called.
        if ( ! isset( $_POST['fields'] ) ) {
            return array();
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the shared nonce before this private helper is called.
        $raw = wp_unslash( $_POST['fields'] );
        if ( ! is_array( $raw ) ) {
            return array();
        }
        $map = array( 'alt' => 'gen_alt', 'title' => 'gen_title', 'caption' => 'gen_caption', 'description' => 'gen_desc' );
        $overrides = array( 'gen_alt' => 0, 'gen_title' => 0, 'gen_caption' => 0, 'gen_desc' => 0 );
        if ( $allow_filename ) {
            $overrides['rename_file'] = 0;
        }
        foreach ( $raw as $field ) {
            $field = sanitize_key( $field );
            if ( isset( $map[ $field ] ) ) {
                $overrides[ $map[ $field ] ] = 1;
            } elseif ( $allow_filename && 'filename' === $field ) {
                $overrides['rename_file'] = 1;
            }
        }
        return $overrides;
    }

    private function apply_option_overrides( $options, $overrides ) {
        if ( ! is_array( $overrides ) || empty( $overrides ) ) {
            return $options;
        }
        foreach ( array( 'gen_alt', 'gen_title', 'gen_caption', 'gen_desc', 'rename_file' ) as $key ) {
            if ( array_key_exists( $key, $overrides ) ) {
                $options[ $key ] = in_array( $overrides[ $key ], array( 1, '1', true ), true ) ? 1 : 0;
            }
        }
        return $options;
    }

    private function send_result( $result ) {
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message(), 'code' => $result->get_error_code() ) );
        }
        wp_send_json_success( $result );
    }

    public function ajax_get_auto_queue() {
        $this->authorize();
        $options = self::get_settings();
        if ( ! $options['auto_upload'] || ! self::has_generation( $options ) ) { wp_send_json_success( array() ); }
        $args = array(
            'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image',
            'posts_per_page' => 5, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true,
            'meta_query' => array(
                array( 'key' => '_altgenix_auto', 'value' => '1' ),
                array( 'key' => '_altgenix_processed', 'compare' => 'NOT EXISTS' ),
            ),
        );
        // Scope before LIMIT: other authors' uploads cannot starve this user's queue.
        if ( ! current_user_can( 'edit_others_posts' ) ) { $args['author'] = get_current_user_id(); }
        $query = new WP_Query( $args );
        $ids = array();
        foreach ( $query->posts as $id ) { if ( current_user_can( 'edit_post', $id ) ) { $ids[] = (int) $id; } }
        wp_send_json_success( $ids );
    }

    public function ajax_process_auto() {
        $this->authorize();
        $id = $this->request_image_id();
        if ( get_post_meta( $id, '_altgenix_auto', true ) !== '1' ) {
            wp_send_json_error( array( 'message' => 'This image is not queued for automatic processing.' ), 409 );
        }
        $this->send_result( $this->process_new_attachment( $id ) );
    }

    public function ajax_get_pending() {
        $this->authorize( 'manage_options' );
        if ( ! self::has_generation( self::get_settings(), true ) ) {
            wp_send_json_error( array( 'message' => 'Enable at least one metadata field before starting a bulk run.' ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() above verifies the shared nonce.
        $after = isset( $_POST['after_id'] ) && is_scalar( $_POST['after_id'] ) ? absint( $_POST['after_id'] ) : 0;
        // An ID cursor is stable even when rows succeed, fail or disappear mid-run.
        global $wpdb;
        $where = function ( $sql, $query ) use ( $after, $wpdb ) {
            if ( $query->get( 'altgenix_cursor_query' ) ) { $sql .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after ); }
            return $sql;
        };
        add_filter( 'posts_where', $where, 10, 2 );
        try {
            $query = new WP_Query( array(
                'altgenix_cursor_query' => true,
                'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image',
                'posts_per_page' => 100, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
                'meta_query' => array( array( 'key' => '_altgenix_processed', 'compare' => 'NOT EXISTS' ) ),
            ) );
        } finally { remove_filter( 'posts_where', $where, 10 ); }
        $ids = array_map( 'intval', $query->posts );
        wp_send_json_success( array( 'ids' => $ids, 'remaining' => (int) $query->found_posts, 'next_cursor' => $ids ? max( $ids ) : $after ) );
    }

    public function ajax_process_image() {
        $this->authorize();
        $id = $this->request_image_id();
        $can_rename = current_user_can( 'manage_options' );
        $overrides  = $this->generation_overrides_from_request( $can_rename );

        if ( array_key_exists( 'rename_file', $overrides ) ) {
            $rename_file = (bool) $overrides['rename_file'];
        } else {
            // Backward compatibility with earlier UI revisions that sent a dedicated rename flag.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the shared nonce above.
            $rename_file = isset( $_POST['rename_file'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['rename_file'] ) );
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the shared nonce above.
        $delete_old = $rename_file && isset( $_POST['delete_old'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['delete_old'] ) );
        if ( $rename_file && ! $can_rename ) {
            wp_send_json_error( array( 'message' => 'You do not have permission to rename attachment files.' ), 403 );
        }
        $this->send_result( $this->process_new_attachment( $id, true, $rename_file, $delete_old, $overrides ) );
    }

    public function ajax_mark_all_processed() {
        $this->authorize( 'manage_options' );
        $query = new WP_Query( array(
            'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image',
            'posts_per_page' => 100, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
            'meta_query' => array(
                array( 'key' => '_altgenix_processed', 'compare' => 'NOT EXISTS' ),
                array( 'key' => '_altgenix_error', 'compare' => 'NOT EXISTS' ),
                array( 'key' => '_altgenix_auto', 'compare' => 'NOT EXISTS' ),
            ),
        ) );
        $count = 0;
        foreach ( $query->posts as $id ) {
            $token = ALTGENIX_Lock::acquire( $id );
            if ( ! $token ) { continue; }
            $save_error = false;
            try {
                if ( ! get_post_meta( $id, '_altgenix_auto', true ) && ! metadata_exists( 'post', $id, '_altgenix_error' ) ) {
                    if ( ! update_post_meta( $id, '_altgenix_processed', '1' ) && get_post_meta( $id, '_altgenix_processed', true ) !== '1' ) {
                        $save_error = true;
                    }
                    if ( ! $save_error ) { $count++; }
                }
            } finally { ALTGENIX_Lock::release( $id, $token ); }
            // wp_send_json_* exits; release the lock before terminating the request.
            if ( $save_error ) { wp_send_json_error( array( 'message' => 'Could not save processed status. Please retry.' ) ); }
        }
        wp_send_json_success( array( 'count' => $count, 'remaining' => max( 0, (int) $query->found_posts - $count ) ) );
    }

    public function ajax_mark_selected_processed() {
        $this->authorize( 'manage_options' );
        $ids = $this->request_image_ids();
        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Select at least one image.' ), 400 );
        }
        $count   = 0;
        $skipped = 0;
        $updated = array();
        foreach ( $ids as $id ) {
            $token = ALTGENIX_Lock::acquire( $id );
            if ( ! $token ) {
                $skipped++;
                continue;
            }
            $save_error = false;
            try {
                if ( get_post_meta( $id, '_altgenix_auto', true ) || metadata_exists( 'post', $id, '_altgenix_error' ) || get_post_meta( $id, '_altgenix_processed', true ) ) {
                    $skipped++;
                    continue;
                }
                if ( ! update_post_meta( $id, '_altgenix_processed', '1' ) && get_post_meta( $id, '_altgenix_processed', true ) !== '1' ) {
                    $save_error = true;
                } else {
                    $count++;
                    $updated[] = $id;
                }
            } finally {
                ALTGENIX_Lock::release( $id, $token );
            }
            if ( $save_error ) {
                wp_send_json_error( array( 'message' => 'Could not save processed status. Please retry.' ) );
            }
        }
        wp_send_json_success( array( 'count' => $count, 'skipped' => $skipped, 'updated_ids' => array_map( 'intval', $updated ) ) );
    }

    private function set_processing_error( $id, $message ) {
        update_post_meta( $id, '_altgenix_error', wp_slash( ALTGENIX_API::redact_error( $message ) ) );
        delete_post_meta( $id, '_altgenix_processed' );
    }

    public function process_new_attachment( $attachment_id, $is_bulk = false, $rename_override = null, $delete_old = false, $options_override = array() ) {
        $attachment_id = absint( $attachment_id );
        if ( get_post_type( $attachment_id ) !== 'attachment' || ! wp_attachment_is_image( $attachment_id ) || get_post_field( 'post_status', $attachment_id ) !== 'inherit' ) {
            return new WP_Error( 'altgenix_not_image', 'Attachment is not an active image.' );
        }
        $options = self::get_settings();
        if ( ! $is_bulk && ( ! $options['auto_upload'] || get_post_meta( $attachment_id, '_altgenix_auto', true ) !== '1' ) ) {
            return new WP_Error( 'altgenix_not_queued', 'Automatic processing is disabled or this upload is not ready.' );
        }
        $token = ALTGENIX_Lock::acquire( $attachment_id );
        if ( ! $token ) { return new WP_Error( 'altgenix_locked', 'This image is being processed. Please retry shortly.' ); }
        try {
            if ( ! $is_bulk && get_post_meta( $attachment_id, '_altgenix_processed', true ) ) {
                return array( 'status' => 'skipped', 'message' => 'Image already processed.' );
            }
            return $this->run_processing( $attachment_id, $is_bulk, $options, $rename_override, $delete_old );
        } finally {
            ALTGENIX_Lock::release( $attachment_id, $token );
            delete_post_meta( $attachment_id, '_altgenix_auto' );
            wp_clear_scheduled_hook( 'altgenix_background_process_image', array( $attachment_id ) );
        }
    }

    private function run_processing( $id, $is_bulk, $options, $rename_override = null, $delete_old = false ) {
        // Automatic uploads follow the saved Rename File setting. Bulk runs never
        // rename implicitly. A manual single-image regeneration may explicitly
        // opt in/out through the confirmation dialog.
        if ( null === $rename_override ) {
            $options['rename_file'] = ( ! $is_bulk && $options['mode'] === 'ai' && ! empty( $options['rename_file'] ) ) ? 1 : 0;
        } else {
            $options['rename_file'] = ( $options['mode'] === 'ai' && $rename_override ) ? 1 : 0;
        }
        if ( ! self::has_generation( $options, false ) ) {
            return array( 'status' => 'skipped', 'message' => 'No metadata fields or filename generation are enabled. Image remains pending.' );
        }
        $path = get_attached_file( $id );
        if ( ! ALTGENIX_Files::is_local_image( $path ) ) {
            $this->set_processing_error( $id, 'Image is unavailable locally or is outside the uploads directory. Restore a local copy before retrying.' );
            return new WP_Error( 'altgenix_no_file', 'No readable local image was found in the uploads directory.' );
        }
        if ( $options['mode'] === 'fallback' ) {
            return $this->apply_filename_fallback( $id, $path, $options, false );
        }
        $api = new ALTGENIX_API();
        if ( ! $api->is_configured() ) {
            $this->set_processing_error( $id, 'Save and verify an API key in Settings before generating AI metadata.' );
            return new WP_Error( 'altgenix_configuration', 'AI is not configured. Existing metadata was preserved.' );
        }
        // When rename_file is enabled above, the filename is requested in this
        // same AI response as the enabled metadata fields.
        $source = $this->resolve_ai_source_image( $id, $path, $options );
        try { $response = $api->generate_advanced_meta( $source['path'], $options ); }
        finally { if ( $source['temp'] ) { wp_delete_file( $source['path'] ); } }
        if ( is_wp_error( $response ) ) {
            $error = ALTGENIX_API::redact_error( $response->get_error_message() );
            $this->set_processing_error( $id, $error );
            // A degraded run must remain a failure, never count as optimized.
            return new WP_Error( $response->get_error_code(), $error );
        }
        $parsed = self::parse_metadata( isset( $response['text'] ) ? $response['text'] : '', $options );
        if ( is_wp_error( $parsed ) ) {
            $this->set_processing_error( $id, $parsed->get_error_message() );
            return $parsed;
        }
        $saved = $this->save_metadata( $id, $parsed, $options );
        if ( is_wp_error( $saved ) ) { $this->set_processing_error( $id, $saved->get_error_message() ); return $saved; }
        $rename_result = null;
        if ( $options['rename_file'] ) {
            $rename = ALTGENIX_Files::rename_copy( $id, $parsed['filename'], (bool) $delete_old );
            if ( is_wp_error( $rename ) ) {
                $message = 'The requested filename change failed: ' . $rename->get_error_message();
                update_post_meta( $id, '_altgenix_rename_error', wp_slash( $message ) );
                update_post_meta( $id, '_altgenix_pending_filename', wp_slash( $parsed['filename'] ) );
                $this->set_processing_error( $id, $message );
                return new WP_Error( 'altgenix_rename_failed', $message . ' Any generated text fields were saved; use the retry rename action to apply the saved filename without another AI request.' );
            } else {
                $rename_result = $rename;
                delete_post_meta( $id, '_altgenix_rename_error' );
                delete_post_meta( $id, '_altgenix_pending_filename' );
            }
        }
        $result = array( 'status' => 'success', 'message' => $options['rename_file'] ? 'AI metadata saved and filename updated.' : 'AI metadata saved.' );
        if ( is_array( $rename_result ) ) {
            if ( ! empty( $rename_result['new_filename'] ) ) { $result['new_filename'] = $rename_result['new_filename']; }
            if ( isset( $rename_result['deleted_old_files'] ) ) { $result['deleted_old_files'] = (int) $rename_result['deleted_old_files']; }
            if ( isset( $rename_result['delete_failures'] ) ) { $result['delete_failures'] = (int) $rename_result['delete_failures']; }
            if ( ! empty( $rename_result['message'] ) ) { $result['rename_message'] = $rename_result['message']; }
        }
        return $result;
    }

    public static function parse_metadata( $text, $options ) {
        if ( ! is_string( $text ) || strlen( $text ) > 32768 ) { return new WP_Error( 'altgenix_json', 'AI returned an invalid metadata response.' ); }
        $text = trim( $text );
        $text = preg_replace( '/\A```(?:json)?\s*([\s\S]*?)\s*```\z/i', '$1', $text );
        $data = json_decode( $text );
        if ( ! is_object( $data ) ) { return new WP_Error( 'altgenix_json', 'AI did not return a JSON object. Existing metadata was preserved.' ); }
        $fields = array( 'alt' => 'gen_alt', 'title' => 'gen_title', 'caption' => 'gen_caption', 'description' => 'gen_desc', 'filename' => 'rename_file' );
        $result = array();
        foreach ( $fields as $field => $toggle ) {
            if ( empty( $options[ $toggle ] ) ) { continue; }
            if ( ! isset( $data->$field ) || ! is_string( $data->$field ) || strlen( $data->$field ) > 8000 ) {
                return new WP_Error( 'altgenix_schema', 'AI returned missing or invalid ' . $field . '. Existing metadata was preserved.' );
            }
            $value = in_array( $field, array( 'description', 'caption' ), true ) ? sanitize_textarea_field( $data->$field ) : sanitize_text_field( $data->$field );
            if ( trim( $value ) === '' ) { return new WP_Error( 'altgenix_schema', 'AI returned empty ' . $field . '. Existing metadata was preserved.' ); }
            $result[ $field ] = $value;
        }
        return $result;
    }

    private function save_metadata( $id, $data, $options ) {
        $post = array( 'ID' => $id );
        foreach ( array( 'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content' ) as $field => $key ) {
            if ( isset( $data[ $field ] ) ) { $post[ $key ] = $data[ $field ]; }
        }
        if ( count( $post ) > 1 ) {
            $result = wp_update_post( wp_slash( $post ), true );
            if ( is_wp_error( $result ) || ! $result ) { return new WP_Error( 'altgenix_save', 'Could not save attachment metadata. Please retry.' ); }
        }
        if ( isset( $data['alt'] ) ) {
            update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $data['alt'] ) );
            if ( get_post_meta( $id, '_wp_attachment_image_alt', true ) !== $data['alt'] ) { return new WP_Error( 'altgenix_save', 'Could not save alt text. Please retry.' ); }
        }
        update_post_meta( $id, '_altgenix_processed', '1' );
        if ( get_post_meta( $id, '_altgenix_processed', true ) !== '1' ) { return new WP_Error( 'altgenix_save', 'Could not save processing status. Please retry.' ); }
        delete_post_meta( $id, '_altgenix_error' );
        return true;
    }

    private function apply_filename_fallback( $id, $path, $options, $degraded ) {
        $name = pathinfo( $path, PATHINFO_FILENAME );
        $name = preg_replace( '/-(?:scaled|rotated)$/i', '', $name );
        $text = sanitize_text_field( ucwords( str_replace( array( '-', '_' ), ' ', $name ) ) );
        if ( $text === '' ) { return new WP_Error( 'altgenix_filename', 'The filename contains no usable text.' ); }
        $data = array();
        foreach ( array( 'alt' => 'gen_alt', 'title' => 'gen_title', 'caption' => 'gen_caption', 'description' => 'gen_desc' ) as $field => $toggle ) {
            if ( ! empty( $options[ $toggle ] ) ) { $data[ $field ] = $text; }
        }
        $result = $this->save_metadata( $id, $data, $options );
        return is_wp_error( $result ) ? $result : array( 'status' => 'fallback', 'message' => 'Metadata generated from the filename.' );
    }

    public function ajax_rename_existing() {
        $this->authorize( 'manage_options' );
        $id = $this->request_image_id( 'attachment_id' );
        $token = ALTGENIX_Lock::acquire( $id );
        if ( ! $token ) { $this->send_result( new WP_Error( 'altgenix_locked', 'This image is being processed. Please retry shortly.' ) ); }
        try {
            $pending = get_post_meta( $id, '_altgenix_pending_filename', true );
            $basis = is_string( $pending ) && trim( $pending ) !== '' ? $pending : get_post_meta( $id, '_wp_attachment_image_alt', true );
            if ( ! is_string( $basis ) || trim( $basis ) === '' ) { $basis = get_post_field( 'post_title', $id ); }
            $delete_old = isset( $_POST['delete_old'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['delete_old'] ) );
            $result = ALTGENIX_Files::rename_copy( $id, $basis, $delete_old );
            if ( ! is_wp_error( $result ) && $pending ) {
                $rename_error = get_post_meta( $id, '_altgenix_rename_error', true );
                if ( $rename_error && get_post_meta( $id, '_altgenix_error', true ) === $rename_error ) {
                    update_post_meta( $id, '_altgenix_processed', '1' );
                    if ( get_post_meta( $id, '_altgenix_processed', true ) === '1' ) { delete_post_meta( $id, '_altgenix_error' ); }
                }
                delete_post_meta( $id, '_altgenix_pending_filename' );
                delete_post_meta( $id, '_altgenix_rename_error' );
            }
        } finally { ALTGENIX_Lock::release( $id, $token ); }
        $this->send_result( $result );
    }

    private function resolve_ai_source_image( $attachment_id, $original_path, $options ) {
        $fallback  = array( 'path' => $original_path, 'temp' => false );
        $provider  = isset( $options['provider'] ) ? $options['provider'] : 'gemini';
        $max_bytes = ALTGENIX_API::max_image_bytes( $provider );
        $allowed   = ALTGENIX_API::supported_mime_types( $provider );

        $meta = wp_get_attachment_metadata( $attachment_id );
        $dir  = dirname( $original_path );

        // Generated copies that are actually on disk in a format this provider
        // reads. Sizes are judged by their recorded dimensions and never by their
        // names, so a theme's custom sizes and an optimization plugin's pruned set
        // are both handled without knowing anything about either.
        $thumbs = array();
        if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $size ) {
                if ( ! is_array( $size ) || empty( $size['file'] ) || empty( $size['width'] ) || empty( $size['height'] ) ) {
                    continue;
                }
                if ( empty( $size['mime-type'] ) || ! in_array( strtolower( $size['mime-type'] ), $allowed, true ) ) {
                    continue;
                }
                if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
                    $ratio = (float) $meta['width'] / $meta['height'];
                    if ( abs( ( (float) $size['width'] / $size['height'] ) / $ratio - 1 ) > 0.02 ) { continue; }
                }
                $path = $dir . '/' . wp_basename( $size['file'] );
                if ( ! ALTGENIX_Files::is_local_image( $path ) ) {
                    continue;
                }
                $thumbs[] = array(
                    'edge' => max( (int) $size['width'], (int) $size['height'] ),
                    'path' => $path,
                );
            }
        }

        usort(
            $thumbs,
            function ( $a, $b ) {
                return ( $a['edge'] === $b['edge'] ) ? 0 : ( $a['edge'] < $b['edge'] ? -1 : 1 );
            }
        );

        $original_usable = in_array( strtolower( (string) get_post_mime_type( $attachment_id ) ), $allowed, true );

        // 0 means "dimensions unknown" — the byte check below then decides.
        $original_edge = ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) )
            ? max( (int) $meta['width'], (int) $meta['height'] )
            : 0;

        // Preference order, best first. Every entry is a file path except the
        // marker 'resize', which means "generate one now".
        $plan = array();

        // 1. Cheapest existing copy that is still detailed enough. Costs nothing:
        //    WordPress wrote it at upload time.
        foreach ( $thumbs as $thumb ) {
            if ( $thumb['edge'] >= self::AI_TARGET_EDGE ) {
                $plan[] = $thumb['path'];
            }
        }

        // 2. The original, when it is readable and not wastefully large.
        if ( $original_usable && ( $original_edge === 0 || $original_edge <= self::AI_RESIZE_ABOVE ) ) {
            $plan[] = $original_path;
        }

        // 3. Generate one. This single step covers every awkward setup at once:
        //    the larger registered sizes switched off, an original too big for the
        //    provider, and an original in a format the API rejects — the resize is
        //    written as JPEG, so it converts on the way past.
        $plan[] = 'resize';

        // 4. Only now fall to smaller existing copies, biggest first, and never
        //    below the floor where a description stops being trustworthy. A vague
        //    guess from a 150px thumbnail is worse than filename-derived text.
        foreach ( array_reverse( $thumbs ) as $thumb ) {
            if ( $thumb['edge'] >= self::AI_MIN_EDGE && $thumb['edge'] < self::AI_TARGET_EDGE ) {
                $plan[] = $thumb['path'];
            }
        }

        // 5. The original, whatever shape it is in. If the API cannot use it, it
        //    refuses with a specific message and preserves existing metadata.
        if ( $original_usable ) {
            $plan[] = $original_path;
        }

        foreach ( $plan as $step ) {

            if ( $step === 'resize' ) {
                $resized = $this->make_temp_resize( $original_path, $max_bytes );
                if ( $resized ) {
                    return $resized;
                }
                continue;
            }

            if ( ! is_file( $step ) ) {
                continue;
            }

            $bytes = filesize( $step );
            if ( $bytes !== false && $bytes <= $max_bytes ) {
                return array( 'path' => $step, 'temp' => false );
            }
        }

        return $fallback;
    }

    /**
     * Write a temporary downscaled JPEG for the AI to read.
     *
     * Saving as JPEG is deliberate: it both shrinks the file and converts formats
     * the vision APIs reject (AVIF, BMP, TIFF) into one they accept.
     *
     * @param string $original_path
     * @param int    $max_bytes
     * @return array{path:string,temp:bool}|false
     */
    private function make_temp_resize( $original_path, $max_bytes ) {
        if ( ! function_exists( 'wp_get_image_editor' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $editor = wp_get_image_editor( $original_path );
        if ( is_wp_error( $editor ) ) {
            return false;
        }

        // false = fit within the box rather than crop, so nothing is lost.
        $resized = $editor->resize( self::AI_TARGET_EDGE, self::AI_TARGET_EDGE, false );
        if ( is_wp_error( $resized ) ) {
            return false;
        }

        $editor->set_quality( 82 );

        $temp  = wp_tempnam( 'altgenix-ai.jpg' );
        if ( ! $temp ) { return false; }
        $saved = $editor->save( $temp, 'image/jpeg' );

        if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
            wp_delete_file( $temp );
            return false;
        }

        // save() may normalise the extension and write somewhere slightly different.
        if ( $saved['path'] !== $temp && file_exists( $temp ) ) {
            wp_delete_file( $temp );
        }

        $bytes = filesize( $saved['path'] );
        if ( $bytes === false || $bytes > $max_bytes ) {
            wp_delete_file( $saved['path'] );
            return false;
        }

        return array( 'path' => $saved['path'], 'temp' => true );
    }

}
