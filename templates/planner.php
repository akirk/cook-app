<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use CookApp\App;

if ( ! is_user_logged_in() ) {
    wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only query params and flash flags.
$week_param = isset( $_GET['week'] ) ? sanitize_text_field( wp_unslash( $_GET['week'] ) ) : '';
$pending_recipe_id = isset( $_GET['recipe_id'] ) ? absint( $_GET['recipe_id'] ) : 0;
$saved = isset( $_GET['saved'] );
$copy_source_param = isset( $_GET['copy-form'] ) ? sanitize_text_field( wp_unslash( $_GET['copy-form'] ) ) : '';
$shopping_status = isset( $_GET['shopping'] ) ? sanitize_text_field( wp_unslash( $_GET['shopping'] ) ) : '';
$shopping_items = isset( $_GET['items'] ) ? absint( $_GET['items'] ) : 0;
$shopping_household = isset( $_GET['household'] ) ? absint( $_GET['household'] ) : 0;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$week_start = App::normalize_week_start( $week_param );
$days       = App::week_days( $week_start );
$meal_slots = App::meal_slots();
$plan_id    = App::get_user_week_plan_id( $week_start, false );
$meals      = App::get_week_meals( $plan_id );

$recipes = get_posts( [
    'post_type'      => App::POST_TYPE,
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
] );
$recipe_title_counts = [];
foreach ( $recipes as $recipe ) {
    $recipe_title = get_the_title( $recipe );
    if ( ! isset( $recipe_title_counts[ $recipe_title ] ) ) {
        $recipe_title_counts[ $recipe_title ] = 0;
    }
    $recipe_title_counts[ $recipe_title ]++;
}
$recipe_option_values = [];
$recipe_lookup = [];
foreach ( $recipes as $recipe ) {
    $recipe_title = get_the_title( $recipe );
    $recipe_value = $recipe_title_counts[ $recipe_title ] > 1
        ? sprintf(
            /* translators: 1: recipe title, 2: recipe ID */
            __( '%1$s (#%2$d)', 'cook-app' ),
            $recipe_title,
            (int) $recipe->ID
        )
        : $recipe_title;
    $recipe_option_values[ $recipe->ID ] = $recipe_value;
    $recipe_lookup[] = [
        'id'    => (int) $recipe->ID,
        'value' => $recipe_value,
    ];
}

$pending_recipe = $pending_recipe_id ? get_post( $pending_recipe_id ) : null;
if ( ! $pending_recipe || $pending_recipe->post_type !== App::POST_TYPE ) {
    $pending_recipe_id = 0;
    $pending_recipe = null;
}

$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
try {
    $start = new DateTimeImmutable( $week_start, $timezone );
} catch ( Exception $e ) {
    $start = new DateTimeImmutable( App::normalize_week_start(), $timezone );
}
$prev_week = $start->modify( '-7 days' )->format( 'Y-m-d' );
$next_week = $start->modify( '+7 days' )->format( 'Y-m-d' );
$current_week_start = App::normalize_week_start();
$is_current_week = $week_start === $current_week_start;

$copy_source_slots = [];
$copy_inserted_slots = [];
$copy_source_week_start = $copy_source_param !== '' ? App::normalize_week_start( $copy_source_param ) : '';
if ( $copy_source_week_start && $copy_source_week_start !== $week_start ) {
    $source_plan_id = App::get_user_week_plan_id( $copy_source_week_start, false );
    $source_meals = App::get_week_meals( $source_plan_id );
    if ( $source_meals ) {
        $source_dates = array_keys( App::week_days( $copy_source_week_start ) );
        $target_dates = array_keys( $days );
        foreach ( $target_dates as $day_index => $target_date ) {
            $source_date = $source_dates[ $day_index ] ?? '';
            if ( $source_date === '' ) {
                continue;
            }
            foreach ( array_keys( $meal_slots ) as $slot ) {
                $recipe_id = isset( $source_meals[ $source_date ][ $slot ] ) ? absint( $source_meals[ $source_date ][ $slot ] ) : 0;
                if ( $recipe_id && isset( $recipe_option_values[ $recipe_id ] ) ) {
                    $field_id = 'meal-' . $target_date . '-' . $slot;
                    $copy_source_slots[ $field_id ] = $recipe_id;
                    $target_recipe_id = isset( $meals[ $target_date ][ $slot ] ) ? absint( $meals[ $target_date ][ $slot ] ) : 0;
                    if ( ! $target_recipe_id ) {
                        $copy_inserted_slots[ $field_id ] = true;
                    }
                }
            }
        }
    }
}

$planned = [];
foreach ( $days as $date => $day ) {
    foreach ( $meal_slots as $slot => $slot_label ) {
        $recipe_id = isset( $meals[ $date ][ $slot ] ) ? absint( $meals[ $date ][ $slot ] ) : 0;
        $recipe = $recipe_id ? get_post( $recipe_id ) : null;
        if ( $recipe && $recipe->post_type === App::POST_TYPE ) {
            $planned[] = [
                'date'       => $date,
                'day'        => $day,
                'slot_label' => $slot_label,
                'recipe'     => $recipe,
            ];
        }
    }
}

$page_title = __( 'Week planner', 'cook-app' );
include __DIR__ . '/_header.php';
?>
<?php
ob_start();
?>
<?php if ( ! $is_current_week && $plan_id ) : ?>
    <a class="btn secondary" href="<?php echo esc_url( add_query_arg( [
        'week'      => $current_week_start,
        'copy-form' => $week_start,
    ], home_url( '/cook-app/planner' ) ) ); ?>"><?php esc_html_e( 'Copy to current week', 'cook-app' ); ?></a>
