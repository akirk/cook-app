<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use Cookbook\App;

if ( ! current_user_can( 'edit_posts' ) ) {
    wp_die( esc_html__( 'Not allowed.', 'cookbook' ), 403 );
}

$id     = 0;
$post   = null;
$is_new = true;
$variation_source_id = 0;
$variation_parent_id = 0;
$title_override = '';
$cancel_url = home_url( '/cookbook/' );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill source.
$requested_variation_source = isset( $_GET['variation_of'] ) ? absint( $_GET['variation_of'] ) : 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill source.
$requested_title = isset( $_GET['title'] ) ? sanitize_text_field( wp_unslash( $_GET['title'] ) ) : '';
if ( $requested_variation_source ) {
    $source = get_post( $requested_variation_source );
    if ( $source && $source->post_type === App::POST_TYPE ) {
        $post = $source;
        $variation_source_id = (int) $source->ID;
        $variation_parent_id = (int) $source->ID;
        $title_override = $requested_title !== '' ? $requested_title : sprintf(
            /* translators: %s: source recipe title */
            __( '%s variation', 'cookbook' ),
            get_the_title( $source )
        );
        $cancel_url = home_url( '/cookbook/recipe/' . $source->ID );
    }
} elseif ( $requested_title !== '' ) {
    $title_override = $requested_title;
}

$page_title = $variation_source_id ? __( 'New variation', 'cookbook' ) : __( 'New recipe', 'cookbook' );
$variation_parent_options = get_posts( [
    'post_type'      => App::POST_TYPE,
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
] );
include __DIR__ . '/_header.php';
?>
<h1><?php echo $variation_source_id ? esc_html__( 'New variation', 'cookbook' ) : esc_html__( 'New recipe', 'cookbook' ); ?></h1>
<?php if ( $variation_source_id ) : ?>
    <p class="subtitle">
        <?php
        echo wp_kses_post( sprintf(
            /* translators: %s: linked source recipe title */
            __( 'Prefilled from %s.', 'cookbook' ),
            '<a href="' . esc_url( $cancel_url ) . '">' . esc_html( get_the_title( $post ) ) . '</a>'
        ) );
        ?>
    </p>
<?php endif; ?>
<?php if ( ! $variation_source_id ) : ?>
    <p class="subtitle">
        <?php
        if ( $variation_parent_options ) {
            echo wp_kses_post( sprintf(
                /* translators: %s: link to recipe import */
                __( 'Enter a recipe below, choose and load an existing recipe as a variation, or <a href="%s">import a URL or pasted text</a>.', 'cookbook' ),
                esc_url( home_url( '/cookbook/import' ) )
            ) );
        } else {
            echo wp_kses_post( sprintf(
                /* translators: %s: link to recipe import */
                __( 'Enter a recipe below, or <a href="%s">import a URL or pasted text</a>.', 'cookbook' ),
                esc_url( home_url( '/cookbook/import' ) )
            ) );
        }
        ?>
    </p>
<?php endif; ?>
<?php include __DIR__ . '/_form.php'; ?>
<?php include __DIR__ . '/_footer.php'; ?>
