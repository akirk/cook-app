<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
use CookApp\App;

if ( ! current_user_can( 'edit_posts' ) ) {
    wp_die( esc_html__( 'Not allowed.', 'cook-app' ), 403 );
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only prefill / error code.
$error      = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
$source_url = isset( $_GET['source_url'] ) ? esc_url_raw( wp_unslash( $_GET['source_url'] ) ) : '';
$source_host = $source_url !== '' ? ( wp_parse_url( $source_url, PHP_URL_HOST ) ?: $source_url ) : '';
$existing_source_recipe = $source_url !== '' ? App::find_recipe_by_source_url( $source_url ) : null;
$existing_source_recipe_url = $existing_source_recipe ? home_url( '/cook-app/recipe/' . $existing_source_recipe->ID ) : '';
$autoimport = ! empty( $_GET['autoimport'] ) && $source_url !== '' && $error === '' && ! $existing_source_recipe;
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$page_title = __( 'Import a recipe', 'cook-app' );
include __DIR__ . '/_header.php';
?>
<h1><?php esc_html_e( 'Import a recipe', 'cook-app' ); ?></h1>
<p class="subtitle"><?php esc_html_e( 'Paste a URL from a recipe site, or paste the recipe text itself.', 'cook-app' ); ?></p>



<?php if ( $error === 'parse' ) : ?>
    <div class="notice error">
        <?php if ( $source_url !== '' ) : ?>
            <?php
            printf(
                /* translators: %s: source hostname */
                esc_html__( 'Could not detect recipe metadata on %s. The URL is kept below as the source; paste the recipe text and use the preview to check what will be imported.', 'cook-app' ),
                esc_html( $source_host )
            );
            ?>
        <?php else : ?>
            <?php esc_html_e( 'Could not detect a recipe. Paste the recipe text and use the preview to check what will be imported.', 'cook-app' ); ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<form
    method="post"
    action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
    id="import-form"
    data-preview-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
    data-preview-strings="<?php
        echo esc_attr(
            wp_json_encode(
                array(
                    'parsing'        => __( 'Checking the pasted text…', 'cook-app' ),
                    'empty'          => __( 'Paste recipe text to fill the checklist.', 'cook-app' ),
                    'review'         => __( 'Review each detected section before importing.', 'cook-app' ),
                    'missing'        => __( 'Missing', 'cook-app' ),
                    'detected'       => __( 'Detected', 'cook-app' ),
                    'noIngredients'  => __( 'No ingredients detected.', 'cook-app' ),
                    'noInstructions' => __( 'No instructions detected.', 'cook-app' ),
                    'blank'          => '-',
                    'error'          => __( 'Could not preview that text yet.', 'cook-app' ),
                ),
                JSON_HEX_TAG | JSON_HEX_AMP
            )
        );
    ?>"
>
    <?php wp_nonce_field( 'cookbook_import' ); ?>
    <input type="hidden" name="action" value="cookbook_import">

    <label for="source_url"><?php esc_html_e( 'Recipe URL', 'cook-app' ); ?></label>
    <input id="source_url" type="url" name="source_url" placeholder="https://example.com/some-recipe" value="<?php echo esc_attr( $source_url ); ?>" autofocus>
    <p class="help"><?php esc_html_e( 'We look for schema.org Recipe metadata first. If that fails, keep the URL here and paste the recipe text below.', 'cook-app' ); ?></p>
    <div class="notice" data-source-url-existing <?php echo $existing_source_recipe ? '' : 'hidden'; ?>>
        <?php esc_html_e( 'This source is already in your cookbook:', 'cook-app' ); ?>
        <a data-source-url-existing-link href="<?php echo esc_url( $existing_source_recipe_url ); ?>"><?php echo $existing_source_recipe ? esc_html( get_the_title( $existing_source_recipe ) ) : ''; ?></a>
    </div>

    <label for="image_url"><?php esc_html_e( 'Photo URL', 'cook-app' ); ?></label>
    <input id="image_url" type="url" name="image_url" placeholder="https://example.com/recipe-photo.jpg">
    <p class="help"><?php esc_html_e( 'Optional. Paste an image URL from the source page to add it to the media library with the imported recipe.', 'cook-app' ); ?></p>
    <div id="image-url-preview" hidden style="margin:0.5rem 0 1rem">
        <img src="" alt="" style="max-width:240px;border-radius:6px;border:1px solid var(--line)">
    </div>

    <div class="import-layout">
        <div>
            <label for="paste"><?php esc_html_e( '…or paste the recipe text', 'cook-app' ); ?></label>
            <textarea id="paste" name="paste" style="min-height:24rem;overflow:hidden" aria-describedby="paste-help import-preview" placeholder="<?php esc_attr_e( "Title\n\nIngredients\n2 cups flour\n1 tsp salt\n\nInstructions\nMix everything\nBake until done", 'cook-app' ); ?>"></textarea>
            <p class="help" id="paste-help"><?php esc_html_e( 'The imported recipe will be available immediately.', 'cook-app' ); ?></p>
            <div class="toolbar">
                <button class="btn" type="submit"><?php esc_html_e( 'Import', 'cook-app' ); ?></button>
                <a class="btn secondary" href="<?php echo esc_url( home_url( '/cook-app/' ) ); ?>"><?php esc_html_e( 'Cancel', 'cook-app' ); ?></a>
            </div>
        </div>
        <aside class="import-preview" id="import-preview" aria-live="polite">
            <div class="import-preview-title"><?php esc_html_e( 'Paste checklist', 'cook-app' ); ?></div>
            <p class="help" data-preview-message><?php esc_html_e( 'Review each detected section before importing.', 'cook-app' ); ?></p>
            <div class="notice error" data-preview-error hidden></div>

            <section class="preview-section" data-preview-section="title" data-state="missing">
                <button type="button" class="preview-section-head" aria-expanded="false">
                    <span class="preview-checkbox" aria-hidden="true"></span>
                    <span class="preview-section-label"><?php esc_html_e( 'Title', 'cook-app' ); ?></span>
                    <span class="preview-section-status" data-preview-title-status><?php esc_html_e( 'Missing', 'cook-app' ); ?></span>
                    <span class="preview-toggle" aria-hidden="true">›</span>
                </button>
                <div class="preview-section-body" hidden>
                    <p class="preview-title-value" data-preview-title></p>
                </div>
            </section>

            <section class="preview-section" data-preview-section="ingredients" data-state="missing">
                <button type="button" class="preview-section-head" aria-expanded="false">
                    <span class="preview-checkbox" aria-hidden="true"></span>
                    <span class="preview-section-label"><?php esc_html_e( 'Ingredients', 'cook-app' ); ?></span>
                    <span class="preview-section-status" data-preview-ingredients-status><?php esc_html_e( 'Missing', 'cook-app' ); ?></span>
                    <span class="preview-toggle" aria-hidden="true">›</span>
                </button>
                <div class="preview-section-body" hidden>
                    <div class="preview-table-wrap">
                        <table class="preview-ingredient-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Number', 'cook-app' ); ?></th>
                                    <th><?php esc_html_e( 'Type', 'cook-app' ); ?></th>
                                    <th><?php esc_html_e( 'Ingredient', 'cook-app' ); ?></th>
                                    <th><?php esc_html_e( 'Additional', 'cook-app' ); ?></th>
                                </tr>
                            </thead>
                            <tbody data-preview-ingredients></tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="preview-section" data-preview-section="instructions" data-state="missing">
                <button type="button" class="preview-section-head" aria-expanded="false">
                    <span class="preview-checkbox" aria-hidden="true"></span>
                    <span class="preview-section-label"><?php esc_html_e( 'Instructions', 'cook-app' ); ?></span>
                    <span class="preview-section-status" data-preview-instructions-status><?php esc_html_e( 'Missing', 'cook-app' ); ?></span>
                    <span class="preview-toggle" aria-hidden="true">›</span>
                </button>
                <div class="preview-section-body" hidden>
                    <ol class="preview-steps" data-preview-instructions></ol>
                </div>
            </section>
        </aside>
    </div>
</form>



<?php if ( $autoimport ) : ?>
<div id="import-overlay" role="status" aria-live="polite">
    <div class="spinner" aria-hidden="true"></div>
    <p>
        <?php
        printf(
            /* translators: %s: hostname being imported from */
            esc_html__( 'Importing from %s…', 'cook-app' ),
            '<strong>' . esc_html( wp_parse_url( $source_url, PHP_URL_HOST ) ?: $source_url ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        );
        ?>
    </p>
</div>

<?php endif; ?>

<?php
wp_app_enqueue_script(
    'cook-app-import',
    COOK_APP_PLUGIN_URL . 'assets/cook-app-import.js',
    array(),
    filemtime( dirname( __DIR__ ) . '/assets/cook-app-import.js' ),
    true,
    'cook-app'
);
?>

<?php include __DIR__ . '/_footer.php'; ?>
