<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use Cookbook\App;

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
    'orderby'    => 'count',
    'order'      => 'DESC',
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


<?php cookbook_page_head( __( 'Manage ingredients', 'cook-app' ), [
    'current_section' => 'ingredients',
    'subtitle'        => __( 'Tick duplicates and merge them into one canonical term, or group similar ingredients under a parent. Merging rewrites the linked recipes; grouping just sets a hierarchy.', 'cook-app' ),
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

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mi-form">
    <?php wp_nonce_field( 'cookbook_manage_ingredients' ); ?>
    <input type="hidden" name="action" id="mi-action" value="cookbook_merge_ingredients">

    <input type="search" class="mi-search" id="mi-search" placeholder="<?php esc_attr_e( 'Filter ingredients…', 'cook-app' ); ?>" autocomplete="off">

    <div class="mi-toolbar" id="mi-toolbar">
        <span><span class="mi-selected-count" id="mi-count">0</span> <?php esc_html_e( 'selected', 'cook-app' ); ?></span>
        <span class="spacer" style="flex:1"></span>
        <label for="mi-target" style="margin:0"><?php esc_html_e( 'Target:', 'cook-app' ); ?></label>
        <select name="target_id" id="mi-target">
            <option value="0">— <?php esc_html_e( 'pick a target ingredient', 'cook-app' ); ?> —</option>
            <?php foreach ( $all_terms as $t ) : ?>
                <option value="<?php echo (int) $t->term_id; ?>"><?php echo esc_html( $t->name ); ?> <?php /* translators: %d: number of recipes using this ingredient */ printf( esc_html__( '(%d)', 'cook-app' ), (int) $t->count ); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn" name="do" value="merge" id="mi-merge" disabled><?php esc_html_e( 'Merge into target', 'cook-app' ); ?></button>
        <button type="submit" class="btn secondary" name="do" value="group" id="mi-group" disabled><?php esc_html_e( 'Make children of target', 'cook-app' ); ?></button>
    </div>

    <ul class="mi-list" id="mi-list">
        <?php foreach ( $all_terms as $t ) :
            $parent = $t->parent && isset( $by_id[ (int) $t->parent ] ) ? $by_id[ (int) $t->parent ] : null;
            $view_url = home_url( '/cook-app/ingredient/' . $t->slug );
            ?>
            <li class="mi-row" data-name="<?php echo esc_attr( mb_strtolower( $t->name ) ); ?>" data-id="<?php echo (int) $t->term_id; ?>">
                <label>
                    <input type="checkbox" name="source_ids[]" value="<?php echo (int) $t->term_id; ?>" class="mi-check">
                    <span class="mi-name"><?php echo esc_html( $t->name ); ?></span>
                    <?php if ( $parent ) : ?>
                        <span class="mi-parent">↳ <?php
                            /* translators: %s: parent ingredient name */
                            echo esc_html( sprintf( __( 'child of %s', 'cook-app' ), $parent->name ) );
                        ?></span>
                    <?php endif; ?>
                </label>
                <span class="mi-rename" data-rename-for="<?php echo (int) $t->term_id; ?>">
                    <input type="text" value="<?php echo esc_attr( $t->name ); ?>" data-original="<?php echo esc_attr( $t->name ); ?>">
                    <button type="button" class="btn mi-rename-save"><?php esc_html_e( 'Save', 'cook-app' ); ?></button>
                    <button type="button" class="btn secondary mi-rename-cancel"><?php esc_html_e( 'Cancel', 'cook-app' ); ?></button>
                </span>
                <span class="mi-count"><?php echo (int) $t->count; ?></span>
                <span class="mi-actions">
                    <a href="<?php echo esc_url( $view_url ); ?>"><?php esc_html_e( 'View', 'cook-app' ); ?></a>
                    <button type="button" class="btn secondary mi-rename-toggle"><?php esc_html_e( 'Rename', 'cook-app' ); ?></button>
                </span>
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
