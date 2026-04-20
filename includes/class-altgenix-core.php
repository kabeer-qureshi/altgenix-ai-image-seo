<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ALTGENIX_Core {

    public function __construct() {
        add_action( 'add_attachment', array( $this, 'schedule_background_processing' ) );
        add_action( 'altgenix_background_process_image', array( $this, 'process_new_attachment' ), 10, 1 );
        
        add_action( 'wp_ajax_altgenix_get_pending', array( $this, 'ajax_get_pending' ) );
        add_action( 'wp_ajax_altgenix_process_image', array( $this, 'ajax_process_image' ) );
        add_action( 'wp_ajax_altgenix_mark_all_processed', array( $this, 'ajax_mark_all_processed' ) );
    }

    public function schedule_background_processing( $attachment_id ) {
        if ( ! wp_attachment_is_image( $attachment_id ) ) return;
        wp_schedule_single_event( time(), 'altgenix_background_process_image', array( $attachment_id ) );
    }

    public function ajax_get_pending() {
        check_ajax_referer( 'altgenix_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( 'Unauthorized Access' ); }

        $supported_mimes = array( 'image/jpeg', 'image/png', 'image/webp' );

        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        $args = array( 
            'post_type'      => 'attachment', 
            'post_status'    => 'inherit', 
            'post_mime_type' => $supported_mimes, 
            'posts_per_page' => 100, 
            'meta_query'     => array( 
                array( 'key' => '_altgenix_processed', 'compare' => 'NOT EXISTS' ) 
            ) 
        );
        
        $query = new WP_Query( $args );
        $valid_ids = array();
        
        foreach ( $query->posts as $post ) {
            $valid_ids[] = $post->ID;
        }
        wp_send_json_success( $valid_ids );
    }

   public function ajax_process_image() {
        check_ajax_referer( 'altgenix_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( 'Unauthorized Access' ); }
        
        $image_id = isset( $_POST['image_id'] ) ? intval( $_POST['image_id'] ) : 0;
        
        if ( $image_id ) { 
            delete_post_meta( $image_id, '_altgenix_processed' );
            $this->process_new_attachment( $image_id, true ); 
            wp_send_json_success(); 
        }
        wp_send_json_error();
    }

    public function ajax_mark_all_processed() {
        check_ajax_referer( 'altgenix_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'upload_files' ) ) { wp_send_json_error( 'Unauthorized Access' ); }

        global $wpdb;
        
        $sql = "INSERT IGNORE INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) 
                SELECT p.ID, '_altgenix_processed', '1' 
                FROM {$wpdb->posts} p 
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_altgenix_processed'
                WHERE p.post_type = 'attachment' 
                AND p.post_mime_type IN ('image/jpeg', 'image/png', 'image/webp') 
                AND pm.post_id IS NULL";
                
        $wpdb->query( $sql );

        wp_send_json_success( array( 'message' => 'All old media marked as processed!' ) );
    }

    private function apply_filename_fallback( $attachment_id, $file_path, $options, $is_bulk ) {
        $gen_alt = !empty( $options['gen_alt'] );
        $gen_title = !empty( $options['gen_title'] );
        $gen_caption = !empty( $options['gen_caption'] );
        $gen_desc = !empty( $options['gen_desc'] );
        $rename_file = !empty( $options['rename_file'] );

        $filename = pathinfo( $file_path, PATHINFO_FILENAME );
        $clean_text = ucwords( str_replace( array( '-', '_' ), ' ', $filename ) );
        
        $attachment_data = array( 'ID' => $attachment_id );
        
        if ( $gen_alt ) update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $clean_text ) );
        if ( $gen_title ) $attachment_data['post_title'] = sanitize_text_field( $clean_text );
        if ( $gen_caption ) $attachment_data['post_excerpt'] = sanitize_textarea_field( $clean_text );
        if ( $gen_desc ) $attachment_data['post_content'] = sanitize_textarea_field( $clean_text );
        
        wp_update_post( $attachment_data );
        update_post_meta( $attachment_id, '_altgenix_processed', '1' ); 
        
        if ( $rename_file && !$is_bulk ) {
            $new_slug = sanitize_title( $clean_text );
            $this->rename_physical_file( $attachment_id, $new_slug );
        }
    }

    public function process_new_attachment( $attachment_id, $is_bulk = false ) {
        if ( ! wp_attachment_is_image( $attachment_id ) ) return;
        
        if ( get_post_meta( $attachment_id, '_altgenix_processed', true ) && !$is_bulk ) return;

        $options = get_option( 'altgenix_settings', array() );
        $mode = isset( $options['mode'] ) ? $options['mode'] : 'fallback'; // Default fallback
        $gen_alt = !empty( $options['gen_alt'] );
        $gen_title = !empty( $options['gen_title'] );
        $gen_caption = !empty( $options['gen_caption'] );
        $gen_desc = !empty( $options['gen_desc'] );
        $rename_file = !empty( $options['rename_file'] );

        if ( !$gen_alt && !$gen_title && !$gen_caption && !$gen_desc && !$rename_file ) {
            update_post_meta( $attachment_id, '_altgenix_processed', '1' );
            return;
        }

        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path ) return;

        if ( $mode === 'fallback' ) {
            $this->apply_filename_fallback( $attachment_id, $file_path, $options, $is_bulk );
            return;
        }

        $api = new ALTGENIX_API();
        if ( ! $api->is_configured() ) {
            $this->apply_filename_fallback( $attachment_id, $file_path, $options, $is_bulk );
            return;
        }

        $api_response = $api->generate_advanced_meta( $file_path, $options );

        if ( strpos( $api_response, 'API_ERROR|' ) === 0 ) {
            $exact_error = str_replace( 'API_ERROR|', '', $api_response );
            $lower_err = strtolower( $exact_error );
            
            if ( strpos( $lower_err, 'large' ) !== false || strpos( $lower_err, 'safety' ) !== false || strpos( $lower_err, 'block' ) !== false ) {
                $this->apply_filename_fallback( $attachment_id, $file_path, $options, $is_bulk );
            } else {
                wp_update_post( array( 'ID' => $attachment_id, 'post_content' => 'AI Error: ' . sanitize_text_field( $exact_error ) ) );
                delete_post_meta( $attachment_id, '_altgenix_processed' ); 
            }
            return;
        }

        $clean_json = trim( $api_response );
        if ( strpos( $clean_json, '```json' ) !== false || strpos( $clean_json, '```' ) !== false ) {
            $clean_json = str_replace( array( '```json', '```' ), '', $clean_json );
            $clean_json = trim( $clean_json );
        }

        $parsed_data = json_decode( $clean_json, true );

        if ( !is_array( $parsed_data ) ) {
            wp_update_post( array( 'ID' => $attachment_id, 'post_content' => 'AI Error: Invalid JSON Format generated.' ) );
            delete_post_meta( $attachment_id, '_altgenix_processed' );
            return;
        }
            
        $attachment_data = array( 'ID' => $attachment_id );
        
        if ( $gen_alt && !empty( $parsed_data['alt'] ) ) update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $parsed_data['alt'] ) );
        if ( $gen_title && !empty( $parsed_data['title'] ) ) $attachment_data['post_title'] = sanitize_text_field( $parsed_data['title'] );
        if ( $gen_caption && !empty( $parsed_data['caption'] ) ) $attachment_data['post_excerpt'] = sanitize_textarea_field( $parsed_data['caption'] );
        if ( $gen_desc && !empty( $parsed_data['description'] ) ) $attachment_data['post_content'] = sanitize_textarea_field( $parsed_data['description'] );

        wp_update_post( $attachment_data );
        update_post_meta( $attachment_id, '_altgenix_processed', '1' ); 

        if ( $rename_file && !$is_bulk ) {
            $name_basis = !empty( $parsed_data['title'] ) ? $parsed_data['title'] : ( !empty( $parsed_data['alt'] ) ? $parsed_data['alt'] : 'optimized-image' );
            $new_slug = sanitize_title( wp_trim_words( $name_basis, 5, '' ) );
            $this->rename_physical_file( $attachment_id, $new_slug );
        }
    }

    private function rename_physical_file( $attachment_id, $new_slug ) {
        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) return;
        
        $info = pathinfo( $file_path );
        $ext  = isset( $info['extension'] ) ? $info['extension'] : '';
        if ( ! $ext ) return;

        $new_file_name = wp_unique_filename( $info['dirname'], $new_slug . '.' . $ext );
        $new_file_path = $info['dirname'] . '/' . $new_file_name;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $filesystem_initialized = WP_Filesystem();
        global $wp_filesystem;

        $moved = false;

        if ( $filesystem_initialized && is_object( $wp_filesystem ) && method_exists( $wp_filesystem, 'move' ) ) {
            $moved = $wp_filesystem->move( $file_path, $new_file_path );
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            $moved = rename( $file_path, $new_file_path );
        }

        if ( $moved ) {
            update_attached_file( $attachment_id, $new_file_path );
            $meta = wp_get_attachment_metadata( $attachment_id );
            
            if ( is_array( $meta ) && ! empty( $meta['file'] ) ) {
                $meta['file'] = dirname( $meta['file'] ) . '/' . $new_file_name;
                
                if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
                    foreach ( $meta['sizes'] as $size => $size_info ) {
                        $old_thumb_path = $info['dirname'] . '/' . $size_info['file'];
                        $thumb_ext = pathinfo( $size_info['file'], PATHINFO_EXTENSION );
                        $thumb_new_name = $new_slug . '-' . $size_info['width'] . 'x' . $size_info['height'] . '.' . $thumb_ext;
                        $new_thumb_path = $info['dirname'] . '/' . $thumb_new_name;

                        if ( file_exists( $old_thumb_path ) ) {
                            if ( $filesystem_initialized && is_object( $wp_filesystem ) ) {
                                $wp_filesystem->move( $old_thumb_path, $new_thumb_path );
                            } else {
                                // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
                                rename( $old_thumb_path, $new_thumb_path );
                            }
                            $meta['sizes'][$size]['file'] = $thumb_new_name;
                        }
                    }
                }
                wp_update_attachment_metadata( $attachment_id, $meta );
            }
        }
    }
}