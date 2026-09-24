<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PlannerService extends AbstractService {
    public function week_plan_payload( string $week_start, int $plan_id = 0 ): array {
        $week_start = $this->normalize_week_start( $week_start );
        $days       = $this->week_days( $week_start );
        $meal_slots = $this->meal_slots();
        $raw_meals  = $plan_id ? $this->get_week_meals( $plan_id ) : [];
        $meal_ids   = $this->sanitize_planner_meals( $raw_meals, $week_start );
        $planned    = [];

        foreach ( $days as $date => $day ) {
            foreach ( $meal_slots as $slot => $slot_label ) {
                $recipe_id = isset( $meal_ids[ $date ][ $slot ] ) ? absint( $meal_ids[ $date ][ $slot ] ) : 0;
                if ( ! $recipe_id ) {
                    continue;
                }

                $recipe = get_post( $recipe_id );
                if ( ! $recipe || $recipe->post_type !== App::POST_TYPE ) {
                    continue;
                }

                $planned[] = [
                    'date'       => $date,
                    'day_short'  => (string) $day['short'],
                    'day_label'  => (string) $day['label'],
                    'slot'       => $slot,
                    'slot_label' => $slot_label,
                    'recipe'     => $this->services->recipes()->recipe_payload( $recipe, false ),
                ];
            }
        }

        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
        try {
            $start = new \DateTimeImmutable( $week_start, $timezone );
        } catch ( \Exception $e ) {
            $start = new \DateTimeImmutable( $this->normalize_week_start(), $timezone );
        }

        return [
            'id'            => $plan_id,
            'week_start'    => $week_start,
            'url'           => add_query_arg( 'week', $week_start, home_url( '/' . $this->get_url_path() . '/planner' ) ),
            'previous_week' => $start->modify( '-7 days' )->format( 'Y-m-d' ),
            'next_week'     => $start->modify( '+7 days' )->format( 'Y-m-d' ),
            'days'          => array_map( function( string $date, array $day ) {
                return [
                    'date'  => $date,
                    'short' => (string) $day['short'],
                    'label' => (string) $day['label'],
                ];
            }, array_keys( $days ), $days ),
            'meal_slots'    => array_map( function( string $slot, string $label ) {
                return [
                    'slot'  => $slot,
                    'label' => $label,
                ];
            }, array_keys( $meal_slots ), $meal_slots ),
            'meal_ids'      => $meal_ids,
            'planned_meals' => $planned,
        ];
    }

    public function merge_week_plan_meals( array $raw_meals, string $week_start, array $base = [] ): array {
        $meals = $this->sanitize_planner_meals( $base, $week_start );
        $days  = array_keys( $this->week_days( $week_start ) );
        $slots = array_keys( $this->meal_slots() );

        foreach ( $days as $date ) {
            if ( ! isset( $raw_meals[ $date ] ) || ! is_array( $raw_meals[ $date ] ) ) {
                continue;
            }

            foreach ( $slots as $slot ) {
                if ( ! array_key_exists( $slot, $raw_meals[ $date ] ) ) {
                    continue;
                }

                $recipe_id = absint( $raw_meals[ $date ][ $slot ] );
                if ( $recipe_id && $this->services->access()->recipe_exists( $recipe_id ) ) {
                    $meals[ $date ][ $slot ] = $recipe_id;
                    continue;
                }

                unset( $meals[ $date ][ $slot ] );
                if ( empty( $meals[ $date ] ) ) {
                    unset( $meals[ $date ] );
                }
            }
        }

        return $meals;
    }

    public function meal_slots(): array {
        $labels = [
            'breakfast' => __( 'Breakfast', 'cook-app' ),
            'lunch'     => __( 'Lunch', 'cook-app' ),
            'dinner'    => __( 'Dinner', 'cook-app' ),
        ];
        return array_intersect_key( $labels, array_flip( App::MEAL_SLOTS ) );
    }

    public function normalize_week_start( string $date = '' ): string {
        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
        try {
            $dt = $date !== ''
                ? new \DateTimeImmutable( $date, $timezone )
                : new \DateTimeImmutable( 'today', $timezone );
        } catch ( \Exception $e ) {
            $dt = new \DateTimeImmutable( 'today', $timezone );
        }

        $start_of_week = (int) get_option( 'start_of_week', 1 );
        $diff = ( (int) $dt->format( 'w' ) - $start_of_week + 7 ) % 7;
        if ( $diff > 0 ) {
            $dt = $dt->modify( '-' . $diff . ' days' );
        }
        return $dt->format( 'Y-m-d' );
    }

    public function week_days( string $week_start ): array {
        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
        try {
            $start = new \DateTimeImmutable( $week_start, $timezone );
        } catch ( \Exception $e ) {
            $start = new \DateTimeImmutable( $this->normalize_week_start(), $timezone );
        }

        $days = [];
        for ( $i = 0; $i < 7; $i++ ) {
            $day = $start->modify( '+' . $i . ' days' );
            $timestamp = $day->getTimestamp();
            $days[ $day->format( 'Y-m-d' ) ] = [
                'short' => wp_date( 'D', $timestamp ),
                'label' => wp_date( get_option( 'date_format' ), $timestamp ),
            ];
        }
        return $days;
    }

    public function get_user_week_plan_id( string $week_start, bool $create = true ): int {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return 0;
        }
        $week_start = $this->normalize_week_start( $week_start );

        $ids = get_posts( [
            'post_type'      => App::WEEK_PLAN_POST_TYPE,
            'post_status'    => [ 'publish', 'private', 'draft' ],
            'author'         => $user_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- exact lookup for one user's weekly plan.
            'meta_query'     => [
                [
                    'key'   => App::META_WEEK_START,
                    'value' => $week_start,
                ],
            ],
        ] );
        if ( $ids ) {
            return (int) $ids[0];
        }
        if ( ! $create ) {
            return 0;
        }

        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
        try {
            $start = new \DateTimeImmutable( $week_start, $timezone );
        } catch ( \Exception $e ) {
            $start = new \DateTimeImmutable( $this->normalize_week_start(), $timezone );
        }
        $title = sprintf(
            /* translators: %s: formatted date */
            __( 'Week of %s', 'cook-app' ),
            wp_date( get_option( 'date_format' ), $start->getTimestamp() )
        );
        $post_id = wp_insert_post( [
            'post_type'   => App::WEEK_PLAN_POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => $title,
            'post_author' => $user_id,
        ], true );
        if ( is_wp_error( $post_id ) ) {
            return 0;
        }
        update_post_meta( (int) $post_id, App::META_WEEK_START, $week_start );
        update_post_meta( (int) $post_id, App::META_WEEK_MEALS, [] );
        return (int) $post_id;
    }

    public function get_week_meals( int $plan_id ): array {
        if ( ! $plan_id ) {
            return [];
        }
        $raw = get_post_meta( $plan_id, App::META_WEEK_MEALS, true );
        return is_array( $raw ) ? $raw : [];
    }

    public function handle_save_planner(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( 'cookbook_save_planner' );

        $week_start = isset( $_POST['week_start'] ) ? sanitize_text_field( wp_unslash( $_POST['week_start'] ) ) : '';
        $week_start = $this->normalize_week_start( $week_start );
        $plan_id    = isset( $_POST['plan_id'] ) ? absint( $_POST['plan_id'] ) : 0;

        if ( $plan_id ) {
            $plan = $this->services->access()->get_owned_post_or_die( $plan_id, App::WEEK_PLAN_POST_TYPE );
            $stored_week_start = (string) get_post_meta( $plan->ID, App::META_WEEK_START, true );
            if ( $this->normalize_week_start( $stored_week_start ) !== $week_start ) {
                $plan_id = $this->get_user_week_plan_id( $week_start, true );
            }
        } else {
            $plan_id = $this->get_user_week_plan_id( $week_start, true );
        }
        $this->services->access()->get_owned_post_or_die( $plan_id, App::WEEK_PLAN_POST_TYPE );

        $raw_meals = isset( $_POST['meals'] ) && is_array( $_POST['meals'] )
            ? wp_unslash( $_POST['meals'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : [];
        $raw_labels = isset( $_POST['meal_labels'] ) && is_array( $_POST['meal_labels'] )
            ? wp_unslash( $_POST['meal_labels'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : [];
        $meals = $this->sanitize_planner_meals( $raw_meals, $week_start, $raw_labels );

        update_post_meta( $plan_id, App::META_WEEK_START, $week_start );
        update_post_meta( $plan_id, App::META_WEEK_MEALS, $meals );
        wp_safe_redirect( add_query_arg( [
            'week'  => $week_start,
            'saved' => '1',
        ], home_url( '/' . $this->get_url_path() . '/planner' ) ) );
        exit;
    }

    private function sanitize_planner_meals( array $raw_meals, string $week_start, array $raw_labels = [] ): array {
        $meals = [];
        $days = array_keys( $this->week_days( $week_start ) );
        $slots = array_keys( $this->meal_slots() );

        foreach ( $days as $date ) {
            foreach ( $slots as $slot ) {
                $recipe_id = isset( $raw_meals[ $date ][ $slot ] ) ? absint( $raw_meals[ $date ][ $slot ] ) : 0;
                $has_label = isset( $raw_labels[ $date ][ $slot ] );
                $label = $has_label ? sanitize_text_field( (string) $raw_labels[ $date ][ $slot ] ) : '';
                if ( $has_label ) {
                    if ( trim( $label ) === '' ) {
                        continue;
                    }
                    if ( ! $recipe_id || ! $this->planner_recipe_label_matches_id( $label, $recipe_id ) ) {
                        $recipe_id = $this->resolve_planner_recipe_label( $label );
                    }
                }
                if ( $recipe_id && $this->services->access()->recipe_exists( $recipe_id ) ) {
                    $meals[ $date ][ $slot ] = $recipe_id;
                }
            }
        }
        return $meals;
    }

    private function planner_recipe_label_matches_id( string $label, int $recipe_id ): bool {
        if ( ! $recipe_id || ! $this->services->access()->recipe_exists( $recipe_id ) ) {
            return false;
        }

        $post = get_post( $recipe_id );
        if ( ! $post || $post->post_type !== App::POST_TYPE ) {
            return false;
        }

        $label = trim( $label );
        $title = get_the_title( $post );
        if ( $label === $title ) {
            return true;
        }

        return $label === sprintf(
            /* translators: 1: recipe title, 2: recipe ID */
            __( '%1$s (#%2$d)', 'cook-app' ),
            $title,
            $recipe_id
        );
    }

    private function resolve_planner_recipe_label( string $label ): int {
        $label = trim( $label );
        if ( $label === '' ) {
            return 0;
        }
        if ( preg_match( '/\(#(\d+)\)$/', $label, $m ) ) {
            $recipe_id = absint( $m[1] );
            return $this->services->access()->recipe_exists( $recipe_id ) ? $recipe_id : 0;
        }

        $candidates = get_posts( [
            'post_type'      => App::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            's'              => $label,
        ] );
        foreach ( $candidates as $candidate ) {
            if ( get_the_title( $candidate ) === $label ) {
                return (int) $candidate->ID;
            }
        }
        return 0;
    }
}
