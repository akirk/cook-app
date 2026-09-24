<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use Cookbook\App;

$entries = App::get_user_cooked_entries();
$entries_by_date = [];
$today_date = wp_date( 'Y-m-d' );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash code.
$cooked_status = isset( $_GET['cooked'] ) ? sanitize_text_field( wp_unslash( $_GET['cooked'] ) ) : '';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash code.
$cooked_flash_date = isset( $_GET['cooked_date'] ) ? App::sanitize_cooked_date( sanitize_text_field( wp_unslash( $_GET['cooked_date'] ) ) ) : '';

foreach ( $entries as $entry ) {
    $recipe_id = (int) get_post_meta( $entry->ID, App::META_COOKED_RECIPE_ID, true );
    $date      = App::sanitize_cooked_date( (string) get_post_meta( $entry->ID, App::META_COOKED_DATE, true ) );
    $recipe    = $recipe_id ? get_post( $recipe_id ) : null;

    if ( ! isset( $entries_by_date[ $date ] ) ) {
        $entries_by_date[ $date ] = [];
    }

    $entries_by_date[ $date ][] = [
        'entry'     => $entry,
        'recipe'    => $recipe && $recipe->post_type === App::POST_TYPE ? $recipe : null,
        'recipe_id' => $recipe_id,
        'note'      => (string) get_post_meta( $entry->ID, App::META_COOKED_NOTE, true ),
    ];
}

$page_title = __( 'Cooking history', 'cook-app' );
include __DIR__ . '/_header.php';
?>
<?php cookbook_page_head( __( 'Cooking history', 'cook-app' ), [ 'current_section' => 'cooked' ] ); ?>

<?php if ( $cooked_status === 'updated' && $cooked_flash_date ) : ?>
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

<?php if ( ! $entries_by_date ) : ?>
    <div class="notice"><?php esc_html_e( 'No cooking history yet. Save a cooked date from any recipe page.', 'cook-app' ); ?></div>
<?php else : ?>
    <?php foreach ( $entries_by_date as $date => $date_entries ) : ?>
        <section class="recipe-alpha-section">
            <h2>
                <?php echo esc_html( App::format_cooked_date( $date ) ); ?>
                <span class="cooked-count">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %d: cooked-history entries on this date */
                        _n( '%d recipe', '%d recipes', count( $date_entries ), 'cook-app' ),
                        count( $date_entries )
                    ) );
                    ?>
                </span>
            </h2>
            <ul class="cooked-day-list">
                <?php foreach ( $date_entries as $item ) :
                    $recipe = $item['recipe'];
                    $entry_date = App::sanitize_cooked_date( (string) get_post_meta( $item['entry']->ID, App::META_COOKED_DATE, true ) );
                    ?>
                    <li>
                        <span class="cooked-history-entry">
                            <?php if ( $recipe ) : ?>
                                <a href="<?php echo esc_url( home_url( '/cook-app/recipe/' . $recipe->ID ) ); ?>"><?php echo esc_html( get_the_title( $recipe ) ); ?></a>
                            <?php else : ?>
                                <span><?php echo esc_html( get_the_title( $item['entry'] ) ); ?></span>
                            <?php endif; ?>
                            <?php if ( $item['note'] !== '' ) : ?>
                                <span class="cooked-note">- <?php echo esc_html( $item['note'] ); ?></span>
                            <?php endif; ?>
                            <button class="cooked-edit-toggle" type="button" aria-expanded="false" aria-controls="cooked-edit-<?php echo (int) $item['entry']->ID; ?>"><?php esc_html_e( 'Edit', 'cook-app' ); ?></button>
                        </span>
                        <time datetime="<?php echo esc_attr( $date ); ?>"><?php echo esc_html( App::format_cooked_date( $date ) ); ?></time>
                        <div class="cooked-edit" id="cooked-edit-<?php echo (int) $item['entry']->ID; ?>" hidden>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <?php wp_nonce_field( 'cookbook_update_cooked' ); ?>
                                <input type="hidden" name="action" value="cookbook_update_cooked">
                                <input type="hidden" name="entry_id" value="<?php echo (int) $item['entry']->ID; ?>">
                                <input type="hidden" name="redirect_to" value="<?php echo esc_url( home_url( '/cook-app/cooked' ) ); ?>">
                                <textarea name="cooked_note" rows="1" aria-label="<?php esc_attr_e( 'Notes', 'cook-app' ); ?>"><?php echo esc_textarea( $item['note'] ); ?></textarea>
                                <input type="date" name="cooked_date" value="<?php echo esc_attr( $entry_date ); ?>" max="<?php echo esc_attr( $today_date ); ?>" aria-label="<?php esc_attr_e( 'Cooked on', 'cook-app' ); ?>">
                                <button class="btn secondary" type="submit"><?php esc_html_e( 'Save', 'cook-app' ); ?></button>
                                <button class="btn secondary cooked-edit-cancel" type="button"><?php esc_html_e( 'Cancel', 'cook-app' ); ?></button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
