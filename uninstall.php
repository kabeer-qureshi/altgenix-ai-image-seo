<?php
/** Remove plugin configuration, jobs and temporary diagnostics while preserving durable media history. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

function altgenix_uninstall_site() {
    foreach ( array( 'altgenix_settings', 'altgenix_valid_models', 'altgenix_models_context', 'altgenix_model_details', 'altgenix_unavailable_models', 'altgenix_working_model', 'altgenix_feedback', 'altgenix_data_version', 'altgenix_services_dismissed' ) as $option ) { delete_option( $option ); }
    wp_unschedule_hook( 'altgenix_background_process_image' );
    global $wpdb;
    // Discover plugin-owned transient/lock names, then use APIs to invalidate caches.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off uninstall cleanup.
    $names = $wpdb->get_col( $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( '_transient_altgenix_' ) . '%', $wpdb->esc_like( '_transient_timeout_altgenix_' ) . '%', $wpdb->esc_like( 'altgenix_lock_' ) . '%'
    ) );
    foreach ( $names as $name ) {
        if ( strpos( $name, '_transient_timeout_' ) === 0 ) { delete_transient( substr( $name, 19 ) ); }
        elseif ( strpos( $name, '_transient_' ) === 0 ) { delete_transient( substr( $name, 11 ) ); }
        else { delete_option( $name ); }
    }
    // Keep durable per-attachment history across uninstall/reinstall. In particular,
    // _altgenix_processed prevents a previously optimized library from suddenly
    // becoming Pending (and potentially being billed again), while
    // _altgenix_filename_slug keeps repeated AI renames idempotent. The legacy
    // migration marker is also retained so reinstalling cannot re-examine content
    // that was already deliberately left untouched.
    foreach ( array( '_altgenix_auto', '_altgenix_error', '_altgenix_legacy_error', '_altgenix_rename_error', '_altgenix_pending_filename', '_altgenix_old_filename' ) as $key ) {
        delete_post_meta_by_key( $key );
    }
    // _altgenix_processed, _altgenix_filename_slug, _altgenix_legacy_skipped,
    // _altgenix_file_backups and _altgenix_previous_meta intentionally remain.
    // Published embeds may still use retained backup URLs (and get their srcset back
    // from the previous metadata on a reinstall), and the history markers prevent
    // accidental reprocessing or repeat renames after a reinstall.
}

if ( is_multisite() ) {
    $altgenix_offset = 0;
    do {
        $altgenix_sites = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $altgenix_offset ) );
        foreach ( $altgenix_sites as $altgenix_site_id ) { switch_to_blog( $altgenix_site_id ); altgenix_uninstall_site(); restore_current_blog(); }
        $altgenix_offset += count( $altgenix_sites );
    } while ( count( $altgenix_sites ) === 100 );
} else { altgenix_uninstall_site(); }
