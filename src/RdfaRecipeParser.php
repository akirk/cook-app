<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Parses Recipe data expressed as Schema.org RDFa. */
class RdfaRecipeParser extends RecipeParser {
    public const SLUG = 'rdfa';
    public const NAME = 'Recipe RDFa';

    public function support_confidence( string $url, string $content_type, string $content ): int {
        if ( preg_match( '/typeof=["\'](?:schema:)?Recipe["\']/i', $content ) && preg_match( '/(?:vocab=["\']https?:\/\/schema\.org\/?["\']|prefix=["\'][^"\']*schema:\s*https?:\/\/schema\.org\/)/i', $content ) ) {
            return 10;
        }
        return 0;
    }

    public function parse( string $url, string $content_type, string $content ): ?array {
        if ( ! class_exists( '\DOMDocument' ) || $this->support_confidence( $url, $content_type, $content ) === 0 ) {
            return null;
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $loaded = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $content, LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            return null;
        }
        $xpath = new \DOMXPath( $document );
        $nodes = $xpath->query( "//*[@typeof='Recipe' or @typeof='schema:Recipe']" );
        if ( ! $nodes || $nodes->length === 0 ) {
            return null;
        }
        $scope = $nodes->item( 0 );
        $ingredients = [];
        foreach ( $this->values( $xpath, $scope, 'recipeIngredient' ) as $value ) {
            $ingredients[] = Importer::parse_ingredient_line( $value );
        }
        $instructions = [];
        foreach ( $this->values( $xpath, $scope, 'recipeInstructions' ) as $value ) {
            $step = Importer::clean_step( $value );
            if ( $step !== '' ) {
                $instructions[] = $step;
            }
        }
        if ( ! $ingredients && ! $instructions ) {
            return null;
        }
        $prep_time = $this->duration( $this->value( $xpath, $scope, 'prepTime' ) );
        $cook_time = $this->duration( $this->value( $xpath, $scope, 'cookTime' ) );
        if ( ! $prep_time && ! $cook_time ) {
            $cook_time = $this->duration( $this->value( $xpath, $scope, 'totalTime' ) );
        }
        return [
            'title'        => $this->value( $xpath, $scope, 'name' ),
            'description'  => $this->value( $xpath, $scope, 'description' ),
            'servings'     => $this->integer_value( $this->value( $xpath, $scope, 'recipeYield' ), 4 ),
            'prep_time'    => $prep_time,
            'cook_time'    => $cook_time,
            'ingredients'  => $ingredients,
            'instructions' => $instructions,
            'parts'        => [],
            'image_url'    => $this->value( $xpath, $scope, 'image' ),
        ];
    }

    private function value( \DOMXPath $xpath, \DOMNode $scope, string $property ): string {
        $values = $this->values( $xpath, $scope, $property );
        if ( $values ) {
            return $values[0];
        }
        return '';
    }

    private function values( \DOMXPath $xpath, \DOMNode $scope, string $property ): array {
        $nodes = $xpath->query( ".//*[@property='" . $property . "' or @property='schema:" . $property . "']", $scope );
        if ( ! $nodes ) {
            return [];
        }
        $values = [];
        foreach ( $nodes as $node ) {
            if ( ! $node instanceof \DOMElement ) {
                continue;
            }
            $value = '';
            foreach ( [ 'content', 'datetime', 'src', 'href', 'resource' ] as $attribute ) {
                $value = trim( $node->getAttribute( $attribute ) );
                if ( $value !== '' ) {
                    break;
                }
            }
            if ( $value === '' ) {
                $value = trim( preg_replace( '/\s+/', ' ', (string) $node->textContent ) );
            }
            if ( $value !== '' ) {
                $values[] = $value;
            }
        }
        return $values;
    }

    private function integer_value( string $value, int $default ): int {
        if ( preg_match( '/(\d+)/', $value, $matches ) ) {
            return (int) $matches[1];
        }
        return $default;
    }

    private function duration( string $value ): int {
        if ( ! preg_match( '/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $value, $matches ) ) {
            return 0;
        }
        $minutes = 0;
        if ( ! empty( $matches[1] ) ) {
            $minutes += (int) $matches[1] * 60;
        }
        if ( ! empty( $matches[2] ) ) {
            $minutes += (int) $matches[2];
        }
        if ( ! empty( $matches[3] ) ) {
            $minutes += (int) ceil( (int) $matches[3] / 60 );
        }
        return $minutes;
    }
}
