<?php
/**
 * Fired when the plugin is uninstalled.
 * This file runs when the user deactivates and deletes the plugin.
 */

// If uninstall is not called from WordPress, then exit (Security check)
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Delete plugin settings and cached data from the database to keep it clean
delete_option( 'altgenix_settings' );
delete_option( 'altgenix_valid_models' );
delete_option( 'altgenix_working_model' );
delete_transient( 'altgenix_gemini_models' );