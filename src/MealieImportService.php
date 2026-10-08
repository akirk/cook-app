<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Import Mealie's single-recipe and bulk recipe exports, not database backups. */
class MealieImportService extends AbstractService {
    const MAX_ENTRIES = 2000;

    public function import_archive( string $path, int $user_id ): int {
        $archive = $this->decode_archive( $path );
        if ( $archive['images'] && ! current_user_can( 'upload_files' ) ) {
            throw new \RuntimeException( 'Not allowed to upload recipe photos.' );
        }
        return $this->services->backups()->restore_data( $archive['data'], $user_id, function( int $post_id, int $source_id ) use ( $archive ): int {
            return isset( $archive['images'][ $source_id ] ) ? $this->attach_image( $post_id, $archive['images'][ $source_id ] ) : 0;
        } );
    }

    /** Read bounded entries without extracting archive paths to the filesystem. */
    public function decode_archive( string $path ): array {
        if ( ! class_exists( '\ZipArchive' ) ) {
            throw new \RuntimeException( 'Mealie imports require the PHP ZIP extension.' );
        }
        $size = filesize( $path );
        if ( $size === false || $size > BackupService::MAX_BYTES ) {
            throw new \InvalidArgumentException( 'Mealie archive exceeds the 10 MB limit.' );
        }
        $zip = new \ZipArchive();
        if ( $zip->open( $path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS ) !== true ) {
            throw new \InvalidArgumentException( 'Invalid Mealie ZIP archive.' );
        }
        try {
            $entries = $this->archive_entries( $zip );
            $data = [ 'format' => 'cook-app-backup', 'version' => 1, 'posts' => [], 'terms' => [] ];
            $images = [];
            $term_index = [];
            $recipe_ids = [];
            $directories = [];
            foreach ( $entries as $name => $index ) {
                if ( ! preg_match( '#^(?:recipes/([^/]+)/)?([^/]+)\.json$#', $name, $match ) ) {
                    continue;
                }
                if ( ! empty( $match[1] ) && $match[1] !== $match[2] ) {
                    throw new \InvalidArgumentException( 'Invalid Mealie recipe directory.' );
                }
                $recipe = json_decode( $this->read_entry( $zip, $index ), true, 64 );
                if ( ! is_array( $recipe ) || ! is_string( $recipe['name'] ?? null ) || trim( $recipe['name'] ) === ''
                    || ! is_array( $this->field( $recipe, 'recipe_ingredient', 'recipeIngredient' ) )
                    || ! is_array( $this->field( $recipe, 'recipe_instructions', 'recipeInstructions' ) ) ) {
                    throw new \InvalidArgumentException( 'Expected a Mealie recipe export, not a database backup or arbitrary ZIP.' );
                }
                $uuid = $this->text( $recipe['id'] ?? '' );
                if ( $uuid !== '' && isset( $recipe_ids[ $uuid ] ) ) {
                    throw new \InvalidArgumentException( 'Duplicate recipe in Mealie archive.' );
                }
                $recipe_ids[ $uuid ] = true;
                $id = count( $data['posts'] ) + 1;
                $directory = dirname( $name );
                if ( isset( $directories[ $directory ] ) ) {
                    throw new \InvalidArgumentException( 'Each Mealie recipe must have its own directory.' );
                }
                $directories[ $directory ] = true;
                $data['posts'][] = $this->recipe_entry( $recipe, $id, $data['terms'], $term_index );
                $prefix = $directory === '.' ? '' : $directory . '/';
                foreach ( [ $prefix . 'images/original.webp', $prefix . 'original.webp' ] as $image_name ) {
                    if ( isset( $entries[ $image_name ] ) ) {
                        $bytes = $this->read_entry( $zip, $entries[ $image_name ] );
                        $info = @getimagesizefromstring( $bytes );
                        if ( ! $info || ( $info['mime'] ?? '' ) !== 'image/webp' || $info[0] * $info[1] > 40000000 ) {
                            throw new \InvalidArgumentException( 'Invalid or oversized Mealie recipe photo.' );
                        }
                        $images[ $id ] = $bytes;
                        break;
                    }
                }
            }
            if ( ! $data['posts'] ) {
                throw new \InvalidArgumentException( 'No Mealie recipes found in the ZIP archive.' );
            }
            // Reuse the complete backup validator; writes start only after every recipe and photo passes.
            $backups = $this->services->backups();
            $json = json_encode( $backups->to_document( $data ), JSON_THROW_ON_ERROR );
            return [ 'data' => $backups->decode_backup( $json ), 'images' => $images ];
        } finally {
            $zip->close();
        }
    }

