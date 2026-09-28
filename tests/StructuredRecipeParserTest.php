<?php

use CookApp\MicrodataRecipeParser;
use CookApp\RdfaRecipeParser;
use PHPUnit\Framework\TestCase;

class StructuredRecipeParserTest extends TestCase {
    private function fixture( string $name ): string {
        return file_get_contents( __DIR__ . '/fixtures/' . $name );
    }

    public function test_parses_recipe_microdata(): void {
        $html = $this->fixture( 'schema-recipe-microdata.html' );
        $parser = new MicrodataRecipeParser();

        $this->assertSame( 10, $parser->support_confidence( 'https://example.com', 'text/html', $html ) );
        $recipe = $parser->parse( 'https://example.com', 'text/html', $html );
        $this->assertSame( 'Microdata Soup', $recipe['title'] );
        $this->assertSame( 2, $recipe['servings'] );
        $this->assertCount( 2, $recipe['ingredients'] );
        $this->assertSame( 'Simmer until tender.', $recipe['instructions'][0] );
    }

    public function test_parses_real_world_recipe_microdata(): void {
        $html = $this->fixture( 'ichkoche-kaesespaetzle.html' );
        $parser = new MicrodataRecipeParser();
        $url = 'https://www.ichkoche.at/kaesespaetzle-rezept-2215';

        $this->assertSame( 10, $parser->support_confidence( $url, 'text/html', $html ) );
        $recipe = $parser->parse( $url, 'text/html', $html );
        $this->assertSame( 'Käsespätzle', $recipe['title'] );
        $this->assertSame( 4, $recipe['servings'] );
        $this->assertSame( 30, $recipe['cook_time'] );
        $this->assertCount( 10, $recipe['ingredients'] );
        $this->assertSame( 'Bergkäse', $recipe['ingredients'][0]['name'] );
        $this->assertStringContainsString( 'Einen großen Topf Salzwasser', $recipe['instructions'][0] );
    }

    public function test_parses_recipe_rdfa(): void {
        $html = $this->fixture( 'schema-recipe-rdfa.html' );
        $parser = new RdfaRecipeParser();

        $this->assertSame( 10, $parser->support_confidence( 'https://example.com', 'text/html', $html ) );
        $recipe = $parser->parse( 'https://example.com', 'text/html', $html );
        $this->assertSame( 'RDFa Stew', $recipe['title'] );
        $this->assertSame( 3, $recipe['servings'] );
        $this->assertSame( 10, $recipe['prep_time'] );
        $this->assertSame( 30, $recipe['cook_time'] );
        $this->assertSame( 'potatoes', $recipe['ingredients'][0]['name'] );
        $this->assertSame( 'Cook until tender.', $recipe['instructions'][0] );
    }
}
