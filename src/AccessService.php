<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AccessService extends AbstractService {
    public function get_owned_post_or_die( int $post_id, string $post_type ): \WP_Post {
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || $post->post_type !== $post_type ) {
            wp_die( esc_html__( 'Not found.', 'cook-app' ), 404 );
        }
        if ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        return $post;
    }

    public function get_owned_shopping_list_or_die( int $post_id ): \WP_Post {
        $post = $this->get_owned_post_or_die( $post_id, App::SHOPPING_LIST_POST_TYPE );
        if ( (int) $post->post_parent !== 0 ) {
            wp_die( esc_html__( 'Shopping list not found.', 'cook-app' ), 404 );
        }
        return $post;
    }

    public function get_recipe_or_die( int $recipe_id ): \WP_Post {
        $post = $recipe_id ? get_post( $recipe_id ) : null;
        if ( ! $post || $post->post_type !== App::POST_TYPE ) {
            wp_die( esc_html__( 'Recipe not found.', 'cook-app' ), 404 );
        }
        return $post;
    }

    public function recipe_exists( int $recipe_id ): bool {
        $post = $recipe_id ? get_post( $recipe_id ) : null;
        return $post && $post->post_type === App::POST_TYPE;
    }
}
