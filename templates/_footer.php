<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

wp_app_enqueue_script(
    'cook-app',
    COOK_APP_PLUGIN_URL . 'assets/cook-app.js',
    array(),
    filemtime( dirname( __DIR__ ) . '/assets/cook-app.js' ),
    true,
    'cook-app'
);
?>
    </main>

    <?php wp_app_body_close(); ?>
</body>
</html>
