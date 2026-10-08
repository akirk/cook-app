<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Parses machine-readable schema.org Recipe JSON-LD. */
class JsonLdRecipeParser extends RecipeParser {
    public const SLUG = 'json-ld';
    public const NAME = 'Recipe JSON-LD';

    private string $cached_document = '';

    private ?array $cached_recipe = null;

    public function support_confidence( string $url, string $content_type, string $content ): int {
        if ( $this->recipe_from_document( $content_type, $content ) ) {
            return 10;
        }
        return 0;
    }

    public function parse( string $url, string $content_type, string $content ): ?array {
        $recipe = $this->recipe_from_document( $content_type, $content );
        if ( ! $recipe ) {
            return null;
        }
        return $this->normalize_recipe( $recipe );
    }

    /** Parse every recipe in a JSON-LD file using the same normalizer as web imports. */
    public function parse_all( string $json ): array {
        $data = json_decode( $json, true, 64 );
        if ( ! is_array( $data ) || ! $this->uses_schema_vocabulary( $data ) ) {
            return [];
        }
        return array_map( function( array $node ): array {
            $recipe = $this->normalize_recipe( $node );
            $recipe['@id'] = $this->scalar_text( $node['@id'] ?? '' );
            $recipe['source_url'] = $this->scalar_text( $node['isBasedOn'] ?? '' ) ?: $this->scalar_text( $node['url'] ?? '' );
            $recipe['notes'] = '';
            foreach ( (array) ( $node['comment'] ?? [] ) as $comment ) {
                if ( is_array( $comment ) && ( $comment['name'] ?? '' ) === 'Author Notes' ) {
                    $recipe['notes'] = $this->scalar_text( $comment['text'] ?? '' );
                    break;
                }
            }
            foreach ( [ 'recipeCategory' => 'categories', 'recipeCuisine' => 'cuisines', 'keywords' => 'tags' ] as $field => $target ) {
                $values = $node[ $field ] ?? [];
                if ( is_string( $values ) ) {
                    $values = $field === 'keywords' ? explode( ',', $values ) : [ $values ];
                }
                $recipe[ $target ] = is_array( $values ) ? array_values( array_filter( array_map( [ $this, 'scalar_text' ], $values ) ) ) : [];
            }
            return $recipe;
        }, $this->find_recipe_nodes( $data ) );
    }

    private function find_recipe_nodes( array $node ): array {
        foreach ( (array) ( $node['@type'] ?? [] ) as $type ) {
            if ( is_string( $type ) && ( strcasecmp( $type, 'Recipe' ) === 0 || preg_match( '#^https?://schema\.org/Recipe$#i', $type ) ) ) {
                return [ $node ];
            }
        }
        $recipes = [];
        foreach ( $node as $value ) {
            if ( is_array( $value ) ) {
                $recipes = array_merge( $recipes, $this->find_recipe_nodes( $value ) );
            }
        }
        return $recipes;
    }

    private function recipe_from_document( string $content_type, string $content ): ?array {
        $document_key = hash( 'sha256', $content_type . "\n" . $content );
        if ( $document_key === $this->cached_document ) {
            return $this->cached_recipe;
        }

        $this->cached_document = $document_key;
        $this->cached_recipe = null;
        $documents = [];
        if ( strtolower( trim( strtok( $content_type, ';' ) ) ) === 'application/ld+json' ) {
            $documents[] = $content;
        } elseif ( preg_match_all( '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', $content, $matches ) ) {
            $documents = $matches[1];
        }

        foreach ( $documents as $json ) {
            $json = preg_replace( '/[\x00-\x09\x0B\x0C\x0E-\x1F]/', ' ', trim( html_entity_decode( $json, ENT_QUOTES, 'UTF-8' ) ) );
            $data = json_decode( $json, true );
            $recipe = null;
            if ( $data && $this->uses_schema_vocabulary( $data ) ) {
                $recipe = $this->find_recipe_node( $data );
            }
            if ( $recipe ) {
                $this->cached_recipe = $recipe;
                break;
            }
        }

        return $this->cached_recipe;
    }

    private function find_recipe_node( $node ): ?array {
        if ( ! is_array( $node ) ) return null;
        foreach ( (array) ( $node['@type'] ?? [] ) as $type ) {
            if ( ! is_string( $type ) ) {
                continue;
            }
            if ( strcasecmp( $type, 'Recipe' ) === 0 || preg_match( '#^https?://schema\.org/Recipe$#i', $type ) ) {
                return $node;
            }
        }
        foreach ( $node as $value ) {
            $recipe = null;
            if ( is_array( $value ) ) {
                $recipe = $this->find_recipe_node( $value );
            }
            if ( $recipe ) return $recipe;
        }
        return null;
    }

