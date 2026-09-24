<?php

namespace Cookbook;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RecipeService extends AbstractService {
    /**
     * Internal recipe search used by ability adapters and other app code.
     */
    public function search_recipes( array $filters = [] ): array {
        $search = isset( $filters['search'] ) ? sanitize_text_field( (string) $filters['search'] ) : '';

        $tax_query = [];
        foreach ( [
            'category'   => App::TAX_CATEGORY,
            'tag'        => App::TAX_TAG,
            'ingredient' => App::TAX_INGREDIENT,
        ] as $field => $taxonomy ) {
            $clause = $this->tax_query_clause( $filters[ $field ] ?? '', $taxonomy );
            if ( $clause ) {
                $tax_query[] = $clause;
            }
        }

        $has_filters = $search !== '' || ! empty( $tax_query );
        $limit       = isset( $filters['limit'] ) ? absint( $filters['limit'] ) : ( $has_filters ? 20 : 10 );
        $limit       = max( 1, min( 100, $limit ) );

        $args = [
            'post_type'      => App::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => $has_filters ? 'title' : 'date',
            'order'          => $has_filters ? 'ASC' : 'DESC',
        ];

        if ( $search !== '' ) {
            $args['s'] = $search;
        }

        if ( $tax_query ) {
            if ( count( $tax_query ) > 1 ) {
                $tax_query['relation'] = 'AND';
            }
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- ability filters by requested taxonomy terms.
            $args['tax_query'] = $tax_query;
        }

        return get_posts( $args );
    }

    /**
     * Find a recipe whose stored source URL matches the provided URL.
     */
    public function find_recipe_by_source_url( string $url ) {
        $lookup_url = esc_url_raw( trim( $url ) );
        if ( $lookup_url === '' ) {
            return null;
        }

        $lookup_key = $this->source_url_lookup_key( $lookup_url );
        if ( $lookup_key === '' ) {
            return null;
        }

        $recipes = get_posts( [
            'post_type'      => App::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        foreach ( $recipes as $recipe ) {
            $source_url = (string) get_post_meta( $recipe->ID, App::META_SOURCE_URL, true );
            if ( $source_url === $lookup_url || $this->source_url_lookup_key( $source_url ) === $lookup_key ) {
                return $recipe;
            }
        }

        return null;
    }

    /**
     * Normalize URLs enough to catch duplicate source links without caring about
     * scheme, fragments, trailing slashes, or common tracking parameters.
     */
    private function source_url_lookup_key( string $url ): string {
        $url = trim( html_entity_decode( $url, ENT_QUOTES ) );
        if ( $url === '' ) {
            return '';
        }

        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }

        $host = strtolower( (string) $parts['host'] );
        $host = preg_replace( '/^www\./', '', $host );
        $port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

        $path = isset( $parts['path'] ) ? rawurldecode( (string) $parts['path'] ) : '';
        $path = preg_replace( '~/+~', '/', '/' . ltrim( $path, '/' ) );
        $path = untrailingslashit( $path );
        if ( $path === '/' ) {
            $path = '';
        }

        $query_string = '';
        if ( ! empty( $parts['query'] ) ) {
            parse_str( (string) $parts['query'], $query );
            foreach ( array_keys( $query ) as $key ) {
                $normalized_key = strtolower( (string) $key );
                if (
                    strpos( $normalized_key, 'utm_' ) === 0
                    || in_array( $normalized_key, [ 'fbclid', 'gclid', 'mc_cid', 'mc_eid', 'igshid' ], true )
                ) {
                    unset( $query[ $key ] );
                }
            }
            ksort( $query );
            $query_string = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        }

        return $host . $port . $path . ( $query_string !== '' ? '?' . $query_string : '' );
    }

    /**
     * Internal structured recipe payload used by ability adapters.
     *
     * @return array|\WP_Error
     */
    public function get_recipe_payload( int $id, bool $include_details ) {
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || $post->post_type !== App::POST_TYPE ) {
            return new \WP_Error( 'cookbook_recipe_not_found', __( 'Recipe not found.', 'cook-app' ) );
        }

        return $this->recipe_payload( $post, $include_details );
    }

