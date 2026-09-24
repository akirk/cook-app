<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use CookApp\App;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idempotent read-only search.
$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idempotent read-only display preference.
$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'compact';
if ( ! in_array( $view, [ 'images', 'compact', 'recent' ], true ) ) {
    $view = 'compact';
}

// If the user pasted a URL into the search box, redirect to the import flow.
if ( $search !== '' && preg_match( '~^https?://~i', $search ) && filter_var( $search, FILTER_VALIDATE_URL ) ) {
    $existing_recipe = App::find_recipe_by_source_url( $search );
    if ( $existing_recipe ) {
        wp_safe_redirect( home_url( '/cook-app/recipe/' . $existing_recipe->ID ) );
        exit;
    }

    wp_safe_redirect( add_query_arg( [
        'source_url' => $search,
        'autoimport' => 1,
    ], home_url( '/cook-app/import' ) ) );
    exit;
}

$is_searching = $search !== '';

$recipes = get_posts( [
    'post_type'      => App::POST_TYPE,
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
] );

if ( $is_searching ) {
    $needle = mb_strtolower( $search );
    $recipes = array_values( array_filter( $recipes, function( $recipe ) use ( $needle ) {
        $haystack = implode( "\n", [
            get_the_title( $recipe ),
            $recipe->post_content,
            $recipe->post_excerpt,
            (string) get_post_meta( $recipe->ID, App::META_NOTES, true ),
        ] );
        return mb_strpos( mb_strtolower( wp_strip_all_tags( $haystack ) ), $needle ) !== false;
    } ) );
}
$image_recipes = array_values( array_filter( $recipes, function( $recipe ) {
    return has_post_thumbnail( $recipe->ID );
} ) );

$last_cooked_by_recipe = [];
if ( $view === 'recent' && $recipes ) {
    $recipe_ids = array_fill_keys( array_map( 'intval', wp_list_pluck( $recipes, 'ID' ) ), true );
    foreach ( App::get_user_cooked_entries() as $entry ) {
        $recipe_id = (int) get_post_meta( $entry->ID, App::META_COOKED_RECIPE_ID, true );
        if ( ! $recipe_id || ! isset( $recipe_ids[ $recipe_id ] ) || isset( $last_cooked_by_recipe[ $recipe_id ] ) ) {
            continue;
        }

        $last_cooked_by_recipe[ $recipe_id ] = (string) get_post_meta( $entry->ID, App::META_COOKED_DATE, true );
    }
}

$recent_recipes = [];
if ( $view === 'recent' ) {
    $recent_recipes = $recipes;
    usort( $recent_recipes, function( $a, $b ) use ( $last_cooked_by_recipe ) {
        $a_time = ! empty( $last_cooked_by_recipe[ $a->ID ] ) ? strtotime( $last_cooked_by_recipe[ $a->ID ] ) : strtotime( $a->post_date_gmt ?: $a->post_date );
        $b_time = ! empty( $last_cooked_by_recipe[ $b->ID ] ) ? strtotime( $last_cooked_by_recipe[ $b->ID ] ) : strtotime( $b->post_date_gmt ?: $b->post_date );

        if ( $a_time === $b_time ) {
            return strcasecmp( get_the_title( $a ), get_the_title( $b ) );
        }

        return $b_time <=> $a_time;
    } );
}

$categories = get_terms( [
    'taxonomy'   => App::TAX_CATEGORY,
    'hide_empty' => true,
] );

$todays_plan = [];
$today_date = wp_date( 'Y-m-d' );
$today_label = wp_date( get_option( 'date_format' ) );
$current_week_start = App::normalize_week_start( $today_date );
$current_plan_id = App::get_user_week_plan_id( $current_week_start, false );
if ( $current_plan_id ) {
    $current_week_meals = App::get_week_meals( $current_plan_id );

    $todays_meals = isset( $current_week_meals[ $today_date ] ) && is_array( $current_week_meals[ $today_date ] )
        ? $current_week_meals[ $today_date ]
        : [];
    foreach ( App::meal_slots() as $slot => $slot_label ) {
        $recipe_id = isset( $todays_meals[ $slot ] ) ? absint( $todays_meals[ $slot ] ) : 0;
        $recipe = $recipe_id ? get_post( $recipe_id ) : null;
        if ( $recipe && $recipe->post_type === App::POST_TYPE ) {
            $todays_plan[] = [
                'slot_label' => $slot_label,
                'recipe'     => $recipe,
            ];
        }
    }
}

