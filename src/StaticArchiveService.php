<?php

namespace Cookbook;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class StaticArchiveService extends AbstractService {
    /**
     * Opt Cook App recipes into Static Archive without making the CPT public.
     */
    public function add_static_archive_post_type( array $post_types ): array {
        if ( ! in_array( App::POST_TYPE, $post_types, true ) ) {
            $post_types[] = App::POST_TYPE;
        }

        return $post_types;
    }

    /**
     * Render Cook App's structured recipe meta as Static Archive HTML.
     */
    public function static_archive_recipe_html( string $html, $post, $generator = null ): string {
        if ( ! $post instanceof \WP_Post || $post->post_type !== App::POST_TYPE ) {
            return $html;
        }

        return $this->render_static_archive_recipe_html( $post );
    }

    /**
     * Render Cook App's structured recipe meta as Static Archive Markdown.
     *
     * The incoming value is null when Static Archive would otherwise derive
     * Markdown from HTML.
     */
    public function static_archive_recipe_markdown( $markdown, $post, $generator = null ) {
        if ( ! $post instanceof \WP_Post || $post->post_type !== App::POST_TYPE ) {
            return $markdown;
        }

        return $this->render_static_archive_recipe_markdown( $post );
    }

    private function render_static_archive_recipe_html( \WP_Post $post ): string {
        $id           = (int) $post->ID;
        $servings     = (int) get_post_meta( $id, App::META_SERVINGS, true );
        $prep         = (int) get_post_meta( $id, App::META_PREP, true );
        $cook         = (int) get_post_meta( $id, App::META_COOK, true );
        $source_url   = (string) get_post_meta( $id, App::META_SOURCE_URL, true );
        $ingredients  = (array) get_post_meta( $id, App::META_INGREDIENTS, true );
        $instructions = $this->clean_static_archive_instructions( (array) get_post_meta( $id, App::META_INSTRUCTIONS, true ) );
        $parts        = $this->clean_static_archive_parts( (array) get_post_meta( $id, App::META_PARTS, true ) );
        $notes        = (string) get_post_meta( $id, App::META_NOTES, true );

        $html = '';

        if ( has_post_thumbnail( $id ) ) {
            $html .= '<figure class="recipe-photo">' . get_the_post_thumbnail(
                $id,
                'large',
                [
                    'style' => 'max-width:100%;height:auto',
                    'alt'   => esc_attr( get_the_title( $post ) ),
                ]
            ) . '</figure>';
        }

        $meta = [];
        if ( $servings ) {
            $meta[] = sprintf(
                /* translators: %d: servings */
                _n( '%d serving', '%d servings', $servings, 'cook-app' ),
                $servings
            );
        }
        if ( $prep ) {
            $meta[] = sprintf(
                /* translators: %d: prep time in minutes */
                __( 'Prep: %d min', 'cook-app' ),
                $prep
            );
        }
        if ( $cook ) {
            $meta[] = sprintf(
                /* translators: %d: cook time in minutes */
                __( 'Cook: %d min', 'cook-app' ),
                $cook
            );
        }
        if ( $source_url ) {
            $source_label = wp_parse_url( $source_url, PHP_URL_HOST ) ?: $source_url;
            $meta[] = sprintf(
                '%s <a href="%s">%s</a>',
                esc_html__( 'Source:', 'cook-app' ),
                esc_url( $source_url ),
                esc_html( $source_label )
            );
        }
        if ( $meta ) {
            $html .= '<ul class="recipe-meta"><li>' . implode( '</li><li>', array_map( 'wp_kses_post', $meta ) ) . '</li></ul>';
        }

        $term_groups = [
            __( 'Categories', 'cook-app' ) => wp_get_object_terms( $id, App::TAX_CATEGORY ),
            __( 'Cuisines', 'cook-app' )   => wp_get_object_terms( $id, App::TAX_CUISINE ),
            __( 'Tags', 'cook-app' )       => wp_get_object_terms( $id, App::TAX_TAG ),
        ];
        $term_lines = [];
        foreach ( $term_groups as $label => $terms ) {
            if ( is_wp_error( $terms ) || ! $terms ) {
                continue;
            }
            $term_lines[] = '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( implode( ', ', wp_list_pluck( $terms, 'name' ) ) ) . '</dd>';
        }
        if ( $term_lines ) {
            $html .= '<dl class="recipe-terms">' . implode( '', $term_lines ) . '</dl>';
        }

        if ( $post->post_content ) {
            $html .= '<div class="recipe-description">' . wp_kses_post( wpautop( $post->post_content ) ) . '</div>';
        }

        $html .= '<h2>' . esc_html__( 'Ingredients', 'cook-app' ) . '</h2>';
        if ( $this->parts_have_ingredients( $parts ) ) {
            $html .= $this->render_static_archive_ingredient_parts_html( $parts );
        } elseif ( $ingredients ) {
            $html .= $this->render_static_archive_ingredients_html( $ingredients );
        } else {
            $html .= '<p>' . esc_html__( 'No ingredients yet.', 'cook-app' ) . '</p>';
        }

        $html .= '<h2>' . esc_html__( 'Instructions', 'cook-app' ) . '</h2>';
        if ( $this->parts_have_instructions( $parts ) ) {
            $html .= $this->render_static_archive_instruction_parts_html( $parts );
        } elseif ( $instructions ) {
            $html .= $this->render_static_archive_instructions_html( $instructions );
        } else {
            $html .= '<p>' . esc_html__( 'No instructions yet.', 'cook-app' ) . '</p>';
        }

        if ( $notes ) {
            $html .= '<h2>' . esc_html__( 'Notes', 'cook-app' ) . '</h2>';
            $html .= '<div class="recipe-notes">' . wp_kses_post( Markdown::to_html( $notes ) ) . '</div>';
        }

        return $html;
    }

