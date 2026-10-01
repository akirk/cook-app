<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use CookApp\App;

if ( ! is_user_logged_in() ) {
    wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
}

$pref = App::get_user_unit_preference();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect-back flag, harmless to read.
$saved = isset( $_GET['saved'] );

$page_title = __( 'Settings', 'cook-app' );
include __DIR__ . '/_header.php';
?>
<h1><?php esc_html_e( 'Settings', 'cook-app' ); ?></h1>

<?php if ( $saved ) : ?>
    <div class="notice success"><?php esc_html_e( 'Settings saved.', 'cook-app' ); ?></div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'cookbook_settings' ); ?>
    <input type="hidden" name="action" value="cookbook_settings">

    <label><?php esc_html_e( 'Preferred unit system', 'cook-app' ); ?></label>
    <p class="help"><?php esc_html_e( 'Recipes are stored in their original units. We convert on display when the system differs from your preference.', 'cook-app' ); ?></p>
    <p>
        <label style="display:inline-block;font-weight:normal;margin-right:1rem">
            <input type="radio" name="unit_preference" value="metric" <?php checked( $pref, 'metric' ); ?>>
            <?php esc_html_e( 'Metric (g, kg, ml, l)', 'cook-app' ); ?>
        </label>
        <label style="display:inline-block;font-weight:normal">
            <input type="radio" name="unit_preference" value="imperial" <?php checked( $pref, 'imperial' ); ?>>
            <?php esc_html_e( 'Imperial (oz, lb, tsp, tbsp, cup)', 'cook-app' ); ?>
        </label>
    </p>

    <div class="toolbar">
        <button class="btn" type="submit"><?php esc_html_e( 'Save settings', 'cook-app' ); ?></button>
    </div>
</form>

<?php if ( current_user_can( 'edit_posts' ) && current_user_can( 'publish_posts' ) ) : ?>
<h2><?php esc_html_e( 'Cookbook backup and restore', 'cook-app' ); ?></h2>
<p><?php esc_html_e( 'Download a JSON-LD backup of your recipes, shopping lists, meal plans, cooking history, and preferences. Recipe photos are saved as URLs; image files are not included.', 'cook-app' ); ?></p>
<?php
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- informational redirect count only.
$restored = isset( $_GET['restored'] ) ? absint( $_GET['restored'] ) : null;
if ( $restored !== null ) : ?>
    <div class="notice success"><?php
        /* translators: %d: number of restored entries */
        echo esc_html( sprintf( __( 'Restored %d entries.', 'cook-app' ), $restored ) );
    ?></div>
<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'cookbook_export_backup' ); ?>
    <input type="hidden" name="action" value="cookbook_export_backup">
    <button class="btn" type="submit"><?php esc_html_e( 'Download backup', 'cook-app' ); ?></button>
</form>
<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'cookbook_restore_backup' ); ?>
    <input type="hidden" name="action" value="cookbook_restore_backup">
    <label for="cookbook-backup"><?php esc_html_e( 'Import recipes or restore a Cook App backup', 'cook-app' ); ?></label>
    <p class="help"><?php esc_html_e( 'Accepts schema.org Recipe JSON-LD files and Cook App backups. Maximum 10 MB. Restore adds new copies without replacing existing recipes, lists, plans, or history. Repeated restores create duplicates. Your unit preference is restored and household ingredients are merged. Photo URLs are retained for reference; upload photos again after restoring.', 'cook-app' ); ?></p>
    <input id="cookbook-backup" type="file" name="backup" accept=".json,.jsonld,application/json,application/ld+json" required>
    <div class="toolbar"><button class="btn" type="submit"><?php esc_html_e( 'Import file', 'cook-app' ); ?></button></div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