$recipes_by_letter = [];
foreach ( $recipes as $recipe ) {
    $letter = mb_strtoupper( mb_substr( trim( get_the_title( $recipe ) ), 0, 1 ) );
    if ( $letter === '' || ! preg_match( '/[[:alpha:]]/u', $letter ) ) {
        $letter = '#';
    }
    if ( ! isset( $recipes_by_letter[ $letter ] ) ) {
        $recipes_by_letter[ $letter ] = [];
    }
    $recipes_by_letter[ $letter ][] = $recipe;
}
uksort( $recipes_by_letter, function( $a, $b ) {
    if ( $a === '#' ) return 1;
    if ( $b === '#' ) return -1;
    return strcasecmp( $a, $b );
} );

$view_query_args = [];
if ( $search !== '' ) {
    $view_query_args['s'] = $search;
}
$images_view_url = add_query_arg( array_merge( $view_query_args, [ 'view' => 'images' ] ), home_url( '/cook-app/' ) );
$compact_view_url = add_query_arg( array_merge( $view_query_args, [ 'view' => 'compact' ] ), home_url( '/cook-app/' ) );
$recent_view_url = add_query_arg( array_merge( $view_query_args, [ 'view' => 'recent' ] ), home_url( '/cook-app/' ) );
$new_recipe_url = $search !== ''
    ? add_query_arg( 'title', $search, home_url( '/cook-app/new' ) )
    : home_url( '/cook-app/new' );

$page_title = __( 'Cook App', 'cook-app' );
include __DIR__ . '/_header.php';
?>

<?php cookbook_page_head( __( 'Cook App', 'cook-app' ), [ 'current_section' => 'recipes' ] ); ?>

<form method="get" action="" class="home-search">
    <input id="cookbook-search" type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search recipes or paste a URL to import…', 'cook-app' ); ?>">
    <input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
    <button class="btn" type="submit"><?php esc_html_e( 'Search', 'cook-app' ); ?></button>
</form>
<?php if ( ! $is_searching ) : ?>
    <?php if ( $todays_plan ) : ?>
        <section class="home-today-plan">
            <div class="home-today-head">
                <div>
                    <h2><?php esc_html_e( "Today's plan", 'cook-app' ); ?></h2>
                    <p class="subtitle"><?php echo esc_html( $today_label ); ?></p>
                </div>
                <a class="badge" href="<?php echo esc_url( home_url( '/cook-app/planner' ) ); ?>"><?php esc_html_e( 'Open week planner', 'cook-app' ); ?></a>
            </div>
            <div class="planned-strip">
                <?php foreach ( $todays_plan as $entry ) :
                    $recipe = $entry['recipe'];
                    ?>
                    <a class="planned-card" href="<?php echo esc_url( home_url( '/cook-app/recipe/' . $recipe->ID ) ); ?>">
                        <?php if ( has_post_thumbnail( $recipe->ID ) ) : ?>
                            <?php echo get_the_post_thumbnail( $recipe->ID, 'thumbnail', [ 'alt' => '' ] ); ?>
                        <?php else : ?>
                            <span class="planned-thumb"><?php echo esc_html( mb_strtoupper( mb_substr( get_the_title( $recipe ), 0, 1 ) ) ); ?></span>
                        <?php endif; ?>
                        <span>
                            <strong><?php echo esc_html( get_the_title( $recipe ) ); ?></strong>
                            <span><?php echo esc_html( $entry['slot_label'] ); ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php if ( ! is_wp_error( $categories ) && $categories ) : ?>
    <div class="toolbar">
        <strong><?php esc_html_e( 'Categories:', 'cook-app' ); ?></strong>
        <?php foreach ( $categories as $cat ) : ?>
            <a class="badge" href="<?php echo esc_url( home_url( '/cook-app/category/' . $cat->slug ) ); ?>">
                <?php echo esc_html( $cat->name ); ?> (<?php echo (int) $cat->count; ?>)
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ( $is_searching ) : ?>
    <h2 style="margin-top:1.25rem"><?php esc_html_e( 'Search results', 'cook-app' ); ?></h2>
