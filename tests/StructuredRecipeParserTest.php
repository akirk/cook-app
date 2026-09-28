<?php

use CookApp\MicrodataRecipeParser;
use CookApp\RdfaRecipeParser;
use PHPUnit\Framework\TestCase;

class StructuredRecipeParserTest extends TestCase {
    public function test_parses_recipe_microdata(): void {
        $html = '<article itemscope itemtype="https://schema.org/Recipe">'
            . '<h1 itemprop="name">Microdata Soup</h1>'
            . '<meta itemprop="recipeYield" content="2">'
            . '<meta itemprop="prepTime" content="PT5M">'
            . '<meta itemprop="cookTime" content="PT20M">'
            . '<meta itemprop="recipeIngredient" content="2 cups stock">'
            . '<meta itemprop="recipeIngredient" content="1 carrot">'
            . '<p itemprop="recipeInstructions">Simmer until tender.</p>'
            . '</article>';
        $parser = new MicrodataRecipeParser();

        $this->assertSame( 10, $parser->support_confidence( 'https://example.com', 'text/html', $html ) );
        $recipe = $parser->parse( 'https://example.com', 'text/html', $html );
        $this->assertSame( 'Microdata Soup', $recipe['title'] );
        $this->assertSame( 2, $recipe['servings'] );
        $this->assertCount( 2, $recipe['ingredients'] );
        $this->assertSame( 'Simmer until tender.', $recipe['instructions'][0] );
    }

    public function test_parses_recipe_rdfa(): void {
        $html = '<article vocab="https://schema.org/" typeof="Recipe">'
            . '<h1 property="name">RDFa Stew</h1>'
            . '<meta property="recipeYield" content="3">'
            . '<span property="recipeIngredient">500 g potatoes</span>'
            . '<p property="recipeInstructions">Cook until tender.</p>'
            . '</article>';
        $parser = new RdfaRecipeParser();

        $this->assertSame( 10, $parser->support_confidence( 'https://example.com', 'text/html', $html ) );
        $recipe = $parser->parse( 'https://example.com', 'text/html', $html );
        $this->assertSame( 'RDFa Stew', $recipe['title'] );
        $this->assertSame( 3, $recipe['servings'] );
        $this->assertSame( 'potatoes', $recipe['ingredients'][0]['name'] );
        $this->assertSame( 'Cook until tender.', $recipe['instructions'][0] );
    }
}
