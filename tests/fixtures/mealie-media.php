<?php
// Media functions used only by isolated Mealie restore tests.
function wp_tempnam( $name ) {
    $path = tempnam( sys_get_temp_dir(), 'cook-app-photo-' );
    $GLOBALS['mealie_temp_paths'][] = $path;
    return $path;
}
function media_handle_sideload( $file, $post_id ) {
    if ( ! empty( $GLOBALS['mealie_fail_media_after'] ) && count( $GLOBALS['mealie_attachments'] ) >= $GLOBALS['mealie_fail_media_after'] ) {
        return 0;
    }
    $id = 1000 + $post_id;
    $GLOBALS['mealie_attachments'][ $id ] = [ 'post_parent' => $post_id, 'bytes' => file_get_contents( $file['tmp_name'] ) ];
    return $id;
}
function set_post_thumbnail( $post_id, $attachment_id ) {
    if ( ! empty( $GLOBALS['mealie_fail_thumbnail'] ) ) {
        return false;
    }
    $GLOBALS['mealie_thumbnails'][ $post_id ] = $attachment_id;
    return true;
}
function wp_delete_attachment( $id, $force = false ) {
    unset( $GLOBALS['mealie_attachments'][ $id ] );
    foreach ( $GLOBALS['mealie_thumbnails'] as $post_id => $attachment_id ) {
        if ( $attachment_id === $id ) {
            unset( $GLOBALS['mealie_thumbnails'][ $post_id ] );
        }
    }
}
function wp_delete_file( $path ) {
    if ( file_exists( $path ) ) {
        unlink( $path );
    }
}