<?php endif; ?>

<div class="recipe-index-row">
    <?php if ( $view === 'compact' && $recipes_by_letter ) : ?>
        <nav class="recipe-index" aria-label="<?php esc_attr_e( 'Recipe index', 'cook-app' ); ?>">
            <?php foreach ( range( 'A', 'Z' ) as $letter ) : ?>
                <?php $letter_id = sanitize_title( $letter ); ?>
                <?php if ( isset( $recipes_by_letter[ $letter ] ) ) : ?>
                    <a class="badge" href="#recipes-<?php echo esc_attr( $letter_id ); ?>"><?php echo esc_html( $letter ); ?></a>
                <?php else : ?>
                    <span class="badge muted" aria-disabled="true"><?php echo esc_html( $letter ); ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ( isset( $recipes_by_letter['#'] ) ) : ?>
                <a class="badge" href="#recipes-other">#</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
    <nav class="recipe-view-switch" aria-label="<?php esc_attr_e( 'Recipe view', 'cook-app' ); ?>">
        <a class="<?php echo $view === 'images' ? 'is-active' : ''; ?>" href="<?php echo esc_url( $images_view_url ); ?>"<?php echo $view === 'images' ? ' aria-current="true"' : ''; ?>>
            <svg class="recipe-view-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <rect x="3" y="5" width="18" height="14" rx="2"></rect>
                <circle cx="8.5" cy="10" r="1.4"></circle>
                <path d="M21 15l-4.5-4.5L10 17l-2.5-2.5L3 19"></path>
            </svg>
            <?php esc_html_e( 'Photos', 'cook-app' ); ?>
        </a>
        <a class="<?php echo $view === 'compact' ? 'is-active' : ''; ?>" href="<?php echo esc_url( $compact_view_url ); ?>"<?php echo $view === 'compact' ? ' aria-current="true"' : ''; ?>>
            <svg class="recipe-view-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M8 6h13"></path>
                <path d="M8 12h13"></path>
                <path d="M8 18h13"></path>
                <path d="M3 6h.01"></path>
                <path d="M3 12h.01"></path>
                <path d="M3 18h.01"></path>
            </svg>
            <?php esc_html_e( 'Compact', 'cook-app' ); ?>
        </a>
        <a class="<?php echo $view === 'recent' ? 'is-active' : ''; ?>" href="<?php echo esc_url( $recent_view_url ); ?>"<?php echo $view === 'recent' ? ' aria-current="true"' : ''; ?>>
            <svg class="recipe-view-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M12 8v5l3 2"></path>
                <path d="M3.05 11a9 9 0 1 1 .5 4"></path>
                <path d="M3 5v6h6"></path>
            </svg>
            <?php esc_html_e( 'Recent', 'cook-app' ); ?>
        </a>
    </nav>
    <a class="btn secondary" href="<?php echo esc_url( home_url( '/cook-app/new' ) ); ?>"><?php esc_html_e( 'New recipe', 'cook-app' ); ?></a>
</div>

