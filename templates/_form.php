<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- variables here are template-local, not actually global.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/**
 * Shared recipe form. Variables expected:
 *   $id (int|0), $post (WP_Post|null), $is_new (bool)
 */
use Cookbook\App;
use Cookbook\Units;

$data_id      = $post ? (int) $post->ID : 0;
$save_id      = $is_new ? 0 : (int) $id;
$source_id    = isset( $variation_source_id ) ? (int) $variation_source_id : 0;
$title        = isset( $title_override ) ? (string) $title_override : ( $post ? get_the_title( $post ) : '' );
$content      = $post ? $post->post_content : '';
$servings     = $post ? (int) get_post_meta( $data_id, App::META_SERVINGS, true ) : 4;
$prep         = $post ? (int) get_post_meta( $data_id, App::META_PREP, true ) : 0;
$cook         = $post ? (int) get_post_meta( $data_id, App::META_COOK, true ) : 0;
$ingredients  = $post ? (array) get_post_meta( $data_id, App::META_INGREDIENTS, true ) : [];
$instructions = $post ? (array) get_post_meta( $data_id, App::META_INSTRUCTIONS, true ) : [];
$recipe_parts = $post ? App::get_recipe_parts( $data_id ) : [];
$source_url   = $post ? (string) get_post_meta( $data_id, App::META_SOURCE_URL, true ) : '';
$notes        = $post ? (string) get_post_meta( $data_id, App::META_NOTES, true ) : '';
$parent_id    = isset( $variation_parent_id )
    ? (int) $variation_parent_id
    : ( $post && ! $is_new ? (int) $post->post_parent : 0 );
$cancel_url   = isset( $cancel_url ) ? (string) $cancel_url : ( $save_id ? home_url( '/cook-app/recipe/' . $save_id ) : home_url( '/cook-app/' ) );
$submit_label = $is_new && $source_id
    ? __( 'Create variation', 'cook-app' )
    : ( $is_new ? __( 'Create recipe', 'cook-app' ) : __( 'Save recipe', 'cook-app' ) );

if ( ! $ingredients ) {
    $ingredients = [ [ 'amount' => '', 'unit' => '', 'name' => '', 'notes' => '' ] ];
}
if ( ! $instructions ) {
    $instructions = [ '' ];
}

$blank_ingredient = [ 'amount' => '', 'unit' => '', 'name' => '', 'notes' => '' ];
$ingredient_sections = [];
$instruction_sections = [];
foreach ( $recipe_parts as $part ) {
    if ( ! empty( $part['ingredients'] ) ) {
        $ingredient_sections[] = [
            'title'       => (string) ( $part['title'] ?? '' ),
            'ingredients' => (array) $part['ingredients'],
        ];
    }
    if ( ! empty( $part['instructions'] ) ) {
        $instruction_sections[] = [
            'title'        => (string) ( $part['title'] ?? '' ),
            'instructions' => (array) $part['instructions'],
        ];
    }
}
if ( ! $ingredient_sections ) {
    $ingredient_sections[] = [
        'title'       => '',
        'ingredients' => $ingredients ?: [ $blank_ingredient ],
    ];
}
if ( ! $instruction_sections ) {
    $instruction_sections[] = [
        'title'        => '',
        'instructions' => $instructions ?: [ '' ],
    ];
}
foreach ( $ingredient_sections as &$section ) {
    if ( empty( $section['ingredients'] ) ) {
        $section['ingredients'] = [ $blank_ingredient ];
    }
}
unset( $section );
foreach ( $instruction_sections as &$section ) {
    if ( empty( $section['instructions'] ) ) {
        $section['instructions'] = [ '' ];
    }
}
unset( $section );

$has_ingredient_sections = count( $ingredient_sections ) > 1;
foreach ( $ingredient_sections as $section ) {
    if ( '' !== trim( (string) ( $section['title'] ?? '' ) ) ) {
        $has_ingredient_sections = true;
        break;
    }
}