<?php endif; ?>
<?php if ( $is_current_week && $plan_id ) : ?>
    <a class="btn secondary" href="<?php echo esc_url( add_query_arg( [
        'week'      => $next_week,
        'copy-form' => $week_start,
    ], home_url( '/cook-app/planner' ) ) ); ?>"><?php esc_html_e( 'Copy to next week', 'cook-app' ); ?></a>
<?php endif; ?>
<button class="btn fresh" type="submit" form="planner-form"><?php esc_html_e( 'Save week', 'cook-app' ); ?></button>
<?php
$planner_actions = ob_get_clean();
cookbook_page_head( __( 'Week planner', 'cook-app' ), [
    'current_section' => 'planner',
    'subtitle'        => sprintf(
        /* translators: %s: formatted date */
        __( 'Planning week of %s.', 'cook-app' ),
        wp_date( get_option( 'date_format' ), $start->getTimestamp() )
    ),
    'actions_html'    => $planner_actions,
] );
?>

<?php if ( $saved ) : ?>
    <div class="notice success"><?php esc_html_e( 'Week planner saved.', 'cook-app' ); ?></div>
<?php endif; ?>
<?php if ( $copy_source_slots ) : ?>
    <div class="notice"><?php esc_html_e( 'Review the copied week, then save the week to keep it.', 'cook-app' ); ?></div>
<?php endif; ?>
<?php if ( $shopping_status === 'added' ) : ?>
    <div class="notice success">
        <?php
        $shopping_message = sprintf(
            /* translators: %d: shopping-list items */
            _n( '%d planned ingredient added to your shopping list.', '%d planned ingredients added to your shopping list.', $shopping_items, 'cook-app' ),
            $shopping_items
        );
        if ( $shopping_household ) {
            $shopping_message .= ' ' . sprintf(
                /* translators: %d: household ingredients */
                _n( '%d household ingredient listed as at home.', '%d household ingredients listed as at home.', $shopping_household, 'cook-app' ),
                $shopping_household
            );
        }
        echo esc_html( $shopping_message );
        ?>
    </div>