    private function render_static_archive_recipe_markdown( \WP_Post $post ): string {
        $id           = (int) $post->ID;
        $servings     = (int) get_post_meta( $id, App::META_SERVINGS, true );
        $prep         = (int) get_post_meta( $id, App::META_PREP, true );
        $cook         = (int) get_post_meta( $id, App::META_COOK, true );
        $source_url   = (string) get_post_meta( $id, App::META_SOURCE_URL, true );
        $ingredients  = (array) get_post_meta( $id, App::META_INGREDIENTS, true );
        $instructions = $this->clean_static_archive_instructions( (array) get_post_meta( $id, App::META_INSTRUCTIONS, true ) );
        $parts        = $this->clean_static_archive_parts( (array) get_post_meta( $id, App::META_PARTS, true ) );
        $notes        = (string) get_post_meta( $id, App::META_NOTES, true );

        $sections = [];

        $thumbnail_url = get_the_post_thumbnail_url( $id, 'large' );
        if ( $thumbnail_url ) {
            $sections[] = '![' . $this->static_archive_markdown_text( get_the_title( $post ) ) . '](' . esc_url_raw( $thumbnail_url ) . ')';
        }

        $meta = [];
        if ( $servings ) {
            $meta[] = sprintf(
                /* translators: %d: servings */
                _n( '%d serving', '%d servings', $servings, 'cook-app' ),
                $servings
            );
        }
        if ( $prep ) {
            $meta[] = sprintf(
                /* translators: %d: prep time in minutes */
                __( 'Prep: %d min', 'cook-app' ),
                $prep
            );
        }
        if ( $cook ) {
            $meta[] = sprintf(
                /* translators: %d: cook time in minutes */
                __( 'Cook: %d min', 'cook-app' ),
                $cook
            );
        }
        if ( $source_url ) {
            $meta[] = __( 'Source:', 'cook-app' ) . ' ' . $source_url;
        }
        if ( $meta ) {
            $sections[] = implode( "\n", array_map( fn( $line ) => '- ' . $this->static_archive_markdown_text( $line ), $meta ) );
        }

        $description = $this->static_archive_markdown_text( $post->post_content );
        if ( $description !== '' ) {
            $sections[] = $description;
        }

        $ingredient_markdown = $this->parts_have_ingredients( $parts )
            ? $this->render_static_archive_ingredient_parts_markdown( $parts )
            : $this->render_static_archive_ingredients_markdown( $ingredients );
        $sections[] = "## " . __( 'Ingredients', 'cook-app' ) . "\n\n" . ( $ingredient_markdown !== '' ? $ingredient_markdown : __( 'No ingredients yet.', 'cook-app' ) );

        $instruction_markdown = $this->parts_have_instructions( $parts )
            ? $this->render_static_archive_instruction_parts_markdown( $parts )
            : $this->render_static_archive_instructions_markdown( $instructions );
        $sections[] = "## " . __( 'Instructions', 'cook-app' ) . "\n\n" . ( $instruction_markdown !== '' ? $instruction_markdown : __( 'No instructions yet.', 'cook-app' ) );

        $notes_text = $this->static_archive_markdown_text( Markdown::strip_images( $notes ) );
        if ( $notes_text !== '' ) {
            $sections[] = "## " . __( 'Notes', 'cook-app' ) . "\n\n" . $notes_text;
        }

        return trim( implode( "\n\n", array_filter( $sections ) ) );
    }

