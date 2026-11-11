<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use CookApp\App;

if ( ! is_user_logged_in() || ! current_user_can( 'manage_categories' ) ) {
    status_header( 403 );
    $page_title = __( 'Not allowed', 'cook-app' );
    include __DIR__ . '/_header.php';
    echo '<h1>' . esc_html__( 'Not allowed.', 'cook-app' ) . '</h1>';
    include __DIR__ . '/_footer.php';
    return;
}

$all_terms = get_terms( [
    'taxonomy'   => App::TAX_INGREDIENT,
    'hide_empty' => false,
    'orderby'    => 'name',
    'order'      => 'ASC',
] );
if ( is_wp_error( $all_terms ) ) {
    $all_terms = [];
}

// Build a name→term lookup so we can show "child of X" inline without extra queries.
$by_id = [];
foreach ( $all_terms as $t ) { $by_id[ (int) $t->term_id ] = $t; }

// Notices from POST handlers.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash messages.
$merged_count  = isset( $_GET['merged'] ) ? max( 0, (int) $_GET['merged'] ) : -1;
$grouped_count = isset( $_GET['grouped'] ) ? max( 0, (int) $_GET['grouped'] ) : -1;
$renamed_count = isset( $_GET['renamed'] ) ? max( 0, (int) $_GET['renamed'] ) : -1;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$page_title = __( 'Manage ingredients', 'cook-app' );
include __DIR__ . '/_header.php';
?>


<?php cook_app_page_head( __( 'Manage ingredients', 'cook-app' ), [
    'current_section' => 'ingredients',
    'subtitle'        => __( 'Rename ingredients, or select duplicates and choose which ingredient to keep.', 'cook-app' ),
] ); ?>

<?php if ( $merged_count >= 0 ) : ?>
    <div class="notice success">
        <?php
        /* translators: %d: number of merged ingredient terms */
        echo esc_html( sprintf( _n( 'Merged %d ingredient.', 'Merged %d ingredients.', max( 1, $merged_count ), 'cook-app' ), $merged_count ) );
        ?>
    </div>
<?php endif; ?>
<?php if ( $grouped_count >= 0 ) : ?>
    <div class="notice success">
        <?php
        /* translators: %d: number of grouped ingredient terms */
        echo esc_html( sprintf( _n( 'Grouped %d ingredient.', 'Grouped %d ingredients.', max( 1, $grouped_count ), 'cook-app' ), $grouped_count ) );
        ?>
    </div>
<?php endif; ?>
<?php if ( $renamed_count >= 0 ) : ?>
    <div class="notice success"><?php esc_html_e( 'Ingredient renamed.', 'cook-app' ); ?></div>
<?php endif; ?>

<?php if ( ! $all_terms ) : ?>
    <div class="notice"><?php esc_html_e( 'No ingredients yet — add or import a recipe to populate this list.', 'cook-app' ); ?></div>