    private function uses_schema_vocabulary( $node ): bool {
        if ( is_string( $node ) ) {
            return (bool) preg_match( '#^https?://schema\.org/?$#i', $node );
        }
        if ( ! is_array( $node ) ) {
            return false;
        }
        if ( isset( $node['@context'] ) && $this->uses_schema_vocabulary( $node['@context'] ) ) {
            return true;
        }
        foreach ( (array) ( $node['@type'] ?? [] ) as $type ) {
            if ( is_string( $type ) && preg_match( '#^https?://schema\.org/#i', $type ) ) {
                return true;
            }
        }
        foreach ( $node as $value ) {
            if ( $this->uses_schema_vocabulary( $value ) ) {
                return true;
            }
        }
        return false;
    }

    private function normalize_recipe( array $recipe ): array {
        $raw_ingredients = $recipe['recipeIngredient'] ?? ( $recipe['ingredients'] ?? [] );
        $ingredient_parts = $this->ingredient_parts( $raw_ingredients );
        $ingredients = $this->ingredients( $raw_ingredients );
        if ( $ingredient_parts ) {
            $ingredients = $this->flatten_parts( $ingredient_parts, 'ingredients' );
        }
        $instruction_parts = $this->instruction_parts( $recipe['recipeInstructions'] ?? [] );
        $instructions = $this->instructions( $recipe['recipeInstructions'] ?? [] );
        if ( $instruction_parts ) {
            $instructions = $this->flatten_parts( $instruction_parts, 'instructions' );
        }
        $prep_time = $this->duration_to_minutes( $recipe['prepTime'] ?? '' );
        $cook_time = $this->duration_to_minutes( $recipe['cookTime'] ?? '' );
        if ( ! $prep_time && ! $cook_time && ! empty( $recipe['totalTime'] ) ) {
            $cook_time = $this->duration_to_minutes( $recipe['totalTime'] );
        }

        $title = '';
        if ( is_string( $recipe['name'] ?? null ) ) {
            $title = trim( $recipe['name'] );
        }
        $description = '';
        if ( is_string( $recipe['description'] ?? null ) ) {
            $description = trim( $recipe['description'] );
        }
        $servings = $this->servings( $recipe['recipeYield'] ?? null );
        if ( ! $servings ) {
            $servings = 4;
        }

        return [
            'title'        => $title,
            'description'  => $description,
            'servings'     => $servings,
            'prep_time'    => $prep_time,
            'cook_time'    => $cook_time,
            'ingredients'  => $ingredients,
            'instructions' => $instructions,
            'parts'        => $this->merge_parts( $ingredient_parts, $instruction_parts ),
            'image_url'    => $this->image_url( $recipe['image'] ?? '' ),
        ];
    }

    private function servings( $yield ): int {
        if ( is_array( $yield ) ) {
            $yield = reset( $yield );
        }
        if ( is_numeric( $yield ) ) return (int) $yield;
        if ( is_string( $yield ) && preg_match( '/(\d+)/', $yield, $matches ) ) {
            return (int) $matches[1];
        }
        return 0;
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
        $items = $raw;
        if ( $this->has_list_items( $raw ) ) {
            $items = $raw['itemListElement'];
        }
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
        $items = $raw;
        if ( $this->has_list_items( $raw ) ) {
            $items = $raw['itemListElement'];
        }
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
                $text = Importer::clean_step( $this->scalar_text( $step['text'] ?? ( $step['name'] ?? '' ) ) );
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
        if ( isset( $ingredient['item'] ) ) {
            return $this->ingredient_text( $ingredient['item'] );
        }
        return '';
    }

    private function image_url( $image ): string {
        if ( is_string( $image ) ) {
            $image = trim( $image );
            if ( filter_var( $image, FILTER_VALIDATE_URL ) ) {
                return $image;
            }
            return '';
        }
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
        if ( is_scalar( $value ) ) {
            return trim( (string) $value );
        }
        return '';
    }

    private function duration_to_minutes( $duration ): int {
        if ( ! is_string( $duration ) || ! preg_match( '/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $duration, $matches ) ) return 0;
        $hours = 0;
        if ( isset( $matches[1] ) && $matches[1] !== '' ) {
            $hours = (int) $matches[1];
        }
        $minutes = 0;
        if ( isset( $matches[2] ) && $matches[2] !== '' ) {
            $minutes = (int) $matches[2];
        }
        $seconds = 0;
        if ( isset( $matches[3] ) && $matches[3] !== '' ) {
            $seconds = (int) $matches[3];
        }
        return $hours * 60 + $minutes + (int) ceil( $seconds / 60 );
    }
}
