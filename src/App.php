<?php

namespace Cookbook;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WpApp\WpApp;
use WpApp\BaseApp;

class App extends BaseApp {
    const POST_TYPE     = 'cb-recipes';
    const TAX_CATEGORY  = 'recipe_category';
    const TAX_CUISINE   = 'recipe_cuisine';
    const TAX_TAG       = 'recipe_tag';
    const TAX_INGREDIENT = 'recipe_ingredient';

    const META_SERVINGS    = '_recipe_servings';
    const META_PREP        = '_recipe_prep_time';
    const META_COOK        = '_recipe_cook_time';
    const META_INGREDIENTS = '_recipe_ingredients';
    const META_INSTRUCTIONS = '_recipe_instructions';
    const META_PARTS       = '_recipe_parts';
    const META_SOURCE_URL  = '_recipe_source_url';
    const META_NOTES       = '_recipe_notes';

    const SHOPPING_LIST_POST_TYPE = 'cb-shopping-list';
    const WEEK_PLAN_POST_TYPE     = 'cb-week-plan';
    const COOKED_ENTRY_POST_TYPE  = 'cb-cooked-entry';
    const SHOPPING_ITEM_STATUS_CHECKED = 'cb_checked';

    const META_SHOPPING_ITEMS = '_cookbook_shopping_items';
    const META_SHOPPING_ITEM_AMOUNT = '_cookbook_shopping_item_amount';
    const META_SHOPPING_ITEM_UNIT = '_cookbook_shopping_item_unit';
    const META_SHOPPING_ITEM_NOTES = '_cookbook_shopping_item_notes';
    const META_SHOPPING_ITEM_SOURCE_RECIPE_ID = '_cookbook_shopping_item_source_recipe_id';
    const META_SHOPPING_ITEM_SOURCE_RECIPE_TITLE = '_cookbook_shopping_item_source_recipe_title';
    const META_SHOPPING_ITEM_SOURCE_RECIPES = '_cookbook_shopping_item_source_recipes';
    const META_SHOPPING_HOUSEHOLD_REMINDERS = '_cookbook_shopping_household_reminders';
    const META_WEEK_START     = '_cookbook_week_start';
    const META_WEEK_MEALS     = '_cookbook_week_meals';
    const META_COOKED_RECIPE_ID = '_cookbook_cooked_recipe_id';
    const META_COOKED_DATE      = '_cookbook_cooked_date';
    const META_COOKED_NOTE      = '_cookbook_cooked_note';

    const USER_PREF_UNITS = 'cookbook_unit_preference';
    const USER_HOUSEHOLD_INGREDIENTS = 'cookbook_household_ingredient_ids';
    const HOME_INGREDIENT_STATS_TRANSIENT = 'cookbook_home_ingredient_stats_v1';

    const MEAL_SLOTS = [ 'breakfast', 'lunch', 'dinner' ];

    private ServiceContainer $services;

    public function __construct( ?ServiceContainer $services = null ) {
        $this->services = $services ?: ServiceContainer::default();
        $this->app = new WpApp( $this->get_template_dir(), $this->get_url_path(), [
            'require_login'       => true,
            'app_name'            => 'Cook App',
            'app_name_textdomain' => 'cook-app',
            'app_icon'            => 'dashicons-food',
            'app_icon_background' => 'linear-gradient(135deg, #f7971e, #ffd200)',
            'app_icon_color'      => '#fff',
            'app_icon_shadow'     => true,
            // Owned content: REST reads are gated with the app's capability and
            // OpenStation keeps these menus out of its dock.
            'post_types'          => [
                self::POST_TYPE,
                self::SHOPPING_LIST_POST_TYPE,
                self::WEEK_PLAN_POST_TYPE,
                self::COOKED_ENTRY_POST_TYPE,
            ],
            'taxonomies'          => [
                self::TAX_CATEGORY,
                self::TAX_CUISINE,
                self::TAX_TAG,
                self::TAX_INGREDIENT,
            ],
        ] );
    }

    protected function get_url_path(): string {
        return 'cook-app';
    }

