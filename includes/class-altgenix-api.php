<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ALTGENIX_API {

    private $api_key;
    private $models;

    /**
     * Maximum allowed image file size for API processing (10MB).
     * Prevents memory exhaustion from base64-encoding oversized files.
     */
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024;

    public function __construct() {
        $options = get_option( 'altgenix_settings' );
        $this->api_key = isset( $options['api_key'] ) ? trim( $options['api_key'] ) : '';
        $this->models  = get_option( 'altgenix_valid_models', array() );
    }

    public function is_configured() {
        return ! empty( $this->api_key ) && ! empty( $this->models );
    }

    /**
     * Generate SEO metadata for an image using the Gemini Vision API.
     *
     * @param string $image_path Absolute path to the image file.
     * @param array  $options    Plugin settings array.
     * @return array|WP_Error Array with parsed AI data on success, WP_Error on failure.
     */
    public function generate_advanced_meta( $image_path, $options ) {

        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'altgenix_no_key', 'API key missing.' );
        }

        if ( empty( $this->models ) ) {
            return new WP_Error( 'altgenix_no_models', 'No models configured.' );
        }

        if ( ! file_exists( $image_path ) ) {
            return new WP_Error( 'altgenix_file_missing', 'File not found.' );
        }

        // C-05 FIX: Check file size before reading to prevent memory exhaustion.
        $file_size = filesize( $image_path );
        if ( $file_size === false || $file_size > self::MAX_IMAGE_SIZE ) {
            return new WP_Error(
                'altgenix_file_too_large',
                sprintf( 'Image file exceeds the %dMB processing limit.', self::MAX_IMAGE_SIZE / ( 1024 * 1024 ) )
            );
        }

        $filetype  = wp_check_filetype( $image_path );
        $mime_type = ! empty( $filetype['type'] ) ? $filetype['type'] : 'image/jpeg';

        $image_data = file_get_contents( $image_path );
        if ( $image_data === false ) {
            return new WP_Error( 'altgenix_read_fail', 'Could not read image file.' );
        }

        $base64_image = base64_encode( $image_data );

        // Free raw image data immediately to reduce peak memory usage.
        unset( $image_data );

        /*
        ========================
        PROMPT BUILDING
        ========================
        */

        $prompt  = "Act as an expert SEO copywriter.\n";
        $prompt .= "Analyze this image and STRICTLY return a JSON object.\n";
        $prompt .= "Return ONLY valid JSON. No markdown. No extra text.\n\n";

        $lengths_map = array(
            'short'  => '1 to 5 words',
            'medium' => '5 to 15 words',
            'long'   => '15 to 30 words'
        );

        // C-07 FIX: Validate length values against whitelist before using as array key.
        $valid_lengths = array_keys( $lengths_map );

        if ( ! empty( $options['gen_alt'] ) ) {
            $alt_len_key = isset( $options['alt_length'] ) && in_array( $options['alt_length'], $valid_lengths, true ) ? $options['alt_length'] : 'short';
            $prompt .= "- \"alt\": Highly descriptive alt text ({$lengths_map[$alt_len_key]})\n";
        }

        if ( ! empty( $options['gen_title'] ) ) {
            $title_len_key = isset( $options['title_length'] ) && in_array( $options['title_length'], $valid_lengths, true ) ? $options['title_length'] : 'short';
            $prompt .= "- \"title\": Catchy SEO title ({$lengths_map[$title_len_key]})\n";
        }

        if ( ! empty( $options['gen_caption'] ) ) {
            $caption_len_key = isset( $options['caption_length'] ) && in_array( $options['caption_length'], $valid_lengths, true ) ? $options['caption_length'] : 'short';
            $prompt .= "- \"caption\": Image caption ({$lengths_map[$caption_len_key]})\n";
        }

        if ( ! empty( $options['gen_desc'] ) ) {
            $desc_len_key = isset( $options['desc_length'] ) && in_array( $options['desc_length'], $valid_lengths, true ) ? $options['desc_length'] : 'medium';
            $prompt .= "- \"description\": Detailed SEO description ({$lengths_map[$desc_len_key]})\n";
        }

        /*
        ========================
        PAYLOAD
        ========================
        */

        $payload = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array( 'text' => $prompt ),
                        array(
                            'inlineData' => array(
                                'mimeType' => $mime_type,
                                'data'     => $base64_image
                            )
                        )
                    )
                )
            ),
            'generationConfig' => array(
                'temperature' => 0.4
            )
        );

        $args = array(
            'method'  => 'POST',
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 60
        );

        // Free base64 data after encoding into JSON payload.
        unset( $base64_image );

        /*
        ========================
        MODEL FALLBACK LOOP
        ========================
        */

        $last_error = '';

        foreach ( $this->models as $model_id ) {

            $model_id = trim( (string) $model_id );

            // C-03 FIX: URL-encode the API key to handle special characters safely.
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model_id ) . ':generateContent?key=' . urlencode( $this->api_key );

            $response = wp_remote_post( esc_url_raw( $url ), $args );

            // m-10 FIX: Connection errors continue to next model instead of aborting.
            if ( is_wp_error( $response ) ) {
                $last_error = 'Connection Error: ' . $response->get_error_message();
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );

            // C-02 FIX: Truncate debug output and never log the full body (may contain echoed URLs/keys).
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'ALTGENIX API STATUS: Model=' . $model_id . ' Code=' . $code . ' BodyLen=' . strlen( $body ) );
            }

            $data = json_decode( $body, true );

            // m-09 FIX: JSON decode errors continue to next model instead of aborting.
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $last_error = 'Invalid JSON response from model: ' . $model_id;
                continue;
            }

            if ( $code === 200 && isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
                return array(
                    'success' => true,
                    'text'    => trim( $data['candidates'][0]['content']['parts'][0]['text'] ),
                );
            }

            // Rate limit or high demand → try next model
            $is_retryable_error = ( $code === 429 || $code === 503 );
            if ( isset( $data['error']['code'] ) && ( $data['error']['code'] == 429 || $data['error']['code'] == 503 ) ) {
                $is_retryable_error = true;
            }
            if ( isset( $data['error']['message'] ) && stripos( $data['error']['message'], 'demand' ) !== false ) {
                $is_retryable_error = true;
            }

            if ( $is_retryable_error ) {
                $last_error = 'Limit/Demand on model ' . $model_id . ( isset($data['error']['message']) ? ': ' . $data['error']['message'] : '' );
                continue;
            }

            // Non-retryable error from this model
            if ( isset( $data['error']['message'] ) ) {
                return new WP_Error( 'altgenix_api_error', $data['error']['message'] );
            }

            $last_error = 'Unexpected response from model: ' . $model_id;
        }

        return new WP_Error( 'altgenix_all_failed', ! empty( $last_error ) ? $last_error : 'All models failed or limits reached.' );
    }
}