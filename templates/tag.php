<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use CookApp\App;

$slug = (string) get_query_var( 'slug' );
$term = $slug ? get_term_by( 'slug', $slug, App::TAX_TAG ) : null;
if ( ! $term ) {
    status_header( 404 );
    $page_title = __( 'Tag not found', 'cook-app' );
    include __DIR__ . '/_header.php';
    echo '<h1>' . esc_html__( 'Tag not found', 'cook-app' ) . '</h1>';
    include __DIR__ . '/_footer.php';
    return;
}

$recipes = get_posts( [
    'post_type'      => App::POST_TYPE,
    'post_status'    => 'publish',
    'posts_per_page' => 100,
    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a tag page intrinsically requires a taxonomy filter.
    'tax_query'      => [
        [ 'taxonomy' => App::TAX_TAG, 'field' => 'term_id', 'terms' => $term->term_id ],
    ],
] );

$page_title = '#' . $term->name;
include __DIR__ . '/_header.php';
?>
<h1>#<?php echo esc_html( $term->name ); ?></h1>
<p class="subtitle">
    <?php
    /* translators: %d: number of recipes */
    echo esc_html( sprintf( _n( '%d recipe.', '%d recipes.', count( $recipes ), 'cook-app' ), count( $recipes ) ) );
    ?>
</p>

<?php if ( ! $recipes ) : ?>
    <div class="notice"><?php esc_html_e( 'No recipes with this tag yet.', 'cook-app' ); ?></div>
<?php else : ?>
    <div class="grid">
    <?php foreach ( $recipes as $r ) : ?>
        <a class="recipe-card" href="<?php echo esc_url( home_url( '/cook-app/recipe/' . $r->ID ) ); ?>">
            <h3><?php echo esc_html( get_the_title( $r ) ); ?></h3>
        </a>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