<?php if ( ! $recipes ) : ?>
    <?php if ( $is_searching ) : ?>
        <div class="notice">
            <?php
            printf(
                /* translators: 1: search text, 2: link to create a new recipe */
                esc_html__( 'No recipes match "%1$s". %2$s', 'cook-app' ),
                esc_html( $search ),
                '<a href="' . esc_url( $new_recipe_url ) . '">' . esc_html__( 'Create a new recipe with that title.', 'cook-app' ) . '</a>'
            );
            ?>
        </div>
    <?php else : ?>
        <div class="notice">
            <?php
            printf(
                /* translators: 1: link to /cook-app/new, 2: link to /cook-app/import */
                esc_html__( 'No recipes yet. %1$s or %2$s.', 'cook-app' ),
                '<a href="' . esc_url( home_url( '/cook-app/new' ) ) . '">' . esc_html__( 'Create one', 'cook-app' ) . '</a>',
                '<a href="' . esc_url( home_url( '/cook-app/import' ) ) . '">' . esc_html__( 'import from a URL', 'cook-app' ) . '</a>'
            );
            ?>
        </div>
    <?php endif; ?>
    <?php elseif ( $view === 'images' ) : ?>
        <?php if ( ! $image_recipes ) : ?>
            <div class="notice">
                <?php
                echo esc_html(
                    $is_searching
                        ? __( 'No recipes with photos match your search.', 'cook-app' )
                        : __( 'No recipes with photos yet.', 'cook-app' )
                );
                ?>
            </div>
        <?php else : ?>
            <div class="recipe-photo-grid">
                <?php foreach ( $image_recipes as $r ) : ?>
                    <a class="recipe-photo-card" href="<?php echo esc_url( home_url( '/cook-app/recipe/' . $r->ID ) ); ?>">
                        <?php echo get_the_post_thumbnail( $r->ID, 'medium_large', [ 'alt' => '' ] ); ?>
                        <span class="recipe-photo-title"><?php echo esc_html( get_the_title( $r ) ); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php elseif ( $view === 'recent' ) : ?>
        <ul class="recipe-recent-list">
            <?php foreach ( $recent_recipes as $r ) : ?>
                <?php
                $last_cooked = isset( $last_cooked_by_recipe[ $r->ID ] ) ? $last_cooked_by_recipe[ $r->ID ] : '';
                $date_label  = $last_cooked
                    ? sprintf(
                        /* translators: %s: cooked date */
                        __( 'Cooked %s', 'cook-app' ),
                        App::format_cooked_date( $last_cooked )
                    )
                    : sprintf(
                        /* translators: %s: recipe published date */
                        __( 'Added %s', 'cook-app' ),
                        get_the_date( '', $r )
                    );
                $datetime    = $last_cooked ? $last_cooked : get_the_date( 'Y-m-d', $r );
                ?>
                <li>
                    <a href="<?php echo esc_url( home_url( '/cook-app/recipe/' . $r->ID ) ); ?>">
                        <span class="recipe-title"><?php echo esc_html( get_the_title( $r ) ); ?></span>
                        <time datetime="<?php echo esc_attr( $datetime ); ?>"><?php echo esc_html( $date_label ); ?></time>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else :
        foreach ( $recipes_by_letter as $letter => $group ) : ?>
            <?php $letter_id = $letter === '#' ? 'other' : sanitize_title( $letter ); ?>
            <section class="recipe-alpha-section" id="recipes-<?php echo esc_attr( $letter_id ); ?>">
                <h3 class="recipe-alpha-heading"><?php echo esc_html( $letter ); ?></h3>
                <ul class="recipe-alpha-list">
                    <?php foreach ( $group as $r ) : ?>
                        <li>
                            <a href="<?php echo esc_url( home_url( '/cook-app/recipe/' . $r->ID ) ); ?>">
                                <span class="recipe-title">
                                    <?php echo esc_html( get_the_title( $r ) ); ?>
                                </span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

	<?php if ( ! $is_searching ) : ?>
	    <section
	        class="home-ingredients"
	        id="home-ingredients"
	        data-home-ingredients-endpoint="<?php echo esc_url( rest_url( 'cookbook/v1/home-ingredients' ) ); ?>"
	        data-home-ingredients-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
	        hidden
	    >
	        <h2 style="margin-top:1.75rem"><?php esc_html_e( 'Browse by ingredient', 'cook-app' ); ?></h2>
	        <div class="ingredient-cloud" data-home-ingredient-cloud></div>
	    </section>
	<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
