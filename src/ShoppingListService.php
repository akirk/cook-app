<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ShoppingListService extends AbstractService {
    public function get_current_user_shopping_list_id( bool $create = true ): int {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return 0;
        }

        $ids = get_posts( [
            'post_type'      => App::SHOPPING_LIST_POST_TYPE,
            'post_status'    => [ 'publish', 'private', 'draft' ],
            'post_parent'    => 0,
            'author'         => $user_id,
            'posts_per_page' => 1,
            'orderby'        => 'date',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ] );
        if ( $ids ) {
            return (int) $ids[0];
        }
        if ( ! $create ) {
            return 0;
        }

        $user  = get_userdata( $user_id );
        $title = $user
            ? sprintf(
                /* translators: %s: user display name */
                __( "%s's shopping list", 'cook-app' ),
                $user->display_name
            )
            : __( 'Shopping list', 'cook-app' );
        $post_id = wp_insert_post( [
            'post_type'   => App::SHOPPING_LIST_POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_author' => $user_id,
        ], true );
        if ( is_wp_error( $post_id ) ) {
            return 0;
        }
        update_post_meta( (int) $post_id, App::META_SHOPPING_ITEMS, [] );
        return (int) $post_id;
    }

    public function get_shopping_items( int $list_id ): array {
        if ( ! $list_id ) {
            return [];
        }
        $this->migrate_legacy_shopping_items( $list_id );
        $items = [];
        foreach ( $this->get_shopping_item_posts( $list_id ) as $post ) {
            $items[] = $this->shopping_item_from_post( $post );
        }
        return $this->sort_shopping_items( $items );
    }

    public function get_shopping_household_reminders( int $list_id ): array {
        if ( ! $list_id ) {
            return [];
        }

        $list = get_post( $list_id );
        if ( ! $list || $list->post_type !== App::SHOPPING_LIST_POST_TYPE || (int) $list->post_parent !== 0 ) {
            return [];
        }

        $reminders = get_post_meta( $list_id, App::META_SHOPPING_HOUSEHOLD_REMINDERS, true );
        if ( ! is_array( $reminders ) ) {
            return [];
        }

        return $this->sort_shopping_items( $this->normalize_shopping_items( $reminders ) );
    }

    private function get_shopping_item_posts( int $list_id ): array {
        if ( ! $list_id ) {
            return [];
        }

        return get_posts( [
            'post_type'      => App::SHOPPING_LIST_POST_TYPE,
            'post_parent'    => $list_id,
            'post_status'    => [ 'publish', App::SHOPPING_ITEM_STATUS_CHECKED ],
            'posts_per_page' => -1,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ] );
    }

    private function migrate_legacy_shopping_items( int $list_id ): void {
        if ( $this->get_shopping_item_posts( $list_id ) ) {
            return;
        }

        $legacy = get_post_meta( $list_id, App::META_SHOPPING_ITEMS, true );
        if ( ! is_array( $legacy ) || ! $legacy ) {
            return;
        }

        $created = [];
        foreach ( $this->normalize_shopping_items( $legacy ) as $index => $item ) {
            $item_id = $this->create_shopping_item_post( $list_id, $item, $index );
            if ( ! $item_id ) {
                foreach ( $created as $created_id ) {
                    wp_delete_post( $created_id, true );
                }
                return;
            }
            $created[] = $item_id;
        }
        update_post_meta( $list_id, App::META_SHOPPING_ITEMS, [] );
    }

    private function shopping_item_from_post( \WP_Post $post ): array {
        $source_recipe_id = (int) get_post_meta( $post->ID, App::META_SHOPPING_ITEM_SOURCE_RECIPE_ID, true );
        $source_recipe_title = (string) get_post_meta( $post->ID, App::META_SHOPPING_ITEM_SOURCE_RECIPE_TITLE, true );
        if ( $source_recipe_id && $source_recipe_title === '' ) {
            $source_recipe_title = get_the_title( $source_recipe_id );
        }
        $source_recipes = get_post_meta( $post->ID, App::META_SHOPPING_ITEM_SOURCE_RECIPES, true );
        $source_recipes = $this->shopping_item_source_recipes( [
            'source_recipe_id'    => $source_recipe_id,
            'source_recipe_title' => $source_recipe_title,
            'source_recipes'      => is_array( $source_recipes ) ? $source_recipes : [],
        ] );
        $source_summary = $this->shopping_item_source_summary( $source_recipes, [
            'source_recipe_id'    => $source_recipe_id,
            'source_recipe_title' => $source_recipe_title,
        ] );

        $term_ids = wp_get_object_terms( $post->ID, App::TAX_INGREDIENT, [ 'fields' => 'ids' ] );
        if ( is_wp_error( $term_ids ) ) {
            $term_ids = [];
        }
        $term_ids = array_values( array_filter( array_map( 'absint', (array) $term_ids ) ) );

        return [
            'id'                  => (string) $post->ID,
            'amount'              => (string) get_post_meta( $post->ID, App::META_SHOPPING_ITEM_AMOUNT, true ),
            'unit'                => (string) get_post_meta( $post->ID, App::META_SHOPPING_ITEM_UNIT, true ),
            'name'                => $post->post_title,
            'notes'               => (string) get_post_meta( $post->ID, App::META_SHOPPING_ITEM_NOTES, true ),
            'checked'             => $post->post_status === App::SHOPPING_ITEM_STATUS_CHECKED,
            'source_recipe_id'    => $source_summary['id'],
            'source_recipe_title' => $source_summary['title'],
            'source_recipes'      => $source_recipes,
            'term_id'             => $term_ids ? (int) $term_ids[0] : 0,
            'term_ids'            => $term_ids,
            'sort'                => (int) $post->menu_order,
        ];
    }

    public function handle_add_to_shopping_list(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( 'cookbook_add_to_shopping_list' );

        $recipe_id = isset( $_POST['recipe_id'] ) ? absint( $_POST['recipe_id'] ) : 0;
        $servings  = isset( $_POST['servings'] ) ? max( 1, absint( $_POST['servings'] ) ) : 0;
        $post      = $this->services->access()->get_recipe_or_die( $recipe_id );

        $payload = $this->collect_recipe_shopping_payload( $recipe_id, $servings );
        $added   = $this->add_items_to_shopping_list( $payload['items'], $payload['household'] );

        wp_safe_redirect( add_query_arg( [
            'shopping'  => 'added',
            'items'     => $added,
            'household' => count( $payload['household'] ),
        ], home_url( '/' . $this->get_url_path() . '/recipe/' . $post->ID ) ) );
        exit;
    }

    public function handle_update_shopping_list(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( 'cookbook_update_shopping_list' );

        $command = isset( $_POST['list_command'] ) ? sanitize_text_field( wp_unslash( $_POST['list_command'] ) ) : 'save';
        $restore_household_index = null;
        if ( preg_match( '/^restore_household:(\d+)$/', $command, $m ) ) {
            $restore_household_index = absint( $m[1] );
            $command = 'restore_household';
        }
        $return_mode = isset( $_POST['return_mode'] ) ? sanitize_key( wp_unslash( $_POST['return_mode'] ) ) : '';
        $redirect_args = [ 'saved' => '1' ];
        if ( $return_mode === 'shop' ) {
            $redirect_args['mode'] = 'shop';
        } elseif ( $return_mode === 'edit' ) {
            $redirect_args['mode'] = 'edit';
        }
        $list_id = isset( $_POST['list_id'] ) ? absint( $_POST['list_id'] ) : 0;
        if ( ! $list_id && in_array( $command, [ 'clear_all', 'clear_checked' ], true ) ) {
            wp_safe_redirect( add_query_arg( $redirect_args, home_url( '/' . $this->get_url_path() . '/shopping-list' ) ) );
            exit;
        }
        $list_id = $list_id ?: $this->get_current_user_shopping_list_id( true );
        $this->services->access()->get_owned_shopping_list_or_die( $list_id );
        $this->migrate_legacy_shopping_items( $list_id );

        if ( $command === 'clear_all' ) {
            $this->delete_shopping_item_posts( $list_id );
            delete_post_meta( $list_id, App::META_SHOPPING_HOUSEHOLD_REMINDERS );
        } else {
            $rows = isset( $_POST['items'] ) && is_array( $_POST['items'] )
                ? wp_unslash( $_POST['items'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                : [];
            $items = $this->normalize_shopping_items( $rows );

            $new_rows = isset( $_POST['new_items'] ) && is_array( $_POST['new_items'] )
                ? wp_unslash( $_POST['new_items'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                : [];
            $items = array_merge( $items, $this->normalize_shopping_items( $new_rows ) );

            if ( $command === 'mark_household' ) {
                $selected = isset( $_POST['selected_items'] ) && is_array( $_POST['selected_items'] )
                    ? array_values( array_unique( array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['selected_items'] ) ) ) ) )
                    : [];
                [ $items, $reminders, $term_ids ] = $this->split_household_selected_items( $items, $selected );
                $this->services->preferences()->add_user_household_ingredient_terms( $term_ids );
                $this->add_household_reminders_to_shopping_list( $list_id, $reminders );
                $this->replace_shopping_item_posts( $list_id, $items );
            } elseif ( $command === 'restore_household' && $restore_household_index !== null ) {
                $reminders = $this->get_shopping_household_reminders( $list_id );
                if ( isset( $reminders[ $restore_household_index ] ) ) {
                    $restored = $reminders[ $restore_household_index ];
                    unset( $reminders[ $restore_household_index ] );
                    $this->services->preferences()->remove_user_household_ingredient_terms( $this->shopping_item_term_ids( $restored ) );
                    $items[] = $restored;
                    $this->replace_household_reminders( $list_id, array_values( $reminders ) );
                }
                $this->replace_shopping_item_posts( $list_id, $items );
            } else {
                $this->replace_shopping_item_posts( $list_id, $items );
            }

            if ( $command === 'clear_checked' ) {
                $this->delete_checked_shopping_item_posts( $list_id );
            }
        }

        update_post_meta( $list_id, App::META_SHOPPING_ITEMS, [] );
        wp_safe_redirect( add_query_arg( $redirect_args, home_url( '/' . $this->get_url_path() . '/shopping-list' ) ) );
        exit;
    }

    public function handle_add_planner_to_shopping_list(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( 'cookbook_add_planner_to_shopping_list' );

        $week_start = isset( $_POST['week_start'] ) ? sanitize_text_field( wp_unslash( $_POST['week_start'] ) ) : '';
        $week_start = $this->services->planner()->normalize_week_start( $week_start );
        $plan_id    = $this->services->planner()->get_user_week_plan_id( $week_start, false );
        $items      = [];
        $household  = [];

        if ( $plan_id ) {
            $this->services->access()->get_owned_post_or_die( $plan_id, App::WEEK_PLAN_POST_TYPE );
            $meals = $this->services->planner()->get_week_meals( $plan_id );
            foreach ( $this->services->planner()->week_days( $week_start ) as $date => $day ) {
                foreach ( array_keys( $this->services->planner()->meal_slots() ) as $slot ) {
                    $recipe_id = isset( $meals[ $date ][ $slot ] ) ? absint( $meals[ $date ][ $slot ] ) : 0;
                    if ( $recipe_id ) {
                        $payload   = $this->collect_recipe_shopping_payload( $recipe_id, 0 );
                        $items     = array_merge( $items, $payload['items'] );
                        $household = array_merge( $household, $payload['household'] );
                    }
                }
            }
        }

        $added = $this->add_items_to_shopping_list( $items, $household );
        wp_safe_redirect( add_query_arg( [
            'week'      => $week_start,
            'shopping'  => 'added',
            'items'     => $added,
            'household' => count( $household ),
        ], home_url( '/' . $this->get_url_path() . '/planner' ) ) );
        exit;
    }

    private function collect_recipe_shopping_items( int $recipe_id, int $servings = 0 ): array {
        $payload = $this->collect_recipe_shopping_payload( $recipe_id, $servings );
        return $payload['items'];
    }

    private function collect_recipe_shopping_payload( int $recipe_id, int $servings = 0 ): array {
        $post = $this->services->access()->get_recipe_or_die( $recipe_id );
        $ingredients = (array) get_post_meta( $recipe_id, App::META_INGREDIENTS, true );
        if ( ! $ingredients ) {
            return [
                'items'     => [],
                'household' => [],
            ];
        }

        $base_servings = max( 1, (int) get_post_meta( $recipe_id, App::META_SERVINGS, true ) ?: 4 );
        $wanted_servings = $servings > 0 ? $servings : $base_servings;
        $scale = $wanted_servings / $base_servings;
        $preference = $this->services->preferences()->get_user_unit_preference();
        $items = [];
        $household = [];

        foreach ( $ingredients as $ingredient ) {
            if ( ! is_array( $ingredient ) || empty( $ingredient['name'] ) ) {
                continue;
            }
            $rendered = Units::render_ingredient( $ingredient, $scale, $preference );
            $item = [
                'amount'              => (string) ( $rendered['amount'] ?? '' ),
                'unit'                => (string) ( $rendered['unit'] ?? '' ),
                'name'                => (string) ( $rendered['name'] ?? '' ),
                'notes'               => (string) ( $rendered['notes'] ?? '' ),
                'checked'             => false,
                'source_recipe_id'    => $recipe_id,
                'source_recipe_title' => get_the_title( $post ),
                'source_recipes'      => [
                    [
                        'id'    => $recipe_id,
                        'title' => get_the_title( $post ),
                    ],
                ],
                'term_id'             => isset( $ingredient['term_id'] ) ? absint( $ingredient['term_id'] ) : 0,
            ];

            if ( ! empty( $item['term_id'] ) && $this->services->preferences()->is_household_ingredient_term( (int) $item['term_id'] ) ) {
                $household[] = $item;
            } else {
                $items[] = $item;
            }
        }
        return [
            'items'     => $this->normalize_shopping_items( $items ),
            'household' => $this->normalize_shopping_items( $household ),
        ];
    }

    private function add_items_to_shopping_list( array $incoming, array $household_reminders = [] ): int {
        $incoming = $this->normalize_shopping_items( $incoming );
        $household_reminders = $this->normalize_shopping_items( $household_reminders );
        if ( ! $incoming && ! $household_reminders ) {
            return 0;
        }

        $list_id = $this->get_current_user_shopping_list_id( true );
        if ( ! $list_id ) {
            return 0;
        }
        $this->services->access()->get_owned_shopping_list_or_die( $list_id );
        $this->migrate_legacy_shopping_items( $list_id );
        $this->add_household_reminders_to_shopping_list( $list_id, $household_reminders );

        $order = count( $this->get_shopping_item_posts( $list_id ) );
        foreach ( $incoming as $item ) {
            $this->create_shopping_item_post( $list_id, $item, $order++ );
        }
        update_post_meta( $list_id, App::META_SHOPPING_ITEMS, [] );
        return count( $incoming );
    }

    private function add_household_reminders_to_shopping_list( int $list_id, array $reminders ): void {
        $reminders = $this->normalize_shopping_items( $reminders );
        if ( ! $reminders ) {
            return;
        }

        $existing = $this->get_shopping_household_reminders( $list_id );
        update_post_meta( $list_id, App::META_SHOPPING_HOUSEHOLD_REMINDERS, $this->merge_household_reminders( $existing, $reminders ) );
    }

    private function replace_household_reminders( int $list_id, array $reminders ): void {
        $reminders = $this->normalize_shopping_items( $reminders );
        if ( ! $reminders ) {
            delete_post_meta( $list_id, App::META_SHOPPING_HOUSEHOLD_REMINDERS );
            return;
        }

        update_post_meta( $list_id, App::META_SHOPPING_HOUSEHOLD_REMINDERS, $this->merge_household_reminders( [], $reminders ) );
    }

    private function merge_household_reminders( array $existing, array $incoming ): array {
        $merged = [];
        foreach ( array_merge( $this->normalize_shopping_items( $existing ), $this->normalize_shopping_items( $incoming ) ) as $item ) {
            $key = $this->household_reminder_key( $item );
            if ( ! isset( $merged[ $key ] ) ) {
                $merged[ $key ] = $item;
                continue;
            }

            $sources = $this->normalize_shopping_source_recipes( array_merge(
                $merged[ $key ]['source_recipes'] ?? [],
                $item['source_recipes'] ?? []
            ) );
            $source_summary = $this->shopping_item_source_summary( $sources );
            $term_ids = array_values( array_unique( array_merge(
                $this->shopping_item_term_ids( $merged[ $key ] ),
                $this->shopping_item_term_ids( $item )
            ) ) );
            $notes = array_values( array_unique( array_filter( [
                trim( (string) ( $merged[ $key ]['notes'] ?? '' ) ),
                trim( (string) ( $item['notes'] ?? '' ) ),
            ] ) ) );

            $merged[ $key ]['notes']               = implode( '; ', $notes );
            $merged[ $key ]['source_recipe_id']    = $source_summary['id'];
            $merged[ $key ]['source_recipe_title'] = $source_summary['title'];
            $merged[ $key ]['source_recipes']      = $sources;
            $merged[ $key ]['term_id']             = $term_ids ? (int) $term_ids[0] : 0;
            $merged[ $key ]['term_ids']            = $term_ids;
        }

        return $this->sort_shopping_items( array_values( $merged ) );
    }

    private function household_reminder_key( array $item ): string {
        $term_ids = $this->shopping_item_term_ids( $item );
        if ( $term_ids ) {
            sort( $term_ids, SORT_NUMERIC );
            return 'term:' . implode( '-', $term_ids );
        }

        return 'name:' . sanitize_title( (string) ( $item['name'] ?? '' ) );
    }

    private function split_household_selected_items( array $items, array $selected_ids ): array {
        $selected_ids = array_values( array_unique( array_filter( array_map( 'sanitize_key', $selected_ids ) ) ) );
        if ( ! $selected_ids ) {
            return [ $items, [], [] ];
        }

        $remaining = [];
        $reminders = [];
        $term_ids  = [];
        foreach ( $this->normalize_shopping_items( $items ) as $item ) {
            if ( ! in_array( sanitize_key( (string) ( $item['id'] ?? '' ) ), $selected_ids, true ) ) {
                $remaining[] = $item;
                continue;
            }

            $item_term_ids = $this->shopping_item_term_ids( $item );
            if ( ! $item_term_ids ) {
                $term_id = $this->services->recipes()->resolve_ingredient_term( (string) ( $item['name'] ?? '' ) );
                if ( $term_id ) {
                    $item_term_ids[] = $term_id;
                }
            }
            if ( $item_term_ids ) {
                $item['term_id']  = (int) $item_term_ids[0];
                $item['term_ids'] = $item_term_ids;
                $term_ids         = array_merge( $term_ids, $item_term_ids );
            }
            $item['checked'] = false;
            $reminders[]     = $item;
        }

        return [
            $remaining,
            $reminders,
            array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) ),
        ];
    }

    private function normalize_shopping_items( array $items ): array {
        $normalized = [];
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $name = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '';
            if ( $name === '' ) {
                continue;
            }
            $id = isset( $item['id'] ) ? sanitize_key( (string) $item['id'] ) : '';
            $source_recipes = $this->shopping_item_source_recipes( $item );
            $source_summary = $this->shopping_item_source_summary( $source_recipes, $item );
            $term_ids = $this->shopping_item_term_ids( $item );
            $normalized[] = [
                'id'                  => $id,
                'amount'              => isset( $item['amount'] ) ? sanitize_text_field( (string) $item['amount'] ) : '',
                'unit'                => isset( $item['unit'] ) ? sanitize_text_field( (string) $item['unit'] ) : '',
                'name'                => $name,
                'notes'               => isset( $item['notes'] ) ? sanitize_text_field( (string) $item['notes'] ) : '',
                'checked'             => ! empty( $item['checked'] ),
                'source_recipe_id'    => $source_summary['id'],
                'source_recipe_title' => $source_summary['title'],
                'source_recipes'      => $source_recipes,
                'term_id'             => $term_ids ? (int) $term_ids[0] : 0,
                'term_ids'            => $term_ids,
                'sort'                => isset( $item['sort'] ) ? absint( $item['sort'] ) : 0,
            ];
        }
        return $normalized;
    }

    private function shopping_item_source_recipes( array $item ): array {
        $sources = [];
        if ( isset( $item['source_recipes'] ) && is_array( $item['source_recipes'] ) ) {
            $sources = $this->normalize_shopping_source_recipes( $item['source_recipes'] );
        }

        if ( ! $sources ) {
            $source_id = isset( $item['source_recipe_id'] ) ? absint( $item['source_recipe_id'] ) : 0;
            $source_title = isset( $item['source_recipe_title'] ) ? sanitize_text_field( (string) $item['source_recipe_title'] ) : '';
            if ( $source_id || $source_title !== '' ) {
                $sources = $this->normalize_shopping_source_recipes( [
                    [
                        'id'    => $source_id,
                        'title' => $source_title,
                    ],
                ] );
            }
        }

        return $sources;
    }

    private function normalize_shopping_source_recipes( array $sources ): array {
        $normalized = [];
        foreach ( $sources as $source ) {
            if ( ! is_array( $source ) ) {
                continue;
            }
            $source_id = isset( $source['id'] )
                ? absint( $source['id'] )
                : ( isset( $source['source_recipe_id'] ) ? absint( $source['source_recipe_id'] ) : 0 );
            $source_title = isset( $source['title'] )
                ? sanitize_text_field( (string) $source['title'] )
                : ( isset( $source['source_recipe_title'] ) ? sanitize_text_field( (string) $source['source_recipe_title'] ) : '' );
            if ( $source_id && ( $source_title === '' || $this->is_multiple_recipes_source_label( $source_title ) ) ) {
                $source_title = get_the_title( $source_id );
            }
            if ( ! $source_id && ( $source_title === '' || $this->is_multiple_recipes_source_label( $source_title ) ) ) {
                continue;
            }
            $key = $source_id ? 'id:' . $source_id : 'title:' . sanitize_title( $source_title );
            $normalized[ $key ] = [
                'id'    => $source_id,
                'title' => $source_title,
            ];
        }

        uasort( $normalized, function( $a, $b ) {
            return strnatcasecmp( $a['title'], $b['title'] );
        } );

        return array_values( $normalized );
    }

    private function shopping_item_source_summary( array $source_recipes, array $fallback = [] ): array {
        if ( count( $source_recipes ) === 1 ) {
            return [
                'id'    => (int) $source_recipes[0]['id'],
                'title' => (string) $source_recipes[0]['title'],
            ];
        }
        if ( count( $source_recipes ) > 1 ) {
            return [
                'id'    => 0,
                'title' => __( 'Multiple recipes', 'cook-app' ),
            ];
        }

        $fallback_title = isset( $fallback['source_recipe_title'] ) ? sanitize_text_field( (string) $fallback['source_recipe_title'] ) : '';
        if ( $this->is_multiple_recipes_source_label( $fallback_title ) ) {
            $fallback_title = '';
        }

        return [
            'id'    => isset( $fallback['source_recipe_id'] ) ? absint( $fallback['source_recipe_id'] ) : 0,
            'title' => $fallback_title,
        ];
    }

    private function is_multiple_recipes_source_label( string $title ): bool {
        $title = trim( $title );
        if ( $title === '' ) {
            return false;
        }

        return in_array( $title, array_unique( [
            'Multiple recipes',
            __( 'Multiple recipes', 'cook-app' ),
        ] ), true );
    }

    private function shopping_item_term_ids( array $item ): array {
        $term_ids = [];
        if ( isset( $item['term_ids'] ) && is_array( $item['term_ids'] ) ) {
            $term_ids = array_map( 'absint', $item['term_ids'] );
        }
        if ( isset( $item['term_id'] ) ) {
            $term_ids[] = absint( $item['term_id'] );
        }
        return array_values( array_unique( array_filter( $term_ids ) ) );
    }

    private function replace_shopping_item_posts( int $list_id, array $items ): void {
        $existing = [];
        foreach ( $this->get_shopping_item_posts( $list_id ) as $post ) {
            $existing[ (int) $post->ID ] = $post;
        }

        $kept = [];
        foreach ( $this->normalize_shopping_items( $items ) as $order => $item ) {
            $item['sort'] = $order;
            $item_id = absint( $item['id'] );
            if ( $item_id && isset( $existing[ $item_id ] ) ) {
                $this->update_shopping_item_post( $item_id, $list_id, $item );
                $kept[ $item_id ] = true;
                continue;
            }

            $created = $this->create_shopping_item_post( $list_id, $item, $order );
            if ( $created ) {
                $kept[ $created ] = true;
            }
        }

        foreach ( array_keys( $existing ) as $item_id ) {
            if ( empty( $kept[ $item_id ] ) ) {
                wp_delete_post( $item_id, true );
            }
        }
    }

    private function create_shopping_item_post( int $list_id, array $item, int $order = 0 ): int {
        $list = get_post( $list_id );
        if ( ! $list || $list->post_type !== App::SHOPPING_LIST_POST_TYPE || (int) $list->post_parent !== 0 ) {
            return 0;
        }

        $post_id = wp_insert_post( [
            'post_type'   => App::SHOPPING_LIST_POST_TYPE,
            'post_status' => ! empty( $item['checked'] ) ? App::SHOPPING_ITEM_STATUS_CHECKED : 'publish',
            'post_title'  => $item['name'],
            'post_author' => (int) $list->post_author,
            'post_parent' => $list_id,
            'menu_order'  => $order,
        ], true );
        if ( is_wp_error( $post_id ) ) {
            return 0;
        }

        $this->save_shopping_item_post_data( (int) $post_id, $item );
        return (int) $post_id;
    }

    private function update_shopping_item_post( int $item_id, int $list_id, array $item ): void {
        $post = get_post( $item_id );
        if ( ! $post || $post->post_type !== App::SHOPPING_LIST_POST_TYPE || (int) $post->post_parent !== $list_id ) {
            return;
        }

        wp_update_post( [
            'ID'          => $item_id,
            'post_status' => ! empty( $item['checked'] ) ? App::SHOPPING_ITEM_STATUS_CHECKED : 'publish',
            'post_title'  => $item['name'],
            'post_parent' => $list_id,
            'menu_order'  => isset( $item['sort'] ) ? absint( $item['sort'] ) : 0,
        ] );
        $this->save_shopping_item_post_data( $item_id, $item );
    }

    private function save_shopping_item_post_data( int $item_id, array $item ): void {
        update_post_meta( $item_id, App::META_SHOPPING_ITEM_AMOUNT, $item['amount'] ?? '' );
        update_post_meta( $item_id, App::META_SHOPPING_ITEM_UNIT, $item['unit'] ?? '' );
        update_post_meta( $item_id, App::META_SHOPPING_ITEM_NOTES, $item['notes'] ?? '' );
        update_post_meta( $item_id, App::META_SHOPPING_ITEM_SOURCE_RECIPE_ID, isset( $item['source_recipe_id'] ) ? absint( $item['source_recipe_id'] ) : 0 );
        update_post_meta( $item_id, App::META_SHOPPING_ITEM_SOURCE_RECIPE_TITLE, isset( $item['source_recipe_title'] ) ? sanitize_text_field( (string) $item['source_recipe_title'] ) : '' );
        update_post_meta( $item_id, App::META_SHOPPING_ITEM_SOURCE_RECIPES, $this->shopping_item_source_recipes( $item ) );

        $term_ids = $this->shopping_item_term_ids( $item );
        if ( ! $term_ids ) {
            $term_id = $this->services->recipes()->resolve_ingredient_term( (string) ( $item['name'] ?? '' ) ) ?: 0;
            if ( $term_id ) {
                $term_ids[] = $term_id;
            }
        }
        wp_set_object_terms( $item_id, $term_ids, App::TAX_INGREDIENT, false );
    }

    private function delete_shopping_item_posts( int $list_id ): void {
        foreach ( $this->get_shopping_item_posts( $list_id ) as $post ) {
            wp_delete_post( $post->ID, true );
        }
    }

    private function delete_checked_shopping_item_posts( int $list_id ): void {
        foreach ( $this->get_shopping_item_posts( $list_id ) as $post ) {
            if ( $post->post_status === App::SHOPPING_ITEM_STATUS_CHECKED ) {
                wp_delete_post( $post->ID, true );
            }
        }
    }

    private function sort_shopping_items( array $items ): array {
        usort( $items, function( $a, $b ) {
            $checked = (int) ! empty( $a['checked'] ) <=> (int) ! empty( $b['checked'] );
            if ( $checked !== 0 ) {
                return $checked;
            }

            $sort = (int) ( $a['sort'] ?? 0 ) <=> (int) ( $b['sort'] ?? 0 );
            if ( $sort !== 0 ) {
                return $sort;
            }

            $name = strnatcasecmp( sanitize_title( $a['name'] ?? '' ), sanitize_title( $b['name'] ?? '' ) );
            if ( $name !== 0 ) {
                return $name;
            }

            $notes = strnatcasecmp( sanitize_title( $a['notes'] ?? '' ), sanitize_title( $b['notes'] ?? '' ) );
            if ( $notes !== 0 ) {
                return $notes;
            }

            return (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
        } );

        return $items;
    }
}
