<?php
/** Copy-based attachment renaming. Published URLs and GUIDs remain stable. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ALTGENIX_Files {
    /** Metadata from before each rename that kept its old files, newest first. */
    private const PREVIOUS_META_KEY = '_altgenix_previous_meta';
    private const PREVIOUS_META_LIMIT = 5;

    public static function is_local_image( $path ) {
        if ( ! is_string( $path ) || $path === '' || wp_is_stream( $path ) ) { return false; }
        $uploads = wp_get_upload_dir();
        $root = realpath( $uploads['basedir'] );
        $file = realpath( $path );
        return $root && $file && is_file( $file ) && is_readable( $file ) &&
            strpos( wp_normalize_path( $file ), trailingslashit( wp_normalize_path( $root ) ) ) === 0;
    }

    /** Caller must hold the attachment's ALTGENIX_Lock. */
    public static function rename_copy( $id, $basis, $delete_old = false ) {
        $file = get_attached_file( $id, true );
        if ( ! self::is_local_image( $file ) ) { return new WP_Error( 'altgenix_file', 'A readable file inside the uploads directory is required.' ); }
        if ( ! is_string( $basis ) || trim( $basis ) === '' ) { return new WP_Error( 'altgenix_name', 'Generate an alt text or title first.' ); }
        $meta = wp_get_attachment_metadata( $id );
        if ( ! is_array( $meta ) || empty( $meta['file'] ) || get_post_meta( $id, '_altgenix_auto', true ) === 'waiting' ) {
            return new WP_Error( 'altgenix_upload_busy', 'WordPress has not finished preparing this image.' );
        }
        $uploads = wp_get_upload_dir();
        $relative = _wp_relative_upload_path( $file );
        if ( wp_normalize_path( $relative ) !== wp_normalize_path( $meta['file'] ) ) {
            return new WP_Error( 'altgenix_metadata', 'Attachment file and metadata paths do not match. Repair the attachment first.' );
        }
        $ext = pathinfo( $file, PATHINFO_EXTENSION );
        if ( ! $ext ) { return new WP_Error( 'altgenix_extension', 'The file has no extension.' ); }
        $slug = rawurldecode( sanitize_title( wp_trim_words( $basis, 6, '' ) ) );
        $slug = rtrim( sanitize_file_name( wp_check_invalid_utf8( substr( $slug, 0, 100 ), true ) ), '-.' );
        if ( $slug === '' ) { $slug = 'optimized-image-' . absint( $id ); }
        $old_name = wp_basename( $file );
        $previous_slug = get_post_meta( $id, '_altgenix_filename_slug', true );
        if ( pathinfo( $file, PATHINFO_FILENAME ) === $slug || $previous_slug === $slug ) {
            return array( 'status' => 'unchanged', 'message' => 'This image already uses this filename.', 'new_filename' => $old_name );
        }
        $dir = dirname( $file );
        $new_name = wp_unique_filename( $dir, $slug . '.' . $ext );
        $base = pathinfo( $new_name, PATHINFO_FILENAME );
        $relative_dir = dirname( $relative );
        $relative_dir = $relative_dir === '.' ? '' : trailingslashit( $relative_dir );
        $map = array( $old_name => $new_name );
        $updated = $meta;
        $updated['file'] = $relative_dir . $new_name;

        if ( ! empty( $meta['original_image'] ) ) {
            if ( wp_basename( $meta['original_image'] ) !== $meta['original_image'] ) { return new WP_Error( 'altgenix_metadata', 'Invalid original-image path.' ); }
            $original = $meta['original_image'];
            if ( ! isset( $map[ $original ] ) ) {
                $map[ $original ] = wp_unique_filename( $dir, $base . '-original.' . pathinfo( $original, PATHINFO_EXTENSION ) );
            }
            $updated['original_image'] = $map[ $original ];
        }
        if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $key => $size ) {
                if ( ! is_array( $size ) || empty( $size['file'] ) ) { continue; }
                $name = $size['file'];
                if ( ! is_string( $name ) || wp_basename( $name ) !== $name ) { return new WP_Error( 'altgenix_metadata', 'Invalid thumbnail path.' ); }
                if ( ! isset( $map[ $name ] ) ) {
                    $suffix = absint( isset( $size['width'] ) ? $size['width'] : 0 ) . 'x' . absint( isset( $size['height'] ) ? $size['height'] : 0 );
                    $candidate = wp_unique_filename( $dir, $base . '-' . $suffix . '.' . pathinfo( $name, PATHINFO_EXTENSION ) );
                    // Two distinct sources can have the same dimensions.
                    if ( in_array( $candidate, $map, true ) ) { $candidate = wp_unique_filename( $dir, $base . '-' . sanitize_key( $key ) . '-' . $suffix . '.' . pathinfo( $name, PATHINFO_EXTENSION ) ); }
                    $map[ $name ] = $candidate;
                }
                $updated['sizes'][ $key ]['file'] = $map[ $name ];
            }
        }
        $created = array();
        foreach ( $map as $from => $to ) {
            $source = $dir . '/' . $from;
            $target = $dir . '/' . $to;
            if ( ! self::is_local_image( $source ) || ! self::copy_exclusive( $source, $target ) ) {
                foreach ( $created as $copy ) { wp_delete_file( $copy ); }
                return new WP_Error( 'altgenix_copy', 'A file could not be copied. The attachment and its existing filenames were preserved.' );
            }
            $created[] = $target;
        }
        // Switch references only after every copy exists. Never SQL-replace serialized
        // metadata, builder data, post content or GUIDs. Existing embeds keep working.
        wp_update_attachment_metadata( $id, $updated );
        update_attached_file( $id, $dir . '/' . $new_name );
        if ( wp_get_attachment_metadata( $id ) !== $updated || wp_normalize_path( get_attached_file( $id, true ) ) !== wp_normalize_path( $dir . '/' . $new_name ) ) {
            wp_update_attachment_metadata( $id, $meta );
            update_attached_file( $id, $file );
            // Keep copies on a database rollback failure; either set of URLs must work.
            if ( wp_get_attachment_metadata( $id ) === $meta && get_attached_file( $id, true ) === $file ) {
                foreach ( $created as $copy ) { wp_delete_file( $copy ); }
            }
            return new WP_Error( 'altgenix_rename_save', 'Could not save the new file references. Existing files were kept.' );
        }
        $backups = get_post_meta( $id, '_altgenix_file_backups', true );
        $backups = is_array( $backups ) ? $backups : array();
        $old_relative = array();
        foreach ( array_keys( $map ) as $name ) { $old_relative[] = $relative_dir . $name; }

        $deleted = 0;
        $delete_failed = 0;
        if ( $delete_old ) {
            foreach ( array_keys( $map ) as $name ) {
                $old_path = $dir . '/' . $name;
                if ( file_exists( $old_path ) ) {
                    wp_delete_file( $old_path );
                    if ( file_exists( $old_path ) ) { $delete_failed++; }
                    else { $deleted++; }
                }
            }
            // Remove only files that were actually deleted from our retained-backup list.
            $backups = array_values( array_filter( $backups, function ( $backup ) use ( $old_relative, $dir ) {
                if ( ! in_array( $backup, $old_relative, true ) ) { return true; }
                return file_exists( $dir . '/' . wp_basename( $backup ) );
            } ) );
            foreach ( $old_relative as $backup ) {
                $backup_path = $dir . '/' . wp_basename( $backup );
                if ( file_exists( $backup_path ) ) { $backups[] = $backup; }
            }
        } else {
            $backups = array_merge( $backups, $old_relative );
        }
        update_post_meta( $id, '_altgenix_file_backups', array_values( array_unique( $backups ) ) );
        update_post_meta( $id, '_altgenix_filename_slug', wp_slash( $slug ) );
        if ( ! $delete_old ) {
            // Posts embedded before this rename still use the old filenames, which no
            // longer match the attachment metadata — so WordPress silently drops their
            // srcset, sizes, width and height. The old files are still on disk; keeping
            // the old metadata lets the filters below answer for them.
            $previous = get_post_meta( $id, self::PREVIOUS_META_KEY, true );
            $previous = is_array( $previous ) ? $previous : array();
            array_unshift( $previous, self::slim_meta( $meta ) );
            update_post_meta( $id, self::PREVIOUS_META_KEY, wp_slash( array_slice( $previous, 0, self::PREVIOUS_META_LIMIT ) ) );
        }

        if ( $delete_old ) {
            $message = $delete_failed
                ? 'Attachment filenames updated. Some old files could not be deleted and were retained.'
                : 'Attachment filenames updated and old files were deleted. Existing URLs that referenced the old files may no longer work.';
            return array( 'status' => 'renamed', 'new_filename' => $new_name, 'deleted_old_files' => $deleted, 'delete_failures' => $delete_failed, 'message' => $message );
        }
        return array( 'status' => 'renamed', 'new_filename' => $new_name, 'message' => 'Attachment filenames updated. Existing embeds still use the retained original files.' );
    }

    /** Only what srcset and dimension lookups read: file, width, height and sizes. */
    private static function slim_meta( $meta ) {
        $slim = array(
            'file'   => (string) $meta['file'],
            'width'  => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
            'height' => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
            'sizes'  => array(),
        );
        if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $key => $size ) {
                if ( ! is_array( $size ) || empty( $size['file'] ) ) { continue; }
                $slim['sizes'][ $key ] = array(
                    'file'      => (string) $size['file'],
                    'width'     => isset( $size['width'] ) ? (int) $size['width'] : 0,
                    'height'    => isset( $size['height'] ) ? (int) $size['height'] : 0,
                    'mime-type' => isset( $size['mime-type'] ) ? (string) $size['mime-type'] : '',
                );
            }
        }
        return $slim;
    }

    /**
     * Width and height of the file a src points at, if that file belongs to $meta.
     * Same test WordPress uses: the upload sub-directory plus the file name.
     */
    private static function find_in_meta( $image_src, $meta ) {
        if ( ! is_string( $image_src ) || $image_src === '' || ! is_array( $meta ) || empty( $meta['file'] ) ) { return null; }
        $parts = explode( '?', $image_src );
        $src   = $parts[0];
        $name  = wp_basename( $src );
        $dir   = dirname( $meta['file'] );
        $dir   = ( $dir === '.' || $dir === '' ) ? '' : trailingslashit( $dir );
        if ( $name === wp_basename( $meta['file'] ) && strpos( $src, $dir . $name ) !== false ) {
            return array( (int) $meta['width'], (int) $meta['height'] );
        }
        if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $size ) {
                if ( is_array( $size ) && isset( $size['file'] ) && $size['file'] === $name && strpos( $src, $dir . $name ) !== false ) {
                    return array( (int) $size['width'], (int) $size['height'] );
                }
            }
        }
        return null;
    }

    /** The pre-rename metadata that owns $image_src, if any. */
    private static function previous_meta_for( $attachment_id, $image_src ) {
        $previous = get_post_meta( $attachment_id, self::PREVIOUS_META_KEY, true );
        if ( ! is_array( $previous ) ) { return null; }
        foreach ( $previous as $meta ) {
            if ( self::find_in_meta( $image_src, $meta ) ) { return $meta; }
        }
        return null;
    }

    /**
     * Filter: wp_calculate_image_srcset_meta.
     * An embed that still uses a pre-rename filename gets its srcset built from the
     * old files, which were kept for exactly this. Anything else passes through.
     */
    public static function filter_srcset_meta( $image_meta, $size_array, $image_src, $attachment_id ) {
        if ( ! $attachment_id || ! is_array( $image_meta ) || self::find_in_meta( $image_src, $image_meta ) ) { return $image_meta; }
        $previous = self::previous_meta_for( $attachment_id, $image_src );
        return $previous ? $previous : $image_meta;
    }

    /**
     * Filter: wp_image_src_get_dimensions.
     * Restores width and height (and so the space reserved while loading) for
     * embeds of pre-rename filenames. Only runs when WordPress found nothing.
     */
    public static function filter_src_dimensions( $dimensions, $image_src, $image_meta, $attachment_id ) {
        if ( $dimensions || ! $attachment_id ) { return $dimensions; }
        $previous = self::previous_meta_for( $attachment_id, $image_src );
        return $previous ? self::find_in_meta( $image_src, $previous ) : $dimensions;
    }

    /**
     * Action: delete_attachment.
     * WordPress deletes only the files named in the current metadata. The copies a
     * rename kept would otherwise stay on disk with nothing pointing to them. A file
     * whose name another attachment in the same folder could own is left alone.
     */
    public static function delete_retained_files( $attachment_id ) {
        $backups = get_post_meta( $attachment_id, '_altgenix_file_backups', true );
        if ( ! is_array( $backups ) || ! $backups ) { return; }
        $uploads = wp_get_upload_dir();
        $basedir = $uploads['basedir'];
        $stems   = array();
        foreach ( $backups as $relative ) {
            if ( ! is_string( $relative ) || $relative === '' || strpos( wp_normalize_path( $relative ), '..' ) !== false ) { continue; }
            $dir = dirname( $relative );
            $dir = $dir === '.' ? '' : $dir;
            if ( ! isset( $stems[ $dir ] ) ) { $stems[ $dir ] = self::stems_owned_by_others( $attachment_id, $dir ); }
            $stem = pathinfo( $relative, PATHINFO_FILENAME );
            foreach ( $stems[ $dir ] as $other ) {
                if ( $stem === $other || strpos( $stem, $other . '-' ) === 0 ) { continue 2; }
            }
            wp_delete_file_from_directory( path_join( $basedir, $relative ), $basedir );
        }
    }

    /** File stems of the other attachments stored directly in one upload folder. */
    private static function stems_owned_by_others( $attachment_id, $dir ) {
        global $wpdb;
        $like = $dir === '' ? '%' : $wpdb->esc_like( trailingslashit( $dir ) ) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Runs once per deleted attachment.
        $files = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND post_id <> %d AND meta_value LIKE %s", $attachment_id, $like ) );
        $stems = array();
        foreach ( (array) $files as $file ) {
            $file_dir = dirname( $file );
            if ( ( $file_dir === '.' ? '' : $file_dir ) !== $dir ) { continue; }
            $stem = pathinfo( $file, PATHINFO_FILENAME );
            $stems[] = $stem;
            $stems[] = preg_replace( '/-scaled$/', '', $stem );
        }
        return array_values( array_unique( $stems ) );
    }

    private static function copy_exclusive( $source, $target ) {
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with exclusive local file handles; WP_Filesystem has no exclusive-create API.
        // Exclusive creation cannot overwrite a competing upload. Both paths have
        // been restricted to the attachment's local upload directory above.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $in = fopen( $source, 'rb' );
        if ( ! $in ) { return false; }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = @fopen( $target, 'xb' );
        if ( ! $out ) { fclose( $in ); return false; }
        $bytes = stream_copy_to_stream( $in, $out );
        fclose( $in );
        fclose( $out );
        $ok = $bytes !== false && $bytes === filesize( $source ) && filesize( $target ) === $bytes;
        if ( ! $ok ) { wp_delete_file( $target ); }
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        return $ok;
    }
}
