<?php
/**
 * Plugin Name: AltGenix AI Image SEO
 * Plugin URI:  https://github.com/kabeer-qureshi/altgenix-ai-image-seo/
 * Description: Automatically generate SEO-optimized Alt Text, Titles, Captions and file names for images using your choice of Google Gemini, OpenAI, Anthropic Claude, DeepSeek or OpenRouter.
 * Version:     1.3.0
 * Author:      Abdul Kabeer
 * Author URI:  https://www.linkedin.com/in/abdulkabeerdeveloper
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Text Domain: altgenix-ai-image-seo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Direct access is not allowed.' );
}

if ( ! defined( 'ALTGENIX_VERSION' ) ) {
    define( 'ALTGENIX_VERSION', '1.3.0' );
}
if ( ! defined( 'ALTGENIX_PLUGIN_DIR' ) ) {
    define( 'ALTGENIX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'ALTGENIX_PLUGIN_URL' ) ) {
    define( 'ALTGENIX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

class ALTGENIX_Plugin_Init {
    public function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        require_once ALTGENIX_PLUGIN_DIR . 'includes/class-altgenix-lock.php';
        require_once ALTGENIX_PLUGIN_DIR . 'includes/class-altgenix-files.php';
        require_once ALTGENIX_PLUGIN_DIR . 'includes/class-altgenix-settings.php';
        require_once ALTGENIX_PLUGIN_DIR . 'includes/class-altgenix-api.php';
        require_once ALTGENIX_PLUGIN_DIR . 'includes/class-altgenix-core.php';
    }

    private function init_hooks() {
        add_action( 'plugins_loaded', array( $this, 'init_plugin_classes' ) );

        // Write the default settings straight away, so the very first upload behaves
        // the way the settings screen already says it will.
        register_activation_hook( __FILE__, array( 'ALTGENIX_Core', 'activate' ) );
        register_deactivation_hook( __FILE__, array( 'ALTGENIX_Core', 'deactivate' ) );
    }

    public function init_plugin_classes() {
        new ALTGENIX_Settings();
        new ALTGENIX_Core();
    }
}
new ALTGENIX_Plugin_Init();
