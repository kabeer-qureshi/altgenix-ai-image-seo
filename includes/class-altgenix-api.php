<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ALTGENIX_API {

    private $api_key;
    private $models;
    private $provider;

    /**
     * Maximum allowed image file size for API processing (10MB).
     * Prevents memory exhaustion from base64-encoding oversized files.
     */
    private const MAX_IMAGE_SIZE = 10 * 1024 * 1024;

    public function __construct() {
        $options = get_option( 'altgenix_settings' );
        $this->api_key  = isset( $options['api_key'] ) ? trim( $options['api_key'] ) : '';
        $this->provider = isset( $options['provider'] ) && in_array( $options['provider'], self::supported_providers(), true ) ? $options['provider'] : 'gemini';
        $this->models   = get_option( 'altgenix_valid_models', array() );

        // Defensive: a corrupted option could be a non-array; foreach over it would fatal.
        if ( ! is_array( $this->models ) ) {
            $this->models = array();
        }
    }

    /**
     * AI providers this plugin can talk to. The array key is the stored value,
     * the value is the human-readable label used in the settings dropdown.
     *
     * @return array<string,string>
     */
    public static function provider_labels() {
        return array(
            'gemini' => 'Google Gemini',
            'openai' => 'OpenAI (GPT-4o)',
            'claude' => 'Anthropic Claude',
        );
    }

    /**
     * @return string[] Valid provider keys.
     */
    public static function supported_providers() {
        return array_keys( self::provider_labels() );
    }

    /**
     * Curated vision-capable model fallback lists per provider.
     *
     * Gemini is auto-discovered from the live API, so it is not listed here.
     * For OpenAI and Claude the plugin tries these in order; if the first hits a
     * rate limit it falls through to the next. Claude leads with the most
     * cost-effective vision model (Haiku 4.5) — ideal for high-volume image
     * tagging — and escalates to Sonnet/Opus only if it is rate-limited.
     *
     * @param string $provider
     * @return string[]
     */
    public static function default_models( $provider ) {
        if ( $provider === 'openai' ) {
            return array( 'gpt-4o-mini', 'gpt-4o' );
        }
        if ( $provider === 'claude' ) {
            return array( 'claude-haiku-4-5', 'claude-sonnet-4-6', 'claude-opus-4-8' );
        }
        return array();
    }

    public function is_configured() {
        return ! empty( $this->api_key ) && ! empty( $this->models );
    }

    /**
     * Languages the user can explicitly pick for generated content.
     *
     * The array VALUE is used both as the dropdown label and as the literal
     * instruction handed to the AI, so each must read naturally in the sentence
     * "Write all text values in ___".
     *
     * @return array<string,string> locale-style code => language name
     */
    public static function supported_languages() {
        return array(
            'en'    => 'English',
            'pt_BR' => 'Brazilian Portuguese',
            'pt_PT' => 'European Portuguese',
            'es'    => 'Spanish',
            'fr'    => 'French',
            'de'    => 'German',
            'it'    => 'Italian',
            'nl'    => 'Dutch',
            'ru'    => 'Russian',
            'ar'    => 'Arabic',
            'hi'    => 'Hindi',
            'tr'    => 'Turkish',
            'pl'    => 'Polish',
            'sv'    => 'Swedish',
            'da'    => 'Danish',
            'fi'    => 'Finnish',
            'no'    => 'Norwegian',
            'hu'    => 'Hungarian',
            'cs'    => 'Czech',
            'ro'    => 'Romanian',
            'el'    => 'Greek',
            'uk'    => 'Ukrainian',
            'id'    => 'Indonesian',
            'th'    => 'Thai',
            'vi'    => 'Vietnamese',
            'he'    => 'Hebrew',
            'ja'    => 'Japanese',
            'ko'    => 'Korean',
            'zh_CN' => 'Simplified Chinese',
            'zh_TW' => 'Traditional Chinese',
        );
    }

    /**
     * Resolve the human-readable target language for AI output.
     *
     * When the setting is "auto" (the default) the active WordPress locale is
     * mapped to a language name, so a Portuguese site produces Portuguese text
     * with zero configuration.
     *
     * @param array $options Plugin settings.
     * @return string Language name, e.g. "Brazilian Portuguese".
     */
    private function resolve_output_language( $options ) {
        $choice = isset( $options['language'] ) ? $options['language'] : 'auto';

        if ( $choice !== 'auto' && $choice !== '' ) {
            $supported = self::supported_languages();
            if ( isset( $supported[ $choice ] ) ) {
                return $supported[ $choice ];
            }
        }

        $locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
        return self::locale_to_language_name( $locale );
    }

    /**
     * Map a WordPress locale (e.g. pt_BR) to a language name for the AI prompt.
     *
     * Unmapped locales fall back to phrasing the locale code itself, so the model
     * still localizes correctly instead of silently defaulting to English.
     *
     * @param string $locale WordPress locale string.
     * @return string Language name or a locale-based instruction.
     */
    private static function locale_to_language_name( $locale ) {
        $locale = (string) $locale;

        $full = array(
            'pt_BR' => 'Brazilian Portuguese',
            'pt_PT' => 'European Portuguese',
            'zh_CN' => 'Simplified Chinese',
            'zh_TW' => 'Traditional Chinese',
            'zh_HK' => 'Traditional Chinese',
            'es_MX' => 'Mexican Spanish',
            'en_GB' => 'British English',
            'en_US' => 'English',
            'en_CA' => 'English',
            'en_AU' => 'English',
        );
        if ( isset( $full[ $locale ] ) ) {
            return $full[ $locale ];
        }

        $prefix     = strtolower( substr( $locale, 0, 2 ) );
        $prefix_map = array(
            'en' => 'English',    'pt' => 'Portuguese',  'es' => 'Spanish',
            'fr' => 'French',     'de' => 'German',      'it' => 'Italian',
            'nl' => 'Dutch',      'ru' => 'Russian',     'ar' => 'Arabic',
            'hi' => 'Hindi',      'tr' => 'Turkish',     'pl' => 'Polish',
            'sv' => 'Swedish',    'da' => 'Danish',      'fi' => 'Finnish',
            'nb' => 'Norwegian',  'nn' => 'Norwegian',   'no' => 'Norwegian',
            'hu' => 'Hungarian',  'cs' => 'Czech',       'ro' => 'Romanian',
            'el' => 'Greek',      'uk' => 'Ukrainian',   'id' => 'Indonesian',
            'th' => 'Thai',       'vi' => 'Vietnamese',  'he' => 'Hebrew',
            'ja' => 'Japanese',   'ko' => 'Korean',      'zh' => 'Simplified Chinese',
            'sk' => 'Slovak',     'bg' => 'Bulgarian',   'hr' => 'Croatian',
            'sr' => 'Serbian',    'ca' => 'Catalan',     'fa' => 'Persian',
        );
        if ( isset( $prefix_map[ $prefix ] ) ) {
            return $prefix_map[ $prefix ];
        }

        // Unknown locale: let the model infer from the code rather than forcing English.
        return "the language with locale code '" . $locale . "'";
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

        // Force the output language so generated text matches the site's language
        // (defaults to the site locale). Keys must stay English so JSON parsing below works.
        $language = $this->resolve_output_language( $options );
        $prompt  .= "\nIMPORTANT: Write the VALUES of every field in {$language}.\n";
        $prompt  .= "Do NOT translate or rename the JSON keys (alt, title, caption, description) — the keys must stay in English.\n";

        // Apply the user's optional custom prompt context (sanitized on save).
        if ( ! empty( $options['custom_prompt'] ) ) {
            $prompt .= "\nAdditional instructions: " . trim( $options['custom_prompt'] ) . "\n";
        }

        // Dispatch to the configured provider. Each sender loops over $this->models
        // and returns array( 'success' => true, 'text' => ... ) or a WP_Error.
        switch ( $this->provider ) {
            case 'openai':
                $result = $this->request_openai( $prompt, $base64_image, $mime_type );
                break;
            case 'claude':
                $result = $this->request_claude( $prompt, $base64_image, $mime_type );
                break;
            default:
                $result = $this->request_gemini( $prompt, $base64_image, $mime_type );
                break;
        }

        // Free the large base64 payload now that the request is done.
        unset( $base64_image );

        return $result;
    }

    /**
     * Send the request to Google Gemini and walk the discovered model fallback list.
     */
    private function request_gemini( $prompt, $base64_image, $mime_type ) {
        $payload = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array( 'text' => $prompt ),
                        array(
                            'inlineData' => array(
                                'mimeType' => $mime_type,
                                'data'     => $base64_image,
                            ),
                        ),
                    ),
                ),
            ),
            'generationConfig' => array( 'temperature' => 0.4 ),
        );

        $args = array(
            'method'  => 'POST',
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 60,
        );

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
                error_log( 'ALTGENIX API STATUS: Provider=gemini Model=' . $model_id . ' Code=' . $code . ' BodyLen=' . strlen( $body ) );
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
                $last_error = 'Limit/Demand on model ' . $model_id . ( isset( $data['error']['message'] ) ? ': ' . $data['error']['message'] : '' );
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

    /**
     * Send the request to OpenAI's Chat Completions API (vision + JSON mode).
     */
    private function request_openai( $prompt, $base64_image, $mime_type ) {
        $data_url   = 'data:' . $mime_type . ';base64,' . $base64_image;
        $last_error = '';

        foreach ( $this->models as $model_id ) {

            $model_id = trim( (string) $model_id );

            $payload = array(
                'model'           => $model_id,
                'max_tokens'      => 1024,
                'response_format' => array( 'type' => 'json_object' ),
                'messages'        => array(
                    array(
                        'role'    => 'user',
                        'content' => array(
                            array( 'type' => 'text', 'text' => $prompt ),
                            array( 'type' => 'image_url', 'image_url' => array( 'url' => $data_url ) ),
                        ),
                    ),
                ),
            );

            $args = array(
                'method'  => 'POST',
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $this->api_key,
                ),
                'body'    => wp_json_encode( $payload ),
                'timeout' => 60,
            );

            $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', $args );

            if ( is_wp_error( $response ) ) {
                $last_error = 'Connection Error: ' . $response->get_error_message();
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'ALTGENIX API STATUS: Provider=openai Model=' . $model_id . ' Code=' . $code . ' BodyLen=' . strlen( $body ) );
            }

            $data = json_decode( $body, true );

            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $last_error = 'Invalid JSON response from model: ' . $model_id;
                continue;
            }

            if ( $code === 200 && isset( $data['choices'][0]['message']['content'] ) ) {
                return array(
                    'success' => true,
                    'text'    => trim( $data['choices'][0]['message']['content'] ),
                );
            }

            // 429 (rate limit) or 5xx (server) → try the next model in the list.
            if ( $code === 429 || $code >= 500 ) {
                $last_error = 'Limit/Server error on model ' . $model_id . ( isset( $data['error']['message'] ) ? ': ' . $data['error']['message'] : '' );
                continue;
            }

            if ( isset( $data['error']['message'] ) ) {
                return new WP_Error( 'altgenix_api_error', $data['error']['message'] );
            }

            $last_error = 'Unexpected response from model: ' . $model_id;
        }

        return new WP_Error( 'altgenix_all_failed', ! empty( $last_error ) ? $last_error : 'All models failed or limits reached.' );
    }

    /**
     * Send the request to Anthropic's Messages API (vision).
     */
    private function request_claude( $prompt, $base64_image, $mime_type ) {
        $last_error = '';

        foreach ( $this->models as $model_id ) {

            $model_id = trim( (string) $model_id );

            $payload = array(
                'model'      => $model_id,
                'max_tokens' => 1024,
                'messages'   => array(
                    array(
                        'role'    => 'user',
                        'content' => array(
                            array(
                                'type'   => 'image',
                                'source' => array(
                                    'type'       => 'base64',
                                    'media_type' => $mime_type,
                                    'data'       => $base64_image,
                                ),
                            ),
                            array( 'type' => 'text', 'text' => $prompt ),
                        ),
                    ),
                ),
            );

            $args = array(
                'method'  => 'POST',
                'headers' => array(
                    'Content-Type'      => 'application/json',
                    'x-api-key'         => $this->api_key,
                    'anthropic-version' => '2023-06-01',
                ),
                'body'    => wp_json_encode( $payload ),
                'timeout' => 60,
            );

            $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', $args );

            if ( is_wp_error( $response ) ) {
                $last_error = 'Connection Error: ' . $response->get_error_message();
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'ALTGENIX API STATUS: Provider=claude Model=' . $model_id . ' Code=' . $code . ' BodyLen=' . strlen( $body ) );
            }

            $data = json_decode( $body, true );

            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $last_error = 'Invalid JSON response from model: ' . $model_id;
                continue;
            }

            // Claude returns content as an array of blocks; concatenate the text blocks.
            if ( $code === 200 && isset( $data['content'] ) && is_array( $data['content'] ) ) {
                $text = '';
                foreach ( $data['content'] as $block ) {
                    if ( isset( $block['type'], $block['text'] ) && $block['type'] === 'text' ) {
                        $text .= $block['text'];
                    }
                }
                if ( $text !== '' ) {
                    return array( 'success' => true, 'text' => trim( $text ) );
                }
                // Empty content (e.g. a safety refusal) → fall through to the next model.
                $last_error = 'Empty response from model: ' . $model_id;
                continue;
            }

            // 429 (rate limit), 529 (overloaded) or 5xx → try the next model.
            if ( $code === 429 || $code === 529 || $code >= 500 ) {
                $last_error = 'Limit/Server error on model ' . $model_id . ( isset( $data['error']['message'] ) ? ': ' . $data['error']['message'] : '' );
                continue;
            }

            if ( isset( $data['error']['message'] ) ) {
                return new WP_Error( 'altgenix_api_error', $data['error']['message'] );
            }

            $last_error = 'Unexpected response from model: ' . $model_id;
        }

        return new WP_Error( 'altgenix_all_failed', ! empty( $last_error ) ? $last_error : 'All models failed or limits reached.' );
    }

    /**
     * Verify an API key for a provider and return the model list to store.
     *
     * @param string $provider One of supported_providers().
     * @param string $key      The API key to validate.
     * @return array{valid:bool,models:string[],message:string}
     */
    public static function verify_key( $provider, $key ) {
        $key = trim( (string) $key );
        if ( $key === '' ) {
            return array( 'valid' => false, 'models' => array(), 'message' => 'API Key is required for AI Mode. Reverted to Original Filename mode.' );
        }

        switch ( $provider ) {
            case 'openai':
                return self::verify_openai( $key );
            case 'claude':
                return self::verify_claude( $key );
            default:
                return self::verify_gemini( $key );
        }
    }

    /**
     * Turn a failed wp_remote_* response into a human-readable reason, so the user
     * can tell an actually-invalid key apart from a server-side network/SSL problem
     * (the latter is common on local dev environments and is NOT a key issue).
     *
     * @param WP_Error|array $response       The wp_remote_* return value.
     * @param string         $provider_label Friendly provider name.
     * @return string
     */
    private static function describe_http_failure( $response, $provider_label ) {
        if ( is_wp_error( $response ) ) {
            // e.g. "cURL error 60: SSL certificate problem..." — a server config issue, not a bad key.
            return 'Could not reach ' . $provider_label . ' (network/SSL error on your server): ' . $response->get_error_message() . '.';
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        $detail = '';
        if ( isset( $body['error']['message'] ) ) {
            $detail = ' — ' . $body['error']['message'];
        } elseif ( isset( $body['error']['status'] ) ) {
            $detail = ' — ' . $body['error']['status'];
        }

        if ( $code === 401 || $code === 403 ) {
            return $provider_label . ' rejected the API key (HTTP ' . $code . ')' . $detail;
        }

        return 'Verification failed for ' . $provider_label . ' (HTTP ' . $code . ')' . $detail;
    }

    /**
     * Validate a Gemini key and auto-discover compatible vision models.
     */
    private static function verify_gemini( $key ) {
        $url      = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode( $key );
        $response = wp_remote_get( $url, array( 'timeout' => 15 ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $msg = self::describe_http_failure( $response, 'Google Gemini' );
            // Google geo-blocks the Gemini API in some countries — point the user to the alternatives.
            if ( stripos( $msg, 'location' ) !== false || stripos( $msg, 'not supported' ) !== false || stripos( $msg, 'region' ) !== false ) {
                $msg .= ' Google Gemini is not available in your server\'s country. Switch the AI Provider above to OpenAI or Anthropic Claude (these work from your region), or route the server through a supported region via VPN/proxy.';
            }
            return array( 'valid' => false, 'models' => array(), 'message' => $msg . ' Reverted to Original Filename mode.' );
        }

        $body         = json_decode( wp_remote_retrieve_body( $response ), true );
        $valid_models = array();

        if ( isset( $body['models'] ) && is_array( $body['models'] ) ) {
            foreach ( $body['models'] as $model ) {
                if ( isset( $model['supportedGenerationMethods'] ) && in_array( 'generateContent', $model['supportedGenerationMethods'], true ) ) {
                    $model_id       = str_replace( 'models/', '', $model['name'] );
                    $model_id_lower = strtolower( $model_id );
                    $is_valid       = (bool) preg_match( '/^gemini-(1\.5|2\.0|2\.5|3\.0|3)-(flash|pro)/i', $model_id_lower );
                    $is_not_audio   = ( strpos( $model_id_lower, 'tts' ) === false && strpos( $model_id_lower, 'audio' ) === false && strpos( $model_id_lower, 'embedding' ) === false );

                    if ( $is_valid && $is_not_audio ) {
                        $valid_models[] = $model_id;
                    }
                }
            }
        }

        if ( empty( $valid_models ) ) {
            return array( 'valid' => false, 'models' => array(), 'message' => 'No compatible AI models found for this key. Reverted to Original Filename mode.' );
        }

        return array( 'valid' => true, 'models' => $valid_models, 'message' => 'API Verified! ' . count( $valid_models ) . ' Vision Models auto-discovered.' );
    }

    /**
     * Validate an OpenAI key via the models endpoint.
     */
    private static function verify_openai( $key ) {
        // A real (tiny) generation call validates the key AND confirms the account has
        // usable credits. Listing models alone passes even with a zero balance — which
        // would silently fall back to filename mode and confuse the user.
        $payload = array(
            'model'      => 'gpt-4o-mini',
            'max_tokens' => 1,
            'messages'   => array( array( 'role' => 'user', 'content' => 'ping' ) ),
        );

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $key,
                ),
                'body'    => wp_json_encode( $payload ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array( 'valid' => false, 'models' => array(), 'message' => self::describe_http_failure( $response, 'OpenAI' ) . ' Reverted to Original Filename mode.' );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 ) {
            $models = self::default_models( 'openai' );
            return array( 'valid' => true, 'models' => $models, 'message' => 'OpenAI Verified! ' . count( $models ) . ' Vision Models ready.' );
        }

        $err_type = isset( $body['error']['type'] ) ? $body['error']['type'] : '';
        $err_msg  = isset( $body['error']['message'] ) ? $body['error']['message'] : '';

        if ( $err_type === 'insufficient_quota' || stripos( $err_msg, 'quota' ) !== false || stripos( $err_msg, 'billing' ) !== false ) {
            return array( 'valid' => false, 'models' => array(), 'message' => 'Your OpenAI key is valid, but the account has no available credits/quota. Add a billing balance at platform.openai.com and try again. Reverted to Original Filename mode.' );
        }

        return array( 'valid' => false, 'models' => array(), 'message' => self::describe_http_failure( $response, 'OpenAI' ) . ' Reverted to Original Filename mode.' );
    }

    /**
     * Validate an Anthropic Claude key via the models endpoint.
     */
    private static function verify_claude( $key ) {
        // A real (tiny) generation call validates the key AND confirms the account has
        // credits — Anthropic has no free API tier, so a zero balance must be caught here
        // rather than silently falling back to filename mode later.
        $payload = array(
            'model'      => 'claude-haiku-4-5',
            'max_tokens' => 1,
            'messages'   => array( array( 'role' => 'user', 'content' => 'ping' ) ),
        );

        $response = wp_remote_post(
            'https://api.anthropic.com/v1/messages',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Content-Type'      => 'application/json',
                    'x-api-key'         => $key,
                    'anthropic-version' => '2023-06-01',
                ),
                'body'    => wp_json_encode( $payload ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array( 'valid' => false, 'models' => array(), 'message' => self::describe_http_failure( $response, 'Anthropic Claude' ) . ' Reverted to Original Filename mode.' );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 ) {
            $models = self::default_models( 'claude' );
            return array( 'valid' => true, 'models' => $models, 'message' => 'Claude Verified! ' . count( $models ) . ' Vision Models ready.' );
        }

        $err_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : '';

        if ( $code === 402 || stripos( $err_msg, 'credit' ) !== false || stripos( $err_msg, 'billing' ) !== false ) {
            return array( 'valid' => false, 'models' => array(), 'message' => 'Your Claude key is valid, but your Anthropic credit balance is too low. Add credits at console.anthropic.com and try again. Reverted to Original Filename mode.' );
        }

        return array( 'valid' => false, 'models' => array(), 'message' => self::describe_http_failure( $response, 'Anthropic Claude' ) . ' Reverted to Original Filename mode.' );
    }
}