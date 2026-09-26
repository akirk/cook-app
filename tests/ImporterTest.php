<?php

use PHPUnit\Framework\TestCase;
use CookApp\Importer;
use CookApp\SchemaOrgRecipeParser;

class ImporterTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wp_remote_get_calls'] = [];
        unset( $GLOBALS['wp_remote_get_mock'] );
    }

    private function fixture( string $name ): string {
        return file_get_contents( __DIR__ . '/fixtures/' . $name );
    }

    public function test_from_url_fetches_url_with_safety_limits(): void {
        $GLOBALS['wp_remote_get_mock'] = function( $url, $args ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => '<script type="application/ld+json">' . json_encode( [
                    '@context' => 'https://schema.org',
                    '@type' => 'Recipe',
                    'name' => 'Remote Recipe',
                    'recipeIngredient' => [ '1 cup flour' ],
                    'recipeInstructions' => [ 'Mix.' ],
                ] ) . '</script>',
            ];
        };

        $parsed = Importer::from_url( 'https://93.184.216.34/recipe' );

        $this->assertIsArray( $parsed );
        $this->assertSame( 'Remote Recipe', $parsed['title'] );
        $this->assertCount( 1, $GLOBALS['wp_remote_get_calls'] );
        $args = $GLOBALS['wp_remote_get_calls'][0][1];
        $this->assertSame( 0, $args['redirection'] );
        $this->assertTrue( $args['reject_unsafe_urls'] );
        $this->assertSame( 5242880, $args['limit_response_size'] );
    }

    public function test_from_url_accepts_public_hostname_without_resolving_it_first(): void {
        $GLOBALS['wp_remote_get_mock'] = function( $url, $args ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => '<script type="application/ld+json">' . json_encode( [
                    '@context' => 'https://schema.org',
                    '@type' => 'Recipe',
                    'name' => 'Hostname Recipe',
                    'recipeIngredient' => [ '1 cup flour' ],
                    'recipeInstructions' => [ 'Mix.' ],
                ] ) . '</script>',
            ];
        };

        $parsed = Importer::from_url( 'https://example.com/recipe' );

        $this->assertIsArray( $parsed );
        $this->assertSame( 'Hostname Recipe', $parsed['title'] );
        $this->assertSame( 'https://example.com/recipe', $GLOBALS['wp_remote_get_calls'][0][0] );
    }

    public function test_from_url_rejects_response_at_body_size_limit(): void {
        $GLOBALS['wp_remote_get_mock'] = function( $url, $args ) {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => str_repeat( 'x', 5242880 ),
            ];
        };

        $this->assertNull( Importer::from_url( 'https://93.184.216.34/recipe' ) );
    }

    public function test_jsonld_gutekueche_schinkenfleckerln(): void {
        $parsed = Importer::from_schema_org_json_ld( $this->fixture( 'gutekueche-schinkenfleckerln.html' ) );

        $this->assertIsArray( $parsed );
        $this->assertSame( 'Cremige Schinkenfleckerln', $parsed['title'] );
        $this->assertSame( 2, $parsed['servings'] );
        $this->assertSame( 10, $parsed['prep_time'] );
        $this->assertSame( 20, $parsed['cook_time'] );
        $this->assertNotEmpty( $parsed['image_url'] );

        $this->assertCount( 5, $parsed['ingredients'] );
        $this->assertSame(
            [ 'amount' => '200', 'unit' => 'g', 'name' => 'Mascarpone', 'notes' => '' ],
            $parsed['ingredients'][0]
        );
        // German "EL" should normalize to tbsp.
        $this->assertSame( 'tbsp',  $parsed['ingredients'][2]['unit'] );
        $this->assertSame( '2',     $parsed['ingredients'][2]['amount'] );
        $this->assertSame( 'Öl',    $parsed['ingredients'][2]['name'] );
        // German "Stk" should normalize to piece.
        $this->assertSame( 'piece', $parsed['ingredients'][4]['unit'] );
    }

    public function test_schema_org_parser_only_accepts_json_ld_recipe_data(): void {
        $parser = new SchemaOrgRecipeParser();
        $html = '<script type="application/ld+json">' . json_encode( [
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => 'Registered Parser Recipe',
            'recipeIngredient' => [ '1 cup flour' ],
            'recipeInstructions' => [ 'Mix.' ],
        ] ) . '</script>';

        $this->assertSame( 10, $parser->support_confidence( 'https://example.com', 'text/html', $html ) );
        $this->assertSame( 'Registered Parser Recipe', $parser->parse( 'https://example.com', 'text/html', $html )['title'] );
        $this->assertSame( 0, $parser->support_confidence( 'https://example.com', 'text/html', '<h1>Recipe</h1>' ) );
    }

    public function test_html_with_no_recipe_returns_null_not_garbage(): void {
        $html = '<html><body><h1>Some article</h1>'
              . '<p>1422 Bewertungen</p>'
              . '<p>5–15 MIN</p>'
              . '<p>— 8.2.2017 um 09:03 Uhr</p>'
              . '<p>Some prose paragraph with no recipe content here at all.</p>'
              . '</body></html>';
        $this->assertNull(
            Importer::from_schema_org_json_ld( $html ),
            'The JSON-LD parser should refuse unrelated HTML.'
        );
    }

    public function test_paste_text_with_section_headers(): void {
        $text = "Pancakes\n\nIngredients\n200 g flour\n2 eggs\n300 ml milk\n1 pinch salt\n\nMethod\nMix everything\nFry in a pan\nServe hot";
        $parsed = Importer::from_text( $text );

        $this->assertIsArray( $parsed );
        $this->assertSame( 'Pancakes', $parsed['title'] );
        $this->assertCount( 4, $parsed['ingredients'] );
        $this->assertSame( '200', $parsed['ingredients'][0]['amount'] );
        $this->assertSame( 'g',   $parsed['ingredients'][0]['unit'] );
        $this->assertSame( 'flour', $parsed['ingredients'][0]['name'] );
        $this->assertCount( 3, $parsed['instructions'] );
    }

    public function test_jsonld_howto_sections_are_preserved_as_parts(): void {
        $html = '<script type="application/ld+json">' . json_encode( [
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => 'Sectioned Instructions',
            'recipeIngredient' => [
                '200 g flour',
            ],
            'recipeInstructions' => [
                [
                    '@type' => 'HowToSection',
                    'name' => 'Dough',
                    'itemListElement' => [
                        [
                            '@type' => 'HowToStep',
                            'text' => 'Mix the flour and water.',
                        ],
                    ],
                ],
                [
                    '@type' => 'HowToSection',
                    'name' => 'Bake',
                    'itemListElement' => [
                        [
                            '@type' => 'HowToStep',
                            'text' => 'Bake until golden.',
                        ],
                    ],
                ],
            ],
        ] ) . '</script>';

        $parsed = Importer::from_schema_org_json_ld( $html );

        $this->assertIsArray( $parsed );
        $this->assertCount( 2, $parsed['parts'] );
        $this->assertSame( 'Dough', $parsed['parts'][0]['title'] );
        $this->assertSame( [ 'Mix the flour and water.' ], $parsed['parts'][0]['instructions'] );
        $this->assertSame( 'Bake', $parsed['parts'][1]['title'] );
        $this->assertSame( [ 'Bake until golden.' ], $parsed['parts'][1]['instructions'] );
        $this->assertSame( [ 'Mix the flour and water.', 'Bake until golden.' ], $parsed['instructions'] );
    }

    public function test_flat_jsonld_does_not_invent_parts(): void {
        $html = '<script type="application/ld+json">' . json_encode( [
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => 'Flat Recipe',
            'recipeIngredient' => [
                '200 g flour',
                '2 eggs',
            ],
            'recipeInstructions' => [
                [
                    '@type' => 'HowToStep',
                    'text' => 'Mix everything.',
                ],
            ],
        ] ) . '</script>';

        $parsed = Importer::from_schema_org_json_ld( $html );

        $this->assertIsArray( $parsed );
        $this->assertSame( [], $parsed['parts'] );
        $this->assertCount( 2, $parsed['ingredients'] );
        $this->assertSame( [ 'Mix everything.' ], $parsed['instructions'] );
    }

    public function test_jsonld_total_time_only_falls_back_to_cook_time(): void {
        $html = '<script type="application/ld+json">' . json_encode( [
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => 'Total Time Only',
            'totalTime' => 'PT25M',
            'recipeIngredient' => [ '200 g flour' ],
            'recipeInstructions' => [ [ '@type' => 'HowToStep', 'text' => 'Mix everything.' ] ],
        ] ) . '</script>';

        $parsed = Importer::from_schema_org_json_ld( $html );

        $this->assertIsArray( $parsed );
        $this->assertSame( 0, $parsed['prep_time'] );
        $this->assertSame(
            25,
            $parsed['cook_time'],
            'A source that supplies only totalTime should not import with no time at all.'
        );
    }

    public function test_explicit_prep_and_cook_times_win_over_total_time(): void {
        $html = '<script type="application/ld+json">' . json_encode( [
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => 'All Three Times',
            'prepTime'  => 'PT5M',
            'cookTime'  => 'PT10M',
            'totalTime' => 'PT15M',
            'recipeIngredient' => [ '200 g flour' ],
            'recipeInstructions' => [ [ '@type' => 'HowToStep', 'text' => 'Mix everything.' ] ],
        ] ) . '</script>';

        $parsed = Importer::from_schema_org_json_ld( $html );

        $this->assertIsArray( $parsed );
        $this->assertSame( 5,  $parsed['prep_time'] );
        $this->assertSame( 10, $parsed['cook_time'] );
    }

    public function test_hellofresh_real_world_page(): void {
        $parsed = Importer::from_schema_org_json_ld( $this->fixture( 'hellofresh-speedy-prawn-rigatoni.html' ) );

        $this->assertIsArray( $parsed );

        // The page publishes only totalTime, and that value is the total the
        // site itself displays ("Total: 25 minutes"). Keep it rather than
        // discarding it, and do not invent a prep/cook split — the page's
        // __NEXT_DATA__ carries a second, larger "totalTime" that does not
        // correspond to anything the reader is shown.
        $this->assertSame( 0,  $parsed['prep_time'] );
        $this->assertSame( 25, $parsed['cook_time'] );

        // The unit(s) / sachet(s) idiom must keep the real ingredient name.
        $names = array_column( $parsed['ingredients'], 'name' );
        $this->assertContains( 'Courgette', $names );
        $this->assertContains( 'Vegetable Stock', $names );

        // Instruction markup must not survive into stored content.
        $this->assertStringNotContainsString( '<li>', $parsed['instructions'][0] );
        $this->assertStringNotContainsString( '<strong>', $parsed['instructions'][0] );
        $this->assertStringContainsString( 'Boil a large pot of salted water', $parsed['instructions'][0] );
    }

    public function test_clean_step_strips_html_markup(): void {
        $this->assertSame(
            'Boil a large pot of salted water. Cook until softened, 12 mins.',
            Importer::clean_step(
                '<ul><li>Boil a large pot of <strong>salted water</strong>.</li>'
                . '<li>Cook until softened, 12 mins.</li></ul>'
            )
        );
        $this->assertSame( 'Mix everything', Importer::clean_step( '<p>Mix everything</p>' ) );
        $this->assertSame( 'Whisk eggs then fold in flour', Importer::clean_step( 'Whisk eggs<br>then fold in flour' ) );
        // Enumerator stripping still applies once the tags are gone.
        $this->assertSame( 'Mix everything', Importer::clean_step( '<p>1. Mix everything</p>' ) );
    }

    public function test_jsonld_iso8601_seconds_round_up_to_minutes(): void {
        $html = '<script type="application/ld+json">' . json_encode( [
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => 'Seconds Recipe',
            'prepTime' => 'PT30S',
            'cookTime' => 'PT1M30S',
            'recipeIngredient' => [
                '1 cup flour',
            ],
            'recipeInstructions' => [
                'Mix.',
            ],
        ] ) . '</script>';

        $parsed = Importer::from_schema_org_json_ld( $html );

        $this->assertIsArray( $parsed );
        $this->assertSame( 1, $parsed['prep_time'] );
        $this->assertSame( 2, $parsed['cook_time'] );
    }

    public function test_clean_step_strips_enumerators(): void {
        $this->assertSame( 'Mix everything', Importer::clean_step( '1. Mix everything' ) );
        $this->assertSame( 'Mix everything', Importer::clean_step( '1) Mix everything' ) );
        $this->assertSame( 'Mix everything', Importer::clean_step( 'Step 3: Mix everything' ) );
        $this->assertSame( 'Mix everything', Importer::clean_step( '- 4. Mix everything' ) );
        $this->assertSame( 'Mix everything', Importer::clean_step( '1. 1. Mix everything' ) );
        $this->assertSame( 'Whisk eggs',     Importer::clean_step( '• Whisk eggs' ) );
    }

    /** @dataProvider ingredientLines */
    public function test_parse_ingredient_line( string $line, array $expected ): void {
        $this->assertSame( $expected, Importer::parse_ingredient_line( $line ) );
    }

    public function test_parse_ingredient_line_handles_preparation_before_ingredient(): void {
        // bbcgoodfood.com writes "140g cooled, cooked rice" — the preparation
        // first, the reverse of the usual "<ingredient>, <preparation>" order.
        // Without the swap this registers "cooled" as the ingredient.
        $this->assertSame(
            [ 'amount' => '140', 'unit' => 'g', 'name' => 'cooked rice', 'notes' => 'cooled' ],
            Importer::parse_ingredient_line( '140g cooled, cooked rice' )
        );

        // A multi-word left side is never swapped, so the common order stands.
        $this->assertSame(
            [ 'amount' => '1', 'unit' => '', 'name' => 'baby potatoes', 'notes' => 'washed' ],
            Importer::parse_ingredient_line( '1 baby potatoes, washed' )
        );

        // Nor is a single word that is not a preparation.
        $this->assertSame(
            'onion',
            Importer::parse_ingredient_line( '1 onion, finely chopped' )['name']
        );
    }

    public function test_parse_ingredient_line_keeps_a_capitalised_compound_name(): void {
        // HelloFresh sells "Garlic, Ginger & Lemongrass Paste" as a single item.
        // The comma belongs to the name, so the line must not be split at all.
        $this->assertSame(
            [ 'amount' => '2', 'unit' => 'tbsp', 'name' => 'Garlic, Ginger & Lemongrass Paste', 'notes' => '' ],
            Importer::parse_ingredient_line( '2 tbsp Garlic, Ginger & Lemongrass Paste' )
        );

        // A lower-case right-hand side is still a note, whatever the left side.
        $this->assertSame(
            [ 'amount' => '2', 'unit' => 'tbsp', 'name' => 'Chicken', 'notes' => 'diced' ],
            Importer::parse_ingredient_line( '2 tbsp Chicken, diced' )
        );

        // "(" always opens a note, even between capitals.
        $this->assertSame(
            [ 'amount' => '200', 'unit' => 'g', 'name' => 'Nudeln', 'notes' => 'Fleckerl' ],
            Importer::parse_ingredient_line( '200 g Nudeln (Fleckerl)' )
        );
    }

    public static function ingredientLines(): array {
        return [
            'metric mass'   => [ '200 g Mascarpone',  [ 'amount' => '200', 'unit' => 'g',     'name' => 'Mascarpone',  'notes' => '' ] ],
            'imperial cup'  => [ '1 cup flour',       [ 'amount' => '1',   'unit' => 'cup',   'name' => 'flour',       'notes' => '' ] ],
            'fraction'      => [ '1/2 tsp salt',      [ 'amount' => '1/2', 'unit' => 'tsp',   'name' => 'salt',        'notes' => '' ] ],
            'mixed number'  => [ '1 1/2 cups water',  [ 'amount' => '1 1/2', 'unit' => 'cup', 'name' => 'water',       'notes' => '' ] ],
            'unicode frac'  => [ '½ tsp pepper',      [ 'amount' => '½',   'unit' => 'tsp',   'name' => 'pepper',      'notes' => '' ] ],
            'range'         => [ '2–3 cups flour',    [ 'amount' => '2–3', 'unit' => 'cup',   'name' => 'flour',       'notes' => '' ] ],
            'german el'     => [ '2 EL Öl',           [ 'amount' => '2',   'unit' => 'tbsp',  'name' => 'Öl',          'notes' => '' ] ],
            'german stk'    => [ '1 Stk Zwiebel',     [ 'amount' => '1',   'unit' => 'piece', 'name' => 'Zwiebel',     'notes' => '' ] ],
            'paren note'    => [ '200 g Nudeln (Fleckerl)', [ 'amount' => '200', 'unit' => 'g', 'name' => 'Nudeln', 'notes' => 'Fleckerl' ] ],
            'alternate unit' => [ '700 g (1 1/2 lb) baby potatoes, washed', [ 'amount' => '700', 'unit' => 'g', 'name' => 'baby potatoes', 'notes' => 'washed' ] ],
            'no number'     => [ 'Salt to taste',     [ 'amount' => '',    'unit' => '',      'name' => 'Salt to taste', 'notes' => '' ] ],
            // Meal-kit pluralisation idiom: the "(s)" must not be read as the
            // start of a parenthetical note, which would leave name = "unit".
            'unit(s) idiom'   => [ '1 unit(s) Courgette',           [ 'amount' => '1', 'unit' => 'piece',  'name' => 'Courgette',       'notes' => '' ] ],
            'unit(s) frac'    => [ '½ unit(s) Lemon',               [ 'amount' => '½', 'unit' => 'piece',  'name' => 'Lemon',           'notes' => '' ] ],
            'sachet(s) idiom' => [ '1 sachet(s) Vegetable Stock',   [ 'amount' => '1', 'unit' => 'packet', 'name' => 'Vegetable Stock', 'notes' => '' ] ],
            'pack(s) idiom'   => [ '1 pack(s) Coconut Milk',        [ 'amount' => '1', 'unit' => 'packet', 'name' => 'Coconut Milk',    'notes' => '' ] ],
            // HelloFresh leads with the qualifier instead of trailing it, so the
            // ingredient has to be lifted out of the phrase to index correctly.
            'leading to taste'      => [ 'to taste Salt',       [ 'amount' => '',  'unit' => '',    'name' => 'Salt',   'notes' => 'to taste' ] ],
            'leading to taste caps' => [ 'To Taste Pepper',     [ 'amount' => '',  'unit' => '',    'name' => 'Pepper', 'notes' => 'to taste' ] ],
            'leading plus amount'   => [ 'to taste 2 tsp salt', [ 'amount' => '2', 'unit' => 'tsp', 'name' => 'salt',   'notes' => 'to taste' ] ],
        ];
    }
}