    public function create_recipe_from_ability_input( array $input, int $parent_id = 0, $source = null ) {
        $source = $source instanceof \WP_Post && $source->post_type === App::POST_TYPE ? $source : null;
        $source_id = $source ? (int) $source->ID : 0;

        $title = $this->ability_text_input(
            $input,
            'title',
            $source
                ? sprintf(
                    /* translators: %s: source recipe title */
                    __( '%s variation', 'cook-app' ),
                    get_the_title( $source )
                )
                : __( 'Untitled recipe', 'cook-app' )
        );
        $description = $this->ability_html_input( $input, 'description', $source ? $source->post_content : '' );
        $servings    = $this->ability_positive_int_input( $input, 'servings', $source_id ? (int) get_post_meta( $source_id, App::META_SERVINGS, true ) : 4, 1 );
        $prep        = $this->ability_positive_int_input( $input, 'prep_time', $source_id ? (int) get_post_meta( $source_id, App::META_PREP, true ) : 0, 0 );
        $cook        = $this->ability_positive_int_input( $input, 'cook_time', $source_id ? (int) get_post_meta( $source_id, App::META_COOK, true ) : 0, 0 );
        $source_url  = isset( $input['source_url'] )
            ? esc_url_raw( (string) $input['source_url'] )
            : ( $source_id ? (string) get_post_meta( $source_id, App::META_SOURCE_URL, true ) : '' );
        $notes       = $this->ability_html_input( $input, 'notes', $source_id ? (string) get_post_meta( $source_id, App::META_NOTES, true ) : '' );

        if ( $source && ! empty( $input['change_summary'] ) ) {
            $change_summary = sanitize_text_field( (string) $input['change_summary'] );
            if ( $change_summary !== '' ) {
                $notes = trim( $notes );
                $notes .= ( $notes === '' ? '' : "\n\n" ) . sprintf(
                    /* translators: %s: generated variation summary */
                    __( 'Variation notes: %s', 'cook-app' ),
                    $change_summary
                );
            }
        }

        $has_parts_input = array_key_exists( 'parts', $input ) && is_array( $input['parts'] );
        $parts           = $has_parts_input
            ? $this->normalize_recipe_parts_array( $input['parts'], false )
            : [];
        $part_ingredients = $has_parts_input ? $this->flatten_recipe_part_ingredients( $parts ) : [];
        $part_instructions = $has_parts_input ? $this->flatten_recipe_part_instructions( $parts ) : [];

        if ( $part_ingredients ) {
            $ingredients = $part_ingredients;
        } elseif ( isset( $input['ingredients'] ) && is_array( $input['ingredients'] ) ) {
            $ingredients = $this->sanitize_recipe_ingredient_rows( $input['ingredients'] );
        } else {
            $ingredients = $source_id ? (array) get_post_meta( $source_id, App::META_INGREDIENTS, true ) : [];
        }

        if ( $part_instructions ) {
            $instructions = $part_instructions;
        } elseif ( isset( $input['instructions'] ) && is_array( $input['instructions'] ) ) {
            $instructions = $this->sanitize_recipe_instruction_rows( $input['instructions'] );
        } else {
            $instructions = $source_id ? (array) get_post_meta( $source_id, App::META_INSTRUCTIONS, true ) : [];
        }

        if ( ! $has_parts_input && $source_id ) {
            $source_parts = (array) get_post_meta( $source_id, App::META_PARTS, true );
            if ( ! array_key_exists( 'ingredients', $input ) && ! array_key_exists( 'instructions', $input ) ) {
                $parts = $source_parts;
            } else {
                $parts = $this->apply_recipe_part_template( $source_parts, $ingredients, $instructions );
            }
        }

        $post_id = wp_insert_post( [
            'post_type'    => App::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $title !== '' ? $title : __( 'Untitled recipe', 'cook-app' ),
            'post_content' => $description,
            'post_author'  => get_current_user_id(),
            'post_parent'  => $parent_id,
        ], true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }
        $post_id = (int) $post_id;

        update_post_meta( $post_id, App::META_SERVINGS, $servings );
        update_post_meta( $post_id, App::META_PREP, $prep );
        update_post_meta( $post_id, App::META_COOK, $cook );
        $this->persist_ingredients( $post_id, $ingredients );
        update_post_meta( $post_id, App::META_INSTRUCTIONS, $instructions );
        $this->persist_recipe_parts( $post_id, $parts );
        update_post_meta( $post_id, App::META_SOURCE_URL, $source_url );
        update_post_meta( $post_id, App::META_NOTES, $notes );

        $this->set_or_copy_ability_terms( $post_id, $input, 'categories', App::TAX_CATEGORY, $source_id );
        $this->set_or_copy_ability_terms( $post_id, $input, 'cuisines', App::TAX_CUISINE, $source_id );
        $this->set_or_copy_ability_terms( $post_id, $input, 'tags', App::TAX_TAG, $source_id );

        $image_url = isset( $input['image_url'] ) ? esc_url_raw( (string) $input['image_url'] ) : '';
        if ( $image_url !== '' ) {
            $this->sideload_image_to_post( $post_id, $image_url );
        } elseif (
            $source_id
            && ( ! array_key_exists( 'copy_source_thumbnail', $input ) || ! empty( $input['copy_source_thumbnail'] ) )
            && has_post_thumbnail( $source_id )
        ) {
            set_post_thumbnail( $post_id, get_post_thumbnail_id( $source_id ) );
        }

        return $this->get_recipe_payload( $post_id, true );
    }

    public function update_recipe_from_ability_input( int $id, array $input ) {
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== App::POST_TYPE ) {
            return new \WP_Error( 'cookbook_recipe_not_found', __( 'Recipe not found.', 'cook-app' ) );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            return new \WP_Error( 'cookbook_recipe_not_allowed', __( 'Not allowed to edit this recipe.', 'cook-app' ) );
        }

        $postarr = [
            'ID'          => $id,
            'post_status' => 'publish',
        ];
        if ( array_key_exists( 'title', $input ) ) {
            $title = $this->ability_text_input( $input, 'title', get_the_title( $post ) );
            $postarr['post_title'] = $title !== '' ? $title : __( 'Untitled recipe', 'cook-app' );
        }
        if ( array_key_exists( 'description', $input ) ) {
            $postarr['post_content'] = $this->ability_html_input( $input, 'description', $post->post_content );
        }
        if ( array_key_exists( 'parent_id', $input ) ) {
            $postarr['post_parent'] = $this->sanitize_recipe_parent_id( absint( $input['parent_id'] ), $id );
        }
        if ( count( $postarr ) > 1 ) {
            $updated = wp_update_post( $postarr, true );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
        }

