<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class AbstractService {
    protected ServiceContainer $services;

    public function __construct( ServiceContainer $services ) {
        $this->services = $services;
    }

    protected function get_url_path(): string {
        return 'cook-app';
    }
}