    private function clean_static_archive_instructions( array $instructions ): array {
        $clean = [];
        foreach ( $instructions as $step ) {
            $step = Importer::clean_step( (string) $step );
            if ( $step !== '' ) {
                $clean[] = $step;
            }
        }

        return $clean;
    }

    private function clean_static_archive_parts( array $parts ): array {
        $clean = [];
        foreach ( $parts as $part ) {
            if ( ! is_array( $part ) ) {
                continue;
            }

            $ingredients = [];
            foreach ( (array) ( $part['ingredients'] ?? [] ) as $ingredient ) {
                if ( ! is_array( $ingredient ) ) {
                    continue;
                }
                if ( $this->static_archive_ingredient_text( $ingredient ) !== '' ) {
                    $ingredients[] = $ingredient;
                }
            }

            $instructions = $this->clean_static_archive_instructions( (array) ( $part['instructions'] ?? [] ) );
            if ( ! $ingredients && ! $instructions ) {
                continue;
            }

            $clean[] = [
                'title'        => sanitize_text_field( (string) ( $part['title'] ?? '' ) ),
                'ingredients'  => $ingredients,
                'instructions' => $instructions,
            ];
        }

        return $clean;
    }

    private function parts_have_ingredients( array $parts ): bool {
        foreach ( $parts as $part ) {
            if ( ! empty( $part['ingredients'] ) ) {
                return true;
            }
        }

        return false;
    }

    private function parts_have_instructions( array $parts ): bool {
        foreach ( $parts as $part ) {
            if ( ! empty( $part['instructions'] ) ) {
                return true;
            }
        }

        return false;
    }

    private function render_static_archive_ingredients_html( array $ingredients ): string {
        $items = [];
        foreach ( $ingredients as $ingredient ) {
            if ( ! is_array( $ingredient ) ) {
                continue;
            }
            $line = $this->static_archive_ingredient_text( $ingredient );
            if ( $line !== '' ) {
                $items[] = '<li>' . esc_html( $line ) . '</li>';
            }
        }

        return $items ? '<ul>' . implode( '', $items ) . '</ul>' : '';
    }

    private function render_static_archive_ingredient_parts_html( array $parts ): string {
        $html = '';
        foreach ( $parts as $part ) {
            if ( empty( $part['ingredients'] ) ) {
                continue;
            }
            $section = '';
            if ( ! empty( $part['title'] ) ) {
                $section .= '<h3>' . esc_html( $part['title'] ) . '</h3>';
            }
            $section .= $this->render_static_archive_ingredients_html( $part['ingredients'] );
            if ( $section !== '' ) {
                $html .= '<section class="recipe-part">' . $section . '</section>';
            }
        }

        return $html;
    }