        if ( array_key_exists( 'servings', $input ) ) {
            update_post_meta( $id, App::META_SERVINGS, $this->ability_positive_int_input( $input, 'servings', 4, 1 ) );
        }
        if ( array_key_exists( 'prep_time', $input ) ) {
            update_post_meta( $id, App::META_PREP, $this->ability_positive_int_input( $input, 'prep_time', 0, 0 ) );
        }
        if ( array_key_exists( 'cook_time', $input ) ) {
            update_post_meta( $id, App::META_COOK, $this->ability_positive_int_input( $input, 'cook_time', 0, 0 ) );
        }
        if ( array_key_exists( 'parts', $input ) && is_array( $input['parts'] ) ) {
            $parts = $this->normalize_recipe_parts_array( $input['parts'], false );
            $part_ingredients = $this->flatten_recipe_part_ingredients( $parts );
            $part_instructions = $this->flatten_recipe_part_instructions( $parts );

            if ( $part_ingredients ) {
                $this->persist_ingredients( $id, $part_ingredients );
            } elseif ( array_key_exists( 'ingredients', $input ) && is_array( $input['ingredients'] ) ) {
                $this->persist_ingredients( $id, $this->sanitize_recipe_ingredient_rows( $input['ingredients'] ) );
            }

            if ( $part_instructions ) {
                update_post_meta( $id, App::META_INSTRUCTIONS, $part_instructions );
            } elseif ( array_key_exists( 'instructions', $input ) && is_array( $input['instructions'] ) ) {
                update_post_meta( $id, App::META_INSTRUCTIONS, $this->sanitize_recipe_instruction_rows( $input['instructions'] ) );
            }

            $this->persist_recipe_parts( $id, $parts );
        } else {
            $has_ingredients_input  = array_key_exists( 'ingredients', $input ) && is_array( $input['ingredients'] );
            $has_instructions_input = array_key_exists( 'instructions', $input ) && is_array( $input['instructions'] );

            if ( $has_ingredients_input || $has_instructions_input ) {
                $ingredients = $has_ingredients_input
                    ? $this->sanitize_recipe_ingredient_rows( $input['ingredients'] )
                    : (array) get_post_meta( $id, App::META_INGREDIENTS, true );
                $instructions = $has_instructions_input
                    ? $this->sanitize_recipe_instruction_rows( $input['instructions'] )
                    : (array) get_post_meta( $id, App::META_INSTRUCTIONS, true );

                if ( $has_ingredients_input ) {
                    $this->persist_ingredients( $id, $ingredients );
                }
                if ( $has_instructions_input ) {
                    update_post_meta( $id, App::META_INSTRUCTIONS, $instructions );
                }

                $parts = $this->apply_recipe_part_template( $this->get_recipe_parts( $id ), $ingredients, $instructions );
                if ( $parts ) {
                    $this->persist_recipe_parts( $id, $parts );
                } else {
                    delete_post_meta( $id, App::META_PARTS );
                }
            }
        }
        if ( array_key_exists( 'source_url', $input ) ) {
            update_post_meta( $id, App::META_SOURCE_URL, esc_url_raw( (string) $input['source_url'] ) );
        }
        if ( array_key_exists( 'notes', $input ) ) {
            update_post_meta( $id, App::META_NOTES, $this->ability_html_input( $input, 'notes', '' ) );
        }

        $this->set_or_copy_ability_terms( $id, $input, 'categories', App::TAX_CATEGORY );
        $this->set_or_copy_ability_terms( $id, $input, 'cuisines', App::TAX_CUISINE );
        $this->set_or_copy_ability_terms( $id, $input, 'tags', App::TAX_TAG );

        $image_url = isset( $input['image_url'] ) ? esc_url_raw( (string) $input['image_url'] ) : '';
        if ( $image_url !== '' ) {
            $this->sideload_image_to_post( $id, $image_url );
        }

        $this->save_recipe_revision_snapshot( $id );