    protected function get_template_dir(): string {
        return dirname( __DIR__ ) . '/templates';
    }

    public function init() {
        $abilities        = $this->services->abilities();
        $cooked_history   = $this->services->cookedHistory();
        $imports          = $this->services->imports();
        $ingredient_admin = $this->services->ingredientAdmin();
        $planner          = $this->services->planner();
        $recipes          = $this->services->recipes();
        $registry         = $this->services->registry();
        $shopping_list    = $this->services->shoppingList();
        $static_archive   = $this->services->staticArchive();

        // cook-app.php hooks this method on init priority 10. We're already in init,
        // so register the CPT and taxonomies directly rather than via nested
        // add_action('init', …) — those don't fire reliably when added during the
        // priority-10 iteration.
        $registry->register_post_type();
        $registry->register_taxonomies();
        add_action( 'admin_post_cookbook_save', [ $recipes, 'handle_save' ] );
        add_action( 'admin_post_cookbook_delete', [ $recipes, 'handle_delete' ] );
        add_action( 'admin_post_cookbook_settings', [ $recipes, 'handle_settings' ] );
        add_action( 'admin_post_cookbook_import', [ $imports, 'handle_import' ] );
        add_action( 'admin_post_cookbook_refetch', [ $imports, 'handle_refetch' ] );
        add_action( 'admin_post_cookbook_replace_ingredient', [ $recipes, 'handle_replace_ingredient' ] );
        add_action( 'admin_post_cookbook_add_to_shopping_list', [ $shopping_list, 'handle_add_to_shopping_list' ] );
        add_action( 'admin_post_cookbook_update_shopping_list', [ $shopping_list, 'handle_update_shopping_list' ] );
        add_action( 'admin_post_cookbook_save_planner', [ $planner, 'handle_save_planner' ] );
        add_action( 'admin_post_cookbook_add_planner_to_shopping_list', [ $shopping_list, 'handle_add_planner_to_shopping_list' ] );
        add_action( 'admin_post_cookbook_log_cooked', [ $cooked_history, 'handle_log_cooked' ] );
        add_action( 'admin_post_cookbook_update_cooked', [ $cooked_history, 'handle_update_cooked' ] );
        add_action( 'admin_post_cookbook_merge_ingredients', [ $ingredient_admin, 'handle_merge_ingredients' ] );
        add_action( 'admin_post_cookbook_group_ingredients', [ $ingredient_admin, 'handle_group_ingredients' ] );
        add_action( 'admin_post_cookbook_rename_ingredient', [ $ingredient_admin, 'handle_rename_ingredient' ] );
        add_action( 'wp_ajax_cookbook_lookup_source_url', [ $imports, 'ajax_lookup_source_url' ] );
        add_action( 'wp_ajax_cookbook_parse_url', [ $imports, 'ajax_parse_url' ] );
        add_action( 'wp_ajax_cookbook_parse_text', [ $imports, 'ajax_parse_text' ] );
        add_action( 'rest_api_init', [ $registry, 'register_rest_routes' ] );

        add_action( 'wp_loaded', [ $imports, 'handle_extension_save' ], 100 );
        add_filter( 'friends_browser_extension_actions', [ $imports, 'register_browser_extension_action' ] );
        add_filter( 'static_archive_post_types', [ $static_archive, 'add_static_archive_post_type' ] );
        add_filter( 'static_archive_post_html', [ $static_archive, 'static_archive_recipe_html' ], 10, 3 );
        add_filter( 'static_archive_post_markdown', [ $static_archive, 'static_archive_recipe_markdown' ], 10, 3 );
        add_action( 'wp_app_admin_bar_menu', [ $registry, 'add_recipe_admin_bar_edit_link' ] );
        add_action( 'wp_abilities_api_categories_init', [ $abilities, 'register_ability_categories' ] );
        add_action( 'wp_abilities_api_init', [ $abilities, 'register_abilities' ] );
        add_filter( 'ai_assistant_ability_domains', [ $abilities, 'register_ability_domains' ] );
        add_filter( 'ai_assistant_ability_instructions', [ $abilities, 'ability_result_instructions' ], 10, 4 );
        add_filter( 'ai_assistant_welcome_tips', [ $abilities, 'register_welcome_tips' ], 10, 2 );
        add_action( 'set_object_terms', [ $registry, 'maybe_flush_home_ingredient_stats_cache_for_terms' ], 10, 6 );
        add_action( 'created_' . self::TAX_INGREDIENT, [ $registry, 'flush_home_ingredient_stats_cache' ] );
        add_action( 'edited_' . self::TAX_INGREDIENT, [ $registry, 'flush_home_ingredient_stats_cache' ] );
        add_action( 'delete_' . self::TAX_INGREDIENT, [ $registry, 'flush_home_ingredient_stats_cache' ] );
        add_action( 'transition_post_status', [ $registry, 'maybe_flush_home_ingredient_stats_cache_for_status' ], 10, 3 );

        parent::init();

        // BaseApp defers setup_menu() to the app's own init filter, which WpApp
        // applies from an init callback it adds at priority 10 -- the priority
        // whose iteration we are inside right now, so that callback is never
        // reached and the menu stays empty. Build it directly instead, for the
        // same reason the post type and taxonomies are registered above.
        $this->setup_menu();
    }

