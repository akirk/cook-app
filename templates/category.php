<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use Cookbook\App;

$slug = (string) get_query_var( 'slug' );
$term = $slug ? get_term_by( 'slug', $slug, App::TAX_CATEGORY ) : null;
if ( ! $term ) {
    status_header( 404 );
    $page_title = __( 'Category not found', 'cook-app' );
    include __DIR__ . '/_header.php';
    echo '<h1>' . esc_html__( 'Category not found', 'cook-app' ) . '</h1>';
    include __DIR__ . '/_footer.php';
    return;
}

$recipes = get_posts( [
    'post_type'      => App::POST_TYPE,
    'post_status'    => 'publish',
    'posts_per_page' => 100,
    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a category page intrinsically requires a taxonomy filter.
    'tax_query'      => [
        [ 'taxonomy' => App::TAX_CATEGORY, 'field' => 'term_id', 'terms' => $term->term_id ],
    ],
] );

$page_title = $term->name;
include __DIR__ . '/_header.php';
?>
<h1><?php echo esc_html( $term->name ); ?></h1>
<p class="subtitle">
    <?php
    /* translators: %d: number of recipes */
    echo esc_html( sprintf( _n( '%d recipe.', '%d recipes.', count( $recipes ), 'cook-app' ), count( $recipes ) ) );
    ?>
</p>

<?php if ( ! $recipes ) : ?>
    <div class="notice"><?php esc_html_e( 'No recipes in this category yet.', 'cook-app' ); ?></div>
<?php else : ?>
    <div class="grid">
    <?php foreach ( $recipes as $r ) : ?>
        <a class="recipe-card" href="<?php echo esc_url( home_url( '/cook-app/recipe/' . $r->ID ) ); ?>">
            <h3><?php echo esc_html( get_the_title( $r ) ); ?></h3>
            <?php if ( $r->post_excerpt ) : ?>
                <p style="margin:0.5rem 0 0;color:var(--muted)"><?php echo esc_html( $r->post_excerpt ); ?></p>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
