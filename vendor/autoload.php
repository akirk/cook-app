<?php
require_once __DIR__ . '/akirk/wp-app/functions.php';
spl_autoload_register( static function ( $class ) {
	$map = [
		'WpApp\\BaseApp' => 'abstract-baseapp.php',
		'WpApp\\BaseStorage' => 'BaseStorage.php',
		'WpApp\\Masterbar' => 'class-masterbar.php',
		'WpApp\\Openstation' => 'class-openstation.php',
		'WpApp\\Pwa' => 'class-pwa.php',
		'WpApp\\Registry' => 'class-registry.php',
		'WpApp\\Router' => 'class-router.php',
		'WpApp\\Settings' => 'class-settings.php',
		'WpApp\\WpApp' => 'class-wpapp.php',
		'WpApp\\Rest\\Access' => 'rest/class-access.php',
		'WpApp\\Rest\\Private_Posts_Controller' => 'rest/class-private-posts-controller.php',
		'WpApp\\Rest\\Private_Terms_Controller' => 'rest/class-private-terms-controller.php',
	];
	if ( isset( $map[ $class ] ) ) {
		require_once __DIR__ . '/akirk/wp-app/' . $map[ $class ];
	}
} );
