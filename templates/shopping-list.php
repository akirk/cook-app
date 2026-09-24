<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use Cookbook\App;

if ( ! is_user_logged_in() ) {
    wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
}

$list_id = App::get_current_user_shopping_list_id( false );
$items   = $list_id ? App::get_shopping_items( $list_id ) : [];
$household_reminders = $list_id ? App::get_shopping_household_reminders( $list_id ) : [];
$checked_count = 0;
foreach ( $items as $item ) {
    if ( ! empty( $item['checked'] ) ) {
        $checked_count++;
    }
}
$remaining_count = max( 0, count( $items ) - $checked_count );

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only flash flags.
$saved = isset( $_GET['saved'] );
$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'shop';
// phpcs:enable WordPress.Security.NonceVerification.Recommended
$is_shop_mode = $mode !== 'edit';
$multiple_recipes_label = __( 'Multiple recipes', 'cook-app' );
$multiple_recipes_labels = array_unique( [ 'Multiple recipes', $multiple_recipes_label ] );
$is_multiple_recipes_label = function( string $title ) use ( $multiple_recipes_labels ): bool {
    $title = trim( $title );
    if ( $title === '' ) {
        return false;
    }
    return in_array( $title, $multiple_recipes_labels, true );
};
$shopping_item_source_titles = function( array $item ) use ( $is_multiple_recipes_label ): array {
    $titles = [];
    foreach ( $item['source_recipes'] ?? [] as $source ) {
        if ( is_array( $source ) && ! empty( $source['title'] ) && ! $is_multiple_recipes_label( (string) $source['title'] ) ) {
            $titles[] = (string) $source['title'];
        }
    }
    if ( ! $titles && ! empty( $item['source_recipe_title'] ) && ! $is_multiple_recipes_label( (string) $item['source_recipe_title'] ) ) {
        $titles[] = (string) $item['source_recipe_title'];
    }
    return array_values( array_unique( $titles ) );
};
$shopping_item_detail = function( array $item ): string {
    $quantity = trim( implode( ' ', array_filter( [ $item['amount'] ?? '', $item['unit'] ?? '' ] ) ) );
    return trim( $quantity . ( $quantity && ! empty( $item['notes'] ) ? ' - ' : '' ) . ( $item['notes'] ?? '' ) );
};