    protected function setup_database(): void {
        // Native WP storage: CPT + taxonomies + post meta.
    }

    protected function setup_routes(): void {
        $this->app->route( 'recipe/{id}' );
        $this->app->route( 'recipe/{id}/edit', 'recipe-edit.php' );
        $this->app->route( 'new' );
        $this->app->route( 'import' );
        $this->app->route( 'shopping-list' );
        $this->app->route( 'planner' );
        $this->app->route( 'cooked' );
        $this->app->route( 'by-ingredients' );
        $this->app->route( 'manage-ingredients' );
        $this->app->route( 'settings' );
        $this->app->route( 'category/{slug}' );
        $this->app->route( 'tag/{slug}' );
        $this->app->route( 'ingredient/{slug}' );
    }

    protected function setup_menu(): void {
        $home = home_url( '/' . $this->get_url_path() . '/' );
        // The sections come first, in the order cookbook_page_head() lists them,
        // so the admin bar and the in-page section nav read the same way.
        $this->app->add_menu_item( 'all', __( 'Recipes', 'cook-app' ), $home );
        $this->app->add_menu_item( 'shopping-list', __( 'Shopping list', 'cook-app' ), $home . 'shopping-list' );
        $this->app->add_menu_item( 'planner', __( 'Week planner', 'cook-app' ), $home . 'planner' );
        $this->app->add_menu_item( 'cooked', __( 'Cooking history', 'cook-app' ), $home . 'cooked' );
        $this->app->add_menu_item( 'by-ingredients', __( 'By ingredients', 'cook-app' ), $home . 'by-ingredients' );
        $this->app->add_menu_item( 'manage-ingredients', __( 'Manage ingredients', 'cook-app' ), $home . 'manage-ingredients' );
        $this->app->add_menu_item( 'new', __( 'New recipe', 'cook-app' ), $home . 'new' );
        $this->app->add_menu_item( 'import', __( 'Import from web', 'cook-app' ), $home . 'import' );
        $this->app->add_menu_item( 'settings', __( 'Settings', 'cook-app' ), $home . 'settings' );
    }

    public function activate(): void {
        $this->services->registry()->activate();
    }

    public function register_post_type(): void {
        $this->services->registry()->register_post_type();
    }

    public function register_taxonomies(): void {
        $this->services->registry()->register_taxonomies();
    }

    public static function add_static_archive_post_type( array $post_types ): array {
        return ServiceContainer::default()->staticArchive()->add_static_archive_post_type( $post_types );
    }

    public static function get_home_ingredient_stats(): array {
        return ServiceContainer::default()->registry()->get_home_ingredient_stats();
    }

    public static function flush_home_ingredient_stats_cache( ...$args ): void {
        ServiceContainer::default()->registry()->flush_home_ingredient_stats_cache( ...$args );
    }

