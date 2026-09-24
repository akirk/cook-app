<?php
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Cooked history is intentionally ordered and filtered by post meta.
namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CookedHistoryService extends AbstractService {
    public function sanitize_cooked_date( string $date = '' ): string {
        $date = trim( $date );
        if ( $date !== '' && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
            $parsed   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );
            if ( $parsed && $parsed->format( 'Y-m-d' ) === $date ) {
                return $date;
            }
        }

        return wp_date( 'Y-m-d' );
    }

    public function format_cooked_date( string $date, string $format = '' ): string {
        $date     = $this->sanitize_cooked_date( $date );
        $format   = $format !== '' ? $format : get_option( 'date_format' );
        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
        $parsed   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $timezone );

        return $parsed ? wp_date( $format, $parsed->getTimestamp() ) : wp_date( $format );
    }

    public function get_user_cooked_entries( array $args = [] ): array {
        $user_id = ! empty( $args['user_id'] ) ? absint( $args['user_id'] ) : get_current_user_id();
        if ( ! $user_id ) {
            return [];
        }

        $number = array_key_exists( 'number', $args ) ? (int) $args['number'] : -1;
        $query  = [
            'post_type'      => App::COOKED_ENTRY_POST_TYPE,
            'post_status'    => [ 'publish', 'private', 'draft' ],
            'author'         => $user_id,
            'posts_per_page' => $number,
            'meta_key'       => App::META_COOKED_DATE,
            'orderby'        => [
                'meta_value' => 'DESC',
                'date'       => 'DESC',
            ],
        ];

        $recipe_id = isset( $args['recipe_id'] ) ? absint( $args['recipe_id'] ) : 0;
        if ( $recipe_id ) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- scoped lookup for one user's cooking history.
            $query['meta_query'] = [
                [
                    'key'   => App::META_COOKED_RECIPE_ID,
                    'value' => $recipe_id,
                    'type'  => 'NUMERIC',
                ],
            ];
        }

        return get_posts( $query );
    }

    public function get_recipe_cooked_entries( int $recipe_id, int $number = 5, int $user_id = 0 ): array {
        return $this->get_user_cooked_entries( [
            'recipe_id' => $recipe_id,
            'number'    => $number,
            'user_id'   => $user_id,
        ] );
    }

    public function get_recipe_last_cooked_date( int $recipe_id, int $user_id = 0 ): string {
        $entries = $this->get_recipe_cooked_entries( $recipe_id, 1, $user_id );
        if ( ! $entries ) {
            return '';
        }

        return (string) get_post_meta( $entries[0]->ID, App::META_COOKED_DATE, true );
    }

    public function handle_log_cooked(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( 'cookbook_log_cooked' );

        $recipe_id = isset( $_POST['recipe_id'] ) ? absint( $_POST['recipe_id'] ) : 0;
        $recipe    = $this->services->access()->get_recipe_or_die( $recipe_id );
        $date      = isset( $_POST['cooked_date'] )
            ? $this->sanitize_cooked_date( sanitize_text_field( wp_unslash( $_POST['cooked_date'] ) ) )
            : $this->sanitize_cooked_date();
        $note      = isset( $_POST['cooked_note'] )
            ? sanitize_textarea_field( wp_unslash( $_POST['cooked_note'] ) )
            : '';
        $result    = $this->record_cooked_recipe( $recipe, $date, 0, $note );

        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ) );
        }

        $fallback = home_url( '/' . $this->get_url_path() . '/recipe/' . $recipe->ID );
        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
        $redirect = wp_validate_redirect( $redirect, $fallback );
        $redirect = add_query_arg( [
            'cooked'      => ! empty( $result['created'] ) ? 'logged' : 'exists',
            'cooked_date' => $result['date'],
        ], $redirect );

        wp_safe_redirect( $redirect );
        exit;
    }

    public function handle_update_cooked(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( 'cookbook_update_cooked' );

        $entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
        $entry    = $entry_id ? get_post( $entry_id ) : null;
        if ( ! $entry || $entry->post_type !== App::COOKED_ENTRY_POST_TYPE || (int) $entry->post_author !== get_current_user_id() ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }

        $recipe_id = (int) get_post_meta( $entry_id, App::META_COOKED_RECIPE_ID, true );
        $recipe    = $this->services->access()->get_recipe_or_die( $recipe_id );
        $date      = isset( $_POST['cooked_date'] )
            ? $this->sanitize_cooked_date( sanitize_text_field( wp_unslash( $_POST['cooked_date'] ) ) )
            : $this->sanitize_cooked_date();
        $note      = isset( $_POST['cooked_note'] )
            ? sanitize_textarea_field( wp_unslash( $_POST['cooked_note'] ) )
            : '';

        $existing_id = $this->find_cooked_entry_id( (int) $recipe->ID, $date, get_current_user_id() );
        if ( $existing_id && $existing_id !== $entry_id ) {
            wp_die( esc_html__( 'You already have a cooked entry for this recipe on that date.', 'cook-app' ) );
        }

        update_post_meta( $entry_id, App::META_COOKED_DATE, $date );
        update_post_meta( $entry_id, App::META_COOKED_NOTE, $note );
        wp_update_post( [
            'ID'         => $entry_id,
            'post_title' => sprintf(
                /* translators: 1: recipe title, 2: cooked date */
                __( '%1$s on %2$s', 'cook-app' ),
                get_the_title( $recipe ),
                $this->format_cooked_date( $date )
            ),
        ] );

        $fallback = home_url( '/' . $this->get_url_path() . '/recipe/' . $recipe->ID . '#cooked-history' );
        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : '';
        $redirect = wp_validate_redirect( $redirect, $fallback );
        $redirect = add_query_arg( [
            'cooked'      => 'updated',
            'cooked_date' => $date,
        ], $redirect );

        wp_safe_redirect( $redirect );
        exit;
    }

    private function record_cooked_recipe( \WP_Post $recipe, string $date, int $user_id = 0, string $note = '' ) {
        if ( $recipe->post_type !== App::POST_TYPE ) {
            return new \WP_Error( 'cookbook_recipe_not_found', __( 'Recipe not found.', 'cook-app' ) );
        }

        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) {
            return new \WP_Error( 'cookbook_not_allowed', __( 'Not allowed.', 'cook-app' ) );
        }

        $date        = $this->sanitize_cooked_date( $date );
        $existing_id = $this->find_cooked_entry_id( (int) $recipe->ID, $date, $user_id );
        if ( $existing_id ) {
            if ( $note !== '' ) {
                update_post_meta( $existing_id, App::META_COOKED_NOTE, $note );
            }

            return [
                'id'      => $existing_id,
                'date'    => $date,
                'created' => false,
            ];
        }

        $post_id = wp_insert_post( [
            'post_type'   => App::COOKED_ENTRY_POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => sprintf(
                /* translators: 1: recipe title, 2: cooked date */
                __( '%1$s on %2$s', 'cook-app' ),
                get_the_title( $recipe ),
                $this->format_cooked_date( $date )
            ),
            'post_author' => $user_id,
        ], true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        update_post_meta( (int) $post_id, App::META_COOKED_RECIPE_ID, (int) $recipe->ID );
        update_post_meta( (int) $post_id, App::META_COOKED_DATE, $date );
        update_post_meta( (int) $post_id, App::META_COOKED_NOTE, $note );

        return [
            'id'      => (int) $post_id,
            'date'    => $date,
            'created' => true,
        ];
    }

    private function find_cooked_entry_id( int $recipe_id, string $date, int $user_id ): int {
        $ids = get_posts( [
            'post_type'      => App::COOKED_ENTRY_POST_TYPE,
            'post_status'    => [ 'publish', 'private', 'draft' ],
            'author'         => $user_id,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- exact duplicate check for one user's recipe/date entry.
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => App::META_COOKED_RECIPE_ID,
                    'value' => $recipe_id,
                    'type'  => 'NUMERIC',
                ],
                [
                    'key'   => App::META_COOKED_DATE,
                    'value' => $date,
                ],
            ],
        ] );

        return $ids ? (int) $ids[0] : 0;
    }
}
