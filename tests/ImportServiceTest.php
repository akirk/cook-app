<?php

use CookApp\ImportService;
use CookApp\RecipeParser;
use CookApp\SchemaOrgRecipeParser;
use PHPUnit\Framework\TestCase;

class ImportServiceTest extends TestCase {
    public function test_bundled_parser_registers_through_parser_hook(): void {
        $imports = ( new ReflectionClass( ImportService::class ) )->newInstanceWithoutConstructor();

        $this->assertSame(
            SchemaOrgRecipeParser::NAME,
            $imports->get_registered_parsers()[ SchemaOrgRecipeParser::SLUG ]
        );
    }

    public function test_registered_parser_can_handle_a_document(): void {
        $imports = ( new ReflectionClass( ImportService::class ) )->newInstanceWithoutConstructor();
        $parser = new class() extends RecipeParser {
            public function support_confidence( string $url, string $content_type, string $content ): int {
                return str_contains( $content, 'custom-recipe' ) ? 100 : 0;
            }

            public function parse( string $url, string $content_type, string $content ): ?array {
                return [ 'title' => 'Extension Recipe' ];
            }
        };

        $this->assertTrue( $imports->register_parser( 'extension', $parser ) );
        $this->assertSame(
            'Extension Recipe',
            $imports->parse_document( 'https://example.com', 'text/html', '<custom-recipe>')['title']
        );
        $this->assertSame( 'extension', array_key_first( $imports->get_registered_parsers() ) );
    }
}
