<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Parses machine-readable schema.org Recipe JSON-LD. */
class SchemaOrgRecipeParser extends RecipeParser {
    public const SLUG = 'schema-org-json-ld';
    public const NAME = 'Schema.org Recipe JSON-LD';

    public function support_confidence( string $url, string $content_type, string $content ): int {
        return stripos( $content, 'application/ld+json' ) !== false && stripos( $content, 'Recipe' ) !== false ? 10 : 0;
    }

    public function parse( string $url, string $content_type, string $content ): ?array {
        if ( ! preg_match_all( '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', $content, $matches ) ) {
            return null;
        }
        foreach ( $matches[1] as $json ) {
            $json = preg_replace( '/[\x00-\x09\x0B\x0C\x0E-\x1F]/', ' ', trim( html_entity_decode( $json, ENT_QUOTES, 'UTF-8' ) ) );
            $data = json_decode( $json, true );
            $recipe = $data ? $this->find_recipe_node( $data ) : null;
            if ( $recipe ) {
                return $this->normalize_recipe( $recipe );
            }
        }
        return null;
    }

    private function find_recipe_node( $node ): ?array {
        if ( ! is_array( $node ) ) return null;
        foreach ( (array) ( $node['@type'] ?? [] ) as $type ) {
            if ( is_string( $type ) && strcasecmp( $type, 'Recipe' ) === 0 ) return $node;
        }
        foreach ( $node as $value ) {
            $recipe = is_array( $value ) ? $this->find_recipe_node( $value ) : null;
            if ( $recipe ) return $recipe;
        }
        return null;
    }

    private function normalize_recipe( array $recipe ): array {
        $raw_ingredients = $recipe['recipeIngredient'] ?? ( $recipe['ingredients'] ?? [] );
        $ingredient_parts = $this->ingredient_parts( $raw_ingredients );
        $ingredients = $ingredient_parts ? $this->flatten_parts( $ingredient_parts, 'ingredients' ) : $this->ingredients( $raw_ingredients );
        $instruction_parts = $this->instruction_parts( $recipe['recipeInstructions'] ?? [] );
        $instructions = $instruction_parts ? $this->flatten_parts( $instruction_parts, 'instructions' ) : $this->instructions( $recipe['recipeInstructions'] ?? [] );
        $prep_time = $this->duration_to_minutes( $recipe['prepTime'] ?? '' );
        $cook_time = $this->duration_to_minutes( $recipe['cookTime'] ?? '' );
        if ( ! $prep_time && ! $cook_time && ! empty( $recipe['totalTime'] ) ) {
            $cook_time = $this->duration_to_minutes( $recipe['totalTime'] );
        }

        return [
            'title'        => is_string( $recipe['name'] ?? null ) ? trim( $recipe['name'] ) : '',
            'description'  => is_string( $recipe['description'] ?? null ) ? trim( $recipe['description'] ) : '',
            'servings'     => $this->servings( $recipe['recipeYield'] ?? null ) ?: 4,
            'prep_time'    => $prep_time,
            'cook_time'    => $cook_time,
            'ingredients'  => $ingredients,
            'instructions' => $instructions,
            'parts'        => $this->merge_parts( $ingredient_parts, $instruction_parts ),
            'image_url'    => $this->image_url( $recipe['image'] ?? '' ),
        ];
    }

    private function servings( $yield ): int {
        $yield = is_array( $yield ) ? reset( $yield ) : $yield;
        if ( is_numeric( $yield ) ) return (int) $yield;
        return is_string( $yield ) && preg_match( '/(\d+)/', $yield, $matches ) ? (int) $matches[1] : 0;
    }

    private function ingredients( $raw ): array {
        if ( is_string( $raw ) ) $raw = preg_split( '/(?:\r?\n)+/', $raw );
        if ( ! is_array( $raw ) ) return [];
        if ( $this->has_list_items( $raw ) ) $raw = $raw['itemListElement'];
        $ingredients = [];
        foreach ( $raw as $ingredient ) {
            if ( is_array( $ingredient ) && $this->has_list_items( $ingredient ) ) {
                $ingredients = array_merge( $ingredients, $this->ingredients( $ingredient['itemListElement'] ) );
                continue;
            }
            if ( is_array( $ingredient ) && isset( $ingredient['item'] ) ) $ingredient = $ingredient['item'];
            $text = $this->ingredient_text( $ingredient );
            if ( $text !== '' ) $ingredients[] = Importer::parse_ingredient_line( $text );
        }
        return $ingredients;
    }

    private function ingredient_parts( $raw ): array {
        if ( ! is_array( $raw ) ) return [];
        $items = $this->has_list_items( $raw ) ? $raw['itemListElement'] : $raw;
        $parts = [];
        foreach ( $items as $item ) {
            if ( is_array( $item ) && isset( $item['item'] ) && is_array( $item['item'] ) ) $item = $item['item'];
            if ( ! is_array( $item ) || ! $this->has_list_items( $item ) ) continue;
            $type = $item['@type'] ?? '';
            if ( $type && ! $this->is_type( $type, 'ItemList' ) && ! $this->is_type( $type, 'ListItem' ) && ! $this->is_type( $type, 'HowToSection' ) ) continue;
            $part = [ 'title' => $this->scalar_text( $item['name'] ?? '' ), 'ingredients' => $this->ingredients( $item['itemListElement'] ), 'instructions' => [] ];
            if ( $part['title'] !== '' || $part['ingredients'] ) $parts[] = $part;
        }
        return $parts;
    }

