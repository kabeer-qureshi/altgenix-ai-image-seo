<?php
/** Per-attachment locks shared by cron, AJAX generation and file renaming. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ALTGENIX_Lock {
    public static function acquire( $attachment_id ) {
        $key = 'altgenix_lock_' . absint( $attachment_id );
        $token = wp_json_encode( array( 'expires' => time() + 600, 'token' => wp_generate_uuid4() ) );
        if ( self::insert( $key, $token ) ) { return $token; }

        global $wpdb;
        // Read the lock authority directly; a request-local option cache may be stale.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-request lock ownership must not use cached state.
        $old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
        $data = is_string( $old ) ? json_decode( $old, true ) : null;
        if ( ! is_array( $data ) || empty( $data['expires'] ) || (int) $data['expires'] >= time() ) {
            return false;
        }
        // Compare-and-delete: an expired owner cannot delete a newer owner's lock.
        self::release( $attachment_id, $old );
        return self::insert( $key, $token ) ? $token : false;
    }

    private static function insert( $key, $token ) {
        global $wpdb;
        // add_option() uses an upsert and can overwrite another worker's lock.
        // INSERT IGNORE makes the unique option_name index the acquisition gate.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic exclusive insertion is not available through the Options API.
        $inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, $token ) );
        if ( $inserted === 1 ) {
            wp_cache_delete( $key, 'options' );
            wp_cache_delete( 'notoptions', 'options' );
            return true;
        }
        return false;
    }

    public static function release( $attachment_id, $token ) {
        global $wpdb;
        $key = 'altgenix_lock_' . absint( $attachment_id );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic compare-and-delete requires the stored token.
        $deleted = $wpdb->delete( $wpdb->options, array( 'option_name' => $key, 'option_value' => $token ), array( '%s', '%s' ) );
        if ( $deleted ) {
            wp_cache_delete( $key, 'options' );
            wp_cache_delete( 'notoptions', 'options' );
        }
    }
}
