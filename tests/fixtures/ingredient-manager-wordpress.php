<?php
namespace CookApp;

require __DIR__ . '/backup-wordpress.php';

function get_post_meta( $id, $key, $single = true ) {
    return $GLOBALS['backup_meta'][ $id ][ $key ] ?? '';
}

function get_objects_in_term( $id, $taxonomy ) {
    $ids = [];
    foreach ( $GLOBALS['backup_assignments'] ?? [] as $post_id => $taxonomies ) {
        if ( in_array( $id, $taxonomies[ $taxonomy ] ?? [], true ) ) $ids[] = $post_id;
    }
    return $ids;
}
function get_term_children( $id, $taxonomy ) { return $GLOBALS['manager_children'][ $id ] ?? []; }
function get_users( $args ) { return array_keys( $GLOBALS['backup_users'] ?? [] ); }
function maybe_unserialize( $value ) { return unserialize( $value ); }
function wp_is_post_revision( $id ) { return in_array( $id, $GLOBALS['manager_revisions'] ?? [], true ); }

$GLOBALS['wpdb'] = new class {
    public string $postmeta = 'postmeta';
    public function prepare( $sql, ...$args ) { return $sql; }
    public function get_results( $sql ) {
        $rows = [];
        foreach ( $GLOBALS['backup_meta'] ?? [] as $id => $meta ) {
            foreach ( $meta as $value ) {
                $rows[] = (object) [ 'post_id' => $id, 'meta_value' => serialize( $value ) ];
            }
        }
        return $rows;
    }
};