<?php else : ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
    id="mi-form"
    data-name-label="<?php esc_attr_e( 'Ingredient', 'cook-app' ); ?>"
    data-amount-label="<?php esc_attr_e( 'Amount', 'cook-app' ); ?>"
    data-unit-label="<?php esc_attr_e( 'Unit', 'cook-app' ); ?>"
    data-save-label="<?php esc_attr_e( 'Save', 'cook-app' ); ?>"
    data-saved-label="<?php esc_attr_e( 'Saved', 'cook-app' ); ?>"
    data-servings-label="<?php esc_attr_e( 'Servings:', 'cook-app' ); ?>"
    data-merge-endpoint="<?php echo esc_url( rest_url( 'cookbook/v1/ingredients/merge' ) ); ?>"
    data-merge-error="<?php esc_attr_e( 'Could not merge ingredients. Please try again.', 'cook-app' ); ?>"
    data-recipes-endpoint="<?php echo esc_url( rest_url( 'cookbook/v1/ingredient-recipes/' ) ); ?>"
    data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
    <?php wp_nonce_field( 'cookbook_manage_ingredients' ); ?>
    <input type="hidden" name="action" id="mi-action" value="cookbook_merge_ingredients">

    <label for="mi-search"><?php esc_html_e( 'Search ingredients', 'cook-app' ); ?></label>
    <input type="search" class="mi-search" id="mi-search" placeholder="<?php esc_attr_e( 'Filter ingredients…', 'cook-app' ); ?>" autocomplete="off">

    <div class="mi-toolbar" id="mi-toolbar">
        <span><span class="mi-selected-count" id="mi-count">0</span> <?php esc_html_e( 'selected', 'cook-app' ); ?></span>
        <span id="mi-merge-status" role="status"></span>
    </div>

    <ul class="mi-list" id="mi-list">
        <?php foreach ( $all_terms as $t ) :
            $parent = $t->parent && isset( $by_id[ (int) $t->parent ] ) ? $by_id[ (int) $t->parent ] : null;
            ?>
            <li class="mi-row" data-name="<?php echo esc_attr( mb_strtolower( $t->name ) ); ?>" data-id="<?php echo (int) $t->term_id; ?>">
                <div class="mi-identity">
                <label>
                    <input type="checkbox" name="source_ids[]" value="<?php echo (int) $t->term_id; ?>" class="mi-check">
                    <span class="mi-name"><?php echo esc_html( $t->name ); ?></span>
                </label>
                <button type="button" class="mi-rename-toggle" aria-label="<?php echo esc_attr( sprintf(
                    /* translators: %s: ingredient name */
                    __( 'Rename %s', 'cook-app' ), $t->name
                ) ); ?>" title="<?php esc_attr_e( 'Rename', 'cook-app' ); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m16 3 5 5-13 13H3v-5L16 3Z"/><path d="m14 5 5 5"/></svg>
                </button>
                    <?php if ( $parent ) : ?>
                        <span class="mi-parent">↳ <?php
                            /* translators: %s: parent ingredient name */
                            echo esc_html( sprintf( __( 'child of %s', 'cook-app' ), $parent->name ) );
                        ?></span>
                    <?php endif; ?>
                </div>
                <span class="mi-rename" data-rename-for="<?php echo (int) $t->term_id; ?>">
                    <input type="text" value="<?php echo esc_attr( $t->name ); ?>" data-original="<?php echo esc_attr( $t->name ); ?>">
                    <button type="button" class="btn mi-rename-save"><?php esc_html_e( 'Save', 'cook-app' ); ?></button>
                    <button type="button" class="btn secondary mi-rename-cancel"><?php esc_html_e( 'Cancel', 'cook-app' ); ?></button>
                </span>
                <span class="mi-count"><?php echo (int) $t->count; ?></span>
                <span class="mi-actions">
                    <button type="submit" class="btn mi-merge" name="target_id" value="<?php echo (int) $t->term_id; ?>" hidden disabled><?php esc_html_e( 'Merge into this', 'cook-app' ); ?></button>
                    <button type="button" class="btn secondary mi-view" aria-expanded="false" aria-controls="mi-recipes-<?php echo (int) $t->term_id; ?>"><?php esc_html_e( 'View recipes', 'cook-app' ); ?></button>
                </span>
                <div class="mi-recipes" id="mi-recipes-<?php echo (int) $t->term_id; ?>" hidden data-loading="<?php esc_attr_e( 'Loading recipes…', 'cook-app' ); ?>" data-empty="<?php esc_attr_e( 'No recipes use this ingredient yet.', 'cook-app' ); ?>" data-error="<?php esc_attr_e( 'Could not load recipes. Please try again.', 'cook-app' ); ?>" aria-live="polite"></div>
            </li>
        <?php endforeach; ?>
    </ul>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mi-rename-form" style="display:none">
    <?php wp_nonce_field( 'cookbook_manage_ingredients' ); ?>
    <input type="hidden" name="action" value="cookbook_rename_ingredient">
    <input type="hidden" name="term_id" id="mi-rename-term">
    <input type="hidden" name="name" id="mi-rename-name">
</form>



<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
