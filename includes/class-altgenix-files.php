<?php
/** Copy-based attachment renaming. Published URLs and GUIDs remain stable. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class ALTGENIX_Files {
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

        if ( $delete_old ) {
            $message = $delete_failed
                ? 'Attachment filenames updated. Some old files could not be deleted and were retained.'
                : 'Attachment filenames updated and old files were deleted. Existing URLs that referenced the old files may no longer work.';
            return array( 'status' => 'renamed', 'new_filename' => $new_name, 'deleted_old_files' => $deleted, 'delete_failures' => $delete_failed, 'message' => $message );
        }
        return array( 'status' => 'renamed', 'new_filename' => $new_name, 'message' => 'Attachment filenames updated. Existing embeds still use the retained original files.' );
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
