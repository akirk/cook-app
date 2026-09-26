<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class RecipeParser {
    public function support_confidence( string $url, string $content_type, string $content ): int {
        return 0;
    }

    /** @return array|null */
    abstract public function parse( string $url, string $content_type, string $content ): ?array;
}