    private function archive_entries( \ZipArchive $zip ): array {
        if ( $zip->numFiles > self::MAX_ENTRIES ) {
            throw new \InvalidArgumentException( 'Too many files in Mealie archive.' );
        }
        $entries = [];
        $seen = [];
        $total = 0;
        for ( $index = 0; $index < $zip->numFiles; ++$index ) {
            $stat = $zip->statIndex( $index );
            if ( ! $stat ) {
                throw new \InvalidArgumentException( 'Unreadable Mealie archive entry.' );
            }
            $name = $stat['name'];
            if ( $name === '' || strpos( $name, '\\' ) !== false || strpos( $name, "\0" ) !== false
                || preg_match( '#(^/|^[a-z]:|(^|/)\.\.?(/|$))#i', $name ) || isset( $seen[ $name ] ) ) {
                throw new \InvalidArgumentException( 'Unsafe or duplicate path in Mealie archive.' );
            }
            $seen[ $name ] = true;
            $system = 0;
            $attributes = 0;
            $zip->getExternalAttributesIndex( $index, $system, $attributes );
            if ( $system === \ZipArchive::OPSYS_UNIX && ( ( $attributes >> 16 ) & 0170000 ) === 0120000 ) {
                throw new \InvalidArgumentException( 'Symbolic links are not supported in Mealie archives.' );
            }
            $total += $stat['size'];
            if ( $total > BackupService::MAX_BYTES ) {
                throw new \InvalidArgumentException( 'Expanded Mealie archive exceeds the 10 MB limit.' );
            }
            if ( substr( $name, -1 ) !== '/' ) {
                $entries[ $name ] = $index;
            }
        }
        return $entries;
    }

    private function read_entry( \ZipArchive $zip, int $index ): string {
        $bytes = $zip->getFromIndex( $index, BackupService::MAX_BYTES + 1 );
        $stat = $zip->statIndex( $index );
        if ( $bytes === false || ! $stat || strlen( $bytes ) !== $stat['size'] || strlen( $bytes ) > BackupService::MAX_BYTES ) {
            throw new \InvalidArgumentException( 'Could not read Mealie archive entry.' );
        }
        return $bytes;
    }

    private function field( array $data, string $snake, string $camel ) {
        return $data[ $snake ] ?? $data[ $camel ] ?? null;
    }

    private function text( $value ): string {
        if ( $value === null ) {
            return '';
        }
        if ( ! is_scalar( $value ) ) {
            throw new \InvalidArgumentException( 'Invalid text field in Mealie recipe.' );
        }
        return trim( (string) $value );
    }