$page_title = __( 'Shopping list', 'cook-app' );
$has_shopping_list_content = ! empty( $items ) || ! empty( $household_reminders );
$clear_list_confirm = __( 'Clear the whole shopping list?', 'cook-app' );
include __DIR__ . '/_header.php';
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="shopping-list-form">
    <?php wp_nonce_field( 'cookbook_update_shopping_list' ); ?>
    <input type="hidden" name="action" value="cookbook_update_shopping_list">
    <input type="hidden" name="list_id" value="<?php echo (int) $list_id; ?>">
    <input type="hidden" name="return_mode" value="<?php echo $is_shop_mode ? 'shop' : 'edit'; ?>">

    <?php
    ob_start();
    ?>
    <?php if ( $is_shop_mode ) : ?>
        <a class="btn secondary" href="<?php echo esc_url( add_query_arg( 'mode', 'edit', home_url( '/cook-app/shopping-list' ) ) ); ?>"><?php esc_html_e( 'Edit list', 'cook-app' ); ?></a>
    <?php else : ?>
        <a class="btn fresh" href="<?php echo esc_url( home_url( '/cook-app/shopping-list' ) ); ?>"><?php esc_html_e( 'Shop mode', 'cook-app' ); ?></a>
        <button class="btn fresh" type="submit" name="list_command" value="save"><?php esc_html_e( 'Save list', 'cook-app' ); ?></button>
        <button class="btn secondary" type="submit" name="list_command" value="clear_checked"><?php esc_html_e( 'Clear checked', 'cook-app' ); ?></button>
    <?php endif; ?>
    <?php
    $shopping_actions = ob_get_clean();
    cookbook_page_head( __( 'Shopping list', 'cook-app' ), [
        'current_section' => 'shopping',
        'subtitle'        => sprintf(
            /* translators: 1: total items, 2: checked items */
            __( '%1$d items, %2$d checked off.', 'cook-app' ),
            count( $items ),
            $checked_count
        ),
        'actions_html'    => $shopping_actions,
    ] );
    ?>

    <?php if ( $saved ) : ?>
        <div class="notice success"><?php esc_html_e( 'Shopping list saved.', 'cook-app' ); ?></div>
    <?php endif; ?>

    <?php if ( ! $items && ! $household_reminders ) : ?>
        <div class="notice"><?php esc_html_e( 'Your shopping list is empty. Add a recipe from its recipe page, build it from the week planner, or add items manually below.', 'cook-app' ); ?></div>
        <div class="shop-add soft-panel">
            <input type="text" name="new_items[0][name]" placeholder="<?php esc_attr_e( 'Add item', 'cook-app' ); ?>">
            <button class="btn fresh" type="submit" name="list_command" value="save"><?php esc_html_e( 'Add', 'cook-app' ); ?></button>
        </div>
    <?php elseif ( $is_shop_mode ) : ?>
        <?php if ( $items ) : ?>
            <div class="shop-bar">
                <div class="shop-bar-main">
                    <strong>
                        <span id="shop-remaining-count"><?php echo (int) $remaining_count; ?></span>
                        <?php esc_html_e( 'remaining', 'cook-app' ); ?>
                    </strong>
                    <?php if ( $household_reminders ) : ?>
                        <span class="shop-household-summary">
                            <?php
                            echo esc_html( sprintf(
                                /* translators: %s: comma-separated household shopping items */
                                __( 'At home: %s', 'cook-app' ),
                                implode( ', ', array_map( function( array $reminder ): string {
                                    return (string) ( $reminder['name'] ?? '' );
                                }, $household_reminders ) )
                            ) );
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
                <button class="btn secondary" type="button" id="undo-shop-check" hidden><?php esc_html_e( 'Undo', 'cook-app' ); ?></button>
                <button class="btn fresh" type="submit" name="list_command" value="save"><?php esc_html_e( 'Save', 'cook-app' ); ?></button>
                <button class="btn secondary" type="submit" name="list_command" value="clear_checked"><?php esc_html_e( 'Clear checked', 'cook-app' ); ?></button>
            </div>

            <ul class="shop-list" id="shop-list">
            <?php
            $shop_items = $items;
            foreach ( $shop_items as $item ) :
                $item_id = $item['id'];
                $is_checked = ! empty( $item['checked'] );
                $detail = $shopping_item_detail( $item );
                $source_titles = $shopping_item_source_titles( $item );
                ?>
                <li class="shop-item<?php echo $is_checked ? ' is-checked' : ''; ?>">
                    <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][id]" value="<?php echo esc_attr( $item_id ); ?>">
                    <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][source_recipe_id]" value="<?php echo (int) $item['source_recipe_id']; ?>">
                    <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][source_recipe_title]" value="<?php echo esc_attr( $item['source_recipe_title'] ); ?>">
                    <?php foreach ( $item['source_recipes'] ?? [] as $source_index => $source_recipe ) : ?>
                        <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][source_recipes][<?php echo (int) $source_index; ?>][id]" value="<?php echo isset( $source_recipe['id'] ) ? (int) $source_recipe['id'] : 0; ?>">
                        <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][source_recipes][<?php echo (int) $source_index; ?>][title]" value="<?php echo esc_attr( isset( $source_recipe['title'] ) ? $source_recipe['title'] : '' ); ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][amount]" value="<?php echo esc_attr( $item['amount'] ); ?>">
                    <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][unit]" value="<?php echo esc_attr( $item['unit'] ); ?>">
                    <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][name]" value="<?php echo esc_attr( $item['name'] ); ?>">
                    <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][notes]" value="<?php echo esc_attr( $item['notes'] ); ?>">
                    <?php foreach ( $item['term_ids'] ?? [] as $term_index => $term_id ) : ?>
                        <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][term_ids][<?php echo (int) $term_index; ?>]" value="<?php echo (int) $term_id; ?>">
                    <?php endforeach; ?>
                    <label>
                        <input class="shop-check shopping-check" type="checkbox" name="items[<?php echo esc_attr( $item_id ); ?>][checked]" value="1" <?php checked( $is_checked ); ?>>
                        <span>
                            <strong><?php echo esc_html( $item['name'] ); ?></strong>
                            <?php if ( $detail ) : ?>
                                <small>
                                    <?php echo esc_html( $detail ); ?>
                                </small>
                            <?php endif; ?>
                            <?php if ( $source_titles ) : ?>
                                <small class="shop-source"><?php
                                echo esc_html( sprintf(
                                    /* translators: %s: comma-separated recipe titles */
                                    __( 'For %s', 'cook-app' ),
                                    implode( ', ', $source_titles )
                                ) );
                                ?></small>
                            <?php endif; ?>
                        </span>
                    </label>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ( $household_reminders ) : ?>
            <section class="household-reminders soft-panel">
                <h2><?php esc_html_e( 'At home', 'cook-app' ); ?></h2>
                <ul class="household-list">
                    <?php foreach ( $household_reminders as $reminder ) :
                        $detail = $shopping_item_detail( $reminder );
                        $source_titles = $shopping_item_source_titles( $reminder );
                        ?>
                        <li>
                            <span>
                                <strong><?php echo esc_html( $reminder['name'] ); ?></strong>
                                <?php if ( $detail ) : ?>
                                    <small><?php echo esc_html( $detail ); ?></small>
                            <?php endif; ?>
                            <?php if ( $source_titles ) : ?>
                                <small class="shop-source"><?php
                                echo esc_html( sprintf(
                                    /* translators: %s: comma-separated recipe titles */
                                    __( 'For %s', 'cook-app' ),
                                    implode( ', ', $source_titles )
                                ) );
                                ?></small>
                            <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <div class="shop-add soft-panel">
            <input type="text" name="new_items[0][name]" placeholder="<?php esc_attr_e( 'Add item', 'cook-app' ); ?>">
            <button class="btn fresh" type="submit" name="list_command" value="save"><?php esc_html_e( 'Add', 'cook-app' ); ?></button>
        </div>
    <?php else : ?>
        <div class="shopping-bulk-bar" id="shopping-bulk-bar" hidden>
            <strong><span id="shopping-selected-count">0</span> <?php esc_html_e( 'selected', 'cook-app' ); ?></strong>
            <input type="text" id="bulk-item-name" placeholder="<?php esc_attr_e( 'Item name', 'cook-app' ); ?>">
            <button class="btn" type="button" id="bulk-merge-selected"><?php esc_html_e( 'Merge selected', 'cook-app' ); ?></button>
            <button class="btn household" type="submit" name="list_command" value="mark_household"><?php esc_html_e( 'Move to At home', 'cook-app' ); ?></button>
            <button class="btn danger" type="button" id="bulk-remove-selected"><?php esc_html_e( 'Remove selected', 'cook-app' ); ?></button>
        </div>

        <?php if ( $items ) : ?>
            <ul class="shopping-list" data-multiple-recipes-labels="<?php echo esc_attr( wp_json_encode( array_values( $multiple_recipes_labels ) ) ); ?>">
            <?php foreach ( $items as $item ) :
                $item_id = $item['id'];
                $recipe_id = ! empty( $item['source_recipe_id'] ) ? (int) $item['source_recipe_id'] : 0;
                $recipe = $recipe_id ? get_post( $recipe_id ) : null;
                $is_checked = ! empty( $item['checked'] );
                $source_recipes = $item['source_recipes'] ?? [];
                $source_titles = $shopping_item_source_titles( $item );
                ?>
                <li class="shopping-row">
                    <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][id]" value="<?php echo esc_attr( $item_id ); ?>">
                    <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][source_recipe_id]" value="<?php echo (int) $recipe_id; ?>">
                    <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][source_recipe_title]" value="<?php echo esc_attr( $item['source_recipe_title'] ); ?>">
                    <?php foreach ( $source_recipes as $source_index => $source_recipe ) : ?>
                        <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][source_recipes][<?php echo (int) $source_index; ?>][id]" value="<?php echo isset( $source_recipe['id'] ) ? (int) $source_recipe['id'] : 0; ?>">
                        <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][source_recipes][<?php echo (int) $source_index; ?>][title]" value="<?php echo esc_attr( isset( $source_recipe['title'] ) ? $source_recipe['title'] : '' ); ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][checked]" value="<?php echo $is_checked ? '1' : ''; ?>">
                    <?php foreach ( $item['term_ids'] ?? [] as $term_index => $term_id ) : ?>
                        <input type="hidden" name="items[<?php echo esc_attr( $item_id ); ?>][term_ids][<?php echo (int) $term_index; ?>]" value="<?php echo (int) $term_id; ?>">
                    <?php endforeach; ?>
                    <input class="shopping-row-select" type="checkbox" name="selected_items[]" value="<?php echo esc_attr( $item_id ); ?>" aria-label="<?php esc_attr_e( 'Select item', 'cook-app' ); ?>">
                    <div>
                        <div class="shopping-fields">
                            <input type="text" name="items[<?php echo esc_attr( $item_id ); ?>][amount]" value="<?php echo esc_attr( $item['amount'] ); ?>" placeholder="<?php esc_attr_e( '2', 'cook-app' ); ?>">
                            <input type="text" name="items[<?php echo esc_attr( $item_id ); ?>][unit]" value="<?php echo esc_attr( $item['unit'] ); ?>" placeholder="<?php esc_attr_e( 'g', 'cook-app' ); ?>">
                            <input type="text" name="items[<?php echo esc_attr( $item_id ); ?>][name]" value="<?php echo esc_attr( $item['name'] ); ?>" placeholder="<?php esc_attr_e( 'ingredient', 'cook-app' ); ?>" required>
                            <input type="text" name="items[<?php echo esc_attr( $item_id ); ?>][notes]" value="<?php echo esc_attr( $item['notes'] ); ?>" placeholder="<?php esc_attr_e( 'notes', 'cook-app' ); ?>">
                            <button type="button" class="remove" aria-label="<?php esc_attr_e( 'Remove', 'cook-app' ); ?>">×</button>
                        </div>
                        <?php if ( $source_titles && $source_recipes ) : ?>
                            <div class="shopping-source">
                                <?php esc_html_e( 'From', 'cook-app' ); ?>
                                <?php $rendered_source_count = 0; ?>
                                <?php foreach ( $source_recipes as $source_index => $source_recipe ) :
                                    $source_recipe_id = isset( $source_recipe['id'] ) ? (int) $source_recipe['id'] : 0;
                                    $source_recipe_post = $source_recipe_id ? get_post( $source_recipe_id ) : null;
                                    $source_recipe_title = isset( $source_recipe['title'] ) ? (string) $source_recipe['title'] : '';
                                    if ( $is_multiple_recipes_label( $source_recipe_title ) ) {
                                        continue;
                                    }
                                    if ( $rendered_source_count > 0 ) {
                                        echo esc_html( ', ' );
                                    }
                                    $rendered_source_count++;
                                    if ( $source_recipe_post && $source_recipe_post->post_type === App::POST_TYPE ) : ?>
                                        <a href="<?php echo esc_url( home_url( '/cook-app/recipe/' . $source_recipe_id ) ); ?>"><?php echo esc_html( get_the_title( $source_recipe_post ) ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $source_recipe_title ); ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ( $recipe && $recipe->post_type === App::POST_TYPE ) : ?>
                            <div class="shopping-source">
                                <?php esc_html_e( 'From', 'cook-app' ); ?>
                                <a href="<?php echo esc_url( home_url( '/cook-app/recipe/' . $recipe_id ) ); ?>"><?php echo esc_html( get_the_title( $recipe ) ); ?></a>
                            </div>
                        <?php elseif ( ! empty( $item['source_recipe_title'] ) && ! $is_multiple_recipes_label( (string) $item['source_recipe_title'] ) ) : ?>
                            <div class="shopping-source"><?php echo esc_html( $item['source_recipe_title'] ); ?></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ( $household_reminders ) : ?>
            <section class="household-reminders soft-panel">
                <h2><?php esc_html_e( 'At home', 'cook-app' ); ?></h2>
                <ul class="household-list">
                    <?php foreach ( $household_reminders as $reminder_index => $reminder ) :
                        $detail = $shopping_item_detail( $reminder );
                        $source_titles = $shopping_item_source_titles( $reminder );
                        ?>
                        <li>
                            <span>
                                <strong><?php echo esc_html( $reminder['name'] ); ?></strong>
                                <?php if ( $detail ) : ?>
                                    <small><?php echo esc_html( $detail ); ?></small>
                                <?php endif; ?>
                                <?php if ( $source_titles ) : ?>
                                    <small class="shop-source"><?php
                                    echo esc_html( sprintf(
                                        /* translators: %s: comma-separated recipe titles */
                                        __( 'For %s', 'cook-app' ),
                                        implode( ', ', $source_titles )
                                    ) );
                                    ?></small>
                                <?php endif; ?>
                            </span>
                            <button class="btn secondary" type="submit" name="list_command" value="restore_household:<?php echo (int) $reminder_index; ?>"><?php esc_html_e( 'Need to buy', 'cook-app' ); ?></button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <h2><?php esc_html_e( 'Add items', 'cook-app' ); ?></h2>
        <div class="soft-panel">
            <div id="manual-items">
                <div class="manual-item-row">
                    <input type="text" name="new_items[0][amount]" placeholder="<?php esc_attr_e( '2', 'cook-app' ); ?>">
                    <input type="text" name="new_items[0][unit]" placeholder="<?php esc_attr_e( 'g', 'cook-app' ); ?>">
                    <input type="text" name="new_items[0][name]" placeholder="<?php esc_attr_e( 'ingredient', 'cook-app' ); ?>">
                    <input type="text" name="new_items[0][notes]" placeholder="<?php esc_attr_e( 'notes', 'cook-app' ); ?>">
                    <button type="button" class="remove" aria-label="<?php esc_attr_e( 'Remove', 'cook-app' ); ?>">×</button>
                </div>
            </div>
            <div class="toolbar" style="margin-bottom:0">
                <button type="button" class="btn secondary" id="add-manual-item"><?php esc_html_e( '+ Add item', 'cook-app' ); ?></button>
                <span class="spacer"></span>
                <button class="btn fresh" type="submit" name="list_command" value="save"><?php esc_html_e( 'Save list', 'cook-app' ); ?></button>
            </div>
        </div>
    <?php endif; ?>

</form>

<?php if ( $has_shopping_list_content ) : ?>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="toolbar">
        <?php wp_nonce_field( 'cookbook_update_shopping_list' ); ?>
        <input type="hidden" name="action" value="cookbook_update_shopping_list">
        <input type="hidden" name="list_id" value="<?php echo (int) $list_id; ?>">
        <input type="hidden" name="return_mode" value="<?php echo $is_shop_mode ? 'shop' : 'edit'; ?>">
        <input type="hidden" name="list_command" value="clear_all">
        <span class="spacer"></span>
        <button class="btn danger" type="submit" data-cookbook-confirm="<?php echo esc_attr( $clear_list_confirm ); ?>"><?php esc_html_e( 'Clear list', 'cook-app' ); ?></button>
    </form>
<?php endif; ?>



<?php include __DIR__ . '/_footer.php'; ?>
