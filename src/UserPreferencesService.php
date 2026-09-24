<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UserPreferencesService extends AbstractService {
    public function get_user_unit_preference( int $user_id = 0 ): string {
        $user_id = $user_id ?: get_current_user_id();
        $pref    = get_user_meta( $user_id, App::USER_PREF_UNITS, true );
        return in_array( $pref, [ 'metric', 'imperial' ], true ) ? $pref : 'metric';
    }

    public function get_user_household_ingredient_ids( int $user_id = 0 ): array {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) {
            return [];
        }

        $ids = get_user_meta( $user_id, App::USER_HOUSEHOLD_INGREDIENTS, true );
        if ( ! is_array( $ids ) ) {
            return [];
        }

        return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
    }

    public function is_household_ingredient_term( int $term_id, int $user_id = 0 ): bool {
        $term_id = absint( $term_id );
        if ( ! $term_id ) {
            return false;
        }

        $household_ids = $this->get_user_household_ingredient_ids( $user_id );
        if ( ! $household_ids ) {
            return false;
        }

        if ( in_array( $term_id, $household_ids, true ) ) {
            return true;
        }

        $ancestors = get_ancestors( $term_id, App::TAX_INGREDIENT, 'taxonomy' );
        foreach ( $ancestors as $ancestor_id ) {
            if ( in_array( (int) $ancestor_id, $household_ids, true ) ) {
                return true;
            }
        }

        return false;
    }

    public function add_user_household_ingredient_terms( array $term_ids, int $user_id = 0 ): int {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) {
            return 0;
        }

        $existing = $this->get_user_household_ingredient_ids( $user_id );
        $merged   = array_values( array_unique( array_filter( array_merge( $existing, array_map( 'absint', $term_ids ) ) ) ) );
        update_user_meta( $user_id, App::USER_HOUSEHOLD_INGREDIENTS, $merged );

        return max( 0, count( $merged ) - count( $existing ) );
    }

    public function remove_user_household_ingredient_terms( array $term_ids, int $user_id = 0 ): int {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) {
            return 0;
        }

        $remove   = array_values( array_unique( array_filter( array_map( 'absint', $term_ids ) ) ) );
        $existing = $this->get_user_household_ingredient_ids( $user_id );
        if ( ! $remove || ! $existing ) {
            return 0;
        }

        $remaining = array_values( array_diff( $existing, $remove ) );
        update_user_meta( $user_id, App::USER_HOUSEHOLD_INGREDIENTS, $remaining );

        return count( $existing ) - count( $remaining );
    }
}