    private function recipe_entry( array $recipe, int $id, array &$terms, array &$term_index ): array {
        $ingredients = [];
        $instructions = [];
        $parts = [];
        foreach ( [ 'ingredients' => $this->field( $recipe, 'recipe_ingredient', 'recipeIngredient' ),
            'instructions' => $this->field( $recipe, 'recipe_instructions', 'recipeInstructions' ) ] as $kind => $rows ) {
            $part = null;
            $occurrences = [];
            foreach ( $rows as $row ) {
                if ( ! is_array( $row ) ) {
                    throw new \InvalidArgumentException( 'Invalid ingredient or instruction in Mealie recipe.' );
                }
                $title = $this->text( $row['title'] ?? '' );
                if ( $title !== '' || $part === null ) {
                    $occurrence = $occurrences[ $title ] ?? 0;
                    $occurrences[ $title ] = $occurrence + 1;
                    $part = $title . ':' . $occurrence;
                    if ( ! isset( $parts[ $part ] ) ) {
                        $parts[ $part ] = [ 'title' => $title, 'ingredients' => [], 'instructions' => [] ];
                    }
                }
                if ( $kind === 'ingredients' ) {
                    $ingredient = $this->ingredient( $row );
                    if ( $ingredient['name'] === '' ) {
                        continue;
                    }
                    $ingredient['term_id'] = $this->term( App::TAX_INGREDIENT, $ingredient['name'], $terms, $term_index );
                    $ingredients[] = $ingredient;
                    $parts[ $part ]['ingredients'][] = $ingredient;
                } else {
                    $step = $this->text( $row['text'] ?? '' );
                    $summary = $this->text( $row['summary'] ?? '' );
                    if ( $summary !== '' ) {
                        $step = $summary . ( $step !== '' ? ': ' . $step : '' );
                    }
                    if ( $step !== '' ) {
                        $instructions[] = $step;
                        $parts[ $part ]['instructions'][] = $step;
                    }
                }
            }
        }
        $parts = array_values( array_filter( $parts, function( $part ) { return $part['ingredients'] || $part['instructions']; } ) );
        if ( ! array_filter( array_column( $parts, 'title' ) ) ) {
            $parts = [];
        }
        $assignments = [ App::TAX_INGREDIENT => array_values( array_unique( array_column( $ingredients, 'term_id' ) ) ) ];
        foreach ( [ App::TAX_CATEGORY => $this->field( $recipe, 'recipe_category', 'recipeCategory' ), App::TAX_TAG => $recipe['tags'] ?? [] ] as $taxonomy => $rows ) {
            if ( $rows !== null && ! is_array( $rows ) ) {
                throw new \InvalidArgumentException( 'Invalid taxonomy in Mealie recipe.' );
            }
            $assignments[ $taxonomy ] = [];
            foreach ( $rows ?? [] as $row ) {
                $name = $this->text( is_array( $row ) ? ( $row['name'] ?? '' ) : $row );
                if ( $name !== '' ) {
                    $assignments[ $taxonomy ][] = $this->term( $taxonomy, $name, $terms, $term_index );
                }
            }
        }
        $notes = [];
        if ( isset( $recipe['notes'] ) && ! is_array( $recipe['notes'] ) ) {
            throw new \InvalidArgumentException( 'Invalid notes in Mealie recipe.' );
        }
        foreach ( $recipe['notes'] ?? [] as $note ) {
            if ( ! is_array( $note ) ) {
                throw new \InvalidArgumentException( 'Invalid note in Mealie recipe.' );
            }
            $title = $this->text( $note['title'] ?? '' );
            $text = $this->text( $note['text'] ?? '' );
            if ( $title !== '' || $text !== '' ) {
                $notes[] = ( $title !== '' ? $title . "\n" : '' ) . $text;
            }
        }
        $servings = $this->field( $recipe, 'recipe_servings', 'recipeServings' );
        if ( ! is_numeric( $servings ) || (float) $servings <= 0 ) {
            $yield = $this->text( $this->field( $recipe, 'recipe_yield', 'recipeYield' ) );
            $servings = preg_match( '/\d+/', $yield, $match ) ? (int) $match[0] : 4;
        }
        $prep = $this->minutes( $this->field( $recipe, 'prep_time', 'prepTime' ) );
        $cook = $this->minutes( $this->field( $recipe, 'cook_time', 'cookTime' ) ?: $this->field( $recipe, 'perform_time', 'performTime' ) );
        if ( ! $cook ) {
            $cook = max( 0, $this->minutes( $this->field( $recipe, 'total_time', 'totalTime' ) ) - $prep );
        }
        return [ 'id' => $id, 'type' => App::POST_TYPE, 'title' => $recipe['name'],
            'content' => $this->text( $recipe['description'] ?? '' ), 'status' => 'publish', 'parent' => 0, 'image_url' => '',
            'terms' => $assignments, 'meta' => [
                App::META_SERVINGS => max( 1, (int) $servings ), App::META_PREP => $prep, App::META_COOK => $cook,
                App::META_INGREDIENTS => $ingredients, App::META_INSTRUCTIONS => $instructions, App::META_PARTS => $parts,
                App::META_SOURCE_URL => $this->text( $this->field( $recipe, 'org_url', 'orgURL' ) ), App::META_NOTES => implode( "\n\n", $notes ),
            ],
        ];
    }

