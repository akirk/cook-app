<?php
// In-memory WordPress functions loaded only by isolated backup integration tests.
namespace CookApp;

function wp_insert_post( $post, $error = false ) {
    if ( ! empty( $GLOBALS['backup_fail_insert'] ) ) {
        throw new \RuntimeException( 'Simulated insert failure' );
    }
    $id = ++$GLOBALS['backup_next_post'];
    $GLOBALS['backup_posts'][$id] = $post;
    return $id;
}
function wp_update_post( $post, $error = false ) {
    $id = $post['ID'];
    $GLOBALS['backup_posts'][$id] = array_merge( $GLOBALS['backup_posts'][$id], $post );
    return $id;
}
function wp_delete_post( $id, $force = false ) {
    unset( $GLOBALS['backup_posts'][$id], $GLOBALS['backup_meta'][$id], $GLOBALS['backup_assignments'][$id] );
}
function update_post_meta( $id, $key, $value ) {
    $GLOBALS['backup_meta'][$id][$key] = $value;
    return true;
}
function wp_slash( $value ) { return $value; }
function wp_set_object_terms( $id, $terms, $taxonomy ) {
    if ( ! empty( $GLOBALS['backup_fail_terms'] ) ) {
        throw new \RuntimeException( 'Simulated taxonomy failure' );
    }
    $GLOBALS['backup_assignments'][$id][$taxonomy] = $terms;
    return $terms;
}
function term_exists( $name, $taxonomy ) {
    foreach ( $GLOBALS['backup_terms'] as $id => $term ) {
        if ( $term['name'] === $name && $term['taxonomy'] === $taxonomy ) {
            return [ 'term_id' => $id ];
        }
    }
    return null;
}
function wp_insert_term( $name, $taxonomy ) {
    $id = ++$GLOBALS['backup_next_term'];
    $GLOBALS['backup_terms'][$id] = [ 'name' => $name, 'taxonomy' => $taxonomy ];
    return [ 'term_id' => $id ];
}
function wp_update_term( $id, $taxonomy, $args ) {
    $GLOBALS['backup_terms'][$id] = array_merge( $GLOBALS['backup_terms'][$id], $args );
    return [ 'term_id' => $id ];
}
function wp_delete_term( $id, $taxonomy ) { unset( $GLOBALS['backup_terms'][$id] ); }
function update_user_meta( $id, $key, $value ) { $GLOBALS['backup_users'][$id][$key] = $value; }
function get_user_meta( $id, $key, $single = true ) { return $GLOBALS['backup_users'][$id][$key] ?? ''; }
function delete_transient( $key ) { return true; }
function sanitize_title( $value ) { return strtolower( str_replace( ' ', '-', $value ) ); }
function get_term_by( $field, $value, $taxonomy ) {
    foreach ( $GLOBALS['backup_terms'] as $id => $term ) {
        if ( $term['taxonomy'] === $taxonomy && sanitize_title( $term['name'] ) === $value ) {
            return (object) [ 'term_id' => $id ];
        }
    }
    return false;
}
function delete_post_meta( $id, $key ) { unset( $GLOBALS['backup_meta'][$id][$key] ); }