        return $this->get_recipe_payload( $id, true );
    }

    private function ability_text_input( array $input, string $key, string $default = '' ): string {
        if ( ! array_key_exists( $key, $input ) || ! is_scalar( $input[ $key ] ) ) {
            return $default;
        }

        return sanitize_text_field( (string) $input[ $key ] );
    }

    private function ability_html_input( array $input, string $key, string $default = '' ): string {
        if ( ! array_key_exists( $key, $input ) || ! is_scalar( $input[ $key ] ) ) {
            return $default;
        }

        return wp_kses_post( (string) $input[ $key ] );
    }

    private function ability_positive_int_input( array $input, string $key, int $default, int $minimum ): int {
        if ( ! array_key_exists( $key, $input ) ) {
            return max( $minimum, $default );
        }

        return max( $minimum, absint( $input[ $key ] ) );
    }

    private function sanitize_recipe_ingredient_rows( array $rows ): array {
        $ingredients = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
            if ( $name === '' ) {
                continue;
            }

            $ingredients[] = [
                'amount' => isset( $row['amount'] ) ? sanitize_text_field( (string) $row['amount'] ) : '',
                'unit'   => isset( $row['unit'] ) ? sanitize_text_field( (string) $row['unit'] ) : '',
                'name'   => $name,
                'notes'  => isset( $row['notes'] ) ? sanitize_text_field( (string) $row['notes'] ) : '',
            ];
        }

        return $ingredients;
    }

    private function sanitize_recipe_instruction_rows( array $rows ): array {
        $instructions = [];
        foreach ( $rows as $step ) {
            if ( ! is_scalar( $step ) ) {
                continue;
            }

            $step = Importer::clean_step( wp_kses_post( (string) $step ) );
            if ( $step !== '' ) {
                $instructions[] = $step;
            }
        }

        return $instructions;
    }

    private function sanitize_submitted_ingredient_parts( array $parts ): array {
        $clean = [];
        foreach ( $parts as $part ) {
            if ( ! is_array( $part ) ) {
                continue;
            }

            $title = isset( $part['title'] ) && is_scalar( $part['title'] )
                ? sanitize_text_field( (string) $part['title'] )
                : '';
            $ingredients = isset( $part['ingredients'] ) && is_array( $part['ingredients'] )
                ? $this->sanitize_recipe_ingredient_rows( $part['ingredients'] )
                : [];

            if ( $ingredients ) {
                $clean[] = [
                    'title'        => $title,
                    'ingredients'  => $ingredients,
                    'instructions' => [],
                ];
            }
        }

        return $clean;
    }

    private function sanitize_submitted_instruction_parts( array $parts ): array {
        $clean = [];
        foreach ( $parts as $part ) {
            if ( ! is_array( $part ) ) {
                continue;
            }

            $title = isset( $part['title'] ) && is_scalar( $part['title'] )
                ? sanitize_text_field( (string) $part['title'] )
                : '';
            $instructions = isset( $part['instructions'] ) && is_array( $part['instructions'] )
                ? $this->sanitize_recipe_instruction_rows( $part['instructions'] )
                : [];

            if ( $instructions ) {
                $clean[] = [
                    'title'        => $title,
                    'ingredients'  => [],
                    'instructions' => $instructions,
                ];
            }
        }

        return $clean;
    }

    private function flatten_recipe_part_ingredients( array $parts ): array {
        $ingredients = [];
        foreach ( $parts as $part ) {
            if ( is_array( $part ) && ! empty( $part['ingredients'] ) && is_array( $part['ingredients'] ) ) {
                $ingredients = array_merge( $ingredients, $part['ingredients'] );
            }
        }
        return $ingredients;
    }

    private function flatten_recipe_part_instructions( array $parts ): array {
        $instructions = [];
        foreach ( $parts as $part ) {
            if ( is_array( $part ) && ! empty( $part['instructions'] ) && is_array( $part['instructions'] ) ) {
                $instructions = array_merge( $instructions, $part['instructions'] );
            }
        }
        return $instructions;
    }

    private function merge_submitted_recipe_parts( array $ingredient_parts, array $instruction_parts ): array {
        $parts = [];
        $index_by_title = [];
        $include_ingredients = $this->submitted_recipe_parts_are_structured( $ingredient_parts );
        $include_instructions = $this->submitted_recipe_parts_are_structured( $instruction_parts );

        $add_part = function( array $part ) use ( &$parts, &$index_by_title ) {
            $title = isset( $part['title'] ) ? (string) $part['title'] : '';
            $key   = $title !== '' ? sanitize_title( $title ) : '';
            if ( $key !== '' && isset( $index_by_title[ $key ] ) ) {
                $index = $index_by_title[ $key ];
                if ( ! empty( $part['ingredients'] ) ) {
                    $parts[ $index ]['ingredients'] = array_merge( $parts[ $index ]['ingredients'], $part['ingredients'] );
                }
                if ( ! empty( $part['instructions'] ) ) {
                    $parts[ $index ]['instructions'] = array_merge( $parts[ $index ]['instructions'], $part['instructions'] );
                }
                return;
            }

            $parts[] = [
                'title'        => $title,
                'ingredients'  => isset( $part['ingredients'] ) && is_array( $part['ingredients'] ) ? $part['ingredients'] : [],
                'instructions' => isset( $part['instructions'] ) && is_array( $part['instructions'] ) ? $part['instructions'] : [],
            ];
            if ( $key !== '' ) {
                $index_by_title[ $key ] = count( $parts ) - 1;
            }
        };

        if ( $include_ingredients ) {
            foreach ( $ingredient_parts as $part ) {
                $add_part( $part );
            }
        }
        if ( $include_instructions ) {
            foreach ( $instruction_parts as $part ) {
                $add_part( $part );
            }
        }

        return $parts;
    }

    private function apply_recipe_part_template( array $template_parts, array $ingredients, array $instructions ): array {
        $template_parts = $this->normalize_recipe_parts_array( $template_parts, false );
        if ( ! $template_parts ) {
            return [];
        }

        $template_ingredient_count = count( $this->flatten_recipe_part_ingredients( $template_parts ) );
        $template_instruction_count = count( $this->flatten_recipe_part_instructions( $template_parts ) );
        $use_ingredients = $template_ingredient_count > 0 && $template_ingredient_count === count( $ingredients );
        $use_instructions = $template_instruction_count > 0 && $template_instruction_count === count( $instructions );

        if ( ! $use_ingredients && ! $use_instructions ) {
            return [];
        }

        $parts = [];
        $ingredient_offset = 0;
        $instruction_offset = 0;
        foreach ( $template_parts as $part ) {
            $ingredient_count = isset( $part['ingredients'] ) && is_array( $part['ingredients'] )
                ? count( $part['ingredients'] )
                : 0;
            $instruction_count = isset( $part['instructions'] ) && is_array( $part['instructions'] )
                ? count( $part['instructions'] )
                : 0;

            $parts[] = [
                'title'        => isset( $part['title'] ) ? (string) $part['title'] : '',
                'ingredients'  => $use_ingredients ? array_slice( $ingredients, $ingredient_offset, $ingredient_count ) : [],
                'instructions' => $use_instructions ? array_slice( $instructions, $instruction_offset, $instruction_count ) : [],
            ];

            $ingredient_offset += $ingredient_count;
            $instruction_offset += $instruction_count;
        }

        return $parts;
    }

    private function submitted_recipe_parts_are_structured( array $parts ): bool {
        if ( count( $parts ) > 1 ) {
            return true;
        }

        foreach ( $parts as $part ) {
            if ( is_array( $part ) && ! empty( $part['title'] ) ) {
                return true;
            }
        }

        return false;
    }

    private function set_or_copy_ability_terms( int $post_id, array $input, string $field, string $taxonomy, int $source_id = 0 ): void {
        if ( array_key_exists( $field, $input ) ) {
            $values = is_array( $input[ $field ] ) ? $input[ $field ] : [];
            $values = array_filter( array_map( function( $value ) {
                return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
            }, $values ) );
            wp_set_object_terms( $post_id, $this->resolve_term_ids( $values, $taxonomy ), $taxonomy );
            return;
        }

        if ( ! $source_id ) {
            return;
        }

        $term_ids = wp_get_object_terms( $source_id, $taxonomy, [ 'fields' => 'ids' ] );
        if ( is_wp_error( $term_ids ) ) {
            return;
        }

        wp_set_object_terms( $post_id, array_map( 'intval', $term_ids ), $taxonomy );
    }

    private function tax_query_clause( $value, string $taxonomy ): array {
        $value = is_scalar( $value ) ? trim( (string) $value ) : '';
        if ( $value === '' ) {
            return [];
        }

        return [
            'taxonomy' => $taxonomy,
            'field'    => ctype_digit( $value ) ? 'term_id' : 'slug',
            'terms'    => ctype_digit( $value ) ? absint( $value ) : sanitize_title( $value ),
        ];
    }

    public function recipe_payload( $post, bool $include_details ): array {
        if ( ! $post instanceof \WP_Post ) {
            return [];
        }

        $id = (int) $post->ID;
        $payload = [
            'id'            => $id,
            'title'         => get_the_title( $post ),
            'url'           => home_url( '/' . $this->get_url_path() . '/recipe/' . $id ),
            'view_url'      => home_url( '/' . $this->get_url_path() . '/recipe/' . $id ),
            'edit_url'      => home_url( '/' . $this->get_url_path() . '/recipe/' . $id . '/edit' ),
            'variation_url' => add_query_arg( 'variation_of', $id, home_url( '/' . $this->get_url_path() . '/new' ) ),
            'parent_id'     => (int) $post->post_parent,
            'variation_root_id' => $this->get_recipe_variation_root_id( $id ),
            'servings'      => (int) get_post_meta( $id, App::META_SERVINGS, true ),
            'prep_time'     => (int) get_post_meta( $id, App::META_PREP, true ),
            'cook_time'     => (int) get_post_meta( $id, App::META_COOK, true ),
            'source_url'    => (string) get_post_meta( $id, App::META_SOURCE_URL, true ),
            'thumbnail_url' => get_the_post_thumbnail_url( $id, 'large' ) ?: '',
            'categories'    => $this->terms_payload( $id, App::TAX_CATEGORY ),
            'cuisines'      => $this->terms_payload( $id, App::TAX_CUISINE ),
            'tags'          => $this->terms_payload( $id, App::TAX_TAG ),
            'ingredients'   => (array) get_post_meta( $id, App::META_INGREDIENTS, true ),
        ];

        if ( $include_details ) {
            $payload['description']  = wp_strip_all_tags( $post->post_content );
            $payload['instructions'] = (array) get_post_meta( $id, App::META_INSTRUCTIONS, true );
            $payload['parts']        = $this->get_recipe_parts( $id );
            $payload['notes']        = wp_strip_all_tags( (string) get_post_meta( $id, App::META_NOTES, true ) );
            $payload['variation_family'] = array_map( function( $item ) {
                $variation = $item['post'] ?? null;
                if ( ! $variation instanceof \WP_Post ) {
                    return [];
                }

                return [
                    'id'        => (int) $variation->ID,
                    'title'     => get_the_title( $variation ),
                    'url'       => home_url( '/' . $this->get_url_path() . '/recipe/' . $variation->ID ),
                    'view_url'  => home_url( '/' . $this->get_url_path() . '/recipe/' . $variation->ID ),
                    'parent_id' => (int) $variation->post_parent,
                    'depth'     => isset( $item['depth'] ) ? (int) $item['depth'] : 0,
                ];
            }, $this->get_recipe_variation_family( $id ) );
        }

        return $payload;
    }

    private function terms_payload( int $post_id, string $taxonomy ): array {
        $terms = wp_get_object_terms( $post_id, $taxonomy );
        if ( is_wp_error( $terms ) || ! $terms ) {
            return [];
        }

        return array_map( function( $term ) {
            return [
                'id'   => (int) $term->term_id,
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
            ];
        }, $terms );
    }

    public function get_recipe_variation_parent( int $recipe_id ): ?\WP_Post {
        $post = $recipe_id ? get_post( $recipe_id ) : null;
        if ( ! $post || $post->post_type !== App::POST_TYPE || ! $post->post_parent ) {
            return null;
        }

        $parent = get_post( (int) $post->post_parent );
        if ( ! $parent || $parent->post_type !== App::POST_TYPE ) {
            return null;
        }

        return $parent;
    }

    public function get_recipe_variation_root_id( int $recipe_id ): int {
        $post = $recipe_id ? get_post( $recipe_id ) : null;
        if ( ! $post || $post->post_type !== App::POST_TYPE ) {
            return 0;
        }

        $root_id = (int) $post->ID;
        $seen    = [];
        while ( $post && $post->post_type === App::POST_TYPE && $post->post_parent ) {
            $parent_id = (int) $post->post_parent;
            if ( isset( $seen[ $parent_id ] ) ) {
                break;
            }
            $seen[ $parent_id ] = true;

            $parent = get_post( $parent_id );
            if ( ! $parent || $parent->post_type !== App::POST_TYPE ) {
                break;
            }

            $root_id = (int) $parent->ID;
            $post    = $parent;
        }

        return $root_id;
    }

    public function get_recipe_variation_family( int $recipe_id ): array {
        $root_id = $this->get_recipe_variation_root_id( $recipe_id );
        $root    = $root_id ? get_post( $root_id ) : null;
        if ( ! $root || $root->post_type !== App::POST_TYPE ) {
            return [];
        }

        $family = [
            [
                'post'  => $root,
                'depth' => 0,
            ],
        ];
        $seen = [ $root_id => true ];
        $this->collect_recipe_variation_descendants( $root_id, 1, $family, $seen );

        return $family;
    }

    public function recipe_is_descendant_of( int $recipe_id, int $ancestor_id ): bool {
        if ( ! $recipe_id || ! $ancestor_id || $recipe_id === $ancestor_id ) {
            return false;
        }

        $post = get_post( $recipe_id );
        $seen = [];
        while ( $post && $post->post_type === App::POST_TYPE && $post->post_parent ) {
            $parent_id = (int) $post->post_parent;
            if ( $parent_id === $ancestor_id ) {
                return true;
            }
            if ( isset( $seen[ $parent_id ] ) ) {
                break;
            }
            $seen[ $parent_id ] = true;
            $post = get_post( $parent_id );
        }

        return false;
    }

    private function collect_recipe_variation_descendants(
        int $parent_id,
        int $depth,
        array &$family,
        array &$seen
    ): void {
        $children = get_posts( [
            'post_type'      => App::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'post_parent'    => $parent_id,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        foreach ( $children as $child ) {
            if ( isset( $seen[ $child->ID ] ) ) {
                continue;
            }
            $seen[ $child->ID ] = true;
            $family[] = [
                'post'  => $child,
                'depth' => $depth,
            ];
            $this->collect_recipe_variation_descendants( (int) $child->ID, $depth + 1, $family, $seen );
        }
    }

    public function handle_save(): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( 'cookbook_save' );

        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $description = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '';
        $servings = isset( $_POST['servings'] ) ? max( 1, absint( $_POST['servings'] ) ) : 4;
        $prep = isset( $_POST['prep_time'] ) ? max( 0, absint( $_POST['prep_time'] ) ) : 0;
        $cook = isset( $_POST['cook_time'] ) ? max( 0, absint( $_POST['cook_time'] ) ) : 0;
        $source_url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
        $image_url = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';
        $notes = isset( $_POST['notes'] ) ? wp_kses_post( wp_unslash( $_POST['notes'] ) ) : '';
        $parent_id = isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0;
        $parent_id = $this->sanitize_recipe_parent_id( $parent_id, $id );

        $ingredient_parts = [];
        if ( isset( $_POST['ingredient_parts'] ) && is_array( $_POST['ingredient_parts'] ) ) {
            $ingredient_parts = $this->sanitize_submitted_ingredient_parts(
                wp_unslash( $_POST['ingredient_parts'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            );
            $ingredients = $this->flatten_recipe_part_ingredients( $ingredient_parts );
        } else {
            $ingredients = [];
            if ( isset( $_POST['ingredients'] ) && is_array( $_POST['ingredients'] ) ) {
                $ingredients = $this->sanitize_recipe_ingredient_rows(
                    wp_unslash( $_POST['ingredients'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                );
            }
        }

        $instruction_parts = [];
        if ( isset( $_POST['instruction_parts'] ) && is_array( $_POST['instruction_parts'] ) ) {
            $instruction_parts = $this->sanitize_submitted_instruction_parts(
                wp_unslash( $_POST['instruction_parts'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            );
            $instructions = $this->flatten_recipe_part_instructions( $instruction_parts );
        } else {
            $instructions = [];
            if ( isset( $_POST['instructions'] ) && is_array( $_POST['instructions'] ) ) {
                $instructions = $this->sanitize_recipe_instruction_rows(
                    wp_unslash( $_POST['instructions'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                );
            }
        }
        $parts = $this->merge_submitted_recipe_parts( $ingredient_parts, $instruction_parts );

        $postarr = [
            'post_type'    => App::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $title !== '' ? $title : __( 'Untitled recipe', 'cook-app' ),
            'post_content' => $description,
            'post_author'  => get_current_user_id(),
            'post_parent'  => $parent_id,
        ];
        if ( $id ) {
            $existing = get_post( $id );
            if ( ! $existing || $existing->post_type !== App::POST_TYPE ) {
                wp_die( esc_html__( 'Recipe not found.', 'cook-app' ), 404 );
            }
            $postarr['ID'] = $id;
            $post_id = wp_update_post( $postarr, true );
        } else {
            $post_id = wp_insert_post( $postarr, true );
        }
        if ( is_wp_error( $post_id ) ) {
            wp_die( esc_html( $post_id->get_error_message() ) );
        }

        update_post_meta( $post_id, App::META_SERVINGS, $servings );
        update_post_meta( $post_id, App::META_PREP, $prep );
        update_post_meta( $post_id, App::META_COOK, $cook );
        $this->persist_ingredients( $post_id, $ingredients );
        update_post_meta( $post_id, App::META_INSTRUCTIONS, $instructions );
        $this->persist_recipe_parts( $post_id, $parts );
        update_post_meta( $post_id, App::META_SOURCE_URL, $source_url );
        update_post_meta( $post_id, App::META_NOTES, $notes );

        $has_uploaded_image = ! empty( $_FILES['image']['name'] ) && empty( $_FILES['image']['error'] );
        $copy_thumbnail_from = isset( $_POST['copy_thumbnail_from'] ) ? absint( $_POST['copy_thumbnail_from'] ) : 0;
        if ( ! $id && $copy_thumbnail_from && $image_url === '' && ! $has_uploaded_image && empty( $_POST['remove_image'] ) ) {
            $copy_source = get_post( $copy_thumbnail_from );
            if ( $copy_source && $copy_source->post_type === App::POST_TYPE && has_post_thumbnail( $copy_source->ID ) ) {
                set_post_thumbnail( $post_id, get_post_thumbnail_id( $copy_source->ID ) );
            }
        }
        if ( ! empty( $_POST['remove_image'] ) ) {
            delete_post_thumbnail( $post_id );
        }
        if ( $image_url !== '' ) {
            $this->sideload_image_to_post( $post_id, $image_url );
        }
        if ( $has_uploaded_image ) {
            $this->attach_uploaded_image_as_thumbnail( $post_id );
        }

        if ( isset( $_POST['categories'] ) ) {
            $cats = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['categories'] ) );
            wp_set_object_terms( $post_id, $this->resolve_term_ids( $cats, App::TAX_CATEGORY ), App::TAX_CATEGORY );
        }
        if ( isset( $_POST['cuisines'] ) ) {
            $cui = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['cuisines'] ) );
            wp_set_object_terms( $post_id, $this->resolve_term_ids( $cui, App::TAX_CUISINE ), App::TAX_CUISINE );
        }
        if ( isset( $_POST['tags'] ) ) {
            $tags = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['tags'] ) ) ) ) );
            wp_set_object_terms( $post_id, $tags, App::TAX_TAG );
        }

        if ( $id ) {
            $this->save_recipe_revision_snapshot( $post_id );
        }

        wp_safe_redirect( home_url( '/' . $this->get_url_path() . '/recipe/' . $post_id ) );
        exit;
    }

    public function sanitize_recipe_parent_id( int $parent_id, int $post_id = 0 ): int {
        if ( ! $parent_id ) {
            return 0;
        }

        $parent = get_post( $parent_id );
        if ( ! $parent || $parent->post_type !== App::POST_TYPE ) {
            return 0;
        }
        if ( $post_id && $parent_id === $post_id ) {
            return 0;
        }
        if ( $post_id && $this->recipe_is_descendant_of( $parent_id, $post_id ) ) {
            return 0;
        }

        return $parent_id;
    }

    /**
     * Sideload an external image URL and set it as the post's featured image.
     * Returns the attachment ID on success.
     */
    private function sideload_image_to_post( int $post_id, string $url ): ?int {
        if ( $url === '' || ! filter_var( $url, FILTER_VALIDATE_URL ) ) return null;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url, 20 );
        if ( is_wp_error( $tmp ) ) return null;

        $name = basename( wp_parse_url( $url, PHP_URL_PATH ) ?: 'recipe-image' );
        $name = sanitize_file_name( $name ) ?: 'recipe-image';
        if ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $name ) ) {
            $name .= '.jpg';
        }

        $file_array = [ 'name' => $name, 'tmp_name' => $tmp ];
        $attachment_id = media_handle_sideload( $file_array, $post_id );
        if ( is_wp_error( $attachment_id ) ) {
            wp_delete_file( $tmp );
            return null;
        }
        set_post_thumbnail( $post_id, $attachment_id );
        return (int) $attachment_id;
    }

    private function attach_uploaded_image_as_thumbnail( int $post_id ): ?int {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment_id = media_handle_upload( 'image', $post_id );
        if ( is_wp_error( $attachment_id ) ) return null;
        set_post_thumbnail( $post_id, $attachment_id );
        return (int) $attachment_id;
    }

    /**
     * Save ingredient rows and sync the recipe_ingredient taxonomy.
     *
     * The original typed name is kept on the row for display; lookup is by
     * slug (sanitize_title folds case + diacritics) so "Tomatoes" and
     * "tomatoes" share a term and "Knödelbrot" stays "Knödelbrot".
     */
    private function persist_ingredients( int $post_id, array $rows ): void {
        $term_ids = [];
        $clean    = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $name = isset( $row['name'] ) ? (string) $row['name'] : '';
            if ( $name === '' ) continue;
            $term_id = $this->resolve_ingredient_term( $name );
            $clean[] = [
                'amount'  => isset( $row['amount'] ) ? (string) $row['amount'] : '',
                'unit'    => isset( $row['unit'] ) ? (string) $row['unit'] : '',
                'name'    => $name,
                'notes'   => isset( $row['notes'] ) ? (string) $row['notes'] : '',
                'term_id' => $term_id ?: 0,
            ];
            if ( $term_id ) {
                $term_ids[ $term_id ] = true;
            }
        }
        update_post_meta( $post_id, App::META_INGREDIENTS, $clean );
        wp_set_object_terms( $post_id, array_keys( $term_ids ), App::TAX_INGREDIENT, false );
    }

    public function get_recipe_parts( int $post_id ): array {
        $parts = get_post_meta( $post_id, App::META_PARTS, true );
        return is_array( $parts ) ? $this->normalize_recipe_parts_array( $parts, false ) : [];
    }

    private function persist_recipe_parts( int $post_id, array $parts ): void {
        $clean = $this->normalize_recipe_parts_array( $parts, true );
        if ( ! $clean ) {
            delete_post_meta( $post_id, App::META_PARTS );
            return;
        }

        update_post_meta( $post_id, App::META_PARTS, $clean );
    }

    private function normalize_recipe_parts_array( array $parts, bool $resolve_terms ): array {
        $clean = [];
        foreach ( $parts as $part ) {
            if ( ! is_array( $part ) ) {
                continue;
            }

            $title = isset( $part['title'] ) && is_scalar( $part['title'] )
                ? sanitize_text_field( (string) $part['title'] )
                : '';

            $ingredients = [];
            foreach ( (array) ( $part['ingredients'] ?? [] ) as $row ) {
                if ( ! is_array( $row ) ) {
                    continue;
                }

                $name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
                if ( $name === '' ) {
                    continue;
                }

                $term_id = isset( $row['term_id'] ) ? absint( $row['term_id'] ) : 0;
                if ( $resolve_terms ) {
                    $term_id = $this->resolve_ingredient_term( $name ) ?: 0;
                }

                $ingredients[] = [
                    'amount'  => isset( $row['amount'] ) ? sanitize_text_field( (string) $row['amount'] ) : '',
                    'unit'    => isset( $row['unit'] ) ? sanitize_text_field( (string) $row['unit'] ) : '',
                    'name'    => $name,
                    'notes'   => isset( $row['notes'] ) ? sanitize_text_field( (string) $row['notes'] ) : '',
                    'term_id' => $term_id,
                ];
            }

            $instructions = [];
            foreach ( (array) ( $part['instructions'] ?? [] ) as $step ) {
                if ( ! is_scalar( $step ) ) {
                    continue;
                }

                $step = Importer::clean_step( wp_kses_post( (string) $step ) );
                if ( $step !== '' ) {
                    $instructions[] = $step;
                }
            }

            if ( $ingredients || $instructions ) {
                $clean[] = [
                    'title'        => $title,
                    'ingredients'  => $ingredients,
                    'instructions' => $instructions,
                ];
            }
        }

        return $clean;
    }

    private function save_recipe_revision_snapshot( int $post_id ): void {
        if ( function_exists( 'wp_save_post_revision' ) ) {
            wp_save_post_revision( $post_id );
        }
    }

    /**
     * Find or create the recipe_ingredient term for a free-form name.
     *
     * Dedup by slug (sanitize_title): "Tomatoes"/"tomatoes" collapse, "Knödelbrot"
     * keeps its umlauts as the display name while sharing slug "knodelbrot" with
     * any future "knödelbrot" entry. No automatic singularization or stopword
     * stripping — grouping similar ingredients is a manual step via the
     * hierarchical taxonomy's parent/child UI.
     */
    public function resolve_ingredient_term( string $name ): ?int {
        $name = trim( $name );
        if ( $name === '' ) return null;
        $slug = sanitize_title( $name );
        if ( $slug === '' ) return null;

        $term = get_term_by( 'slug', $slug, App::TAX_INGREDIENT );
        if ( $term && ! is_wp_error( $term ) ) {
            return (int) $term->term_id;
        }
        $created = wp_insert_term( $name, App::TAX_INGREDIENT, [ 'slug' => $slug ] );
        if ( is_wp_error( $created ) ) {
            // Race: another request just created it, or slug collision with a different name.
            $term = get_term_by( 'slug', $slug, App::TAX_INGREDIENT );
            return $term ? (int) $term->term_id : null;
        }
        return isset( $created['term_id'] ) ? (int) $created['term_id'] : null;
    }

    private function resolve_term_ids( array $values, string $taxonomy ): array {
        $ids = [];
        $expanded = [];
        foreach ( $values as $value ) {
            if ( $value === '' ) continue;
            if ( ctype_digit( $value ) ) {
                $expanded[] = $value;
                continue;
            }
            foreach ( array_map( 'trim', explode( ',', $value ) ) as $part ) {
                if ( $part !== '' ) $expanded[] = $part;
            }
        }
        foreach ( $expanded as $value ) {
            if ( ctype_digit( $value ) ) {
                $ids[] = (int) $value;
                continue;
            }
            $term = term_exists( $value, $taxonomy );
            if ( ! $term ) {
                $term = wp_insert_term( $value, $taxonomy );
            }
            if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
                $ids[] = (int) $term['term_id'];
            }
        }
        return array_values( array_unique( $ids ) );
    }

    public function handle_delete(): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'delete_posts' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( 'cookbook_delete' );
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || $post->post_type !== App::POST_TYPE ) {
            wp_die( esc_html__( 'Recipe not found.', 'cook-app' ), 404 );
        }
        wp_trash_post( $id );
        wp_safe_redirect( home_url( '/' . $this->get_url_path() . '/' ) );
        exit;
    }

    public function handle_settings(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( 'cookbook_settings' );
        $pref = isset( $_POST['unit_preference'] ) ? sanitize_text_field( wp_unslash( $_POST['unit_preference'] ) ) : 'metric';
        if ( ! in_array( $pref, [ 'metric', 'imperial' ], true ) ) {
            $pref = 'metric';
        }
        update_user_meta( get_current_user_id(), App::USER_PREF_UNITS, $pref );
        wp_safe_redirect( home_url( '/' . $this->get_url_path() . '/settings?saved=1' ) );
        exit;
    }

    public function handle_replace_ingredient(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }

        $id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $index = isset( $_POST['ingredient_index'] ) ? absint( $_POST['ingredient_index'] ) : 0;
        check_admin_referer( 'cookbook_replace_ingredient_' . $id . '_' . $index );

        $post = $id ? get_post( $id ) : null;
        if ( ! $post || $post->post_type !== App::POST_TYPE ) {
            wp_die( esc_html__( 'Recipe not found.', 'cook-app' ), 404 );
        }
        if ( ! current_user_can( 'edit_post', $id ) ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }

        $ingredients = (array) get_post_meta( $id, App::META_INGREDIENTS, true );
        if ( ! isset( $ingredients[ $index ] ) || ! is_array( $ingredients[ $index ] ) ) {
            wp_die( esc_html__( 'Ingredient not found.', 'cook-app' ), 404 );
        }

        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        if ( $name === '' ) {
            wp_die( esc_html__( 'Replacement ingredient is required.', 'cook-app' ), 400 );
        }

        $replacement = [
            'amount' => isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '',
            'unit'   => isset( $_POST['unit'] ) ? sanitize_text_field( wp_unslash( $_POST['unit'] ) ) : '',
            'name'   => $name,
            'notes'  => isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '',
        ];
        $ingredients[ $index ] = $replacement;

        $this->save_recipe_revision_snapshot( $id );
        $this->persist_ingredients( $id, $ingredients );
        $parts = $this->get_recipe_parts( $id );
        if ( $parts ) {
            $part_index = 0;
            foreach ( $parts as &$part ) {
                foreach ( $part['ingredients'] as &$part_ingredient ) {
                    if ( $part_index === $index ) {
                        $part_ingredient = $replacement;
                        break 2;
                    }
                    $part_index++;
                }
            }
            unset( $part, $part_ingredient );
            $this->persist_recipe_parts( $id, $parts );
        }
        $this->save_recipe_revision_snapshot( $id );

        wp_safe_redirect( add_query_arg( 'replaced', '1', home_url( '/' . $this->get_url_path() . '/recipe/' . $id . '#ingredients' ) ) );
        exit;
    }

    /**
     * Write the parts of a parsed-recipe payload that we store on the post.
     *
     * @param bool $only_if_present  When true, skip writes for fields the parser
     *                               left empty — used by refetch so a partial
     *                               parse doesn't wipe existing data.
     */
    public function apply_parsed_payload( int $post_id, array $parsed, string $url, bool $only_if_present ): void {
        if ( ! $only_if_present || ! empty( $parsed['servings'] ) ) {
            update_post_meta( $post_id, App::META_SERVINGS, (int) ( $parsed['servings'] ?? 4 ) );
        }
        if ( ! $only_if_present || ! empty( $parsed['prep_time'] ) ) {
            update_post_meta( $post_id, App::META_PREP, (int) ( $parsed['prep_time'] ?? 0 ) );
        }
        if ( ! $only_if_present || ! empty( $parsed['cook_time'] ) ) {
            update_post_meta( $post_id, App::META_COOK, (int) ( $parsed['cook_time'] ?? 0 ) );
        }
        if ( ! $only_if_present || ! empty( $parsed['ingredients'] ) ) {
            $this->persist_ingredients( $post_id, $parsed['ingredients'] ?? [] );
        }
        if ( ! $only_if_present || ! empty( $parsed['instructions'] ) ) {
            update_post_meta( $post_id, App::META_INSTRUCTIONS, $parsed['instructions'] ?? [] );
        }
        if ( ! $only_if_present || ! empty( $parsed['parts'] ) ) {
            $this->persist_recipe_parts( $post_id, is_array( $parsed['parts'] ?? null ) ? $parsed['parts'] : [] );
        }
        if ( $url !== '' ) {
            update_post_meta( $post_id, App::META_SOURCE_URL, $url );
        }
        if ( ! empty( $parsed['image_url'] ) ) {
            $this->sideload_image_to_post( $post_id, (string) $parsed['image_url'] );
        }
    }
}
