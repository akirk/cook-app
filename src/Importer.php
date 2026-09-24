<?php

namespace Cookbook;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Recipe importer.
 *
 *   from_url()  – fetch a page and look for schema.org Recipe JSON-LD.
 *   from_text() – best-effort parser for pasted text (Ingredients/Method headers).
 *
 * Returns an associative array shaped like:
 *   [
 *     'title'        => string,
 *     'description'  => string,
 *     'servings'     => int,
 *     'prep_time'    => int (minutes),
 *     'cook_time'    => int (minutes),
 *     'ingredients'  => [ [ 'amount','unit','name','notes' ], … ],
 *     'instructions' => [ string, … ],
 *     'parts'        => [ [ 'title', 'ingredients', 'instructions' ], … ],
 *   ]
 * Or null if nothing useful could be extracted.
 */
class Importer {

    private const MAX_IMPORT_BODY_BYTES = 5242880; // 5 MB.
    private const MAX_IMPORT_REDIRECTS = 5;

    public static function from_url( string $url ): ?array {
        if ( ! self::is_safe_import_url( $url ) ) {
            return null;
        }

        for ( $redirects = 0; $redirects <= self::MAX_IMPORT_REDIRECTS; $redirects++ ) {
            $response = wp_remote_get( $url, [
                'timeout'             => 12,
                'user-agent'          => 'Mozilla/5.0 (compatible; WP-Cook-App/1.0)',
                'redirection'         => 0,
                'reject_unsafe_urls'  => true,
                'limit_response_size' => self::MAX_IMPORT_BODY_BYTES,
            ] );
            if ( is_wp_error( $response ) ) return null;

            $code = function_exists( 'wp_remote_retrieve_response_code' )
                ? (int) wp_remote_retrieve_response_code( $response )
                : 200;
            if ( $code >= 300 && $code < 400 ) {
                $location = function_exists( 'wp_remote_retrieve_header' )
                    ? wp_remote_retrieve_header( $response, 'location' )
                    : '';
                if ( is_array( $location ) ) {
                    $location = reset( $location );
                }
                $next = is_string( $location )
                    ? self::resolve_redirect_url( $url, $location )
                    : null;
                if ( $next === null ) {
                    return null;
                }
                $url = $next;
                continue;
            }

            if ( $code < 200 || $code >= 300 ) {
                return null;
            }

            $body = wp_remote_retrieve_body( $response );
            if ( ! $body || strlen( $body ) >= self::MAX_IMPORT_BODY_BYTES ) return null;
            return self::from_html( $body );
        }

        return null;
    }

    private static function is_safe_import_url( string $url ): bool {
        if ( $url === '' ) {
            return false;
        }

        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return false;
        }

