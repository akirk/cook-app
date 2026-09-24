<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
if ( ! function_exists( 'cookbook_page_head' ) ) {
    function cookbook_page_actions_allowed_html(): array {
        return [
            'a'      => [
                'aria-current' => true,
                'aria-label'   => true,
                'class'        => true,
                'href'         => true,
                'title'        => true,
            ],
            'button' => [
                'aria-label' => true,
                'class'      => true,
                'disabled'   => true,
                'form'       => true,
                'name'       => true,
                'title'      => true,
                'type'       => true,
                'value'      => true,
            ],
            'span'   => [
                'aria-hidden' => true,
                'class'       => true,
            ],
        ];
    }

    /**
     * Render a consistent Cook App page heading with optional section navigation.
     *
     * @param string $title Page heading text.
     * @param array  $args  {
     *     Optional heading arguments.
     *
     *     @type string $current_section Current section key: shopping, planner, cooked, ingredients.
     *     @type string $subtitle        Plain subtitle text.
     *     @type string $actions_html    Rendered action controls filtered against a small allow-list.
     *     @type bool   $nav             Whether to show section navigation. Default true.
     * }
     */
    function cookbook_page_head( string $title, array $args = [] ): void {
        $show_nav = array_key_exists( 'nav', $args ) ? (bool) $args['nav'] : true;
        $current_section = isset( $args['current_section'] ) ? (string) $args['current_section'] : '';
        $subtitle = isset( $args['subtitle'] ) ? (string) $args['subtitle'] : '';
        $actions_html = isset( $args['actions_html'] ) ? (string) $args['actions_html'] : '';
        $sections = [
            'recipes'     => [
                'label' => __( 'Recipes', 'cook-app' ),
                'url'   => home_url( '/cook-app/' ),
            ],
            'shopping'    => [
                'label' => __( 'Shopping', 'cook-app' ),
                'url'   => home_url( '/cook-app/shopping-list' ),
            ],
            'planner'     => [
                'label' => __( 'Planner', 'cook-app' ),
                'url'   => home_url( '/cook-app/planner' ),
            ],
            'cooked'      => [
                'label' => __( 'Cooking history', 'cook-app' ),
                'url'   => home_url( '/cook-app/cooked' ),
            ],
            'ingredients' => [
                'label' => __( 'Ingredients', 'cook-app' ),
                'url'   => home_url( '/cook-app/by-ingredients' ),
            ],
        ];
        ?>
        <div class="page-head">
            <div class="page-head-main">
                <h1><?php echo esc_html( $title ); ?></h1>
                <?php if ( $show_nav ) : ?>
                    <nav class="page-head-nav" aria-label="<?php esc_attr_e( 'Cook App sections', 'cook-app' ); ?>">
                        <?php foreach ( $sections as $section_key => $section ) : ?>
                            <a href="<?php echo esc_url( $section['url'] ); ?>"<?php echo $current_section === $section_key ? ' aria-current="page"' : ''; ?>>
                                <?php echo esc_html( $section['label'] ); ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>
                <?php if ( $subtitle !== '' ) : ?>
                    <p class="subtitle"><?php echo esc_html( $subtitle ); ?></p>
                <?php endif; ?>
            </div>
            <?php if ( $actions_html !== '' ) : ?>
                <div class="page-actions">
                    <?php echo wp_kses( $actions_html, cookbook_page_actions_allowed_html() ); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
/**
 * Shared header partial for Cook App.
 *
 * Templates include this near the top to set up <head>, masterbar, and the
 * outer page chrome. Pair it with templates/_footer.php.
 */
wp_app_enqueue_style(
    'cook-app',
    COOK_APP_PLUGIN_URL . 'assets/cookbook.css',
    array(),
    filemtime( dirname( __DIR__ ) . '/assets/cookbook.css' ),
    'cook-app'
);
?>
<!DOCTYPE html>
<html <?php echo wp_app_language_attributes( false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- language attributes are escaped by WordPress. ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_app_the_title( isset( $page_title ) ? $page_title : '' ); ?></title>
    <?php wp_app_head(); ?>

</head>
<body class="wp-app-body">
    <?php wp_app_body_open(); ?>
    <main>
