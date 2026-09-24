<?php

namespace Cookbook;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Manage the four Cookbook vocabularies through a small shared ability API. */
class TermAbilitiesService {
    private const TYPES = [
        'ingredients' => App::TAX_INGREDIENT,
        'categories'  => App::TAX_CATEGORY,
        'cuisines'    => App::TAX_CUISINE,
        'tags'        => App::TAX_TAG,
    ];

    public function register_abilities(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        $type = [
            'type'        => 'string',
            'enum'        => array_keys( self::TYPES ),
            'description' => __( 'Cookbook vocabulary to manage.', 'cookbook' ),
        ];
        $id = [ 'type' => 'integer', 'minimum' => 1 ];
        $ids = [ 'type' => 'array', 'items' => $id, 'minItems' => 1 ];
        $definitions = [
            'list-recipe-terms' => [
                'label'       => __( 'List Cookbook Ingredients, Categories, Cuisines or Tags', 'cookbook' ),
                'description' => __( 'Lists Cookbook vocabulary entries with IDs, usage counts and parent IDs.', 'cookbook' ),
                'properties'  => [ 'type' => $type, 'search' => [ 'type' => 'string' ], 'include_unused' => [ 'type' => 'boolean' ], 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 500 ], 'offset' => [ 'type' => 'integer', 'minimum' => 0 ] ],
                'required'    => [ 'type' ],
                'callback'    => 'list_terms',
                'readonly'    => true,
                'destructive' => false,
                'instructions' => __( 'Use to inspect Cookbook ingredients, categories, cuisines, or tags before changing them. Results include IDs needed for merge, update, and delete. Paginate with limit and offset.', 'cookbook' ),
            ],
            'merge-recipe-terms' => [
                'label'       => __( 'Merge Cookbook Ingredients, Categories, Cuisines or Tags', 'cookbook' ),
                'description' => __( 'Moves assignments from source entries to an existing target, then deletes the sources.', 'cookbook' ),
                'properties'  => [ 'type' => $type, 'source_ids' => $ids, 'target_id' => $id ],
                'required'    => [ 'type', 'source_ids', 'target_id' ],
                'callback'    => 'merge_terms',
                'readonly'    => false,
                'destructive' => true,
                'instructions' => __( 'Use only after identifying the exact source and target IDs. Ingredient merges preserve the wording, amounts, and notes in recipes while updating stored ingredient IDs. The source entries are deleted.', 'cookbook' ),
            ],
            'update-recipe-term' => [
                'label'       => __( 'Update Cookbook Ingredient, Category, Cuisine or Tag', 'cookbook' ),
                'description' => __( 'Renames a Cookbook vocabulary entry and optionally changes its slug.', 'cookbook' ),
                'properties'  => [ 'type' => $type, 'id' => $id, 'name' => [ 'type' => 'string', 'minLength' => 1 ], 'slug' => [ 'type' => 'string', 'minLength' => 1 ] ],
                'required'    => [ 'type', 'id', 'name' ],
                'callback'    => 'update_term',
                'readonly'    => false,
                'destructive' => false,
                'instructions' => __( 'Use to rename one Cookbook ingredient, category, cuisine, or tag. Omit slug to preserve existing links. This does not rewrite recipe ingredient wording.', 'cookbook' ),
            ],
            'delete-recipe-terms' => [
                'label'       => __( 'Delete Unused Cookbook Ingredients, Categories, Cuisines or Tags', 'cookbook' ),
                'description' => __( 'Deletes only entries with no object assignments or stored ingredient references.', 'cookbook' ),
                'properties'  => [ 'type' => $type, 'ids' => $ids ],
                'required'    => [ 'type', 'ids' ],
                'callback'    => 'delete_terms',
                'readonly'    => false,
                'destructive' => true,
                'instructions' => __( 'Use to remove confirmed unused Cookbook entries. Assigned entries are refused; merge them first. This permanently deletes the listed entries.', 'cookbook' ),
            ],
        ];