$categories = get_terms( [ 'taxonomy' => App::TAX_CATEGORY, 'hide_empty' => false ] );
$cuisines   = get_terms( [ 'taxonomy' => App::TAX_CUISINE,  'hide_empty' => false ] );
$current_categories = $post ? wp_get_object_terms( $data_id, App::TAX_CATEGORY, [ 'fields' => 'ids' ] ) : [];
$current_cuisines   = $post ? wp_get_object_terms( $data_id, App::TAX_CUISINE,  [ 'fields' => 'ids' ] ) : [];
$current_tags = $post ? wp_get_object_terms( $data_id, App::TAX_TAG, [ 'fields' => 'names' ] ) : [];
$tags_string = is_wp_error( $current_tags ) ? '' : implode( ', ', $current_tags );
$variation_parent_options = isset( $variation_parent_options ) ? $variation_parent_options : get_posts( [
    'post_type'      => App::POST_TYPE,
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
] );
$variation_parent_options = array_values( array_filter( $variation_parent_options, static function ( $candidate ) use ( $save_id ) {
    return ! $save_id || ( (int) $candidate->ID !== $save_id && ! App::recipe_is_descendant_of( (int) $candidate->ID, $save_id ) );
} ) );

$pref = App::get_user_unit_preference();
$unit_options = Units::COMMON_UNITS[ $pref ];
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="recipe-form">
    <?php wp_nonce_field( 'cookbook_save' ); ?>
    <input type="hidden" name="action" value="cookbook_save">
    <input type="hidden" name="id" value="<?php echo (int) $save_id; ?>">
    <?php if ( $is_new && $source_id && has_post_thumbnail( $source_id ) ) : ?>
        <input type="hidden" name="copy_thumbnail_from" value="<?php echo (int) $source_id; ?>">
    <?php endif; ?>

    <label for="title"><?php esc_html_e( 'Title', 'cook-app' ); ?></label>
    <input id="title" type="text" name="title" value="<?php echo esc_attr( $title ); ?>" data-generated-title="<?php echo $source_id && ( ! isset( $requested_title ) || $requested_title === '' ) ? '1' : '0'; ?>" required <?php echo ( ! $is_new || $source_id ) ? 'autofocus' : ''; ?>>

    <?php if ( $variation_parent_options ) : ?>
        <details class="variation-details" <?php echo $parent_id ? 'open' : ''; ?>>
            <summary><?php esc_html_e( 'Is this a variant of an existing recipe?', 'cook-app' ); ?></summary>
            <label for="parent_id"><?php esc_html_e( 'Variation of', 'cook-app' ); ?></label>
            <div class="variation-source-control">
                <select id="parent_id" name="parent_id">
                    <option value="0"><?php esc_html_e( 'Standalone recipe', 'cook-app' ); ?></option>
                    <?php foreach ( $variation_parent_options as $candidate ) : ?>
                        <option value="<?php echo (int) $candidate->ID; ?>" <?php selected( $parent_id, (int) $candidate->ID ); ?>>
                            <?php echo esc_html( get_the_title( $candidate ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ( $is_new ) : ?>
                    <button class="btn secondary" id="load-variation" type="button" data-load-url="<?php echo esc_url( home_url( '/cook-app/new' ) ); ?>" <?php disabled( ! $parent_id ); ?>><?php esc_html_e( 'Load recipe', 'cook-app' ); ?></button>
                <?php endif; ?>
            </div>
            <p class="help"><?php echo $is_new ? esc_html__( 'Select a recipe and load it to copy its details into a new variation.', 'cook-app' ) : esc_html__( 'Choose a parent recipe to make this recipe a variation.', 'cook-app' ); ?></p>
        </details>
    <?php endif; ?>

    <label><?php esc_html_e( 'Photo', 'cook-app' ); ?></label>
    <?php $thumb_url = $post && has_post_thumbnail( $data_id ) ? get_the_post_thumbnail_url( $data_id, 'medium' ) : ''; ?>
    <?php if ( $thumb_url ) : ?>
        <div style="display:flex;gap:1rem;align-items:flex-start;margin-bottom:0.5rem">
            <img src="<?php echo esc_url( $thumb_url ); ?>" alt="" style="max-width:240px;border-radius:6px;border:1px solid var(--line)">
            <?php if ( ! $is_new ) : ?>
                <label style="font-weight:normal;display:flex;gap:0.4rem;align-items:center;margin:0">
                    <input type="checkbox" name="remove_image" value="1"> <?php esc_html_e( 'Remove photo', 'cook-app' ); ?>
                </label>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <input id="image" type="file" name="image" accept="image/*">
    <p class="help">
        <?php
        if ( $thumb_url && $is_new ) {
            esc_html_e( 'The source photo will be reused unless you upload a different one.', 'cook-app' );
        } elseif ( $thumb_url ) {
            esc_html_e( 'Upload a new file to replace the current photo.', 'cook-app' );
        } else {
            esc_html_e( 'Optional. Will be added to the media library.', 'cook-app' );
        }
        ?>
    </p>
    <label for="image_url"><?php esc_html_e( 'Photo URL', 'cook-app' ); ?></label>
    <input id="image_url" type="url" name="image_url" placeholder="https://example.com/recipe-photo.jpg">
    <p class="help"><?php esc_html_e( 'Paste an image URL to add it to the media library. If you also upload a file, the uploaded file is used.', 'cook-app' ); ?></p>
    <div id="image-url-preview" hidden style="margin-top:0.5rem">
        <img src="" alt="" style="max-width:240px;border-radius:6px;border:1px solid var(--line)">
    </div>

    <label for="description"><?php esc_html_e( 'Short description', 'cook-app' ); ?></label>
    <textarea id="description" name="description" style="min-height:4rem"><?php echo esc_textarea( $content ); ?></textarea>

    <div class="grid">
        <div>
            <label for="servings"><?php esc_html_e( 'Servings (default)', 'cook-app' ); ?></label>
            <input id="servings" type="number" min="1" name="servings" value="<?php echo (int) ( $servings ?: 4 ); ?>">
        </div>
        <div>
            <label for="prep_time"><?php esc_html_e( 'Prep time (minutes)', 'cook-app' ); ?></label>
            <input id="prep_time" type="number" min="0" name="prep_time" value="<?php echo (int) $prep; ?>">
        </div>
        <div>
            <label for="cook_time"><?php esc_html_e( 'Cook time (minutes)', 'cook-app' ); ?></label>
            <input id="cook_time" type="number" min="0" name="cook_time" value="<?php echo (int) $cook; ?>">
        </div>
        <div>
            <label for="source_url"><?php esc_html_e( 'Source URL (optional)', 'cook-app' ); ?></label>
            <input id="source_url" type="url" name="source_url" value="<?php echo esc_attr( $source_url ); ?>">
        </div>
    </div>

    <h2><?php esc_html_e( 'Ingredients', 'cook-app' ); ?></h2>
    <p class="help"><?php esc_html_e( 'Amount + unit are optional. Enter "1/2", "1.5", or use fractions like ½. Recognised units convert automatically; "piece", "clove", "pinch" etc. are kept as-is.', 'cook-app' ); ?></p>
    <div id="ingredient-sections" class="recipe-form-sections<?php echo $has_ingredient_sections ? ' has-recipe-sections' : ''; ?>" data-section-root="ingredient">
        <?php foreach ( $ingredient_sections as $section_index => $section ) : ?>
            <?php
            $section_has_title = '' !== trim( (string) ( $section['title'] ?? '' ) );
            $section_classes = 'recipe-form-section';
            if ( $section_has_title ) {
                $section_classes .= ' has-section-title has-section-boundary';
            } elseif ( $section_index > 0 ) {
                $section_classes .= ' has-section-boundary';
            }
            ?>
            <section class="<?php echo esc_attr( $section_classes ); ?>" data-ingredient-section data-section-index="<?php echo (int) $section_index; ?>">
                <div class="recipe-form-section-header">
                    <input type="text" name="ingredient_parts[<?php echo (int) $section_index; ?>][title]" value="<?php echo esc_attr( $section['title'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Section header', 'cook-app' ); ?>">
                    <button type="button" class="btn secondary remove-section recipe-section-remove" aria-label="<?php esc_attr_e( 'Remove section header and merge ingredients', 'cook-app' ); ?>" title="<?php esc_attr_e( 'Remove section header and merge ingredients', 'cook-app' ); ?>">×</button>
                </div>
                <div class="recipe-form-section-rows" data-ingredient-rows>
                    <?php foreach ( (array) $section['ingredients'] as $i => $row ) : ?>
                        <div class="row">
                            <input type="text" name="ingredient_parts[<?php echo (int) $section_index; ?>][ingredients][<?php echo (int) $i; ?>][amount]" value="<?php echo esc_attr( $row['amount'] ?? '' ); ?>" placeholder="<?php esc_attr_e( '2', 'cook-app' ); ?>">
                            <input type="text" name="ingredient_parts[<?php echo (int) $section_index; ?>][ingredients][<?php echo (int) $i; ?>][unit]" value="<?php echo esc_attr( $row['unit'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'g', 'cook-app' ); ?>" list="recipe-units">
                            <input type="text" name="ingredient_parts[<?php echo (int) $section_index; ?>][ingredients][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $row['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'ingredient', 'cook-app' ); ?>" required>
                            <input type="text" name="ingredient_parts[<?php echo (int) $section_index; ?>][ingredients][<?php echo (int) $i; ?>][notes]" value="<?php echo esc_attr( $row['notes'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'chopped', 'cook-app' ); ?>">
                            <button type="button" class="remove" aria-label="<?php esc_attr_e( 'Remove', 'cook-app' ); ?>">×</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <datalist id="recipe-units">
        <?php foreach ( $unit_options as $u ) : ?>
            <option value="<?php echo esc_attr( $u ); ?>"></option>
        <?php endforeach; ?>
    </datalist>
    <h2><?php esc_html_e( 'Instructions', 'cook-app' ); ?></h2>
    <div id="instruction-sections" class="recipe-form-sections" data-section-root="instruction">
        <?php foreach ( $instruction_sections as $section_index => $section ) : ?>
            <section class="recipe-form-section" data-instruction-section data-section-index="<?php echo (int) $section_index; ?>">
                <div class="recipe-form-section-header">
                    <input type="text" name="instruction_parts[<?php echo (int) $section_index; ?>][title]" value="<?php echo esc_attr( $section['title'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Section title (optional)', 'cook-app' ); ?>">
                    <button type="button" class="btn secondary remove-section recipe-section-remove" aria-label="<?php esc_attr_e( 'Remove section header and merge steps', 'cook-app' ); ?>" title="<?php esc_attr_e( 'Remove section header and merge steps', 'cook-app' ); ?>">×</button>
                </div>
                <div class="recipe-form-section-rows" data-instruction-rows>
                    <?php foreach ( (array) $section['instructions'] as $i => $step ) : ?>
                        <div class="row" style="grid-template-columns: 1fr auto; align-items: flex-start">
                            <textarea name="instruction_parts[<?php echo (int) $section_index; ?>][instructions][]" placeholder="<?php
                                /* translators: %d: step number */
                                echo esc_attr( sprintf( __( 'Step %d', 'cook-app' ), (int) $i + 1 ) );
                            ?>"><?php echo esc_textarea( $step ); ?></textarea>
                            <button type="button" class="remove" aria-label="<?php esc_attr_e( 'Remove', 'cook-app' ); ?>">×</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <h2><?php esc_html_e( 'Categorisation', 'cook-app' ); ?></h2>
    <div class="grid">
        <div>
            <label for="categories"><?php esc_html_e( 'Categories', 'cook-app' ); ?></label>
            <select id="categories" name="categories[]" multiple size="5" style="height:auto">
                <?php foreach ( (array) $categories as $cat ) : ?>
                    <option value="<?php echo (int) $cat->term_id; ?>" <?php selected( in_array( $cat->term_id, (array) $current_categories ) ); ?>>
                        <?php echo esc_html( $cat->name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="help"><?php esc_html_e( 'Or type new ones (comma-separated):', 'cook-app' ); ?> <input type="text" name="categories[]" placeholder="<?php esc_attr_e( 'Mains, Desserts', 'cook-app' ); ?>"></p>
        </div>
        <div>
            <label for="cuisines"><?php esc_html_e( 'Cuisines', 'cook-app' ); ?></label>
            <select id="cuisines" name="cuisines[]" multiple size="5" style="height:auto">
                <?php foreach ( (array) $cuisines as $cui ) : ?>
                    <option value="<?php echo (int) $cui->term_id; ?>" <?php selected( in_array( $cui->term_id, (array) $current_cuisines ) ); ?>>
                        <?php echo esc_html( $cui->name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="help"><?php esc_html_e( 'Or type a new one:', 'cook-app' ); ?> <input type="text" name="cuisines[]" placeholder="<?php esc_attr_e( 'Italian', 'cook-app' ); ?>"></p>
        </div>
    </div>

    <label for="tags"><?php esc_html_e( 'Tags (comma-separated)', 'cook-app' ); ?></label>
    <input id="tags" type="text" name="tags" value="<?php echo esc_attr( $tags_string ); ?>" placeholder="<?php esc_attr_e( 'quick, vegetarian, weeknight', 'cook-app' ); ?>">

    <label for="notes"><?php esc_html_e( 'Notes (optional)', 'cook-app' ); ?></label>
    <textarea id="notes" name="notes" style="min-height:5rem"><?php echo esc_textarea( $notes ); ?></textarea>

    <div class="toolbar" style="margin-top:1.5rem">
        <button class="btn" type="submit"><?php echo esc_html( $submit_label ); ?></button>
        <a class="btn secondary" href="<?php echo esc_url( $cancel_url ); ?>"><?php esc_html_e( 'Cancel', 'cook-app' ); ?></a>
    </div>

    <div
        id="cookbook-form-config"
        hidden
        data-strings="<?php
            echo esc_attr(
                wp_json_encode(
                    array(
                        'sectionTitle'              => __( 'Section title (optional)', 'cook-app' ),
                        'ingredientSectionTitle'    => __( 'Section header', 'cook-app' ),
                        'mergeIngredientSection'    => __( 'Remove section header and merge ingredients', 'cook-app' ),
                        'mergeInstructionSection'   => __( 'Remove section header and merge steps', 'cook-app' ),
                        'two'                       => __( '2', 'cook-app' ),
                        'gram'                      => __( 'g', 'cook-app' ),
                        'ingredient'                => __( 'ingredient', 'cook-app' ),
                        'chopped'                   => __( 'chopped', 'cook-app' ),
                        'remove'                    => __( 'Remove', 'cook-app' ),
                        'ingredientShort'           => __( 'Ingredient', 'cook-app' ),
                        'sectionShort'              => __( 'Section', 'cook-app' ),
                        'step'                      => __( 'Step', 'cook-app' ),
                        'replaceWarning'            => __( 'Loading this recipe will replace the details you have entered. Continue?', 'cook-app' ),
                    ),
                    JSON_HEX_TAG | JSON_HEX_AMP
                )
            );
        ?>"
    ></div>
</form>

<?php
wp_app_enqueue_script(
    'cookbook-form',
    COOK_APP_PLUGIN_URL . 'assets/cookbook-form.js',
    array(),
    filemtime( dirname( __DIR__ ) . '/assets/cookbook-form.js' ),
    true,
    'cook-app'
);
?>
