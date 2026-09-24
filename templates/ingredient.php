<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use Cookbook\App;

$slug = (string) get_query_var( 'slug' );
$term = $slug ? get_term_by( 'slug', $slug, App::TAX_INGREDIENT ) : null;
if ( ! $term ) {
    status_header( 404 );
    $page_title = __( 'Ingredient not found', 'cook-app' );
    include __DIR__ . '/_header.php';
    echo '<h1>' . esc_html__( 'Ingredient not found', 'cook-app' ) . '</h1>';
    include __DIR__ . '/_footer.php';
    return;
}

$recipes = get_posts( [
    'post_type'      => App::POST_TYPE,
    'post_status'    => 'publish',
    'posts_per_page' => 100,
    'orderby'        => 'title',
    'order'          => 'ASC',
    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- ingredient page intrinsically requires a taxonomy filter.
    'tax_query'      => [
        [ 'taxonomy' => App::TAX_INGREDIENT, 'field' => 'term_id', 'terms' => $term->term_id ],
    ],
] );

$page_title = ucfirst( $term->name );
include __DIR__ . '/_header.php';
?>
<?php cookbook_page_head( ucfirst( $term->name ), [
    'current_section' => 'ingredients',
    'subtitle'        => sprintf(
        /* translators: %d: number of recipes using this ingredient */
        _n( '%d recipe uses this ingredient.', '%d recipes use this ingredient.', count( $recipes ), 'cook-app' ),
        count( $recipes )
    ),
] ); ?>

<?php if ( ! $recipes ) : ?>
    <div class="notice"><?php esc_html_e( 'No recipes use this ingredient yet.', 'cook-app' ); ?></div>
<?php else : ?>
    <div class="grid">
    <?php foreach ( $recipes as $r ) : ?>
        <a class="recipe-card" href="<?php echo esc_url( home_url( '/cook-app/recipe/' . $r->ID ) ); ?>" style="<?php echo has_post_thumbnail( $r->ID ) ? 'display:flex;gap:0.9rem;align-items:flex-start' : ''; ?>">
            <?php if ( has_post_thumbnail( $r->ID ) ) : ?>
                <?php echo get_the_post_thumbnail( $r->ID, 'thumbnail', [
                    'style' => 'width:80px;height:80px;object-fit:cover;border-radius:6px;flex-shrink:0',
                    'alt'   => '',
                ] ); ?>
                <div style="flex:1;min-width:0">
            <?php endif; ?>
            <h3><?php echo esc_html( get_the_title( $r ) ); ?></h3>
            <?php if ( $r->post_excerpt ) : ?>
                <p style="margin:0.4rem 0 0;color:var(--muted)"><?php echo esc_html( $r->post_excerpt ); ?></p>
            <?php endif; ?>
            <?php if ( has_post_thumbnail( $r->ID ) ) : ?>
                </div>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
