<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use Cookbook\App;
use Cookbook\Importer;
use Cookbook\Markdown;
use Cookbook\Units;

$id = (int) get_query_var( 'id' );
$post = $id ? get_post( $id ) : null;
if ( ! $post || $post->post_type !== App::POST_TYPE ) {
    status_header( 404 );
    $page_title = __( 'Recipe not found', 'cook-app' );
    include __DIR__ . '/_header.php';
    echo '<h1>' . esc_html__( 'Not found', 'cook-app' ) . '</h1><p>' . esc_html__( 'That recipe does not exist.', 'cook-app' ) . '</p>';
    include __DIR__ . '/_footer.php';
    return;
}
$page_title = get_the_title( $post );

$servings_default = max( 1, (int) get_post_meta( $id, App::META_SERVINGS, true ) ?: 4 );
$prep             = (int) get_post_meta( $id, App::META_PREP, true );
$cook             = (int) get_post_meta( $id, App::META_COOK, true );
$ingredients      = (array) get_post_meta( $id, App::META_INGREDIENTS, true );
$instructions     = (array) get_post_meta( $id, App::META_INSTRUCTIONS, true );
$recipe_parts     = App::get_recipe_parts( $id );
$source_url       = (string) get_post_meta( $id, App::META_SOURCE_URL, true );
$notes            = (string) get_post_meta( $id, App::META_NOTES, true );

$clean_instructions = [];
foreach ( $instructions as $step ) {
    $step = Importer::clean_step( (string) $step );
    if ( $step !== '' ) {
        $clean_instructions[] = $step;
    }
}

$ingredient_parts = [];
$clean_instruction_parts = [];
foreach ( $recipe_parts as $part_index => $part ) {
    if ( ! empty( $part['ingredients'] ) ) {
        $part['part_index'] = (int) $part_index;
        $ingredient_parts[] = $part;
    }

    $part_steps = [];
    foreach ( (array) ( $part['instructions'] ?? [] ) as $step ) {
        $step = Importer::clean_step( (string) $step );
        if ( $step !== '' ) {
            $part_steps[] = $step;
        }
    }
    if ( $part_steps ) {
        $clean_instruction_parts[] = [
            'title' => (string) ( $part['title'] ?? '' ),
            'instructions' => $part_steps,
            'part_index' => (int) $part_index,
        ];
    }
}
$cook_step_count = $clean_instruction_parts
    ? array_sum( array_map( static fn( $part ) => count( (array) $part['instructions'] ), $clean_instruction_parts ) )
    : count( $clean_instructions );
$has_clean_instructions = $cook_step_count > 0;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display preference, validated against an allow-list below.
$units_param = isset( $_GET['units'] ) ? sanitize_text_field( wp_unslash( $_GET['units'] ) ) : '';
$preference  = in_array( $units_param, [ 'metric', 'imperial' ], true )
    ? $units_param
    : App::get_user_unit_preference();

