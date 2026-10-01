<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Portable, additive backups of the signed-in user's cookbook. */
class BackupService extends AbstractService {
    const MAX_BYTES = 10485760;

    private function types(): array {
        return [ App::POST_TYPE, App::SHOPPING_LIST_POST_TYPE, App::WEEK_PLAN_POST_TYPE, App::COOKED_ENTRY_POST_TYPE ];
    }

    private function meta_keys(): array {
        return [
            App::META_SERVINGS, App::META_PREP, App::META_COOK, App::META_INGREDIENTS,
            App::META_INSTRUCTIONS, App::META_PARTS, App::META_SOURCE_URL, App::META_NOTES,
            App::META_SHOPPING_ITEMS, App::META_SHOPPING_ITEM_AMOUNT, App::META_SHOPPING_ITEM_UNIT,
            App::META_SHOPPING_ITEM_NOTES, App::META_SHOPPING_ITEM_SOURCE_RECIPE_ID,
            App::META_SHOPPING_ITEM_SOURCE_RECIPE_TITLE, App::META_SHOPPING_ITEM_SOURCE_RECIPES,
            App::META_SHOPPING_HOUSEHOLD_REMINDERS, App::META_WEEK_START, App::META_WEEK_MEALS,
            App::META_COOKED_RECIPE_ID, App::META_COOKED_DATE, App::META_COOKED_NOTE,
        ];
    }

    private function taxonomies(): array {
        return [ App::TAX_CATEGORY, App::TAX_CUISINE, App::TAX_TAG, App::TAX_INGREDIENT ];
    }

