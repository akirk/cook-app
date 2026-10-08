<?php
/** Generate fresh interchange data through Cook App's exporter, without WordPress storage. */
require dirname( __DIR__ ) . '/bootstrap.php';

use CookApp\App;
use CookApp\BackupService;

$ingredient = [ 'name' => 'flour', 'amount' => '200', 'unit' => 'g', 'notes' => 'sifted' ];
$base = [ 'type' => App::POST_TYPE, 'content' => 'Compatibility test recipe', 'status' => 'publish',
    'parent' => 0, 'image_url' => '', 'terms' => [ App::TAX_CATEGORY => [ 8 ], App::TAX_TAG => [ 9 ] ],
    'meta' => [ App::META_SERVINGS => 2, App::META_PREP => 5, App::META_COOK => 10,
        App::META_INGREDIENTS => [ $ingredient ], App::META_INSTRUCTIONS => [ 'Mix the ingredients.', 'Cook in a pan.' ],
        App::META_SOURCE_URL => 'https://example.com/recipe', App::META_NOTES => 'Serve warm.',
    ],
];
$flat = array_merge( $base, [ 'id' => 1, 'title' => 'Simple pancakes' ] );
$grouped = array_merge( $base, [ 'id' => 2, 'title' => 'Pancakes with topping' ] );
$grouped['meta'][ App::META_PARTS ] = [
    [ 'title' => 'Batter', 'ingredients' => [ $ingredient ], 'instructions' => [ 'Mix the ingredients.', 'Cook in a pan.' ] ],
    [ 'title' => 'Topping', 'ingredients' => [ [ 'name' => 'berries', 'amount' => '0.5', 'unit' => 'cup', 'notes' => '' ] ],
        'instructions' => [ 'Add the berries.' ] ],
];
$backups = ( new ReflectionClass( BackupService::class ) )->newInstanceWithoutConstructor();
$document = $backups->to_document( [ 'posts' => [ $flat, $grouped ], 'terms' => [
    [ 'id' => 8, 'taxonomy' => App::TAX_CATEGORY, 'name' => 'Breakfast', 'parent' => 0 ],
    [ 'id' => 9, 'taxonomy' => App::TAX_TAG, 'name' => 'Quick', 'parent' => 0 ],
] ] );
echo json_encode( $backups->recipe_document( $document ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
