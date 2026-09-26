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
        if ( stripos( $content, 'application/ld+json' ) === false || stripos( $content, 'Recipe' ) === false ) {
            return 0;
        }
        return 10;
    }

    public function parse( string $url, string $content_type, string $content ): ?array {
        return Importer::from_schema_org_json_ld( $content );
    }
}