<?php endif; ?>
<?php if ( $pending_recipe ) : ?>
    <div class="notice">
        <?php
        echo esc_html( sprintf(
            /* translators: %s: recipe title */
            __( 'Select a slot for %s, then save the week.', 'cook-app' ),
            get_the_title( $pending_recipe )
        ) );
        ?>
    </div>
<?php endif; ?>

<nav class="planner-nav" aria-label="<?php esc_attr_e( 'Planner week navigation', 'cook-app' ); ?>">
    <a class="btn secondary" href="<?php echo esc_url( add_query_arg( 'week', $prev_week, home_url( '/cook-app/planner' ) ) ); ?>"><?php esc_html_e( 'Previous week', 'cook-app' ); ?></a>
    <a class="badge" href="<?php echo esc_url( home_url( '/cook-app/planner' ) ); ?>"><?php esc_html_e( 'This week', 'cook-app' ); ?></a>
    <a class="btn secondary" href="<?php echo esc_url( add_query_arg( 'week', $next_week, home_url( '/cook-app/planner' ) ) ); ?>"><?php esc_html_e( 'Next week', 'cook-app' ); ?></a>
</nav>

<form
    method="post"
    action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
    id="planner-form"
    data-pending-recipe="<?php echo (int) $pending_recipe_id; ?>"
    data-recipes="<?php echo esc_attr( wp_json_encode( $recipe_lookup, JSON_HEX_TAG | JSON_HEX_AMP ) ); ?>"
    data-copy-source-slots="<?php echo esc_attr( wp_json_encode( $copy_source_slots, JSON_HEX_TAG | JSON_HEX_AMP ) ); ?>"
    data-copy-inserted-slots="<?php echo esc_attr( wp_json_encode( $copy_inserted_slots, JSON_HEX_TAG | JSON_HEX_AMP ) ); ?>"
    data-place-label="<?php esc_attr_e( 'Place selected recipe here', 'cook-app' ); ?>"
    data-remove-label="<?php esc_attr_e( 'Remove from stash', 'cook-app' ); ?>"