    private function render_static_archive_instructions_html( array $instructions ): string {
        return $instructions ? '<ol><li>' . implode( '</li><li>', array_map( 'wp_kses_post', $instructions ) ) . '</li></ol>' : '';
    }

    private function render_static_archive_instruction_parts_html( array $parts ): string {
        $html = '';
        foreach ( $parts as $part ) {
            if ( empty( $part['instructions'] ) ) {
                continue;
            }
            $section = '';
            if ( ! empty( $part['title'] ) ) {
                $section .= '<h3>' . esc_html( $part['title'] ) . '</h3>';
            }
            $section .= $this->render_static_archive_instructions_html( $part['instructions'] );
            if ( $section !== '' ) {
                $html .= '<section class="recipe-part">' . $section . '</section>';
            }
        }

        return $html;
    }

    private function render_static_archive_ingredients_markdown( array $ingredients ): string {
        $lines = [];
        foreach ( $ingredients as $ingredient ) {
            if ( ! is_array( $ingredient ) ) {
                continue;
            }
            $line = $this->static_archive_ingredient_text( $ingredient );
            if ( $line !== '' ) {
                $lines[] = '- ' . $this->static_archive_markdown_text( $line );
            }
        }

        return implode( "\n", $lines );
    }

    private function render_static_archive_ingredient_parts_markdown( array $parts ): string {
        $sections = [];
        foreach ( $parts as $part ) {
            if ( empty( $part['ingredients'] ) ) {
                continue;
            }
            $lines = [];
            if ( ! empty( $part['title'] ) ) {
                $lines[] = '### ' . $this->static_archive_markdown_text( $part['title'] );
                $lines[] = '';
            }
            $lines[] = $this->render_static_archive_ingredients_markdown( $part['ingredients'] );
            $sections[] = trim( implode( "\n", array_filter( $lines, static fn( $line ) => $line !== null ) ) );
        }

        return trim( implode( "\n\n", array_filter( $sections ) ) );
    }

    private function render_static_archive_instructions_markdown( array $instructions ): string {
        $lines = [];
        foreach ( $instructions as $index => $step ) {
            $text = $this->static_archive_markdown_text( $step );
            if ( $text !== '' ) {
                $lines[] = ( $index + 1 ) . '. ' . $text;
            }
        }

        return implode( "\n", $lines );
    }

    private function render_static_archive_instruction_parts_markdown( array $parts ): string {
        $sections = [];
        foreach ( $parts as $part ) {
            if ( empty( $part['instructions'] ) ) {
                continue;
            }
            $lines = [];
            if ( ! empty( $part['title'] ) ) {
                $lines[] = '### ' . $this->static_archive_markdown_text( $part['title'] );
                $lines[] = '';
            }
            $lines[] = $this->render_static_archive_instructions_markdown( $part['instructions'] );
            $sections[] = trim( implode( "\n", array_filter( $lines, static fn( $line ) => $line !== null ) ) );
        }

        return trim( implode( "\n\n", array_filter( $sections ) ) );
    }

    private function static_archive_ingredient_text( array $ingredient ): string {
        $rendered = Units::render_ingredient( $ingredient, 1.0, 'metric' );
        $quantity = trim( trim( (string) $rendered['amount'] ) . ' ' . trim( (string) $rendered['unit'] ) );
        $line     = trim( $quantity . ' ' . trim( (string) $rendered['name'] ) );

        if ( ! empty( $rendered['notes'] ) ) {
            $line .= ' (' . trim( (string) $rendered['notes'] ) . ')';
        }

        return trim( preg_replace( '/\s+/', ' ', $line ) );
    }

    private function static_archive_markdown_text( string $value ): string {
        return trim( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
    }
}