    private function ingredient( array $row ): array {
        $food = $row['food'] ?? null;
        $unit = $row['unit'] ?? null;
        $name = $this->text( is_array( $food ) ? ( $food['name'] ?? '' ) : $food );
        $unit_name = $this->text( is_array( $unit ) ? ( $unit['abbreviation'] ?? '' ) : $unit );
        if ( $unit_name === '' && is_array( $unit ) ) {
            $unit_name = $this->text( $unit['name'] ?? '' );
        }
        $quantity = $row['quantity'] ?? null;
        if ( $quantity !== null && $quantity !== '' && ! is_numeric( $quantity ) ) {
            throw new \InvalidArgumentException( 'Invalid quantity in Mealie recipe.' );
        }
        if ( $name !== '' ) {
            return [ 'name' => $name, 'amount' => $quantity === null || $quantity === '' || (float) $quantity === 0.0 ? '' : (string) $quantity,
                'unit' => $unit_name, 'notes' => $this->text( $row['note'] ?? '' ) ];
        }
        // Text-only recipes store the original line in note/display, without food or unit objects.
        $line = $this->text( $row['display'] ?? '' ) ?: $this->text( $row['note'] ?? '' );
        return Importer::parse_ingredient_line( $line );
    }

    private function term( string $taxonomy, string $name, array &$terms, array &$index ): int {
        $key = $taxonomy . ':' . $name;
        if ( ! isset( $index[ $key ] ) ) {
            $index[ $key ] = count( $terms ) + 1;
            $terms[] = [ 'id' => $index[ $key ], 'taxonomy' => $taxonomy, 'name' => $name, 'parent' => 0 ];
        }
        return $index[ $key ];
    }

    private function minutes( $value ): int {
        $text = $this->text( $value );
        if ( $text === '' ) {
            return 0;
        }
        if ( preg_match( '/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', $text, $match ) ) {
            return (int) ( $match[1] ?? 0 ) * 1440 + (int) ( $match[2] ?? 0 ) * 60
                + (int) ( $match[3] ?? 0 ) + (int) ceil( (int) ( $match[4] ?? 0 ) / 60 );
        }
        if ( is_numeric( $text ) ) {
            return max( 0, (int) ceil( (float) $text ) );
        }
        if ( preg_match( '/^(\d+):(\d{2}):(\d{2})$/', $text, $match ) ) {
            return (int) $match[1] * 60 + (int) $match[2] + (int) ceil( (int) $match[3] / 60 );
        }
        $minutes = 0;
        if ( preg_match( '/(\d+(?:\.\d+)?)\s*(?:days?|d)\b/i', $text, $match ) ) {
            $minutes += (int) ceil( (float) $match[1] * 1440 );
        }
        if ( preg_match( '/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?|h)\b/i', $text, $match ) ) {
            $minutes += (int) ceil( (float) $match[1] * 60 );
        }
        if ( preg_match( '/(\d+(?:\.\d+)?)\s*(?:minutes?|mins?|m)\b/i', $text, $match ) ) {
            $minutes += (int) ceil( (float) $match[1] );
        }
        return $minutes;
    }

    private function attach_image( int $post_id, string $bytes ): int {
        if ( ! function_exists( 'media_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $path = wp_tempnam( 'mealie-recipe.webp' );
        if ( ! $path ) {
            throw new \RuntimeException( 'Could not create a temporary recipe photo.' );
        }
        try {
            if ( file_put_contents( $path, $bytes ) !== strlen( $bytes ) ) {
                throw new \RuntimeException( 'Could not write the recipe photo.' );
            }
            $attachment_id = media_handle_sideload( [ 'name' => 'mealie-recipe.webp', 'tmp_name' => $path ], $post_id );
            if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
                throw new \RuntimeException( 'Could not import the recipe photo.' );
            }
            if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
                wp_delete_attachment( $attachment_id, true );
                throw new \RuntimeException( 'Could not set the recipe photo.' );
            }
            return (int) $attachment_id;
        } finally {
            wp_delete_file( $path );
        }
    }
}