    private function instruction_parts( $raw ): array {
        if ( ! is_array( $raw ) ) return [];
        $items = $this->has_list_items( $raw ) ? $raw['itemListElement'] : $raw;
        $parts = [];
        foreach ( $items as $item ) {
            if ( is_array( $item ) && isset( $item['item'] ) && is_array( $item['item'] ) ) $item = $item['item'];
            if ( ! is_array( $item ) || ! $this->is_type( $item['@type'] ?? '', 'HowToSection' ) ) continue;
            $part = [ 'title' => $this->scalar_text( $item['name'] ?? '' ), 'ingredients' => [], 'instructions' => $this->instructions( $item['itemListElement'] ?? ( $item['steps'] ?? [] ) ) ];
            if ( $part['title'] !== '' || $part['instructions'] ) $parts[] = $part;
        }
        return $parts;
    }

    private function instructions( $raw ): array {
        if ( is_string( $raw ) ) $raw = preg_split( '/(?:\r?\n)+|(?<=[.!?])\s+(?=[A-Z])/', $raw );
        if ( ! is_array( $raw ) ) return [];
        $instructions = [];
        foreach ( $raw as $step ) {
            if ( is_string( $step ) ) {
                $text = Importer::clean_step( $step );
            } elseif ( is_array( $step ) && $this->is_type( $step['@type'] ?? '', 'HowToSection' ) ) {
                $name = $this->scalar_text( $step['name'] ?? '' );
                if ( $name !== '' ) $instructions[] = $name . ':';
                $instructions = array_merge( $instructions, $this->instructions( $step['itemListElement'] ?? [] ) );
                continue;
            } elseif ( is_array( $step ) ) {
                $text = Importer::clean_step( (string) ( $step['text'] ?? ( $step['name'] ?? '' ) ) );
            } else {
                continue;
            }
            if ( $text !== '' ) $instructions[] = $text;
        }
        return $instructions;
    }

    private function merge_parts( array $first, array $second ): array {
        $parts = [];
        $indexes = [];
        foreach ( array_merge( $first, $second ) as $part ) {
            $key = preg_replace( '/[^a-z0-9]+/', '', strtolower( trim( $part['title'] ) ) );
            if ( $key !== '' && isset( $indexes[ $key ] ) ) {
                $index = $indexes[ $key ];
                foreach ( [ 'ingredients', 'instructions' ] as $field ) {
                    if ( ! $parts[ $index ][ $field ] && $part[ $field ] ) $parts[ $index ][ $field ] = $part[ $field ];
                }
                continue;
            }
            $parts[] = $part;
            if ( $key !== '' ) $indexes[ $key ] = count( $parts ) - 1;
        }
        return $parts;
    }

    private function flatten_parts( array $parts, string $field ): array {
        $values = [];
        foreach ( $parts as $part ) $values = array_merge( $values, $part[ $field ] );
        return $values;
    }

    private function ingredient_text( $ingredient ): string {
        if ( is_string( $ingredient ) ) return trim( $ingredient );
        if ( ! is_array( $ingredient ) ) return '';
        foreach ( [ '@value', 'text' ] as $field ) {
            if ( isset( $ingredient[ $field ] ) ) return $this->scalar_text( $ingredient[ $field ] );
        }
        if ( isset( $ingredient['value'] ) || isset( $ingredient['name'] ) ) {
            return trim( preg_replace( '/\s+/', ' ', $this->scalar_text( $ingredient['value'] ?? '' ) . ' ' . $this->scalar_text( $ingredient['unitText'] ?? '' ) . ' ' . $this->scalar_text( $ingredient['name'] ?? '' ) ) );
        }
        return isset( $ingredient['item'] ) ? $this->ingredient_text( $ingredient['item'] ) : '';
    }

    private function image_url( $image ): string {
        if ( is_string( $image ) ) return filter_var( trim( $image ), FILTER_VALIDATE_URL ) ? trim( $image ) : '';
        if ( ! is_array( $image ) ) return '';
        foreach ( [ 'url', 'contentUrl' ] as $field ) {
            if ( isset( $image[ $field ] ) ) return $this->image_url( $image[ $field ] );
        }
        foreach ( $image as $candidate ) {
            $url = $this->image_url( $candidate );
            if ( $url !== '' ) return $url;
        }
        return '';
    }

    private function has_list_items( array $node ): bool {
        return isset( $node['itemListElement'] ) && is_array( $node['itemListElement'] );
    }

    private function is_type( $type, string $expected ): bool {
        foreach ( (array) $type as $candidate ) {
            if ( is_string( $candidate ) && strcasecmp( $candidate, $expected ) === 0 ) return true;
        }
        return false;
    }

    private function scalar_text( $value ): string {
        return is_scalar( $value ) ? trim( (string) $value ) : '';
    }

    private function duration_to_minutes( $duration ): int {
        if ( ! is_string( $duration ) || ! preg_match( '/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $duration, $matches ) ) return 0;
        $hours = isset( $matches[1] ) && $matches[1] !== '' ? (int) $matches[1] : 0;
        $minutes = isset( $matches[2] ) && $matches[2] !== '' ? (int) $matches[2] : 0;
        $seconds = isset( $matches[3] ) && $matches[3] !== '' ? (int) $matches[3] : 0;
        return $hours * 60 + $minutes + (int) ceil( $seconds / 60 );
    }
}