        foreach ( $definitions as $name => $definition ) {
            wp_register_ability( 'cookbook/' . $name, [
                'label'               => $definition['label'],
                'description'         => $definition['description'],
                'category'            => 'cookbook',
                'input_schema'        => [ 'type' => 'object', 'properties' => $definition['properties'], 'required' => $definition['required'], 'additionalProperties' => false ],
                'output_schema'       => [ 'type' => 'object', 'additionalProperties' => true ],
                'execute_callback'    => [ $this, $definition['callback'] ],
                'permission_callback' => $definition['readonly'] ? [ $this, 'can_read' ] : [ $this, 'can_manage' ],
                'meta'                => [
                    'annotations' => [
                        'instructions' => $definition['instructions'],
                        'readonly' => $definition['readonly'],
                        'destructive' => $definition['destructive'],
                        'idempotent' => $definition['readonly'] || $name === 'update-recipe-term',
                    ],
                    'show_in_rest' => true,
                ],
            ] );
        }
    }

    public function can_read(): bool {
        return is_user_logged_in();
    }

    public function can_manage(): bool {
        return is_user_logged_in() && current_user_can( 'manage_categories' );
    }

    private function taxonomy( $input ) {
        $type = is_array( $input ) ? ( $input['type'] ?? '' ) : '';
        return self::TYPES[ $type ] ?? new \WP_Error( 'cookbook_invalid_type', __( 'Unknown Cookbook vocabulary.', 'cookbook' ) );
    }

    private function entry( $id, string $taxonomy ) {
        $term = get_term( absint( $id ), $taxonomy );
        return $term instanceof \WP_Term ? $term : new \WP_Error( 'cookbook_term_missing', __( 'Cookbook entry not found.', 'cookbook' ) );
    }

    private function payload( \WP_Term $term ): array {
        return [ 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'count' => (int) $term->count, 'parent_id' => (int) $term->parent ];
    }

    public function list_terms( $input = [] ) {
        $taxonomy = $this->taxonomy( $input );
        if ( is_wp_error( $taxonomy ) ) return $taxonomy;
        $limit = min( 500, max( 1, absint( $input['limit'] ?? 100 ) ) );
        $offset = absint( $input['offset'] ?? 0 );
        $args = [
            'taxonomy' => $taxonomy,
            'hide_empty' => isset( $input['include_unused'] ) && ! $input['include_unused'],
            'number' => $limit,
            'offset' => $offset,
            'orderby' => 'name',
            'hierarchical' => false,
        ];
        if ( ! empty( $input['search'] ) ) $args['search'] = sanitize_text_field( $input['search'] );
        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) return $terms;
        return [ 'type' => $input['type'], 'count' => count( $terms ), 'terms' => array_map( [ $this, 'payload' ], $terms ), 'next_offset' => count( $terms ) === $limit ? $offset + $limit : null ];
    }

    public function update_term( $input = [] ) {
        $taxonomy = $this->taxonomy( $input );
        if ( is_wp_error( $taxonomy ) ) return $taxonomy;
        $term = $this->entry( $input['id'] ?? 0, $taxonomy );
        if ( is_wp_error( $term ) ) return $term;
        $name = sanitize_text_field( $input['name'] ?? '' );
        if ( $name === '' ) return new \WP_Error( 'cookbook_empty_name', __( 'Name is required.', 'cookbook' ) );
        $args = [ 'name' => $name ];
        if ( isset( $input['slug'] ) ) {
            $args['slug'] = sanitize_title( $input['slug'] );
            if ( $args['slug'] === '' ) return new \WP_Error( 'cookbook_empty_slug', __( 'Slug cannot be empty.', 'cookbook' ) );
        }
        $result = wp_update_term( $term->term_id, $taxonomy, $args );
        if ( is_wp_error( $result ) ) return $result;
        return [ 'type' => $input['type'], 'term' => $this->payload( get_term( $term->term_id, $taxonomy ) ) ];
    }

    public function merge_terms( $input = [] ) {
        $taxonomy = $this->taxonomy( $input );
        if ( is_wp_error( $taxonomy ) ) return $taxonomy;
        $target = $this->entry( $input['target_id'] ?? 0, $taxonomy );
        if ( is_wp_error( $target ) ) return $target;
        $source_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $input['source_ids'] ?? [] ) ) ) ) );
        if ( ! $source_ids || in_array( (int) $target->term_id, $source_ids, true ) ) {
            return new \WP_Error( 'cookbook_invalid_merge', __( 'Choose source entries different from the target.', 'cookbook' ) );
        }
        foreach ( $source_ids as $source_id ) {
            $source = $this->entry( $source_id, $taxonomy );
            if ( is_wp_error( $source ) ) return $source;
            if ( $source->parent === $target->term_id ) continue;
            // A target below a source would create a hierarchy cycle when children move.
            if ( is_taxonomy_hierarchical( $taxonomy ) && term_is_ancestor_of( $source_id, $target->term_id, $taxonomy ) ) {
                return new \WP_Error( 'cookbook_merge_cycle', __( 'The target cannot be a descendant of a source.', 'cookbook' ) );
            }
        }
        $merged = [];
        foreach ( $source_ids as $source_id ) {
            $result = $this->merge_one( $source_id, (int) $target->term_id, $taxonomy );
            if ( is_wp_error( $result ) ) return $result;
            $merged[] = $source_id;
        }
        return [ 'type' => $input['type'], 'target' => $this->payload( get_term( $target->term_id, $taxonomy ) ), 'merged_ids' => $merged ];
    }

    private function merge_one( int $source_id, int $target_id, string $taxonomy ) {
        // Includes draft/private recipes and shopping items as well as published posts.
        $object_ids = get_objects_in_term( $source_id, $taxonomy );
        if ( is_wp_error( $object_ids ) ) return $object_ids;
        if ( $taxonomy === App::TAX_INGREDIENT ) {
            foreach ( $this->ingredient_reference_post_ids( $source_id ) as $post_id ) {
                $this->replace_ingredient_references( $post_id, $source_id, $target_id );
            }
        }
        foreach ( $object_ids as $object_id ) {
            $object_id = (int) $object_id;
            $added = wp_add_object_terms( $object_id, $target_id, $taxonomy );
            if ( is_wp_error( $added ) ) return $added;
            $removed = wp_remove_object_terms( $object_id, $source_id, $taxonomy );
            if ( is_wp_error( $removed ) ) return $removed;
        }
        if ( $taxonomy === App::TAX_INGREDIENT ) $this->replace_household_ingredient_ids( $source_id, $target_id );
        if ( is_taxonomy_hierarchical( $taxonomy ) ) {
            $children = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'parent' => $source_id, 'fields' => 'ids' ] );
            if ( is_wp_error( $children ) ) return $children;
            foreach ( $children as $child_id ) {
                if ( (int) $child_id === $target_id ) continue;
                $updated = wp_update_term( (int) $child_id, $taxonomy, [ 'parent' => $target_id ] );
                if ( is_wp_error( $updated ) ) return $updated;
            }
        }
        $deleted = wp_delete_term( $source_id, $taxonomy );
        if ( $deleted !== true ) return is_wp_error( $deleted ) ? $deleted : new \WP_Error( 'cookbook_delete_failed', __( 'Could not delete source entry.', 'cookbook' ) );
        return true;
    }

    private function replace_ingredient_references( int $object_id, int $source_id, int $target_id ): void {
        foreach ( [ App::META_INGREDIENTS, App::META_PARTS, App::META_SHOPPING_ITEMS ] as $key ) {
            $value = get_post_meta( $object_id, $key, true );
            if ( ! is_array( $value ) ) continue;
            $changed = $this->replace_nested_ids( $value, $source_id, $target_id );
            if ( $changed ) update_post_meta( $object_id, $key, $value );
        }
    }

    private function replace_nested_ids( array &$rows, int $source_id, int $target_id ): bool {
        $changed = false;
        foreach ( $rows as &$row ) {
            if ( ! is_array( $row ) ) continue;
            if ( isset( $row['term_id'] ) && (int) $row['term_id'] === $source_id ) {
                $row['term_id'] = $target_id;
                $changed = true;
            }
            if ( isset( $row['term_ids'] ) && is_array( $row['term_ids'] ) ) {
                $replaced = array_values( array_unique( array_map( function( $id ) use ( $source_id, $target_id ) {
                    return (int) $id === $source_id ? $target_id : (int) $id;
                }, $row['term_ids'] ) ) );
                if ( $replaced !== $row['term_ids'] ) { $row['term_ids'] = $replaced; $changed = true; }
            }
            if ( $this->replace_nested_ids( $row, $source_id, $target_id ) ) $changed = true;
        }
        unset( $row );
        return $changed;
    }

    private function ingredient_reference_post_ids( int $id ): array {
        global $wpdb;
        // Serialized ingredient rows cannot be searched reliably with a meta query.
        // This one-time scan is needed to catch references without taxonomy assignments.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time scan of serialized ingredient metadata.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s, %s)",
            App::META_INGREDIENTS,
            App::META_PARTS,
            App::META_SHOPPING_ITEMS
        ) );
        $ids = [];
        foreach ( $rows as $row ) {
            $value = maybe_unserialize( $row->meta_value );
            if ( is_array( $value ) && $this->contains_ingredient_id( $value, $id ) ) $ids[] = (int) $row->post_id;
        }
        return array_values( array_unique( $ids ) );
    }

    private function contains_ingredient_id( array $rows, int $id ): bool {
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            if ( isset( $row['term_id'] ) && (int) $row['term_id'] === $id ) return true;
            if ( isset( $row['term_ids'] ) && in_array( $id, array_map( 'absint', (array) $row['term_ids'] ), true ) ) return true;
            if ( $this->contains_ingredient_id( $row, $id ) ) return true;
        }
        return false;
    }

    public function delete_terms( $input = [] ) {
        $taxonomy = $this->taxonomy( $input );
        if ( is_wp_error( $taxonomy ) ) return $taxonomy;
        $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $input['ids'] ?? [] ) ) ) ) );
        if ( ! $ids ) return new \WP_Error( 'cookbook_empty_ids', __( 'Choose entries to delete.', 'cookbook' ) );
        // Validate the entire batch before deleting anything. Counts alone omit drafts.
        foreach ( $ids as $id ) {
            $term = $this->entry( $id, $taxonomy );
            if ( is_wp_error( $term ) ) return $term;
            $assigned = get_objects_in_term( $id, $taxonomy );
            if ( is_wp_error( $assigned ) ) return $assigned;
            if ( $assigned ) {
                /* translators: %d: Cookbook entry ID. */
                return new \WP_Error( 'cookbook_term_in_use', sprintf( __( 'Entry %d is assigned and cannot be deleted.', 'cookbook' ), $id ) );
            }
            if ( $taxonomy === App::TAX_INGREDIENT && $this->ingredient_reference_post_ids( $id ) ) {
                /* translators: %d: Ingredient term ID. */
                return new \WP_Error( 'cookbook_term_in_use', sprintf( __( 'Ingredient %d is referenced by saved items.', 'cookbook' ), $id ) );
            }
            if ( $taxonomy === App::TAX_INGREDIENT && $this->ingredient_id_in_preferences( $id ) ) {
                /* translators: %d: Ingredient term ID. */
                return new \WP_Error( 'cookbook_term_in_use', sprintf( __( 'Ingredient %d is saved in household preferences.', 'cookbook' ), $id ) );
            }
        }
        foreach ( $ids as $id ) {
            $deleted = wp_delete_term( $id, $taxonomy );
            if ( $deleted !== true ) return is_wp_error( $deleted ) ? $deleted : new \WP_Error( 'cookbook_delete_failed', __( 'Could not delete entry.', 'cookbook' ) );
        }
        return [ 'type' => $input['type'], 'deleted_ids' => $ids ];
    }

    private function ingredient_id_in_preferences( int $id ): bool {
        // User meta has an index on meta_key; narrow the lookup before reading each user's IDs.
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed meta key for a small set of household preferences.
        $user_ids = get_users( [ 'meta_key' => App::USER_HOUSEHOLD_INGREDIENTS, 'fields' => 'ID' ] );
        foreach ( $user_ids as $user_id ) {
            $ids = get_user_meta( (int) $user_id, App::USER_HOUSEHOLD_INGREDIENTS, true );
            if ( in_array( $id, array_map( 'absint', (array) $ids ), true ) ) return true;
        }
        return false;
    }

    private function replace_household_ingredient_ids( int $source_id, int $target_id ): void {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed meta key for a small set of household preferences.
        $user_ids = get_users( [ 'meta_key' => App::USER_HOUSEHOLD_INGREDIENTS, 'fields' => 'ID' ] );
        foreach ( $user_ids as $user_id ) {
            $ids = (array) get_user_meta( (int) $user_id, App::USER_HOUSEHOLD_INGREDIENTS, true );
            if ( ! in_array( $source_id, array_map( 'absint', $ids ), true ) ) continue;
            $ids = array_values( array_unique( array_map( function( $id ) use ( $source_id, $target_id ) {
                return (int) $id === $source_id ? $target_id : absint( $id );
            }, $ids ) ) );
            update_user_meta( (int) $user_id, App::USER_HOUSEHOLD_INGREDIENTS, $ids );
        }
    }
}