>
    <?php wp_nonce_field( 'cookbook_save_planner' ); ?>
    <input type="hidden" name="action" value="cookbook_save_planner">
    <input type="hidden" name="plan_id" value="<?php echo (int) $plan_id; ?>">
    <input type="hidden" name="week_start" value="<?php echo esc_attr( $week_start ); ?>">

    <div class="planner-grid">
        <?php foreach ( $days as $date => $day ) : ?>
            <section class="planner-day">
                <h3>
                    <?php echo esc_html( $day['short'] ); ?>
                    <span><?php echo esc_html( $day['label'] ); ?></span>
                </h3>
                <?php foreach ( $meal_slots as $slot => $slot_label ) :
                    $selected = isset( $meals[ $date ][ $slot ] ) ? absint( $meals[ $date ][ $slot ] ) : 0;
                    $selected_value = $selected && isset( $recipe_option_values[ $selected ] ) ? $recipe_option_values[ $selected ] : '';
                    $field_id = 'meal-' . $date . '-' . $slot;
                    $hidden_id = 'meal-id-' . $date . '-' . $slot;
                    $copied_recipe_id = isset( $copy_source_slots[ $field_id ] ) ? absint( $copy_source_slots[ $field_id ] ) : 0;
                    $has_copied_change = $copied_recipe_id && $copied_recipe_id !== $selected;
                    $is_copy_inserted = isset( $copy_inserted_slots[ $field_id ] );
                    ?>
                    <div class="planner-slot">
                        <div class="planner-slot-label">
                            <label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $slot_label ); ?></label>
                            <button
                                class="planner-action"
                                type="button"
                                data-planner-here
                                data-target-id="<?php echo esc_attr( $field_id ); ?>"
                                data-day-label="<?php echo esc_attr( $day['short'] ); ?>"
                                data-slot-label="<?php echo esc_attr( $slot_label ); ?>"
                                hidden
                            ><?php esc_html_e( 'Here', 'cook-app' ); ?></button>
                            <button
                                class="planner-action"
                                type="button"
                                data-planner-lift
                                data-target-id="<?php echo esc_attr( $field_id ); ?>"
                                aria-label="<?php echo esc_attr( sprintf(
                                    /* translators: 1: day label, 2: meal slot label */
                                    __( 'Lift recipe from %1$s %2$s', 'cook-app' ),
                                    $day['short'],
                                    $slot_label
                                ) ); ?>"
                                hidden
                            ><?php esc_html_e( 'Lift', 'cook-app' ); ?></button>
                        </div>
                        <input
                            id="<?php echo esc_attr( $field_id ); ?>"
                            type="text"
                            name="meal_labels[<?php echo esc_attr( $date ); ?>][<?php echo esc_attr( $slot ); ?>]"
                            value="<?php echo esc_attr( $selected_value ); ?>"
                            placeholder="<?php esc_attr_e( 'Search recipes', 'cook-app' ); ?>"
                            data-meal-input
                            data-hidden-id="<?php echo esc_attr( $hidden_id ); ?>"
                            data-slot="<?php echo esc_attr( $slot ); ?>"
                            <?php if ( $is_copy_inserted ) : ?>
                                data-copy-highlight
                            <?php endif; ?>
                            autocomplete="off"
                        >
                        <input id="<?php echo esc_attr( $hidden_id ); ?>" type="hidden" name="meals[<?php echo esc_attr( $date ); ?>][<?php echo esc_attr( $slot ); ?>]" value="<?php echo (int) $selected; ?>">
                        <?php if ( $has_copied_change && $selected && $selected_value ) : ?>
                            <div
                                class="planner-previous"
                                data-copy-previous
                                data-recipe-id="<?php echo (int) $selected; ?>"
                                data-recipe-value="<?php echo esc_attr( $selected_value ); ?>"
                            >
                                <span><?php echo esc_html( $selected_value ); ?></span>
                                <button class="planner-action" type="button" data-copy-previous-put-back data-target-id="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Put back', 'cook-app' ); ?></button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
        <section class="planner-day planner-stash" data-planner-stash hidden>
            <h3>
                <?php esc_html_e( 'Stash', 'cook-app' ); ?>
                <button class="planner-action" type="button" data-planner-clear-stash><?php esc_html_e( 'Clear', 'cook-app' ); ?></button>
            </h3>
            <div class="planner-stash-items" data-planner-stash-items></div>
        </section>
    </div>

    <div class="toolbar">
        <button class="btn fresh" type="submit"><?php esc_html_e( 'Save week', 'cook-app' ); ?></button>
    </div>
</form>

<?php if ( $planned ) : ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="toolbar">
        <?php wp_nonce_field( 'cookbook_add_planner_to_shopping_list' ); ?>
        <input type="hidden" name="action" value="cookbook_add_planner_to_shopping_list">
        <input type="hidden" name="week_start" value="<?php echo esc_attr( $week_start ); ?>">
        <button class="btn" type="submit"><?php esc_html_e( 'Add planned ingredients to shopping list', 'cook-app' ); ?></button>
        <a class="btn secondary" href="<?php echo esc_url( home_url( '/cook-app/shopping-list' ) ); ?>"><?php esc_html_e( 'Open shopping list', 'cook-app' ); ?></a>
    </form>
<?php endif; ?>

<?php if ( $planned ) : ?>
    <h2><?php esc_html_e( 'Planned recipes', 'cook-app' ); ?></h2>
    <div class="planned-strip">
        <?php foreach ( $planned as $entry ) :
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
                    <span><?php echo esc_html( $entry['day']['short'] . ' - ' . $entry['slot_label'] ); ?></span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
wp_app_enqueue_script(
    'cook-app-planner',
    COOK_APP_PLUGIN_URL . 'assets/cook-app-planner.js',
    array(),
    filemtime( dirname( __DIR__ ) . '/assets/cook-app-planner.js' ),
    true,
    'cook-app'
);
?>



<?php include __DIR__ . '/_footer.php'; ?>
