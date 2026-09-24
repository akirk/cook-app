<?php

use CookApp\StaticArchiveService;
use PHPUnit\Framework\TestCase;

class StaticArchiveServiceTest extends TestCase {

    private StaticArchiveService $archive;

    protected function setUp(): void {
        $this->archive = ( new ReflectionClass( StaticArchiveService::class ) )->newInstanceWithoutConstructor();
    }

    public function test_static_archive_renders_ingredient_sections_as_html(): void {
        $parts = $this->invoke( 'clean_static_archive_parts', [
            [
                'title'       => 'Sauce',
                'ingredients' => [
                    [ 'amount' => '1', 'unit' => 'tbsp', 'name' => 'Dijon mustard', 'notes' => '' ],
                ],
            ],
            [
                'title'       => 'Fish',
                'ingredients' => [
                    [ 'amount' => '4', 'unit' => '', 'name' => 'salmon fillets', 'notes' => 'skin on' ],
                ],
            ],
        ] );

        $html = $this->invoke( 'render_static_archive_ingredient_parts_html', $parts );

        $this->assertStringContainsString( '<section class="recipe-part"><h3>Sauce</h3><ul><li>1 tbsp Dijon mustard</li></ul></section>', $html );
        $this->assertStringContainsString( '<section class="recipe-part"><h3>Fish</h3><ul><li>4 salmon fillets (skin on)</li></ul></section>', $html );
    }

    public function test_static_archive_renders_instruction_sections_as_markdown(): void {
        $parts = $this->invoke( 'clean_static_archive_parts', [
            [
                'title'        => 'Potatoes',
                'instructions' => [
                    '1. Boil potatoes.',
                    '<strong>Roast until crisp.</strong>',
                ],
            ],
            [
                'title'        => 'Sauce',
                'instructions' => [
                    'Mix sauce ingredients.',
                ],
            ],
        ] );

        $markdown = $this->invoke( 'render_static_archive_instruction_parts_markdown', $parts );

        $this->assertSame(
            "### Potatoes\n\n1. Boil potatoes.\n2. Roast until crisp.\n\n### Sauce\n\n1. Mix sauce ingredients.",
            $markdown
        );
    }

    public function test_notes_markdown_renders_basic_markup_without_images(): void {
        $html = CookApp\Markdown::to_html(
            "Keep it simple.\n\n## Variations\n\n- **Makhani:** add cream\n- [Source](https://example.com)\n- ![Photo](https://example.com/photo.jpg)"
        );

        $this->assertStringContainsString( '<p>Keep it simple.</p>', $html );
        $this->assertStringContainsString( '<h3>Variations</h3>', $html );
        $this->assertStringContainsString( '<li><strong>Makhani:</strong> add cream</li>', $html );
        $this->assertStringContainsString( '<a href="https://example.com">Source</a>', $html );
        $this->assertStringContainsString( '<li>Photo</li>', $html );
        $this->assertStringNotContainsString( '<img', $html );
    }

    public function test_note_images_are_removed_from_markdown_exports(): void {
        $this->assertSame(
            'See Photo here.',
            $this->invoke( 'static_archive_markdown_text', CookApp\Markdown::strip_images( 'See ![Photo](https://example.com/photo.jpg) here.' ) )
        );
    }

    private function invoke( string $method, ...$args ) {
        $reflection = new ReflectionMethod( StaticArchiveService::class, $method );
        $reflection->setAccessible( true );
        return $reflection->invokeArgs( $this->archive, $args );
    }
}
