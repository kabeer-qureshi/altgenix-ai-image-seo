<?php
/**
 * Plugin Name: AltGenix AI Image SEO
 * Plugin URI:  https://github.com/kabeer-qureshi/altgenix-ai-image-seo/
 * Description: Automatically generate SEO-optimized Alt Text, Titles, and Descriptions for uploaded images using Google Gemini Vision AI.
 * Version:     1.0.1
 * Author:      Abdul Kabeer
 * Author URI:  https://www.linkedin.com/in/abdul-kabeer-b959682b4/
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: altgenix-ai-image-seo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Direct access is not allowed.' );
}

if ( ! defined( 'ALTGENIX_VERSION' ) ) {
    define( 'ALTGENIX_VERSION', '1.0.1' );
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
        require_once ALTGENIX_PLUGIN_DIR . 'includes/class-altgenix-settings.php';
        require_once ALTGENIX_PLUGIN_DIR . 'includes/class-altgenix-api.php';
        require_once ALTGENIX_PLUGIN_DIR . 'includes/class-altgenix-core.php';
    }

    private function init_hooks() {
        add_action( 'plugins_loaded', array( $this, 'init_plugin_classes' ) );
    }

    public function init_plugin_classes() {
        new ALTGENIX_Settings();
        new ALTGENIX_Core();
    }
}
new ALTGENIX_Plugin_Init();