        $scheme = strtolower( (string) $parts['scheme'] );
        if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
            return false;
        }

        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
            return false;
        }

        return self::is_allowed_import_host( (string) $parts['host'] );
    }

    private static function resolve_redirect_url( string $base_url, string $location ): ?string {
        $location = trim( html_entity_decode( $location, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( $location === '' ) {
            return null;
        }

        $base = wp_parse_url( $base_url );
        if ( ! is_array( $base ) || empty( $base['scheme'] ) || empty( $base['host'] ) ) {
            return null;
        }

        if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $location ) ) {
            $url = $location;
        } elseif ( strpos( $location, '//' ) === 0 ) {
            $url = strtolower( (string) $base['scheme'] ) . ':' . $location;
        } else {
            $prefix = strtolower( (string) $base['scheme'] ) . '://' . $base['host'];
            if ( isset( $base['port'] ) ) {
                $prefix .= ':' . (int) $base['port'];
            }

            if ( strpos( $location, '?' ) === 0 ) {
                $path = $base['path'] ?? '/';
                $url = $prefix . $path . $location;
            } elseif ( strpos( $location, '/' ) === 0 ) {
                $url = $prefix . $location;
            } else {
                $path = $base['path'] ?? '/';
                $dir = preg_replace( '#/[^/]*$#', '/', $path );
                $url = $prefix . $dir . $location;
            }
        }

        return self::is_safe_import_url( $url ) ? $url : null;
    }

    private static function is_allowed_import_host( string $host ): bool {
        $host = trim( $host, "[] \t\n\r\0\x0B." );
        if ( $host === '' || strpos( $host, "\0" ) !== false ) {
            return false;
        }

        if ( function_exists( 'idn_to_ascii' ) ) {
            $ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
            if ( is_string( $ascii ) && $ascii !== '' ) {
                $host = $ascii;
            }
        }

        if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
            return true;
        }

        if ( ! preg_match( '/^[a-z0-9.-]+$/i', $host ) ) {
            return false;
        }

        return true;
    }

    /**
     * Parse a recipe from a chunk of HTML — used for the browser-extension
     * import where the page body has already been captured client-side.
     *
     * Tries JSON-LD first, then HTML microdata. Falls back to text-parsing
     * the stripped page only if it has explicit "Ingredients" / "Method"
     * section markers — without them the heuristic line classifier
     * mis-identifies things like rating counts and comment timestamps as
     * ingredients.
     */
    public static function from_html( string $html ): ?array {
        if ( $html === '' ) return null;

        $parsed = null;

        $recipe = self::extract_jsonld_recipe( $html );
        if ( $recipe ) {
            $normalized = self::normalize_jsonld( $recipe );
            if ( $normalized ) {
                $parsed = self::merge_html_parts_into_parsed( $normalized, $html );
            }
        }

        if ( ! $parsed ) {
            $micro = self::extract_microdata_recipe( $html );
            if ( $micro ) {
                $parsed = self::merge_html_parts_into_parsed( $micro, $html );
            }
        }

        if ( ! $parsed ) {
            $text = wp_strip_all_tags( $html );
            if ( ! self::has_recipe_section_markers( $text ) ) {
                return null;
            }
            $parsed = self::from_text( $text );
        }

        if ( ! $parsed ) return null;

        return $parsed;
    }

    private static function has_recipe_section_markers( string $text ): bool {
        return (bool) preg_match(
            '/^\s*(ingredients?|zutaten|method|instructions?|directions?|preparation|zubereitung|steps?)\s*:?\s*$/im',
            $text
        );
    }

    public static function from_text( string $text ): ?array {
        $text = trim( $text );
        if ( $text === '' ) return null;

        $lines = preg_split( '/\r\n|\r|\n/', $text );
        $lines = array_map( 'trim', $lines );
        $lines = array_values( array_filter( $lines, function( $l ) { return $l !== ''; } ) );
        if ( ! $lines ) return null;

        $title = $lines[0];
        if ( mb_strlen( $title ) > 120 ) {
            $title = '';
        }

        $section = 'unknown';
        $ingredients_lines = [];
        $instructions_lines = [];

        foreach ( $lines as $i => $line ) {
            $lower = strtolower( $line );
            if ( preg_match( '/^(ingredients?|zutaten)\b[: ]*$/iu', $lower ) ) {
                $section = 'ingredients';
                continue;
            }
            if ( preg_match( '/^(method|instructions?|directions?|preparation|steps?|zubereitung|anleitung)\b[: ]*$/iu', $lower ) ) {
                $section = 'instructions';
                continue;
            }
            if ( preg_match( '/^(notes?|tips?|tipp|tipps|hinweise?)\b[: ]*$/iu', $lower ) ) {
                $section = 'notes';
                continue;
            }
            if ( $section === 'ingredients' ) {
                $ingredients_lines[] = $line;
            } elseif ( $section === 'instructions' ) {
                $instructions_lines[] = $line;
            }
        }

        // If no headers found, guess: lines that start with a number are ingredients,
        // longer prose lines are instructions.
        if ( ! $ingredients_lines && ! $instructions_lines ) {
            foreach ( array_slice( $lines, 1 ) as $line ) {
                if ( self::looks_like_ingredient( $line ) ) {
                    $ingredients_lines[] = $line;
                } elseif ( str_word_count( $line ) >= 5 ) {
                    $instructions_lines[] = $line;
                }
            }
        }

        $ingredients = array_map( [ self::class, 'parse_ingredient_line' ], $ingredients_lines );
        $instructions = array_values( array_filter( array_map( [ self::class, 'clean_step' ], $instructions_lines ) ) );

        if ( ! $ingredients && ! $instructions ) {
            return null;
        }

        return [
            'title'        => $title,
            'description'  => '',
            'servings'     => 4,
            'prep_time'    => 0,
            'cook_time'    => 0,
            'ingredients'  => $ingredients,
            'instructions' => $instructions,
            'parts'        => [],
            'image_url'    => '',
        ];
    }

    private static function looks_like_ingredient( string $line ): bool {
        if ( preg_match( '/^[-*•]\s*/', $line ) ) return true;
        if ( preg_match( '/^[\d½⅓⅔¼¾⅛]/u', $line ) ) return true;
        return false;
    }

    public static function parse_ingredient_line( string $line ): array {
        $line = preg_replace( '#^\s*[-*•]\s*#u', '', $line );
        $line = preg_replace( '/\b(\w+)\(s\)/u', '$1s', $line );

        // Some sites lead with the quantity qualifier rather than trailing it:
        // HelloFresh writes "to taste Salt". Lift it out so the ingredient name
        // is the ingredient, and record the qualifier as a note. The trailing
        // form ("Salt to taste") is left alone -- there the whole phrase reads
        // as the name and existing behaviour covers it.
        $qualifier = '';
        if ( preg_match( '/^(to taste)\s+(.+)$/iu', $line, $q ) ) {
            $qualifier = 'to taste';
            $line      = $q[2];
        }

        // Longest alternatives first so regex doesn't match a prefix (e.g. "kg" before "g").
        $units_pattern = 'kilograms|kilogram|milliliters|milliliter|millilitres|millilitre|'
            . 'tablespoons|tablespoon|teaspoons|teaspoon|fluid ounce|'
            . 'pounds|pound|ounces|ounce|gallons|gallon|quarts|quart|pints|pint|'
            . 'liters|liter|litres|litre|grams|gram|cups|tbsp|tbs|tsp|fl oz|kg|mg|ml|lb|lbs|oz|pt|qt|cup|gal|l|g|'
            . 'EL|TL|Stk|Stück|Msp|Pk|Pkg|Pck|Prise|Bund|Pkt|'
            . 'pinch|dash|cloves|clove|slices|slice|pieces|piece|cans|can|bunch|'
            . 'units|unit|sachets|sachet|packs|pack';

        $single_amount_pattern = '(?:\d+(?:[.,]\d+)?\s+\d+/\d+|\d+/\d+|\d+(?:[.,]\d+)?|[½⅓⅔¼¾⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞]|\d+\s*[½⅓⅔¼¾⅕⅖⅗⅘⅙⅚⅛⅜⅝⅞])';
        $amount_pattern = '(?:' . $single_amount_pattern . '(?:\s*(?:-|–|—|to)\s*' . $single_amount_pattern . ')?)';

        if ( preg_match( '#^(' . $amount_pattern . ')\s*(' . $units_pattern . ')\b\.?\s+(.+)$#u', $line, $m ) ) {
            $row = self::ingredient_row( $m[1], $m[2], $m[3] );
        } elseif ( preg_match( '#^(' . $amount_pattern . ')\s+(.+)$#u', $line, $m ) ) {
            $row = self::ingredient_row( $m[1], '', $m[2] );
        } else {
            $row = self::ingredient_row( '', '', $line );
        }

        if ( $qualifier !== '' ) {
            $row['notes'] = $row['notes'] === '' ? $qualifier : $qualifier . ', ' . $row['notes'];
        }

        return $row;
    }

    private static function ingredient_row( string $amount, string $unit, string $rest ): array {
        $notes = '';
        // Recipe plugins often include an alternate unit after the primary unit,
        // e.g. "700 g (1½ lb) baby potatoes". That parenthetical is not the
        // ingredient note and should not erase the actual ingredient name.
        $rest = preg_replace( '/^\([^)]*\)\s*/u', '', trim( $rest ) );
        if ( preg_match( '/^(.+?)([,(])(.*)$/u', $rest, $m ) ) {
            $delim = $m[2];
            $left  = trim( $m[1] );
            $right = trim( rtrim( $m[3], ')' ) );

            // A comma between two capitalised segments belongs to the name, not
            // to a note: HelloFresh sells "Garlic, Ginger & Lemongrass Paste" as
            // one item. Notes are conventionally lower case ("finely chopped"),
            // so requiring a capital on both sides keeps those splitting. Only
            // commas are ambiguous this way; "(" always opens a note.
            if ( ',' === $delim && self::starts_upper( $left ) && self::starts_upper( $right ) ) {
                return [
                    'amount' => trim( $amount ),
                    'unit'   => Units::normalize_unit( $unit ),
                    'name'   => $rest,
                    'notes'  => '',
                ];
            }
            // Ingredient lists are not consistent about which side of the comma
            // holds the ingredient. "140g cooled, cooked rice" writes the
            // preparation first, which would otherwise register "cooled" as the
            // ingredient. Only swap when the left side is a single unambiguous
            // preparation word, so "onion, finely chopped" and "baby potatoes,
            // washed" keep their current behaviour.
            if ( $right !== '' && self::is_preparation_word( $left ) ) {
                $tmp   = $left;
                $left  = $right;
                $right = $tmp;
            }
            $rest  = $left;
            $notes = $right;
        }
        return [
            'amount' => trim( $amount ),
            'unit'   => Units::normalize_unit( $unit ),
            'name'   => trim( $rest ),
            'notes'  => $notes,
        ];
    }

    /**
     * A single word that describes how an ingredient was prepared rather than
     * what it is. Deliberately conservative: multi-word input is never a match,
     * and "cooked" is excluded because "cooked rice" is an ingredient in its
     * own right.
     */
    private static function starts_upper( string $text ): bool {
        if ( $text === '' ) {
            return false;
        }
        $first = mb_substr( $text, 0, 1 );
        return mb_strtolower( $first ) !== $first;
    }

    private static function is_preparation_word( string $text ): bool {
        if ( strpos( $text, ' ' ) !== false ) {
            return false;
        }
        $words = [ 'cooled', 'chilled', 'warmed', 'melted', 'softened', 'beaten',
                   'drained', 'rinsed', 'peeled', 'crushed', 'grated', 'minced',
                   'chopped', 'diced', 'sliced', 'halved', 'quartered', 'trimmed',
                   'shredded', 'toasted', 'cubed', 'washed' ];
        return in_array( mb_strtolower( $text ), $words, true );
    }

    /**
     * Pull a Recipe from HTML microdata (itemtype="...Recipe" + itemprop="...").
     */
    private static function extract_microdata_recipe( string $html ): ?array {
        if ( ! class_exists( '\\DOMDocument' ) ) return null;

        $doc = self::load_html_document( $html );
        if ( ! $doc ) return null;

        $xpath = new \DOMXPath( $doc );
        $recipe_nodes = $xpath->query( "//*[contains(@itemtype, '/Recipe')]" );
        if ( ! $recipe_nodes || $recipe_nodes->length === 0 ) return null;
        $recipe = $recipe_nodes->item( 0 );

        $name = self::microdata_first_value( $xpath, $recipe, 'name' );
        $description = self::microdata_first_value( $xpath, $recipe, 'description' );

        $servings = 0;
        $yield = self::microdata_first_value( $xpath, $recipe, 'recipeYield' );
        if ( is_numeric( $yield ) ) {
            $servings = (int) $yield;
        } elseif ( $yield !== '' && preg_match( '/(\d+)/', $yield, $m ) ) {
            $servings = (int) $m[1];
        }
        if ( ! $servings ) $servings = 4;

        $prep_iso = self::microdata_first_value( $xpath, $recipe, 'prepTime' );
        $cook_iso = self::microdata_first_value( $xpath, $recipe, 'cookTime' );
        $total_iso = self::microdata_first_value( $xpath, $recipe, 'totalTime' );
        $prep = self::iso8601_to_minutes( $prep_iso );
        $cook = self::iso8601_to_minutes( $cook_iso );
        if ( ! $prep && ! $cook && $total_iso ) {
            $cook = self::iso8601_to_minutes( $total_iso );
        }

        $ingredients = [];
        foreach ( self::microdata_all_values( $xpath, $recipe, 'recipeIngredient' ) as $line ) {
            if ( $line !== '' ) {
                $ingredients[] = self::parse_ingredient_line( $line );
            }
        }

        $instructions = [];
        foreach ( self::microdata_all_values( $xpath, $recipe, 'recipeInstructions' ) as $step ) {
            $step = self::clean_step( $step );
            if ( $step !== '' ) $instructions[] = $step;
        }

        $image_url = self::microdata_first_value( $xpath, $recipe, 'image' );

        if ( ! $ingredients && ! $instructions ) {
            return null;
        }

        return [
            'title'        => $name,
            'description'  => $description,
            'servings'     => $servings,
            'prep_time'    => $prep,
            'cook_time'    => $cook,
            'ingredients'  => $ingredients,
            'instructions' => $instructions,
            'parts'        => [],
            'image_url'    => $image_url,
        ];
    }

    /**
     * Get a single value for an itemprop within $scope, ignoring matches that
     * sit inside a nested itemscope (e.g. an author's name vs the recipe's name).
     */
    private static function microdata_first_value( \DOMXPath $xpath, \DOMNode $scope, string $prop ): string {
        foreach ( self::microdata_owned_nodes( $xpath, $scope, $prop ) as $node ) {
            $value = self::microdata_node_value( $node );
            if ( $value !== '' ) return $value;
        }
        return '';
    }

    private static function microdata_all_values( \DOMXPath $xpath, \DOMNode $scope, string $prop ): array {
        $out = [];
        foreach ( self::microdata_owned_nodes( $xpath, $scope, $prop ) as $node ) {
            // For HowToStep wrappers prefer the inner [itemprop=text].
            $inner = $xpath->query( ".//*[@itemprop='text']", $node );
            if ( $inner && $inner->length > 0 ) {
                $value = self::microdata_node_value( $inner->item( 0 ) );
            } else {
                $value = self::microdata_node_value( $node );
            }
            if ( $value !== '' ) $out[] = $value;
        }
        return $out;
    }

    private static function microdata_owned_nodes( \DOMXPath $xpath, \DOMNode $scope, string $prop ): array {
        $nodes = $xpath->query( ".//*[@itemprop='" . $prop . "']", $scope );
        if ( ! $nodes ) return [];
        $owned = [];
        foreach ( $nodes as $node ) {
            // Skip if any intervening ancestor (between $node and $scope) is itself an itemscope.
            $a = $node->parentNode;
            $clean = true;
            while ( $a && $a !== $scope ) {
                if ( $a instanceof \DOMElement && $a->hasAttribute( 'itemscope' ) ) {
                    $clean = false;
                    break;
                }
                $a = $a->parentNode;
            }
            if ( $clean ) $owned[] = $node;
        }
        return $owned;
    }

    private static function microdata_node_value( \DOMNode $node ): string {
        if ( ! $node instanceof \DOMElement ) {
            return trim( (string) $node->nodeValue );
        }
        $tag = strtolower( $node->tagName );
        switch ( $tag ) {
            case 'meta':
                return trim( $node->getAttribute( 'content' ) );
            case 'img':
                return trim( $node->getAttribute( 'src' ) );
            case 'link':
            case 'a':
                return trim( $node->getAttribute( 'href' ) ) ?: trim( $node->nodeValue );
            case 'time':
                $dt = trim( $node->getAttribute( 'datetime' ) );
                return $dt !== '' ? $dt : trim( $node->nodeValue );
            default:
                $content = trim( $node->getAttribute( 'content' ) );
                if ( $content !== '' ) return $content;
                return trim( preg_replace( '/\s+/', ' ', (string) $node->nodeValue ) );
        }
    }

    private static function extract_jsonld_recipe( string $html ): ?array {
        if ( ! preg_match_all( '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si', $html, $matches ) ) {
            return null;
        }
        foreach ( $matches[1] as $json ) {
            $json = trim( html_entity_decode( $json, ENT_QUOTES, 'UTF-8' ) );
            // Strip control chars that break json_decode.
            $json = preg_replace( '/[\x00-\x09\x0B\x0C\x0E-\x1F]/', ' ', $json );
            $data = json_decode( $json, true );
            if ( ! $data ) continue;
            $found = self::find_recipe_node( $data );
            if ( $found ) return $found;
        }
        return null;
    }

    private static function find_recipe_node( $node ): ?array {
        if ( ! is_array( $node ) ) return null;
        $type = $node['@type'] ?? null;
        if ( $type ) {
            $types = (array) $type;
            foreach ( $types as $t ) {
                if ( strcasecmp( $t, 'Recipe' ) === 0 ) return $node;
            }
        }
        if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
            foreach ( $node['@graph'] as $sub ) {
                $r = self::find_recipe_node( $sub );
                if ( $r ) return $r;
            }
        }
        // Some sites put it as a top-level array.
        foreach ( $node as $v ) {
            if ( is_array( $v ) ) {
                $r = self::find_recipe_node( $v );
                if ( $r ) return $r;
            }
        }
        return null;
    }

    private static function normalize_jsonld( array $r ): ?array {
        $title = is_string( $r['name'] ?? null ) ? trim( $r['name'] ) : '';
        $description = is_string( $r['description'] ?? null ) ? trim( $r['description'] ) : '';
        $image_url = self::extract_image_url( $r['image'] ?? '' );

        $servings = 0;
        if ( isset( $r['recipeYield'] ) ) {
            $y = is_array( $r['recipeYield'] ) ? reset( $r['recipeYield'] ) : $r['recipeYield'];
            if ( is_numeric( $y ) ) {
                $servings = (int) $y;
            } elseif ( is_string( $y ) && preg_match( '/(\d+)/', $y, $m ) ) {
                $servings = (int) $m[1];
            }
        }
        if ( ! $servings ) $servings = 4;

        $raw_ingredients = $r['recipeIngredient'] ?? ( $r['ingredients'] ?? [] );
        $ingredient_parts = self::extract_jsonld_ingredient_parts( $raw_ingredients );
        $ingredients = $ingredient_parts
            ? self::flatten_part_ingredients( $ingredient_parts )
            : self::parse_jsonld_ingredients( $raw_ingredients );

        $instructions = [];
        $instruction_parts = [];
        if ( isset( $r['recipeInstructions'] ) ) {
            $instruction_parts = self::extract_jsonld_instruction_parts( $r['recipeInstructions'] );
            $instructions = $instruction_parts
                ? self::flatten_part_instructions( $instruction_parts )
                : self::flatten_instructions( $r['recipeInstructions'] );
        }
        $parts = self::merge_recipe_parts( $ingredient_parts, $instruction_parts );

        $prep_min = self::iso8601_to_minutes( $r['prepTime'] ?? '' );
        $cook_min = self::iso8601_to_minutes( $r['cookTime'] ?? '' );
        if ( ! $prep_min && ! $cook_min && ! empty( $r['totalTime'] ) ) {
            $cook_min = self::iso8601_to_minutes( $r['totalTime'] );
        }

        return [
            'title'        => $title,
            'description'  => $description,
            'servings'     => $servings,
            'prep_time'    => $prep_min,
            'cook_time'    => $cook_min,
            'ingredients'  => $ingredients,
            'instructions' => $instructions,
            'parts'        => $parts,
            'image_url'    => $image_url,
        ];
    }

    private static function parse_jsonld_ingredients( $raw_ingredients ): array {
        $ingredients = [];
        if ( is_string( $raw_ingredients ) ) {
            $raw_ingredients = preg_split( '/(?:\r?\n)+/', $raw_ingredients );
        }
        if ( ! is_array( $raw_ingredients ) ) {
            return $ingredients;
        }

        if ( self::jsonld_has_list_items( $raw_ingredients ) ) {
            $raw_ingredients = $raw_ingredients['itemListElement'];
        }

        foreach ( $raw_ingredients as $line ) {
            if ( is_array( $line ) && self::jsonld_has_list_items( $line ) ) {
                $ingredients = array_merge( $ingredients, self::parse_jsonld_ingredients( $line['itemListElement'] ) );
                continue;
            }
            if ( is_array( $line ) && isset( $line['item'] ) ) {
                $line = $line['item'];
            }

            $text = self::jsonld_ingredient_text( $line );
            if ( $text !== '' ) {
                $ingredients[] = self::parse_ingredient_line( $text );
            }
        }

        return $ingredients;
    }

    private static function extract_jsonld_ingredient_parts( $raw_ingredients ): array {
        $parts = [];
        if ( ! is_array( $raw_ingredients ) ) {
            return $parts;
        }

        $items = self::jsonld_has_list_items( $raw_ingredients )
            ? $raw_ingredients['itemListElement']
            : $raw_ingredients;
        if ( ! is_array( $items ) ) {
            return $parts;
        }

        foreach ( $items as $item ) {
            if ( is_array( $item ) && isset( $item['item'] ) && is_array( $item['item'] ) ) {
                $item = $item['item'];
            }
            if ( ! is_array( $item ) || ! self::jsonld_has_list_items( $item ) ) {
                continue;
            }

            $type = $item['@type'] ?? '';
            if (
                $type
                && ! self::jsonld_is_type( $type, 'ItemList' )
                && ! self::jsonld_is_type( $type, 'ListItem' )
                && ! self::jsonld_is_type( $type, 'HowToSection' )
            ) {
                continue;
            }

            $ingredients = self::parse_jsonld_ingredients( $item['itemListElement'] );
            $title       = self::jsonld_scalar_text( $item['name'] ?? '' );
            if ( $title !== '' || $ingredients ) {
                $parts[] = [
                    'title'        => $title,
                    'ingredients'  => $ingredients,
                    'instructions' => [],
                ];
            }
        }

        return self::normalize_recipe_parts( $parts );
    }

    private static function extract_jsonld_instruction_parts( $instructions ): array {
        if ( ! is_array( $instructions ) ) {
            return [];
        }

        $items = self::jsonld_has_list_items( $instructions )
            ? $instructions['itemListElement']
            : $instructions;
        if ( ! is_array( $items ) ) {
            return [];
        }

        $parts = [];
        foreach ( $items as $step ) {
            if ( is_array( $step ) && isset( $step['item'] ) && is_array( $step['item'] ) ) {
                $step = $step['item'];
            }
            if ( ! is_array( $step ) ) {
                continue;
            }
            $type = $step['@type'] ?? '';
            if ( ! self::jsonld_is_type( $type, 'HowToSection' ) ) {
                continue;
            }

            $title = self::jsonld_scalar_text( $step['name'] ?? '' );
            $items = $step['itemListElement'] ?? ( $step['steps'] ?? [] );
            $part_steps = self::flatten_instructions( $items );
            if ( $title !== '' || $part_steps ) {
                $parts[] = [
                    'title'        => $title,
                    'ingredients'  => [],
                    'instructions' => $part_steps,
                ];
            }
        }

        return self::normalize_recipe_parts( $parts );
    }

    private static function jsonld_has_list_items( array $node ): bool {
        return isset( $node['itemListElement'] ) && is_array( $node['itemListElement'] );
    }

    private static function jsonld_is_type( $type, string $expected ): bool {
        foreach ( (array) $type as $candidate ) {
            $candidate = is_string( $candidate ) ? $candidate : '';
            if ( $candidate !== '' && strcasecmp( $candidate, $expected ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    private static function jsonld_scalar_text( $value ): string {
        if ( is_scalar( $value ) ) {
            return trim( (string) $value );
        }
        return '';
    }

    private static function jsonld_ingredient_text( $ingredient ): string {
        if ( is_string( $ingredient ) ) {
            return trim( $ingredient );
        }
        if ( ! is_array( $ingredient ) ) {
            return '';
        }
        if ( isset( $ingredient['@value'] ) ) {
            return self::jsonld_scalar_text( $ingredient['@value'] );
        }
        if ( isset( $ingredient['text'] ) ) {
            return self::jsonld_scalar_text( $ingredient['text'] );
        }
        if ( isset( $ingredient['value'] ) || isset( $ingredient['name'] ) ) {
            $amount = self::jsonld_scalar_text( $ingredient['value'] ?? '' );
            $unit   = self::jsonld_scalar_text( $ingredient['unitText'] ?? '' );
            $name   = self::jsonld_scalar_text( $ingredient['name'] ?? '' );
            return trim( preg_replace( '/\s+/', ' ', trim( $amount . ' ' . $unit . ' ' . $name ) ) );
        }
        if ( isset( $ingredient['item'] ) ) {
            return self::jsonld_ingredient_text( $ingredient['item'] );
        }
        return '';
    }

    private static function merge_html_parts_into_parsed( array $parsed, string $html ): array {
        $html_parts = self::extract_html_recipe_parts( $html );
        if ( ! $html_parts ) {
            $parsed['parts'] = self::normalize_recipe_parts( $parsed['parts'] ?? [] );
            return $parsed;
        }

        $parsed['parts'] = self::merge_recipe_parts(
            self::normalize_recipe_parts( $parsed['parts'] ?? [] ),
            $html_parts
        );

        $part_ingredients = self::flatten_part_ingredients( $parsed['parts'] );
        if ( $part_ingredients ) {
            $parsed['ingredients'] = $part_ingredients;
        }

        $part_instructions = self::flatten_part_instructions( $parsed['parts'] );
        if ( $part_instructions && empty( $parsed['instructions'] ) ) {
            $parsed['instructions'] = $part_instructions;
        }

        return $parsed;
    }

    private static function extract_html_recipe_parts( string $html ): array {
        if ( ! class_exists( '\\DOMDocument' ) ) {
            return [];
        }

        $doc = self::load_html_document( $html );
        if ( ! $doc ) {
            return [];
        }

        $xpath = new \DOMXPath( $doc );
        $parts = self::extract_wprm_recipe_parts( $xpath );
        if ( $parts ) {
            return $parts;
        }

        return self::extract_heading_based_recipe_parts( $xpath );
    }

    private static function load_html_document( string $html ): ?\DOMDocument {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors( true );
        $loaded = $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );
        return $loaded ? $doc : null;
    }

    private static function extract_wprm_recipe_parts( \DOMXPath $xpath ): array {
        $parts = [];
        $groups = $xpath->query( self::xpath_class_query( 'wprm-recipe-ingredient-group' ) );
        if ( ! $groups || $groups->length === 0 ) {
            return [];
        }

        foreach ( $groups as $group ) {
            $title = self::first_descendant_class_text( $xpath, $group, 'wprm-recipe-group-name' );
            if ( $title === '' ) {
                $title = self::first_descendant_class_text( $xpath, $group, 'wprm-recipe-ingredient-group-name' );
            }

            $ingredients = [];
            $items = $xpath->query( './/' . self::xpath_class_query( 'wprm-recipe-ingredient', false ), $group );
            if ( ! $items || $items->length === 0 ) {
                $items = $xpath->query( './/li', $group );
            }
            if ( $items ) {
                foreach ( $items as $item ) {
                    $row = self::wprm_ingredient_row( $xpath, $item );
                    if ( ! empty( $row['name'] ) ) {
                        $ingredients[] = $row;
                    }
                }
            }

            if ( $title !== '' || $ingredients ) {
                $parts[] = [
                    'title'        => $title,
                    'ingredients'  => $ingredients,
                    'instructions' => [],
                ];
            }
        }

        return self::normalize_recipe_parts( $parts );
    }

    private static function extract_heading_based_recipe_parts( \DOMXPath $xpath ): array {
        $headings = $xpath->query( '//*[self::h2 or self::h3 or self::h4][translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "ingredients"]' );
        if ( ! $headings || $headings->length === 0 ) {
            return [];
        }

        $parts = [];
        $current = null;
        $node = $headings->item( 0 );
        while ( $node ) {
            $node = $node->nextSibling;
            if ( ! $node ) {
                break;
            }
            if ( ! $node instanceof \DOMElement ) {
                continue;
            }

            $tag = strtolower( $node->tagName );
            $text = trim( preg_replace( '/\s+/', ' ', (string) $node->textContent ) );
            if ( in_array( $tag, [ 'h2', 'h3' ], true ) && preg_match( '/^(instructions?|directions?|method|nutrition|notes?)$/i', $text ) ) {
                break;
            }
            if ( in_array( $tag, [ 'h3', 'h4', 'strong', 'p' ], true ) && self::looks_like_recipe_part_title( $text ) ) {
                if ( $current && ( $current['title'] !== '' || $current['ingredients'] ) ) {
                    $parts[] = $current;
                }
                $current = [
                    'title'        => $text,
                    'ingredients'  => [],
                    'instructions' => [],
                ];
                continue;
            }
            if ( $tag === 'ul' || $tag === 'ol' ) {
                if ( ! $current ) {
                    $current = [
                        'title'        => '',
                        'ingredients'  => [],
                        'instructions' => [],
                    ];
                }
                foreach ( $xpath->query( './/li', $node ) as $li ) {
                    $line = self::clean_html_list_text( (string) $li->textContent );
                    if ( $line !== '' ) {
                        $current['ingredients'][] = self::parse_ingredient_line( $line );
                    }
                }
            }
        }

        if ( $current && ( $current['title'] !== '' || $current['ingredients'] ) ) {
            $parts[] = $current;
        }

        return self::normalize_recipe_parts( $parts );
    }

    private static function wprm_ingredient_row( \DOMXPath $xpath, \DOMNode $node ): array {
        $amount = self::first_descendant_class_text( $xpath, $node, 'wprm-recipe-ingredient-amount' );
        $unit   = self::first_descendant_class_text( $xpath, $node, 'wprm-recipe-ingredient-unit' );
        $name   = self::first_descendant_class_text( $xpath, $node, 'wprm-recipe-ingredient-name' );
        $notes  = self::first_descendant_class_text( $xpath, $node, 'wprm-recipe-ingredient-notes' );

        if ( $name !== '' ) {
            return [
                'amount' => trim( $amount ),
                'unit'   => Units::normalize_unit( $unit ),
                'name'   => trim( $name ),
                'notes'  => self::clean_note( $notes ),
            ];
        }

        return self::parse_ingredient_line( self::clean_html_list_text( (string) $node->textContent ) );
    }

    /**
     * A note taken from a recipe plugin's own field can still carry the
     * separator that was meant to join it to the ingredient name. WP Recipe
     * Maker renders "<name> (<notes>)", and sites store the comma inside the
     * notes field: veganhuggs.com's "1 small red onion (, diced)" comes from a
     * notes value of ", diced". Nothing useful ever starts a note.
     */
    private static function clean_note( string $note ): string {
        return trim( preg_replace( '/^[\s,;]+/u', '', $note ) );
    }

    private static function first_descendant_class_text( \DOMXPath $xpath, \DOMNode $scope, string $class_name ): string {
        $nodes = $xpath->query( './/' . self::xpath_class_query( $class_name, false ), $scope );
        if ( ! $nodes || $nodes->length === 0 ) {
            return '';
        }

        return trim( preg_replace( '/\s+/', ' ', (string) $nodes->item( 0 )->textContent ) );
    }

    private static function xpath_class_query( string $class_name, bool $absolute = true ): string {
        $prefix = $absolute ? '//*' : '*';
        return $prefix . '[contains(concat(" ", normalize-space(@class), " "), " ' . $class_name . ' ")]';
    }

    private static function clean_html_list_text( string $text ): string {
        $text = trim( preg_replace( '/\s+/', ' ', $text ) );
        $text = preg_replace( '/^[\x{2610}\x{2611}\x{2612}\x{25A1}\x{25A2}\x{25A3}\x{25AA}\x{25AB}\x{25B8}\x{2022}\-*]+\s*/u', '', $text );
        return trim( $text );
    }

    private static function looks_like_recipe_part_title( string $text ): bool {
        $text = trim( $text );
        if ( $text === '' || mb_strlen( $text ) > 60 ) {
            return false;
        }

        return (bool) preg_match( '/^[\p{Lu}\d\s&\/\-]+$/u', $text );
    }

    private static function normalize_recipe_parts( array $parts ): array {
        $normalized = [];
        foreach ( $parts as $part ) {
            if ( ! is_array( $part ) ) {
                continue;
            }

            $ingredients = [];
            foreach ( (array) ( $part['ingredients'] ?? [] ) as $ingredient ) {
                if ( is_array( $ingredient ) && ! empty( $ingredient['name'] ) ) {
                    $ingredients[] = [
                        'amount' => isset( $ingredient['amount'] ) ? trim( (string) $ingredient['amount'] ) : '',
                        'unit'   => isset( $ingredient['unit'] ) ? Units::normalize_unit( (string) $ingredient['unit'] ) : '',
                        'name'   => trim( (string) $ingredient['name'] ),
                        'notes'  => isset( $ingredient['notes'] ) ? trim( (string) $ingredient['notes'] ) : '',
                    ];
                }
            }

            $instructions = [];
            foreach ( (array) ( $part['instructions'] ?? [] ) as $step ) {
                if ( is_scalar( $step ) ) {
                    $step = self::clean_step( (string) $step );
                    if ( $step !== '' ) {
                        $instructions[] = $step;
                    }
                }
            }

            $title = isset( $part['title'] ) && is_scalar( $part['title'] )
                ? trim( (string) $part['title'] )
                : '';

            if ( $title !== '' || $ingredients || $instructions ) {
                $normalized[] = [
                    'title'        => $title,
                    'ingredients'  => $ingredients,
                    'instructions' => $instructions,
                ];
            }
        }

        return $normalized;
    }

    private static function merge_recipe_parts( array $first, array $second ): array {
        $merged = [];
        $index_by_key = [];
        foreach ( array_merge( self::normalize_recipe_parts( $first ), self::normalize_recipe_parts( $second ) ) as $part ) {
            $key = self::part_key( $part['title'] );
            if ( $key !== '' && isset( $index_by_key[ $key ] ) ) {
                $index = $index_by_key[ $key ];
                if ( ! $merged[ $index ]['ingredients'] && $part['ingredients'] ) {
                    $merged[ $index ]['ingredients'] = $part['ingredients'];
                }
                if ( ! $merged[ $index ]['instructions'] && $part['instructions'] ) {
                    $merged[ $index ]['instructions'] = $part['instructions'];
                }
                continue;
            }

            $merged[] = $part;
            if ( $key !== '' ) {
                $index_by_key[ $key ] = count( $merged ) - 1;
            }
        }

        return $merged;
    }

    private static function part_key( string $title ): string {
        $title = strtolower( trim( $title ) );
        return preg_replace( '/[^a-z0-9]+/', '', $title );
    }

    private static function flatten_part_ingredients( array $parts ): array {
        $ingredients = [];
        foreach ( self::normalize_recipe_parts( $parts ) as $part ) {
            $ingredients = array_merge( $ingredients, $part['ingredients'] );
        }
        return $ingredients;
    }

    private static function flatten_part_instructions( array $parts ): array {
        $instructions = [];
        foreach ( self::normalize_recipe_parts( $parts ) as $part ) {
            $instructions = array_merge( $instructions, $part['instructions'] );
        }
        return $instructions;
    }

    /**
     * schema.org "image" can be a string URL, an array of URLs, or an
     * ImageObject (or array of those). Walk it and return the first usable URL.
     */
    private static function extract_image_url( $image ): string {
        if ( is_string( $image ) ) {
            $image = trim( $image );
            return filter_var( $image, FILTER_VALIDATE_URL ) ? $image : '';
        }
        if ( ! is_array( $image ) ) return '';
        if ( isset( $image['url'] ) && is_string( $image['url'] ) ) {
            return self::extract_image_url( $image['url'] );
        }
        if ( isset( $image['contentUrl'] ) && is_string( $image['contentUrl'] ) ) {
            return self::extract_image_url( $image['contentUrl'] );
        }
        foreach ( $image as $candidate ) {
            $url = self::extract_image_url( $candidate );
            if ( $url !== '' ) return $url;
        }
        return '';
    }

    private static function flatten_instructions( $instructions ): array {
        $out = [];
        if ( is_string( $instructions ) ) {
            $parts = preg_split( '/(?:\r?\n)+|(?<=[.!?])\s+(?=[A-Z])/', $instructions );
            foreach ( $parts as $p ) {
                $p = self::clean_step( $p );
                if ( $p !== '' ) $out[] = $p;
            }
            return $out;
        }
        if ( ! is_array( $instructions ) ) return $out;

        foreach ( $instructions as $step ) {
            if ( is_string( $step ) ) {
                $step = self::clean_step( $step );
                if ( $step !== '' ) $out[] = $step;
                continue;
            }
            if ( ! is_array( $step ) ) continue;
            $type = $step['@type'] ?? '';
            if ( strcasecmp( (string) $type, 'HowToSection' ) === 0 ) {
                $name = isset( $step['name'] ) ? trim( $step['name'] ) : '';
                if ( $name !== '' ) $out[] = $name . ':';
                $sub = self::flatten_instructions( $step['itemListElement'] ?? [] );
                $out = array_merge( $out, $sub );
                continue;
            }
            if ( isset( $step['text'] ) && is_string( $step['text'] ) ) {
                $t = self::clean_step( $step['text'] );
                if ( $t !== '' ) $out[] = $t;
            } elseif ( isset( $step['name'] ) && is_string( $step['name'] ) ) {
                $t = self::clean_step( $step['name'] );
                if ( $t !== '' ) $out[] = $t;
            }
        }
        return $out;
    }

    /**
     * Strip leading enumerators ("1.", "1)", "Step 1:", "- ", "• ") so we don't
     * double up with the <ol> numbering on the recipe view.
     */
    public static function clean_step( string $step ): string {
        $step = preg_replace( '#</(li|p|div)>|<br\s*/?>#i', ' ', $step );
        $step = wp_strip_all_tags( $step );
        $step = preg_replace( '/\s+/u', ' ', $step );
        $step = trim( $step );
        if ( $step === '' ) return '';
        // Loop so combined prefixes ("- 1. text") get peeled off in any order.
        for ( $i = 0; $i < 4; $i++ ) {
            $before = $step;
            $step = preg_replace( '/^(?:step\s+)?\d+\s*[\.\)\:\-]\s*/iu', '', $step );
            $step = preg_replace( '/^[-*•]\s*/u', '', $step );
            $step = trim( $step );
            if ( $step === $before ) break;
        }
        return $step;
    }

    private static function iso8601_to_minutes( $duration ): int {
        if ( ! is_string( $duration ) || $duration === '' ) return 0;
        if ( ! preg_match( '/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $duration, $m ) ) return 0;
        $h = isset( $m[1] ) && $m[1] !== '' ? (int) $m[1] : 0;
        $i = isset( $m[2] ) && $m[2] !== '' ? (int) $m[2] : 0;
        $s = isset( $m[3] ) && $m[3] !== '' ? (int) $m[3] : 0;
        return $h * 60 + $i + (int) ceil( $s / 60 );
    }
}
