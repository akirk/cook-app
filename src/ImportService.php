<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ImportService extends AbstractService {
    /**
     * Shared import flow used by the import form, browser extension, and abilities.
     *
     * @return int|\WP_Error Recipe ID on success.
     */
    public function import_recipe( string $url = '', string $paste = '', string $image_url = '', string $html = '' ) {
        $parsed = $this->parse_recipe_input( $url, $paste, $html );
        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        if ( $image_url !== '' ) {
            $parsed['image_url'] = $image_url;
        }

        return $this->create_recipe_from_parsed( $parsed, $url );
    }

    /**
     * Parse recipe input from captured HTML, URL, or pasted text in priority order.
     *
     * @return array|\WP_Error
     */
    private function parse_recipe_input( string $url = '', string $paste = '', string $html = '' ) {
        if ( $url === '' && trim( $paste ) === '' && trim( $html ) === '' ) {
            return new \WP_Error( 'cookbook_import_empty', __( 'Provide a source URL or pasted recipe text.', 'cook-app' ) );
        }

        $parsed = null;
        if ( $html !== '' ) {
            $parsed = Importer::from_html( $html );
        }
        if ( ! $parsed && $url !== '' ) {
            $parsed = Importer::from_url( $url );
        }
        if ( ! $parsed && trim( $paste ) !== '' ) {
            $parsed = Importer::from_text( $paste );
        }
        if ( ! $parsed ) {
            return new \WP_Error( 'cookbook_import_parse_failed', __( 'Could not parse a recipe from that input.', 'cook-app' ) );
        }

        return $parsed;
    }

    /**
     * Create a recipe from an already parsed payload.
     *
     * @return int|\WP_Error
     */
    private function create_recipe_from_parsed( array $parsed, string $url = '' ) {
        $post_id = wp_insert_post( [
            'post_type'    => App::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $parsed['title'] ?: __( 'Imported recipe', 'cook-app' ),
            'post_content' => $parsed['description'] ?? '',
            'post_author'  => get_current_user_id(),
        ], true );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        $this->services->recipes()->apply_parsed_payload( (int) $post_id, $parsed, $url, false );
        return (int) $post_id;
    }

    public function handle_import(): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( 'cookbook_import' );

        $url   = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
        // $paste is HTML-ish recipe text; sanitize via wp_kses_post which preserves line breaks.
        $paste = isset( $_POST['paste'] ) ? wp_kses_post( wp_unslash( $_POST['paste'] ) ) : '';

        $image_url = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';
        $existing = $url !== '' ? $this->services->recipes()->find_recipe_by_source_url( $url ) : null;
        if ( $existing ) {
            wp_safe_redirect( home_url( '/' . $this->get_url_path() . '/recipe/' . $existing->ID ) );
            exit;
        }

        $post_id = $this->import_recipe( $url, $paste, $image_url );
        if ( is_wp_error( $post_id ) && in_array( $post_id->get_error_code(), [ 'cookbook_import_empty', 'cookbook_import_parse_failed' ], true ) ) {
            $this->redirect_import_parse_error( $url );
        }
        if ( is_wp_error( $post_id ) ) {
            wp_die( esc_html( $post_id->get_error_message() ) );
        }

        wp_safe_redirect( home_url( '/' . $this->get_url_path() . '/recipe/' . $post_id . '/edit' ) );
        exit;
    }

    /**
     * Re-fetch a recipe from its stored source URL and overwrite parsed fields.
     *
     * Only fields the parser actually returned are touched, so that a partial
     * parse (e.g. missing prep_time) doesn't clobber the recipe's existing data.
     * Notes and taxonomy assignments are always left alone.
     */
    public function handle_refetch(): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }
        check_admin_referer( 'cookbook_refetch' );

        $id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $post = $id ? get_post( $id ) : null;
        if ( ! $post || $post->post_type !== App::POST_TYPE ) {
            wp_die( esc_html__( 'Recipe not found.', 'cook-app' ), 404 );
        }
        $url = (string) get_post_meta( $id, App::META_SOURCE_URL, true );
        if ( $url === '' || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            wp_safe_redirect( home_url( '/' . $this->get_url_path() . '/recipe/' . $id . '?refetch=no_url' ) );
            exit;
        }

        $parsed = $this->parse_recipe_input( $url );
        if ( is_wp_error( $parsed ) ) {
            wp_safe_redirect( home_url( '/' . $this->get_url_path() . '/recipe/' . $id . '?refetch=parse_error' ) );
            exit;
        }

        $update = [ 'ID' => $id ];
        if ( ! empty( $parsed['title'] ) ) {
            $update['post_title'] = $parsed['title'];
        }
        if ( ! empty( $parsed['description'] ) ) {
            $update['post_content'] = $parsed['description'];
        }
        if ( count( $update ) > 1 ) {
            wp_update_post( $update );
        }

        $this->services->recipes()->apply_parsed_payload( $id, $parsed, $url, true );

        wp_safe_redirect( home_url( '/' . $this->get_url_path() . '/recipe/' . $id . '?refetch=ok' ) );
        exit;
    }

    /**
     * Friends browser-extension integration.
     *
     * Adds a "Save to Recipes" action to the Friends extension popup. When the
     * user clicks it, the extension POSTs the current page's HTML to our
     * endpoint with the URL as a query arg. We parse it server-side using the
     * same Importer used for the manual import form.
     *
     * @see https://github.com/akirk/browser-extension
     */
    public function register_browser_extension_action( $actions ) {
        if ( ! is_array( $actions ) ) $actions = [];
        $actions[] = [
            'name'     => __( 'Save as Recipe', 'cook-app' ),
            'url'      => home_url( '/?cookbook-collect={current_url}' ),
            'method'   => 'POST',
            'fields'   => [ 'body' => '{page_html}' ],
            'category' => __( 'Recipes', 'cook-app' ),
        ];
        return $actions;
    }

    public function handle_extension_save(): void {
        // The browser extension authenticates via the user's logged-in session
        // (cookies); there is no nonce to verify here, hence the phpcs ignores.
        if ( empty( $_REQUEST['cookbook-collect'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $request_method = isset( $_SERVER['REQUEST_METHOD'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) )
            : '';
        if ( 'POST' !== $request_method ) {
            return;
        }
        if ( ! is_user_logged_in() ) {
            auth_redirect();
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
        }

        $url = esc_url_raw( wp_unslash( $_REQUEST['cookbook-collect'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        // Raw page HTML; passed to Importer::from_html which extracts JSON-LD or strips tags.
        $html = isset( $_POST['body'] ) ? (string) wp_unslash( $_POST['body'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $existing = $url !== '' ? $this->services->recipes()->find_recipe_by_source_url( $url ) : null;
        if ( $existing ) {
            wp_safe_redirect( home_url( '/' . $this->get_url_path() . '/recipe/' . $existing->ID ) );
            exit;
        }

        $post_id = $this->import_recipe( $url, '', '', $html );
        if ( is_wp_error( $post_id ) && in_array( $post_id->get_error_code(), [ 'cookbook_import_empty', 'cookbook_import_parse_failed' ], true ) ) {
            $this->redirect_import_parse_error( $url );
        }
        if ( is_wp_error( $post_id ) ) {
            wp_die( esc_html( $post_id->get_error_message() ) );
        }

        wp_safe_redirect( home_url( '/' . $this->get_url_path() . '/recipe/' . $post_id . '/edit' ) );
        exit;
    }

    public function ajax_lookup_source_url(): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Not allowed.', 'cook-app' ) ], 403 );
        }
        check_ajax_referer( 'cookbook_import' );
        $url = isset( $_POST['source_url'] ) ? esc_url_raw( wp_unslash( $_POST['source_url'] ) ) : '';
        if ( $url === '' ) {
            wp_send_json_success( [ 'exists' => false ] );
        }

        $recipe = $this->services->recipes()->find_recipe_by_source_url( $url );
        if ( ! $recipe ) {
            wp_send_json_success( [ 'exists' => false ] );
        }

        wp_send_json_success( [
            'exists' => true,
            'recipe' => [
                'id'       => (int) $recipe->ID,
                'title'    => get_the_title( $recipe ),
                'view_url' => home_url( '/' . $this->get_url_path() . '/recipe/' . $recipe->ID ),
            ],
        ] );
    }

    public function ajax_parse_url(): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Not allowed.', 'cook-app' ) ], 403 );
        }
        check_ajax_referer( 'cookbook_import' );
        $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        if ( $url === '' ) {
            wp_send_json_error( [ 'message' => __( 'Missing URL.', 'cook-app' ) ] );
        }
        $parsed = $this->parse_recipe_input( $url );
        if ( is_wp_error( $parsed ) ) {
            wp_send_json_error( [ 'message' => __( 'Could not parse a recipe from that URL.', 'cook-app' ) ] );
        }
        wp_send_json_success( $parsed );
    }

    public function ajax_parse_text(): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => __( 'Not allowed.', 'cook-app' ) ], 403 );
        }
        check_ajax_referer( 'cookbook_import' );
        $paste = isset( $_POST['paste'] ) ? wp_kses_post( wp_unslash( $_POST['paste'] ) ) : '';
        if ( trim( $paste ) === '' ) {
            wp_send_json_error( [ 'message' => __( 'Paste recipe text to preview it.', 'cook-app' ) ] );
        }
        $parsed = $this->parse_recipe_input( '', $paste );
        if ( is_wp_error( $parsed ) ) {
            wp_send_json_error( [ 'message' => __( 'No ingredients or instructions detected yet.', 'cook-app' ) ] );
        }
        wp_send_json_success( $parsed );
    }

    private function redirect_import_parse_error( string $source_url = '' ): void {
        $args = [ 'error' => 'parse' ];
        if ( $source_url !== '' ) {
            $args['source_url'] = $source_url;
        }
        wp_safe_redirect( add_query_arg( $args, home_url( '/' . $this->get_url_path() . '/import' ) ) );
        exit;
    }
}
