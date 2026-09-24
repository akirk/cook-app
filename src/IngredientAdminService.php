<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class IngredientAdminService extends AbstractService {
    /**
     * Collapse one or more ingredient terms into a single target term.
     *
     * For each source term: rewrites the per-recipe `_recipe_ingredients` meta
     * rows so any reference to the source's term_id points at the target,
     * reassigns the term itself on each recipe, reparents any children of the
     * source onto the target, then deletes the source. The source ingredient
     * names are preserved in the meta rows (only the term_id link moves), so
     * recipes still display the wording the user originally typed.
     */
    public function handle_merge_ingredients(): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_categories' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( 'cookbook_manage_ingredients' );

        $sources = isset( $_POST['source_ids'] ) && is_array( $_POST['source_ids'] )
            ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['source_ids'] ) ) ) ) )
            : [];
        $target = isset( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;
        $sources = array_values( array_diff( $sources, [ $target ] ) );

        $merged = 0;
        if ( $target && get_term( $target, App::TAX_INGREDIENT ) instanceof \WP_Term && $sources ) {
            foreach ( $sources as $source_id ) {
                if ( $this->merge_ingredient_term( $source_id, $target ) ) {
                    $merged++;
                }
            }
        }

        $back = home_url( '/' . $this->get_url_path() . '/manage-ingredients' );
        wp_safe_redirect( add_query_arg( [ 'merged' => $merged ], $back ) );
        exit;
    }

    /**
     * Merge a single source term into a target term. Returns true on success.
     */
    private function merge_ingredient_term( int $source_id, int $target_id ): bool {
        if ( $source_id === $target_id ) return false;
        $source = get_term( $source_id, App::TAX_INGREDIENT );
        if ( ! $source instanceof \WP_Term ) return false;

        $posts = get_posts( [
            'post_type'      => App::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one-time admin operation, exact-term filter.
            'tax_query'      => [
                [ 'taxonomy' => App::TAX_INGREDIENT, 'field' => 'term_id', 'terms' => $source_id ],
            ],
        ] );
        foreach ( $posts as $post_id ) {
            $rows    = (array) get_post_meta( $post_id, App::META_INGREDIENTS, true );
            $changed = false;
            foreach ( $rows as &$row ) {
                if ( ! is_array( $row ) ) continue;
                if ( isset( $row['term_id'] ) && (int) $row['term_id'] === $source_id ) {
                    $row['term_id'] = $target_id;
                    $changed        = true;
                }
            }
            unset( $row );
            if ( $changed ) {
                update_post_meta( $post_id, App::META_INGREDIENTS, $rows );
            }
            wp_remove_object_terms( $post_id, $source_id, App::TAX_INGREDIENT );
            wp_add_object_terms( $post_id, $target_id, App::TAX_INGREDIENT );
        }

        // Reparent children of the source onto the target so the hierarchy survives the delete.
        $children = get_terms( [
            'taxonomy'   => App::TAX_INGREDIENT,
            'hide_empty' => false,
            'parent'     => $source_id,
            'fields'     => 'ids',
        ] );
        if ( is_array( $children ) ) {
            foreach ( $children as $child_id ) {
                wp_update_term( (int) $child_id, App::TAX_INGREDIENT, [ 'parent' => $target_id ] );
            }
        }

        $deleted = wp_delete_term( $source_id, App::TAX_INGREDIENT );
        return $deleted === true;
    }

    /**
     * Re-parent one or more ingredient terms onto a target (group as hierarchy).
     */
    public function handle_group_ingredients(): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_categories' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( 'cookbook_manage_ingredients' );

        $sources = isset( $_POST['source_ids'] ) && is_array( $_POST['source_ids'] )
            ? array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $_POST['source_ids'] ) ) ) ) )
            : [];
        $target = isset( $_POST['target_id'] ) ? absint( $_POST['target_id'] ) : 0;
        $sources = array_values( array_diff( $sources, [ $target ] ) );

        $grouped = 0;
        if ( $target === 0 || ( get_term( $target, App::TAX_INGREDIENT ) instanceof \WP_Term ) ) {
            foreach ( $sources as $source_id ) {
                $res = wp_update_term( $source_id, App::TAX_INGREDIENT, [ 'parent' => $target ] );
                if ( ! is_wp_error( $res ) ) $grouped++;
            }
        }

        $back = home_url( '/' . $this->get_url_path() . '/manage-ingredients' );
        wp_safe_redirect( add_query_arg( [ 'grouped' => $grouped ], $back ) );
        exit;
    }

    /**
     * Rename a single ingredient term. The slug is left untouched so existing
     * /ingredient/{slug} URLs and slug-based dedup still work.
     */
    public function handle_rename_ingredient(): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_categories' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( 'cookbook_manage_ingredients' );

        $term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
        $name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        $renamed = 0;
        if ( $term_id && $name !== '' ) {
            $res = wp_update_term( $term_id, App::TAX_INGREDIENT, [ 'name' => $name ] );
            if ( ! is_wp_error( $res ) ) $renamed = 1;
        }

        $back = home_url( '/' . $this->get_url_path() . '/manage-ingredients' );
        wp_safe_redirect( add_query_arg( [ 'renamed' => $renamed ], $back ) );
        exit;
    }
}
