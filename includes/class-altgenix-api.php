<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ALTGENIX_API {

    private $api_key;
    private $models;

    public function __construct() {
        $options = get_option( 'altgenix_settings' );
        $this->api_key = isset( $options['api_key'] ) ? trim( $options['api_key'] ) : '';
        $this->models  = get_option( 'altgenix_valid_models', array() );
    }

    public function is_configured() {
        return ! empty( $this->api_key ) && ! empty( $this->models );
    }

    public function generate_advanced_meta( $image_path, $options ) {

        if ( empty( $this->api_key ) ) {
            return 'API_ERROR|API key missing.';
        }

        if ( empty( $this->models ) ) {
            return 'API_ERROR|No models configured.';
        }

        if ( ! file_exists( $image_path ) ) {
            return 'API_ERROR|File not found.';
        }

        $filetype  = wp_check_filetype( $image_path );
        $mime_type = ! empty( $filetype['type'] ) ? $filetype['type'] : 'image/jpeg';

        $image_data = file_get_contents( $image_path );
        if ( $image_data === false ) {
            return 'API_ERROR|Could not read image file.';
        }

        $base64_image = base64_encode( $image_data );

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

        if ( ! empty( $options['gen_alt'] ) ) {
            $len = isset( $options['alt_length'] ) ? $lengths_map[$options['alt_length']] : $lengths_map['short'];
            $prompt .= "- \"alt\": Highly descriptive alt text ($len)\n";
        }

        if ( ! empty( $options['gen_title'] ) ) {
            $len = isset( $options['title_length'] ) ? $lengths_map[$options['title_length']] : $lengths_map['short'];
            $prompt .= "- \"title\": Catchy SEO title ($len)\n";
        }

        if ( ! empty( $options['gen_caption'] ) ) {
            $len = isset( $options['caption_length'] ) ? $lengths_map[$options['caption_length']] : $lengths_map['short'];
            $prompt .= "- \"caption\": Image caption ($len)\n";
        }

        if ( ! empty( $options['gen_desc'] ) ) {
            $len = isset( $options['desc_length'] ) ? $lengths_map[$options['desc_length']] : $lengths_map['medium'];
            $prompt .= "- \"description\": Detailed SEO description ($len)\n";
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

        /*
        ========================
        MODEL FALLBACK LOOP
        ========================
        */

        foreach ( $this->models as $model_id ) {

            $model_id = trim( (string) $model_id );
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model_id . ':generateContent?key=' . $this->api_key;

            $response = wp_remote_post( esc_url_raw( $url ), $args );

            if ( is_wp_error( $response ) ) {
                return 'API_ERROR|Connection Error: ' . $response->get_error_message();
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );

            // Debug log (production me helpful)
            if ( defined('WP_DEBUG') && WP_DEBUG ) {
                error_log( 'ALTGENIX API RESPONSE: ' . $body );
            }

            $data = json_decode( $body, true );

            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return 'API_ERROR|Invalid JSON response from API.';
            }

            if ( $code === 200 && isset( $data['candidates'][0]['content']['parts'][0]['text'] ) ) {
                return trim( $data['candidates'][0]['content']['parts'][0]['text'] );
            }

            // Rate limit → try next model
            if ( $code === 429 || ( isset( $data['error']['code'] ) && $data['error']['code'] == 429 ) ) {
                continue;
            }

            if ( isset( $data['error']['message'] ) ) {
                return 'API_ERROR|' . $data['error']['message'];
            }
        }

        return 'API_ERROR|All models failed or limits reached.';
    }
}