$cats     = wp_get_object_terms( $id, App::TAX_CATEGORY );
$cuisines = wp_get_object_terms( $id, App::TAX_CUISINE );
$tags     = wp_get_object_terms( $id, App::TAX_TAG );
$variation_parent = App::get_recipe_variation_parent( $id );
$variation_root_id = App::get_recipe_variation_root_id( $id );
$variation_family = App::get_recipe_variation_family( $id );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash code.
$refetch_status = isset( $_GET['refetch'] ) ? sanitize_text_field( wp_unslash( $_GET['refetch'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash code.
$shopping_status = isset( $_GET['shopping'] ) ? sanitize_text_field( wp_unslash( $_GET['shopping'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash code.
$shopping_items = isset( $_GET['items'] ) ? absint( $_GET['items'] ) : 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash code.
$shopping_household = isset( $_GET['household'] ) ? absint( $_GET['household'] ) : 0;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash code.
$replaced = isset( $_GET['replaced'] );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash code.
$cooked_status = isset( $_GET['cooked'] ) ? sanitize_text_field( wp_unslash( $_GET['cooked'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash code.
$cooked_flash_date = isset( $_GET['cooked_date'] ) ? App::sanitize_cooked_date( sanitize_text_field( wp_unslash( $_GET['cooked_date'] ) ) ) : '';

$today_date      = wp_date( 'Y-m-d' );
$recipe_url      = home_url( '/cook-app/recipe/' . $id );
$cooked_entries  = App::get_recipe_cooked_entries( $id, -1 );
$cooked_count    = count( $cooked_entries );
$last_cooked_date = $cooked_entries
    ? (string) get_post_meta( $cooked_entries[0]->ID, App::META_COOKED_DATE, true )
    : '';
$recent_cooked_entries = array_slice( $cooked_entries, 0, 5 );

include __DIR__ . '/_header.php';
?>
<?php cookbook_page_head( get_the_title( $post ), [ 'current_section' => 'recipes' ] ); ?>

<?php if ( has_post_thumbnail( $id ) ) : ?>
    <?php echo get_the_post_thumbnail( $id, 'large', [
        'style' => 'max-width:100%;height:auto;border-radius:8px;margin:0.5rem 0 1rem',
        'alt'   => esc_attr( get_the_title( $post ) ),
    ] ); ?>
<?php endif; ?>

<div class="meta">
    <?php if ( $prep ) : ?>
        <span>
            <?php
            /* translators: %d: prep time in minutes */
            echo esc_html( sprintf( __( 'Prep: %d min', 'cook-app' ), $prep ) );
            ?>
        </span>
    <?php endif; ?>
    <?php if ( $cook ) : ?>
        <span>
            <?php
            /* translators: %d: cook time in minutes */
            echo esc_html( sprintf( __( 'Cook: %d min', 'cook-app' ), $cook ) );
            ?>
        </span>
    <?php endif; ?>
    <?php if ( $source_url ) : ?>
        <span>
            <?php esc_html_e( 'Source:', 'cook-app' ); ?>
            <a href="<?php echo esc_url( $source_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( wp_parse_url( $source_url, PHP_URL_HOST ) ?: $source_url ); ?></a>
        </span>
    <?php endif; ?>
    <?php if ( $variation_parent && count( $variation_family ) <= 1 ) : ?>
        <span>
            <?php esc_html_e( 'Variation of:', 'cook-app' ); ?>
            <a href="<?php echo esc_url( home_url( '/cook-app/recipe/' . $variation_parent->ID ) ); ?>"><?php echo esc_html( get_the_title( $variation_parent ) ); ?></a>
        </span>
    <?php endif; ?>
</div>

<?php if ( ( ! is_wp_error( $cats ) && $cats ) || ( ! is_wp_error( $cuisines ) && $cuisines ) || ( ! is_wp_error( $tags ) && $tags ) ) : ?>
<p style="margin-top:0.75rem">
    <?php foreach ( (array) $cats as $t ) : ?>
        <a class="badge" href="<?php echo esc_url( home_url( '/cook-app/category/' . $t->slug ) ); ?>"><?php echo esc_html( $t->name ); ?></a>
    <?php endforeach; ?>
    <?php foreach ( (array) $cuisines as $t ) : ?>
        <span class="badge"><?php echo esc_html( $t->name ); ?></span>
    <?php endforeach; ?>
    <?php foreach ( (array) $tags as $t ) : ?>
        <a class="badge" href="<?php echo esc_url( home_url( '/cook-app/tag/' . $t->slug ) ); ?>">#<?php echo esc_html( $t->name ); ?></a>
    <?php endforeach; ?>
</p>
<?php endif; ?>

<?php if ( count( $variation_family ) > 1 ) : ?>
    <section class="variation-panel" aria-labelledby="recipe-variations-title">
        <div class="variation-panel-title">
            <strong id="recipe-variations-title"><?php esc_html_e( 'Recipe variations', 'cook-app' ); ?></strong>
        </div>
        <ul class="variation-list">
            <?php foreach ( $variation_family as $variation_item ) :
                $variation_post = $variation_item['post'];
                $variation_depth = min( 4, max( 0, (int) $variation_item['depth'] ) );
                $variation_indent = number_format( $variation_depth * 1.1, 1, '.', '' );
                $is_current_variation = (int) $variation_post->ID === $id;
                ?>
                <li style="margin-left:<?php echo esc_attr( $variation_indent ); ?>rem">
                    <?php if ( $is_current_variation ) : ?>
                        <strong><?php echo esc_html( get_the_title( $variation_post ) ); ?></strong>
                    <?php else : ?>
                        <a href="<?php echo esc_url( home_url( '/cook-app/recipe/' . $variation_post->ID ) ); ?>"><?php echo esc_html( get_the_title( $variation_post ) ); ?></a>
                    <?php endif; ?>
                    <?php if ( (int) $variation_post->ID === $variation_root_id ) : ?>
                        <span class="badge"><?php esc_html_e( 'base', 'cook-app' ); ?></span>
                    <?php endif; ?>
                    <?php if ( $is_current_variation ) : ?>
                        <span class="badge"><?php esc_html_e( 'current', 'cook-app' ); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<div class="recipe-toolbar" role="group" aria-label="<?php esc_attr_e( 'Recipe controls', 'cook-app' ); ?>">
    <div class="recipe-toolbar-settings">
        <div class="portion-control">
            <label for="servings" style="margin:0"><?php esc_html_e( 'Servings:', 'cook-app' ); ?></label>
            <input id="servings" type="number" min="1" step="1" value="<?php echo (int) $servings_default; ?>" data-default="<?php echo (int) $servings_default; ?>">
        </div>
        <div class="unit-toggle" role="tablist" aria-label="<?php esc_attr_e( 'Unit system', 'cook-app' ); ?>">
            <button type="button" class="<?php echo $preference === 'metric' ? 'active' : ''; ?>" data-units="metric"><?php esc_html_e( 'Metric', 'cook-app' ); ?></button>
            <button type="button" class="<?php echo $preference === 'imperial' ? 'active' : ''; ?>" data-units="imperial"><?php esc_html_e( 'Imperial', 'cook-app' ); ?></button>
        </div>
    </div>

    <div class="recipe-cooked-status">
        <span><?php esc_html_e( 'Last cooked:', 'cook-app' ); ?></span>
        <?php if ( $last_cooked_date ) : ?>
            <a href="#cooked-history"><time datetime="<?php echo esc_attr( $last_cooked_date ); ?>"><?php echo esc_html( App::format_cooked_date( $last_cooked_date ) ); ?></time></a>
            <small>
                <?php
                echo esc_html(
                    '(' . sprintf(
                        /* translators: %d: number of times the recipe was cooked */
                        _n( '%d time', '%d times', $cooked_count, 'cook-app' ),
                        $cooked_count
                    ) . ')'
                );
                ?>
            </small>
        <?php else : ?>
            <strong><?php esc_html_e( 'Not yet', 'cook-app' ); ?></strong>
        <?php endif; ?>
    </div>

    <div class="recipe-primary-actions">
        <?php if ( $clean_instructions ) : ?>
            <button class="btn" type="button" id="cook-mode-open"><?php esc_html_e( 'Cook this', 'cook-app' ); ?></button>
        <?php endif; ?>
        <?php if ( $ingredients ) : ?>
            <form class="recipe-inline-action" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cookbook_add_to_shopping_list' ); ?>
                <input type="hidden" name="action" value="cookbook_add_to_shopping_list">
                <input type="hidden" name="recipe_id" value="<?php echo (int) $id; ?>">
                <input type="hidden" id="shopping-servings" name="servings" value="<?php echo (int) $servings_default; ?>">
                <button class="btn fresh" type="submit"><?php esc_html_e( 'Add to shopping list', 'cook-app' ); ?></button>
            </form>
        <?php endif; ?>

        <details class="recipe-action-menu">
            <summary class="btn secondary"><?php esc_html_e( 'More actions', 'cook-app' ); ?></summary>
            <div class="recipe-action-menu-panel">
                <?php if ( $ingredients ) : ?>
                    <a class="recipe-menu-action" href="<?php echo esc_url( add_query_arg( 'recipe_id', $id, home_url( '/cook-app/planner' ) ) ); ?>"><?php esc_html_e( 'Plan recipe', 'cook-app' ); ?></a>
                <?php endif; ?>

                <?php if ( $source_url ) : ?>
                    <form class="recipe-menu-action-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cookbook-confirm="<?php esc_attr_e( 'Re-fetch this recipe from its source URL? Ingredients, instructions, times and image will be replaced with the latest parsed data. Notes and tags are kept.', 'cook-app' ); ?>">
                        <?php wp_nonce_field( 'cookbook_refetch' ); ?>
                        <input type="hidden" name="action" value="cookbook_refetch">
                        <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                        <button class="recipe-menu-action" type="submit" title="<?php esc_attr_e( 'Re-import from source URL', 'cook-app' ); ?>"><?php esc_html_e( 'Refetch from source', 'cook-app' ); ?></button>
                    </form>
                <?php endif; ?>

                <a class="recipe-menu-action" href="<?php echo esc_url( home_url( '/cook-app/recipe/' . $id . '/edit' ) ); ?>"><?php esc_html_e( 'Edit recipe', 'cook-app' ); ?></a>
                <?php if ( current_user_can( 'edit_posts' ) ) : ?>
                    <a class="recipe-menu-action" href="<?php echo esc_url( add_query_arg( 'variation_of', $id, home_url( '/cook-app/new' ) ) ); ?>"><?php esc_html_e( 'Edit as variation', 'cook-app' ); ?></a>
                <?php endif; ?>
            </div>
        </details>
    </div>
</div>

<?php if ( $refetch_status === 'ok' ) : ?>
    <div class="notice success"><?php esc_html_e( 'Refetched from source.', 'cook-app' ); ?></div>
<?php elseif ( $refetch_status === 'parse_error' ) : ?>
    <div class="notice error"><?php esc_html_e( 'Could not re-parse the source URL — recipe left unchanged.', 'cook-app' ); ?></div>
<?php elseif ( $refetch_status === 'no_url' ) : ?>
    <div class="notice error"><?php esc_html_e( 'No source URL stored on this recipe.', 'cook-app' ); ?></div>
<?php endif; ?>
<?php if ( $shopping_status === 'added' ) : ?>
    <div class="notice success">
        <?php
        $shopping_message = sprintf(
            /* translators: %d: shopping-list items */
            _n( '%d ingredient added to your shopping list.', '%d ingredients added to your shopping list.', $shopping_items, 'cook-app' ),
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
<?php if ( $replaced ) : ?>
    <div class="notice success"><?php esc_html_e( 'Ingredient replaced.', 'cook-app' ); ?></div>
<?php endif; ?>
<?php if ( $cooked_status === 'logged' && $cooked_flash_date ) : ?>
    <div class="notice success">
        <?php
        echo esc_html( sprintf(
            /* translators: %s: cooked date */
            __( 'Saved that you cooked this on %s.', 'cook-app' ),
            App::format_cooked_date( $cooked_flash_date )
        ) );
        ?>
    </div>
<?php elseif ( $cooked_status === 'exists' && $cooked_flash_date ) : ?>
    <div class="notice">
        <?php
        echo esc_html( sprintf(
            /* translators: %s: cooked date */
            __( 'This recipe was already saved for %s.', 'cook-app' ),
            App::format_cooked_date( $cooked_flash_date )
        ) );
        ?>
    </div>
<?php elseif ( $cooked_status === 'updated' && $cooked_flash_date ) : ?>
    <div class="notice success">
        <?php
        echo esc_html( sprintf(
            /* translators: %s: cooked date */
            __( 'Updated your cooked entry for %s.', 'cook-app' ),
            App::format_cooked_date( $cooked_flash_date )
        ) );
        ?>
    </div>
<?php endif; ?>

<?php if ( $post->post_content ) : ?>
    <div class="description"><?php echo wp_kses_post( wpautop( $post->post_content ) ); ?></div>
<?php endif; ?>

<?php
$render_ingredient_row = function( array $ing, int $i ) use ( $preference, $id ) {
    $rendered = Units::render_ingredient( $ing, 1.0, $preference );
    $raw_amount = isset( $ing['amount'] ) ? $ing['amount'] : '';
    $parsed_amount = Units::parse_amount( $raw_amount );
    ?>
    <li
        data-amount="<?php echo esc_attr( $parsed_amount ?? '' ); ?>"
        data-amount-raw="<?php echo esc_attr( $raw_amount ); ?>"
        data-unit="<?php echo esc_attr( Units::normalize_unit( $ing['unit'] ?? '' ) ); ?>"
        class="ingredient-row"
    >
        <div class="ingredient-line">
            <span class="amt"><?php
                echo esc_html( trim( $rendered['amount'] . ' ' . $rendered['unit'] ) );
            ?></span>
            <span class="ingredient-name">
                <?php
                $ing_term_id = isset( $ing['term_id'] ) ? (int) $ing['term_id'] : 0;
                $ing_term    = $ing_term_id ? get_term( $ing_term_id, App::TAX_INGREDIENT ) : null;
                if ( $ing_term && ! is_wp_error( $ing_term ) ) :
                    ?>
                    <a href="<?php echo esc_url( home_url( '/cook-app/ingredient/' . $ing_term->slug ) ); ?>"><?php echo esc_html( $rendered['name'] ); ?></a>
                <?php else : ?>
                    <?php echo esc_html( $rendered['name'] ); ?>
                <?php endif; ?>
                <?php if ( ! empty( $rendered['notes'] ) ) : ?>
                    <span style="color:var(--muted)"> - <?php echo esc_html( $rendered['notes'] ); ?></span>
                <?php endif; ?>
            </span>
            <?php if ( current_user_can( 'edit_post', $id ) ) : ?>
                <span class="ingredient-actions">
                    <button type="button" class="ingredient-replace-toggle" data-replace-target="replace-ingredient-<?php echo (int) $i; ?>"><?php esc_html_e( 'Replace', 'cook-app' ); ?></button>
                </span>
            <?php endif; ?>
        </div>
        <?php if ( current_user_can( 'edit_post', $id ) ) : ?>
            <form id="replace-ingredient-<?php echo (int) $i; ?>" class="ingredient-replace-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
                <?php wp_nonce_field( 'cookbook_replace_ingredient_' . $id . '_' . $i ); ?>
                <input type="hidden" name="action" value="cookbook_replace_ingredient">
                <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                <input type="hidden" name="ingredient_index" value="<?php echo (int) $i; ?>">
                <input type="text" name="amount" value="<?php echo esc_attr( $ing['amount'] ?? '' ); ?>" placeholder="<?php esc_attr_e( '2', 'cook-app' ); ?>">
                <input type="text" name="unit" value="<?php echo esc_attr( $ing['unit'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'g', 'cook-app' ); ?>">
                <input type="text" name="name" value="" placeholder="<?php echo esc_attr( sprintf(
                    /* translators: %s: ingredient name */
                    __( 'Replace %s with...', 'cook-app' ),
                    $ing['name'] ?? ''
                ) ); ?>" required>
                <input type="text" name="notes" value="<?php echo esc_attr( $ing['notes'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'notes', 'cook-app' ); ?>">
                <button class="btn fresh" type="submit"><?php esc_html_e( 'Save', 'cook-app' ); ?></button>
                <button class="btn secondary ingredient-replace-cancel" type="button"><?php esc_html_e( 'Cancel', 'cook-app' ); ?></button>
            </form>
        <?php endif; ?>
    </li>
    <?php
};
?>

<h2><?php esc_html_e( 'Ingredients', 'cook-app' ); ?></h2>
<?php if ( ! $ingredients ) : ?>
    <p class="help">
        <?php
        printf(
            /* translators: %s: link to the recipe edit page */
            esc_html__( 'No ingredients yet. %s.', 'cook-app' ),
            '<a href="' . esc_url( home_url( '/cook-app/recipe/' . $id . '/edit' ) ) . '">' . esc_html__( 'Add some', 'cook-app' ) . '</a>'
        );
        ?>
    </p>
<?php else : ?>
<div id="ingredients" class="<?php echo $ingredient_parts ? 'ingredient-sections' : ''; ?>">
    <?php if ( $ingredient_parts ) : ?>
        <?php $ingredient_index = 0; ?>
        <?php foreach ( $ingredient_parts as $part ) : ?>
            <section class="recipe-part">
                <?php if ( ! empty( $part['title'] ) ) : ?>
                    <h3 class="recipe-part-title"><?php echo esc_html( $part['title'] ); ?></h3>
                <?php endif; ?>
                <ul class="ingredient-list">
                    <?php foreach ( (array) $part['ingredients'] as $ing ) : ?>
                        <?php $render_ingredient_row( $ing, $ingredient_index++ ); ?>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    <?php else : ?>
        <ul class="ingredient-list">
            <?php foreach ( $ingredients as $i => $ing ) : ?>
                <?php $render_ingredient_row( $ing, (int) $i ); ?>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<h2><?php esc_html_e( 'Instructions', 'cook-app' ); ?></h2>
<?php if ( ! $has_clean_instructions ) : ?>
    <p class="help"><?php esc_html_e( 'No instructions yet.', 'cook-app' ); ?></p>
<?php elseif ( $clean_instruction_parts ) : ?>
    <div class="instruction-sections">
        <?php foreach ( $clean_instruction_parts as $part ) : ?>
            <section class="recipe-part">
                <?php if ( ! empty( $part['title'] ) ) : ?>
                    <h3 class="recipe-part-title"><?php echo esc_html( $part['title'] ); ?></h3>
                <?php endif; ?>
                <ol class="instruction-list">
                    <?php foreach ( (array) $part['instructions'] as $step ) : ?>
                        <li><?php echo wp_kses_post( $step ); ?></li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endforeach; ?>
    </div>
<?php else : ?>
<ol class="instruction-list">
    <?php foreach ( $clean_instructions as $step ) : ?>
        <li><?php echo wp_kses_post( $step ); ?></li>
    <?php endforeach; ?>
</ol>
<?php endif; ?>

<?php if ( $notes ) : ?>
    <h2><?php esc_html_e( 'Notes', 'cook-app' ); ?></h2>
    <div><?php echo wp_kses_post( Markdown::to_html( $notes ) ); ?></div>
<?php endif; ?>

<?php if ( $cooked_entries ) : ?>
    <h2 id="cooked-history" class="section-heading-with-action">
        <span><?php esc_html_e( 'Cooking history', 'cook-app' ); ?></span>
        <a href="<?php echo esc_url( home_url( '/cook-app/cooked' ) ); ?>"><?php esc_html_e( 'All', 'cook-app' ); ?></a>
    </h2>
    <p class="subtitle">
        <?php
        echo esc_html( sprintf(
            /* translators: 1: number of times, 2: last cooked date */
            _n( 'Cooked %1$d time. Last: %2$s.', 'Cooked %1$d times. Last: %2$s.', $cooked_count, 'cook-app' ),
            $cooked_count,
            App::format_cooked_date( $last_cooked_date )
        ) );
        ?>
    </p>
    <ul class="cooked-history-list">
        <?php foreach ( $recent_cooked_entries as $entry ) :
            $entry_date = (string) get_post_meta( $entry->ID, App::META_COOKED_DATE, true );
            $entry_note = (string) get_post_meta( $entry->ID, App::META_COOKED_NOTE, true );
            ?>
            <li>
                <span class="cooked-history-entry">
                    <?php if ( $entry_note !== '' ) : ?>
                        <span class="cooked-note"><?php echo esc_html( $entry_note ); ?></span>
                    <?php else : ?>
                        <span><?php esc_html_e( 'Cooked', 'cook-app' ); ?></span>
                    <?php endif; ?>
                    <button class="cooked-edit-toggle" type="button" aria-expanded="false" aria-controls="cooked-edit-<?php echo (int) $entry->ID; ?>"><?php esc_html_e( 'Edit', 'cook-app' ); ?></button>
                </span>
                <time datetime="<?php echo esc_attr( $entry_date ); ?>"><?php echo esc_html( App::format_cooked_date( $entry_date ) ); ?></time>
                <div class="cooked-edit" id="cooked-edit-<?php echo (int) $entry->ID; ?>" hidden>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'cookbook_update_cooked' ); ?>
                        <input type="hidden" name="action" value="cookbook_update_cooked">
                        <input type="hidden" name="entry_id" value="<?php echo (int) $entry->ID; ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url( $recipe_url . '#cooked-history' ); ?>">
                        <textarea name="cooked_note" rows="1" aria-label="<?php esc_attr_e( 'Notes', 'cook-app' ); ?>"><?php echo esc_textarea( $entry_note ); ?></textarea>
                        <input type="date" name="cooked_date" value="<?php echo esc_attr( $entry_date ); ?>" max="<?php echo esc_attr( $today_date ); ?>" aria-label="<?php esc_attr_e( 'Cooked on', 'cook-app' ); ?>">
                        <button class="btn secondary" type="submit"><?php esc_html_e( 'Save', 'cook-app' ); ?></button>
                        <button class="btn secondary cooked-edit-cancel" type="button"><?php esc_html_e( 'Cancel', 'cook-app' ); ?></button>
                    </form>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ( $has_clean_instructions ) : ?>
<div class="cook-mode" id="cook-mode" role="dialog" aria-modal="true" aria-labelledby="cook-mode-title" hidden>
    <div class="cook-mode-shell">
        <header class="cook-mode-topbar">
            <div class="cook-mode-title">
                <p class="cook-mode-kicker"><?php esc_html_e( 'Cook mode', 'cook-app' ); ?></p>
                <h2 id="cook-mode-title"><?php echo esc_html( get_the_title( $post ) ); ?></h2>
            </div>
            <button class="btn secondary" type="button" id="cook-mode-close"><?php esc_html_e( 'Exit', 'cook-app' ); ?></button>
        </header>

        <div class="cook-mode-layout">
            <?php if ( $ingredients ) : ?>
                <aside class="cook-mode-panel cook-mode-ingredients">
                    <h3><?php esc_html_e( 'Ingredients', 'cook-app' ); ?></h3>
                    <?php if ( $ingredient_parts ) : ?>
                        <?php $cook_ingredient_index = 0; ?>
                        <?php foreach ( $ingredient_parts as $part ) : ?>
                            <section class="cook-ingredient-section" data-cook-ingredient-section data-cook-part-index="<?php echo (int) $part['part_index']; ?>">
                                <?php if ( ! empty( $part['title'] ) ) : ?>
                                    <h4 class="cook-ingredient-section-title"><?php echo esc_html( $part['title'] ); ?></h4>
                                <?php endif; ?>
                                <ul class="cook-ingredient-list">
                                    <?php foreach ( (array) $part['ingredients'] as $ing ) :
                                        $rendered = Units::render_ingredient( $ing, 1.0, $preference );
                                        $quantity = trim( $rendered['amount'] . ' ' . $rendered['unit'] );
                                        ?>
                                        <li class="cook-ingredient" data-cook-ingredient-index="<?php echo (int) $cook_ingredient_index; ?>" data-cook-part-index="<?php echo (int) $part['part_index']; ?>">
                                            <label for="cook-ingredient-<?php echo (int) $cook_ingredient_index; ?>">
                                                <input id="cook-ingredient-<?php echo (int) $cook_ingredient_index; ?>" type="checkbox" data-cook-ingredient-check>
                                                <span class="cook-ingredient-amount"><?php echo esc_html( $quantity ); ?></span>
                                                <span class="cook-ingredient-name">
                                                    <?php echo esc_html( $rendered['name'] ); ?>
                                                    <?php if ( ! empty( $rendered['notes'] ) ) : ?>
                                                        <span class="cook-ingredient-note"> - <?php echo esc_html( $rendered['notes'] ); ?></span>
                                                    <?php endif; ?>
                                                </span>
                                            </label>
                                        </li>
                                        <?php $cook_ingredient_index++; ?>
                                    <?php endforeach; ?>
                                </ul>
                            </section>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <ul class="cook-ingredient-list">
                            <?php foreach ( $ingredients as $i => $ing ) :
                                $rendered = Units::render_ingredient( $ing, 1.0, $preference );
                                $quantity = trim( $rendered['amount'] . ' ' . $rendered['unit'] );
                                ?>
                                <li class="cook-ingredient" data-cook-ingredient-index="<?php echo (int) $i; ?>">
                                    <label for="cook-ingredient-<?php echo (int) $i; ?>">
                                        <input id="cook-ingredient-<?php echo (int) $i; ?>" type="checkbox" data-cook-ingredient-check>
                                        <span class="cook-ingredient-amount"><?php echo esc_html( $quantity ); ?></span>
                                        <span class="cook-ingredient-name">
                                            <?php echo esc_html( $rendered['name'] ); ?>
                                            <?php if ( ! empty( $rendered['notes'] ) ) : ?>
                                                <span class="cook-ingredient-note"> - <?php echo esc_html( $rendered['notes'] ); ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </aside>
            <?php endif; ?>

            <section class="cook-mode-main">
                <div class="cook-mode-panel cook-mode-progress">
                    <div class="cook-mode-progress-row">
                        <strong id="cook-step-count"></strong>
                        <span id="cook-step-done-count"></span>
                    </div>
                    <progress id="cook-step-progress" value="1" max="<?php echo (int) $cook_step_count; ?>"></progress>
                    <p class="help" style="margin:0"><?php esc_html_e( 'Shortcuts: Space or Right arrow for next, Left arrow for previous, Escape to exit.', 'cook-app' ); ?></p>
                </div>

                <div class="cook-active-step" id="cook-active-step" tabindex="-1"></div>

                <div class="cook-mode-nav">
                    <label class="cook-active-check" for="cook-active-check">
                        <input id="cook-active-check" type="checkbox">
                        <?php esc_html_e( 'Step done', 'cook-app' ); ?>
                    </label>
                    <div class="cook-mode-nav-group">
                        <button class="btn secondary" type="button" id="cook-prev-step" aria-keyshortcuts="ArrowLeft" title="<?php esc_attr_e( 'Previous step (Left arrow)', 'cook-app' ); ?>"><?php esc_html_e( 'Previous', 'cook-app' ); ?></button>
                        <button class="btn" type="button" id="cook-next-step" aria-keyshortcuts="ArrowRight Space" title="<?php esc_attr_e( 'Next step (Space or Right arrow)', 'cook-app' ); ?>"><?php esc_html_e( 'Next', 'cook-app' ); ?></button>
                        <button class="btn secondary" type="button" id="cook-reset"><?php esc_html_e( 'Reset', 'cook-app' ); ?></button>
                    </div>
                </div>

                <div class="cook-mode-panel cook-finish" id="cook-finish" hidden>
                    <strong><?php esc_html_e( 'Mark this recipe as cooked?', 'cook-app' ); ?></strong>
                    <p class="help"><?php esc_html_e( 'Save the date and any notes from this cook session.', 'cook-app' ); ?></p>
                    <form id="cook-finish-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'cookbook_log_cooked' ); ?>
                        <input type="hidden" name="action" value="cookbook_log_cooked">
                        <input type="hidden" name="recipe_id" value="<?php echo (int) $id; ?>">
                        <input type="hidden" name="redirect_to" value="<?php echo esc_url( $recipe_url . '#cooked-history' ); ?>">
                        <label for="cook-finish-date"><?php esc_html_e( 'Cooked on', 'cook-app' ); ?></label>
                        <input id="cook-finish-date" type="date" name="cooked_date" value="<?php echo esc_attr( $today_date ); ?>" max="<?php echo esc_attr( $today_date ); ?>">
                        <label for="cook-finish-note"><?php esc_html_e( 'Notes', 'cook-app' ); ?></label>
                        <textarea id="cook-finish-note" name="cooked_note" rows="3" placeholder="<?php esc_attr_e( 'Tweaks, timing, reactions', 'cook-app' ); ?>"></textarea>
                        <button class="btn fresh" type="submit"><?php esc_html_e( 'Mark as cooked', 'cook-app' ); ?></button>
                        <button class="btn secondary" type="button" id="cook-finish-dismiss"><?php esc_html_e( 'Not now', 'cook-app' ); ?></button>
                    </form>
                </div>

                <ol class="cook-step-list">
                    <?php $cook_step_index = 0; ?>
                    <?php if ( $clean_instruction_parts ) : ?>
                        <?php foreach ( $clean_instruction_parts as $part ) : ?>
                            <?php if ( ! empty( $part['title'] ) ) : ?>
                                <li class="cook-step-section-title"><?php echo esc_html( $part['title'] ); ?></li>
                            <?php endif; ?>
                            <?php foreach ( (array) $part['instructions'] as $step ) : ?>
                                <li class="cook-step-row" data-cook-step-index="<?php echo (int) $cook_step_index; ?>" data-cook-part-index="<?php echo (int) $part['part_index']; ?>">
                                    <div class="cook-step-full" hidden><?php echo wp_kses_post( $step ); ?></div>
                                    <div class="cook-step-list-row">
                                        <input
                                            id="cook-step-check-<?php echo (int) $cook_step_index; ?>"
                                            class="cook-step-check"
                                            type="checkbox"
                                            data-cook-step-check
                                            aria-label="<?php echo esc_attr( sprintf(
                                                /* translators: %d: step number */
                                                __( 'Step %d done', 'cook-app' ),
                                                (int) $cook_step_index + 1
                                            ) ); ?>"
                                        >
                                        <button class="cook-step-jump" type="button" data-cook-step-jump="<?php echo (int) $cook_step_index; ?>">
                                            <span class="cook-step-list-index">
                                                <?php
                                                echo esc_html( sprintf(
                                                    /* translators: %d: step number */
                                                    __( 'Step %d', 'cook-app' ),
                                                    (int) $cook_step_index + 1
                                                ) );
                                                ?>
                                            </span>
                                            <span class="cook-step-list-text"><?php echo esc_html( wp_strip_all_tags( $step ) ); ?></span>
                                        </button>
                                    </div>
                                </li>
                                <?php $cook_step_index++; ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <?php foreach ( $clean_instructions as $step ) : ?>
                            <li class="cook-step-row" data-cook-step-index="<?php echo (int) $cook_step_index; ?>">
                                <div class="cook-step-full" hidden><?php echo wp_kses_post( $step ); ?></div>
                                <div class="cook-step-list-row">
                                    <input
                                        id="cook-step-check-<?php echo (int) $cook_step_index; ?>"
                                        class="cook-step-check"
                                        type="checkbox"
                                        data-cook-step-check
                                        aria-label="<?php echo esc_attr( sprintf(
                                            /* translators: %d: step number */
                                            __( 'Step %d done', 'cook-app' ),
                                            (int) $cook_step_index + 1
                                        ) ); ?>"
                                    >
                                    <button class="cook-step-jump" type="button" data-cook-step-jump="<?php echo (int) $cook_step_index; ?>">
                                        <span class="cook-step-list-index">
                                            <?php
                                            echo esc_html( sprintf(
                                                /* translators: %d: step number */
                                                __( 'Step %d', 'cook-app' ),
                                                (int) $cook_step_index + 1
                                            ) );
                                            ?>
                                        </span>
                                        <span class="cook-step-list-text"><?php echo esc_html( wp_strip_all_tags( $step ) ); ?></span>
                                    </button>
                                </div>
                            </li>
                            <?php $cook_step_index++; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ol>
            </section>
        </div>
    </div>
</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:2rem" data-cookbook-confirm="<?php esc_attr_e( 'Move this recipe to trash?', 'cook-app' ); ?>">
    <?php wp_nonce_field( 'cookbook_delete' ); ?>
    <input type="hidden" name="action" value="cookbook_delete">
    <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
    <button class="btn danger" type="submit"><?php esc_html_e( 'Delete recipe', 'cook-app' ); ?></button>
</form>

<div
    id="cookbook-recipe-config"
    hidden
    data-edit-url="<?php echo esc_url( home_url( '/cook-app/recipe/' . $id . '/edit' ) ); ?>"
    data-cook-state-key="<?php echo esc_attr( 'cookbook:cook-mode:' . (int) $id ); ?>"
    data-preference="<?php echo esc_attr( $preference ); ?>"
    data-strings="<?php
        echo esc_attr(
            wp_json_encode(
                array(
                    /* translators: 1: current step number, 2: total number of steps */
                    'stepOf'      => __( 'Step %1$d of %2$d', 'cook-app' ),
                    /* translators: 1: number of completed steps, 2: total number of steps */
                    'doneCount'   => __( '%1$d of %2$d done', 'cook-app' ),
                    'next'        => __( 'Next', 'cook-app' ),
                    'finish'      => __( 'Finish', 'cook-app' ),
                    'forThisStep' => __( 'For this step', 'cook-app' ),
                ),
                JSON_HEX_TAG | JSON_HEX_AMP
            )
        );
    ?>"
></div>

<?php
wp_app_enqueue_script(
    'cookbook-recipe',
    COOK_APP_PLUGIN_URL . 'assets/cookbook-recipe.js',
    array(),
    filemtime( dirname( __DIR__ ) . '/assets/cookbook-recipe.js' ),
    true,
    'cook-app'
);
?>


<?php include __DIR__ . '/_footer.php'; ?>