    public static function maybe_flush_home_ingredient_stats_cache_for_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
        ServiceContainer::default()->registry()->maybe_flush_home_ingredient_stats_cache_for_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids );
    }

    public static function maybe_flush_home_ingredient_stats_cache_for_status( string $new_status, string $old_status, \WP_Post $post ): void {
        ServiceContainer::default()->registry()->maybe_flush_home_ingredient_stats_cache_for_status( $new_status, $old_status, $post );
    }

    public static function find_recipe_by_source_url( string $url ) {
        return ServiceContainer::default()->recipes()->find_recipe_by_source_url( $url );
    }

    public static function get_recipe_parts( int $post_id ): array {
        return ServiceContainer::default()->recipes()->get_recipe_parts( $post_id );
    }

    public static function get_recipe_variation_parent( int $recipe_id ): ?\WP_Post {
        return ServiceContainer::default()->recipes()->get_recipe_variation_parent( $recipe_id );
    }

    public static function get_recipe_variation_root_id( int $recipe_id ): int {
        return ServiceContainer::default()->recipes()->get_recipe_variation_root_id( $recipe_id );
    }

    public static function get_recipe_variation_family( int $recipe_id ): array {
        return ServiceContainer::default()->recipes()->get_recipe_variation_family( $recipe_id );
    }

    public static function recipe_is_descendant_of( int $recipe_id, int $ancestor_id ): bool {
        return ServiceContainer::default()->recipes()->recipe_is_descendant_of( $recipe_id, $ancestor_id );
    }

    public static function get_user_unit_preference( int $user_id = 0 ): string {
        return ServiceContainer::default()->preferences()->get_user_unit_preference( $user_id );
    }

    public static function get_user_household_ingredient_ids( int $user_id = 0 ): array {
        return ServiceContainer::default()->preferences()->get_user_household_ingredient_ids( $user_id );
    }

    public static function is_household_ingredient_term( int $term_id, int $user_id = 0 ): bool {
        return ServiceContainer::default()->preferences()->is_household_ingredient_term( $term_id, $user_id );
    }

    public static function meal_slots(): array {
        return ServiceContainer::default()->planner()->meal_slots();
    }

    public static function normalize_week_start( string $date = '' ): string {
        return ServiceContainer::default()->planner()->normalize_week_start( $date );
    }

    public static function week_days( string $week_start ): array {
        return ServiceContainer::default()->planner()->week_days( $week_start );
    }

    public static function get_user_week_plan_id( string $week_start, bool $create = true ): int {
        return ServiceContainer::default()->planner()->get_user_week_plan_id( $week_start, $create );
    }

    public static function get_week_meals( int $plan_id ): array {
        return ServiceContainer::default()->planner()->get_week_meals( $plan_id );
    }

    public static function sanitize_cooked_date( string $date = '' ): string {
        return ServiceContainer::default()->cookedHistory()->sanitize_cooked_date( $date );
    }

    public static function format_cooked_date( string $date, string $format = '' ): string {
        return ServiceContainer::default()->cookedHistory()->format_cooked_date( $date, $format );
    }

    public static function get_user_cooked_entries( array $args = [] ): array {
        return ServiceContainer::default()->cookedHistory()->get_user_cooked_entries( $args );
    }

    public static function get_recipe_cooked_entries( int $recipe_id, int $number = 5, int $user_id = 0 ): array {
        return ServiceContainer::default()->cookedHistory()->get_recipe_cooked_entries( $recipe_id, $number, $user_id );
    }

    public static function get_recipe_last_cooked_date( int $recipe_id, int $user_id = 0 ): string {
        return ServiceContainer::default()->cookedHistory()->get_recipe_last_cooked_date( $recipe_id, $user_id );
    }

    public static function get_current_user_shopping_list_id( bool $create = true ): int {
        return ServiceContainer::default()->shoppingList()->get_current_user_shopping_list_id( $create );
    }

    public static function get_shopping_items( int $list_id ): array {
        return ServiceContainer::default()->shoppingList()->get_shopping_items( $list_id );
    }

    public static function get_shopping_household_reminders( int $list_id ): array {
        return ServiceContainer::default()->shoppingList()->get_shopping_household_reminders( $list_id );
    }

}