    private function authorize( string $action ): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) || ! current_user_can( 'publish_posts' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( $action );
    }

    public function export_data( int $user_id ): array {
        $data = [ 'format' => 'cook-app-backup', 'version' => 1, 'posts' => [], 'terms' => [] ];
        $posts = get_posts( [
            'post_type' => $this->types(), 'author' => $user_id, 'posts_per_page' => -1,
            'post_status' => [ 'publish', 'private', 'draft', 'pending', App::SHOPPING_ITEM_STATUS_CHECKED ],
            'orderby' => 'ID', 'order' => 'ASC',
        ] );
        foreach ( $posts as $post ) {
            $entry = [
                'id' => (int) $post->ID, 'type' => $post->post_type, 'title' => $post->post_title,
                'content' => $post->post_content, 'status' => $post->post_status,
                'parent' => (int) $post->post_parent, 'date' => $post->post_date,
                'meta' => [], 'terms' => [],
                'image_url' => get_the_post_thumbnail_url( $post->ID, 'full' ) ?: (string) get_post_meta( $post->ID, '_cookbook_backup_image_url', true ),
            ];
            foreach ( $this->meta_keys() as $key ) {
                if ( metadata_exists( 'post', $post->ID, $key ) ) {
                    $entry['meta'][ $key ] = get_post_meta( $post->ID, $key, true );
                }
            }
            foreach ( $this->taxonomies() as $taxonomy ) {
                $ids = wp_get_object_terms( $post->ID, $taxonomy, [ 'fields' => 'ids' ] );
                if ( ! is_wp_error( $ids ) ) {
                    $entry['terms'][ $taxonomy ] = array_map( 'intval', $ids );
                }
            }
            $data['posts'][] = $entry;
        }
        // Preserve ingredient groups, including ancestors and household-only terms.
        foreach ( $this->taxonomies() as $taxonomy ) {
            $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
            if ( is_wp_error( $terms ) ) {
                continue;
            }
            foreach ( $terms as $term ) {
                $data['terms'][] = [ 'id' => (int) $term->term_id, 'taxonomy' => $taxonomy, 'name' => $term->name, 'parent' => (int) $term->parent ];
            }
        }
        $data['preferences'] = [
            'units' => $this->services->preferences()->get_user_unit_preference( $user_id ),
            'household' => $this->services->preferences()->get_user_household_ingredient_ids( $user_id ),
        ];
        return $this->to_document( $data );
    }

    /** Standard recipes plus namespaced information for a lossless Cook App restore. */
    public function to_document( array $data ): array {
        $graph = [];
        foreach ( $data['posts'] as &$post ) {
            $this->validate_meta( $post['meta'] );
            if ( $post['type'] !== App::POST_TYPE ) {
                continue;
            }
            $node = $this->recipe_node( $post, $data['terms'] );
            $graph[] = $node;
            $post['recipe'] = $node['@id'];
            unset( $post['title'], $post['content'], $post['image_url'] );
        }
        unset( $post );
        $data['@context'] = [ '@vocab' => 'https://github.com/akirk/cook-app#' ];
        return [
            '@context' => [ '@vocab' => 'https://schema.org/', 'cookApp' => 'https://github.com/akirk/cook-app#' ],
            '@graph' => $graph,
            'cookApp:backup' => $data,
        ];
    }

    private function recipe_node( array $post, array $terms ): array {
        $meta = $post['meta'];
        $node = [
            '@type' => 'Recipe', '@id' => 'urn:cook-app:recipe:' . $post['id'],
            'name' => $post['title'], 'description' => $post['content'] ?? '',
            'recipeYield' => (string) ( $meta[ App::META_SERVINGS ] ?? 4 ),
            'prepTime' => 'PT' . (int) ( $meta[ App::META_PREP ] ?? 0 ) . 'M',
            'cookTime' => 'PT' . (int) ( $meta[ App::META_COOK ] ?? 0 ) . 'M',
            'recipeIngredient' => [], 'recipeInstructions' => [],
        ];
        if ( ! empty( $post['image_url'] ) ) {
            $node['image'] = $post['image_url'];
        }
        if ( ! empty( $meta[ App::META_SOURCE_URL ] ) ) {
            $node['url'] = $meta[ App::META_SOURCE_URL ];
        }
        $parts = $meta[ App::META_PARTS ] ?? [];
        $ingredients = [];
        $steps = [];
        foreach ( $parts as $part ) {
            $ingredients = array_merge( $ingredients, $part['ingredients'] ?? [] );
            if ( ! empty( $part['instructions'] ) ) {
                $steps[] = [ '@type' => 'HowToSection', 'name' => $part['title'] ?? '',
                    'itemListElement' => array_map( function( $step ) { return [ '@type' => 'HowToStep', 'text' => $step ]; }, $part['instructions'] ) ];
            }
        }
        foreach ( $ingredients ?: ( $meta[ App::META_INGREDIENTS ] ?? [] ) as $ingredient ) {
            $line = trim( ( $ingredient['amount'] ?? '' ) . ' ' . ( $ingredient['unit'] ?? '' ) . ' ' . ( $ingredient['name'] ?? '' ) );
            if ( ! empty( $ingredient['notes'] ) ) {
                $line .= ' (' . $ingredient['notes'] . ')';
            }
            $node['recipeIngredient'][] = $line;
        }
        $node['recipeInstructions'] = $steps ?: array_map( function( $step ) { return [ '@type' => 'HowToStep', 'text' => $step ]; }, $meta[ App::META_INSTRUCTIONS ] ?? [] );
        $term_index = array_column( $terms, 'name', 'id' );
        foreach ( [ App::TAX_CATEGORY => 'recipeCategory', App::TAX_CUISINE => 'recipeCuisine', App::TAX_TAG => 'keywords' ] as $taxonomy => $field ) {
            $node[ $field ] = array_values( array_filter( array_map( function( $id ) use ( $term_index ) { return $term_index[ $id ] ?? ''; }, $post['terms'][ $taxonomy ] ?? [] ) ) );
        }
        return $node;
    }

    /** Convert portable recipes into our validated restore records. */
    private function recipe_entry( array $recipe, int $id ): array {
        return [
            'id' => $id, 'type' => App::POST_TYPE, 'title' => $recipe['title'],
            'content' => $recipe['description'], 'image_url' => $recipe['image_url'],
            'parent' => 0, 'status' => 'publish', 'terms' => [],
            'meta' => [
                App::META_SERVINGS => $recipe['servings'], App::META_PREP => $recipe['prep_time'],
                App::META_COOK => $recipe['cook_time'], App::META_INGREDIENTS => $recipe['ingredients'],
                App::META_INSTRUCTIONS => $recipe['instructions'], App::META_PARTS => $recipe['parts'],
                App::META_SOURCE_URL => $recipe['source_url'],
            ],
        ];
    }

    public function handle_export(): void {
        $this->authorize( 'cookbook_export_backup' );
        try {
            $json = wp_json_encode( $this->export_data( get_current_user_id() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        } catch ( \Throwable $error ) {
            wp_die( esc_html( $error->getMessage() ) );
        }
        if ( false === $json || strlen( $json ) > self::MAX_BYTES ) {
            wp_die( esc_html__( 'Could not export the backup within the 10 MB limit.', 'cook-app' ) );
        }
        nocache_headers();
        header( 'Content-Type: application/ld+json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="cook-app-backup.json"' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- downloadable JSON, not HTML.
        echo $json;
        exit;
    }

    /** Validate the whole document before creating content. */
    public function decode_backup( string $json ): array {
        if ( strlen( $json ) > self::MAX_BYTES ) {
            throw new \InvalidArgumentException( 'Backup exceeds the 10 MB limit.' );
        }
        $document = json_decode( $json, true, 64 );
        if ( ! is_array( $document ) ) {
            throw new \InvalidArgumentException( 'Invalid JSON-LD file.' );
        }
        $recipes = ( new JsonLdRecipeParser() )->parse_all( $json );
        if ( array_key_exists( 'cookApp:backup', $document ) ) {
            $data = $document['cookApp:backup'];
            if ( ! is_array( $data ) || ( $data['format'] ?? '' ) !== 'cook-app-backup' || ( $data['version'] ?? null ) !== 1
                || ! isset( $data['posts'], $data['terms'] ) || ! is_array( $data['posts'] ) || ! is_array( $data['terms'] ) ) {
                throw new \InvalidArgumentException( 'Invalid Cook App backup or unsupported version.' );
            }
            $index = [];
            foreach ( $recipes as $recipe ) {
                if ( $recipe['@id'] === '' || isset( $index[ $recipe['@id'] ] ) ) {
                    throw new \InvalidArgumentException( 'Missing or duplicate recipe identifier.' );
                }
                $index[ $recipe['@id'] ] = $recipe;
            }
            $referenced = [];
            foreach ( $data['posts'] as &$post ) {
                if ( ! is_array( $post ) ) {
                    throw new \InvalidArgumentException( 'Invalid backup entry.' );
                }
                if ( ( $post['type'] ?? '' ) === App::POST_TYPE ) {
                    if ( ! is_string( $post['recipe'] ?? null ) || ! isset( $index[ $post['recipe'] ] ) || ! is_int( $post['id'] ?? null ) ) {
                        throw new \InvalidArgumentException( 'Missing recipe referenced by backup.' );
                    }
                    if ( isset( $referenced[ $post['recipe'] ] ) ) {
                        throw new \InvalidArgumentException( 'Duplicate recipe reference in backup.' );
                    }
                    $referenced[ $post['recipe'] ] = true;
                    $portable = $this->recipe_entry( $index[ $post['recipe'] ], $post['id'] );
                    $post['title'] = $portable['title'];
                    $post['content'] = $portable['content'];
                    $post['image_url'] = $portable['image_url'];
                }
            }
            unset( $post );
            if ( count( $referenced ) !== count( $index ) ) {
                throw new \InvalidArgumentException( 'Recipe missing from backup records.' );
            }
        } else {
            if ( ! $recipes ) {
                throw new \InvalidArgumentException( 'No schema.org recipes found in the file.' );
            }
            $data = [ 'posts' => [], 'terms' => [], 'portable' => true ];
            $term_ids = [];
            foreach ( $recipes as $recipe ) {
                $post = $this->recipe_entry( $recipe, count( $data['posts'] ) + 1 );
                foreach ( [ 'categories' => App::TAX_CATEGORY, 'cuisines' => App::TAX_CUISINE, 'tags' => App::TAX_TAG ] as $field => $taxonomy ) {
                    foreach ( $recipe[ $field ] as $name ) {
                        $key = $taxonomy . ':' . $name;
                        if ( ! isset( $term_ids[ $key ] ) ) {
                            $id = count( $data['terms'] ) + 1;
                            $term_ids[ $key ] = $id;
                            $data['terms'][] = [ 'id' => $id, 'taxonomy' => $taxonomy, 'name' => $name, 'parent' => 0 ];
                        }
                        $post['terms'][ $taxonomy ][] = $term_ids[ $key ];
                    }
                }
                $data['posts'][] = $post;
            }
        }
        $seen = [];
        foreach ( $data['posts'] as $post ) {
            if ( ! is_array( $post ) || ! isset( $post['id'], $post['type'], $post['title'], $post['meta'], $post['terms'] )
                || ! is_int( $post['id'] ) || $post['id'] < 1 || isset( $seen[ $post['id'] ] )
                || ! in_array( $post['type'], $this->types(), true ) || ! is_string( $post['title'] )
                || ! is_array( $post['meta'] ) || ! is_array( $post['terms'] ) ) {
                throw new \InvalidArgumentException( 'Invalid or duplicate post in backup.' );
            }
            foreach ( [ 'content', 'status', 'date', 'image_url' ] as $field ) {
                if ( isset( $post[ $field ] ) && ! is_string( $post[ $field ] ) ) {
                    throw new \InvalidArgumentException( 'Invalid post field in backup.' );
                }
            }
            if ( isset( $post['parent'] ) && ! is_int( $post['parent'] ) ) {
                throw new \InvalidArgumentException( 'Invalid parent in backup.' );
            }
            foreach ( $post['terms'] as $taxonomy => $ids ) {
                if ( ! in_array( $taxonomy, $this->taxonomies(), true ) || ! is_array( $ids ) || count( array_filter( $ids, 'is_int' ) ) !== count( $ids ) ) {
                    throw new \InvalidArgumentException( 'Invalid taxonomy assignments in backup.' );
                }
            }
            $this->validate_meta( $post['meta'] );
            if ( isset( $post['date'] ) && ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $post['date'] ) ) {
                throw new \InvalidArgumentException( 'Invalid date in backup.' );
            }
            $seen[ $post['id'] ] = true;
        }
        $seen = [];
        foreach ( $data['terms'] as $term ) {
            if ( ! is_array( $term ) || ! isset( $term['id'], $term['taxonomy'], $term['name'], $term['parent'] )
                || ! is_int( $term['id'] ) || $term['id'] < 1 || isset( $seen[ $term['id'] ] ) || ! is_int( $term['parent'] )
                || ! in_array( $term['taxonomy'], $this->taxonomies(), true ) || ! is_string( $term['name'] ) || trim( $term['name'] ) === '' ) {
                throw new \InvalidArgumentException( 'Invalid or duplicate term in backup.' );
            }
            $seen[ $term['id'] ] = true;
        }
        if ( isset( $data['preferences'] ) && ( ! is_array( $data['preferences'] )
            || ! in_array( $data['preferences']['units'] ?? '', [ 'metric', 'imperial' ], true )
            || ! is_array( $data['preferences']['household'] ?? null )
            || count( array_filter( $data['preferences']['household'], 'is_int' ) ) !== count( $data['preferences']['household'] ) ) ) {
            throw new \InvalidArgumentException( 'Invalid preferences in backup.' );
        }
        $this->validate_relationships( $data );
        return $data;
    }

    private function validate_meta( array $meta ): void {
        $arrays = [ App::META_INGREDIENTS, App::META_INSTRUCTIONS, App::META_PARTS,
            App::META_SHOPPING_ITEMS, App::META_SHOPPING_ITEM_SOURCE_RECIPES,
            App::META_SHOPPING_HOUSEHOLD_REMINDERS, App::META_WEEK_MEALS ];
        foreach ( $meta as $key => $value ) {
            if ( ! in_array( $key, $this->meta_keys(), true ) ) {
                throw new \InvalidArgumentException( 'Unknown metadata in backup.' );
            }
            if ( in_array( $key, $arrays, true ) ? ! is_array( $value ) : ! is_scalar( $value ) ) {
                throw new \InvalidArgumentException( 'Invalid metadata in backup.' );
            }
            $this->validate_nested_meta( $value, $key );
            if ( $key === App::META_WEEK_MEALS ) {
                foreach ( $value as $day ) {
                    if ( ! is_array( $day ) || count( array_filter( $day, 'is_int' ) ) !== count( $day ) ) {
                        throw new \InvalidArgumentException( 'Invalid meal plan in backup.' );
                    }
                }
            }
        }
    }

    private function validate_nested_meta( $value, string $key = '' ): void {
        $lists = [ App::META_INGREDIENTS, 'ingredients', App::META_PARTS,
            App::META_SHOPPING_ITEMS, App::META_SHOPPING_HOUSEHOLD_REMINDERS,
            App::META_SHOPPING_ITEM_SOURCE_RECIPES, 'source_recipes' ];
        if ( in_array( $key, $lists, true ) ) {
            if ( ! is_array( $value ) ) {
                throw new \InvalidArgumentException( 'Invalid rows in backup.' );
            }
            foreach ( $value as $row ) {
                if ( ! is_array( $row ) ) {
                    throw new \InvalidArgumentException( 'Invalid row in backup.' );
                }
            }
        }
        if ( in_array( $key, [ App::META_INSTRUCTIONS, 'instructions' ], true )
            && ( ! is_array( $value ) || count( array_filter( $value, 'is_string' ) ) !== count( $value ) ) ) {
            throw new \InvalidArgumentException( 'Invalid instructions in backup.' );
        }
        if ( $key === 'term_ids' && ( ! is_array( $value ) || count( array_filter( $value, 'is_int' ) ) !== count( $value ) ) ) {
            throw new \InvalidArgumentException( 'Invalid ingredient references in backup.' );
        }
        if ( in_array( $key, [ 'id', 'term_id', 'recipe_id', 'source_recipe_id', 'amount', 'unit', 'name', 'notes', 'title' ], true ) && ! is_scalar( $value ) ) {
            throw new \InvalidArgumentException( 'Invalid field in backup.' );
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $field => $item ) {
                $this->validate_nested_meta( $item, (string) $field );
            }
        }
    }

    private function validate_relationships( array $data ): void {
        $posts = array_column( $data['posts'], null, 'id' );
        $terms = array_column( $data['terms'], null, 'id' );
        foreach ( [ $posts, $terms ] as $index => $entries ) {
            foreach ( $entries as $entry ) {
                $path = [];
                $current = $entry;
                while ( ! empty( $current['parent'] ) && isset( $entries[ $current['parent'] ] ) ) {
                    $parent = $entries[ $current['parent'] ];
                    $field = $index === 0 ? 'type' : 'taxonomy';
                    if ( isset( $path[ $current['id'] ] ) || $current[ $field ] !== $parent[ $field ] ) {
                        throw new \InvalidArgumentException( 'Invalid or cyclic parent relationship in backup.' );
                    }
                    $path[ $current['id'] ] = true;
                    $current = $parent;
                }
            }
        }
        foreach ( $posts as $post ) {
            foreach ( $post['terms'] as $taxonomy => $ids ) {
                foreach ( $ids as $id ) {
                    if ( ! isset( $terms[ $id ] ) || $terms[ $id ]['taxonomy'] !== $taxonomy ) {
                        throw new \InvalidArgumentException( 'Missing or mismatched term in backup.' );
                    }
                }
            }
        }
    }

    /** Rewrite foreign IDs; missing references never point at local content. */
    public function remap_value( $value, array $posts, array $terms, string $key = '' ) {
        if ( is_array( $value ) ) {
            $clean = [];
            foreach ( $value as $field => $item ) {
                $this->validate_nested_meta( $value, $key );
            if ( $key === App::META_WEEK_MEALS ) {
                    $clean[ $field ] = $this->remap_value( $item, $posts, $terms, 'meal_day' );
                } elseif ( $key === 'meal_day' && is_scalar( $item ) ) {
                    $clean[ $field ] = $posts[ (int) $item ] ?? 0;
                } elseif ( $key === App::META_SHOPPING_ITEM_SOURCE_RECIPES || $key === 'source_recipes' ) {
                    $clean[ $field ] = $this->remap_value( $item, $posts, $terms, 'source_recipe' );
                } elseif ( $key === 'source_recipe' && $field === 'id' && is_scalar( $item ) ) {
                    $clean[ $field ] = $posts[ (int) $item ] ?? 0;
                } elseif ( $field === 'term_ids' && is_array( $item ) ) {
                    $clean[ $field ] = array_values( array_filter( array_map( function( $id ) use ( $terms ) { return $terms[ (int) $id ] ?? 0; }, $item ) ) );
                } else {
                    $clean[ $field ] = $this->remap_value( $item, $posts, $terms, (string) $field );
                }
            }
            return $clean;
        }
        if ( in_array( $key, [ App::META_COOKED_RECIPE_ID, App::META_SHOPPING_ITEM_SOURCE_RECIPE_ID, 'source_recipe_id', 'recipe_id' ], true ) ) {
            return $posts[ (int) $value ] ?? 0;
        }
        if ( $key === 'term_id' ) {
            return $terms[ (int) $value ] ?? 0;
        }
        return is_string( $value ) ? wp_kses_post( $value ) : $value;
    }

    public function restore_data( array $data, int $user_id ): int {
        $this->validate_relationships( $data );
        $term_map = [];
        $created_terms = [];
        $post_map = [];
        $recipe_map = [];
        try {
            foreach ( $data['terms'] as $term ) {
                $name = sanitize_text_field( $term['name'] );
                if ( $name === '' ) {
                    throw new \RuntimeException( 'A term name is empty after sanitization.' );
                }
                $existing = term_exists( $name, $term['taxonomy'] );
                $result = $existing ?: wp_insert_term( $name, $term['taxonomy'] );
                if ( is_wp_error( $result ) ) {
                    throw new \RuntimeException( $result->get_error_message() );
                }
                $id = (int) ( is_array( $result ) ? $result['term_id'] : $result );
                $term_map[ $term['id'] ] = $id;
                if ( ! $existing ) {
                    $created_terms[ $term['id'] ] = $term;
                }
            }
            foreach ( $created_terms as $old_id => $term ) {
                if ( $term['parent'] && isset( $term_map[ $term['parent'] ] ) ) {
                    $result = wp_update_term( $term_map[ $old_id ], $term['taxonomy'], [ 'parent' => $term_map[ $term['parent'] ] ] );
                    if ( is_wp_error( $result ) ) {
                        throw new \RuntimeException( $result->get_error_message() );
                    }
                }
            }
            foreach ( $data['posts'] as $post ) {
                $status = $post['status'] ?? 'publish';
                $allowed = [ 'publish', 'private', 'draft', 'pending' ];
                if ( $post['type'] === App::SHOPPING_LIST_POST_TYPE ) {
                    $allowed[] = App::SHOPPING_ITEM_STATUS_CHECKED;
                }
                $result = wp_insert_post( wp_slash( [
                    'post_type' => $post['type'], 'post_author' => $user_id,
                    'post_title' => sanitize_text_field( $post['title'] ),
                    'post_content' => wp_kses_post( $post['content'] ?? '' ),
                    'post_status' => in_array( $status, $allowed, true ) ? $status : 'publish',
                    'post_date' => $post['date'] ?? '',
                ] ), true );
                if ( is_wp_error( $result ) || ! $result ) {
                    throw new \RuntimeException( 'Could not create restored content.' );
                }
                $post_map[ $post['id'] ] = (int) $result;
                if ( $post['type'] === App::POST_TYPE ) {
                    $recipe_map[ $post['id'] ] = (int) $result;
                }
            }
            foreach ( $data['posts'] as $post ) {
                $id = $post_map[ $post['id'] ];
                $parent = $post_map[ $post['parent'] ?? 0 ] ?? 0;
                if ( $parent && $parent !== $id ) {
                    $result = wp_update_post( [ 'ID' => $id, 'post_parent' => $parent ], true );
                    if ( is_wp_error( $result ) ) {
                        throw new \RuntimeException( $result->get_error_message() );
                    }
                }
                foreach ( $post['meta'] as $key => $value ) {
                    if ( in_array( $key, $this->meta_keys(), true ) ) {
                        update_post_meta( $id, $key, wp_slash( $this->remap_value( $value, $recipe_map, $term_map, $key ) ) );
                    }
                }
                foreach ( $post['terms'] as $taxonomy => $ids ) {
                    $mapped = array_values( array_filter( array_map( function( $old_id ) use ( $term_map ) { return $term_map[ $old_id ] ?? 0; }, $ids ) ) );
                    $result = wp_set_object_terms( $id, $mapped, $taxonomy );
                    if ( is_wp_error( $result ) ) {
                        throw new \RuntimeException( $result->get_error_message() );
                    }
                }
                if ( ! empty( $data['portable'] ) && $post['type'] === App::POST_TYPE ) {
                    $meta = $post['meta'];
                    $this->services->recipes()->apply_parsed_payload( $id, [
                        'servings' => $meta[ App::META_SERVINGS ] ?? 4,
                        'prep_time' => $meta[ App::META_PREP ] ?? 0,
                        'cook_time' => $meta[ App::META_COOK ] ?? 0,
                        'ingredients' => $meta[ App::META_INGREDIENTS ] ?? [],
                        'instructions' => $meta[ App::META_INSTRUCTIONS ] ?? [],
                        'parts' => $meta[ App::META_PARTS ] ?? [],
                    ], (string) ( $meta[ App::META_SOURCE_URL ] ?? '' ), false );
                }
                // Keep the URL portable without initiating remote downloads on restore.
                if ( ! empty( $post['image_url'] ) && $post['type'] === App::POST_TYPE ) {
                    update_post_meta( $id, '_cookbook_backup_image_url', esc_url_raw( $post['image_url'] ) );
                }
            }
        } catch ( \Throwable $error ) {
            foreach ( array_reverse( $post_map ) as $id ) {
                wp_delete_post( $id, true );
            }
            foreach ( $created_terms as $old_id => $term ) {
                wp_delete_term( $term_map[ $old_id ], $term['taxonomy'] );
            }
            throw $error;
        }
        if ( isset( $data['preferences'] ) ) {
            update_user_meta( $user_id, App::USER_PREF_UNITS, $data['preferences']['units'] );
            $household = array_values( array_filter( array_map( function( $id ) use ( $term_map ) { return $term_map[ $id ] ?? 0; }, $data['preferences']['household'] ) ) );
            $this->services->preferences()->add_user_household_ingredient_terms( $household, $user_id );
        }
        delete_transient( App::HOME_INGREDIENT_STATS_TRANSIENT );
        return count( $post_map );
    }

    public function handle_restore(): void {
        $this->authorize( 'cookbook_restore_backup' );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- uploaded bytes are validated as a versioned backup below.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- authorize() verifies the nonce; file bytes are validated below.
        $file = $_FILES['backup'] ?? [];
        if ( ! isset( $file['error'], $file['tmp_name'] ) || $file['error'] !== UPLOAD_ERR_OK || ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            wp_die( esc_html__( 'Choose a valid backup file.', 'cook-app' ) );
        }
        $json = file_get_contents( $file['tmp_name'], false, null, 0, self::MAX_BYTES + 1 );
        try {
            if ( false === $json || strlen( $json ) > self::MAX_BYTES ) {
                throw new \RuntimeException( 'Could not read the backup.' );
            }
            $count = $this->restore_data( $this->decode_backup( $json ), get_current_user_id() );
        } catch ( \Throwable $error ) {
            wp_die( esc_html( $error->getMessage() ) );
        }
        wp_safe_redirect( add_query_arg( 'restored', $count, home_url( '/cook-app/settings' ) ) );
        exit;
    }
}
