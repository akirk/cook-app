<?php

namespace Cookbook;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AbilitiesService extends AbstractService {
    /**
     * Tell AI Assistant which user topics should prefer Cook App abilities.
     *
     * @param array $domains Existing plugin domain hints.
     * @return array
     */
    public function register_ability_domains( array $domains ): array {
        $domains['cook-app'] = implode( ', ', [
            'saved recipe collection',
            'URL/text recipe import',
            'ingredient-based recipe search',
            'weekly meal planner',
            'shopping-list builder',
            'serving scaling and variations',
            'ingredient, category, cuisine, and tag cleanup',
        ] );

        return $domains;
    }

    /**
     * Register AI Assistant welcome tips for Cook App pages.
     *
     * @param array $tips Existing welcome tips.
     * @param array $context AI Assistant request context.
     * @return array
     */
    public function register_welcome_tips( array $tips, array $context = [] ): array {
        $cookbook_tips = [
            __( 'Ask me to find saved recipes by title, ingredient, category, or tag.', 'cook-app' ),
            __( 'Ask me to import a recipe from a URL, create a recipe variation, or help plan meals for the week.', 'cook-app' ),
            __( 'Ask me to review and tidy Cook App ingredients, categories, cuisines, or tags.', 'cook-app' ),
        ];

        $existing = isset( $tips[ $this->get_url_path() ] ) ? $tips[ $this->get_url_path() ] : [];
        $existing = is_array( $existing ) ? $existing : [ $existing ];

        $tips[ $this->get_url_path() ] = array_merge( $existing, $cookbook_tips );

        return $tips;
    }

    /**
     * Tell AI Assistant how to present Cook App ability results.
     *
     * @param string $instructions Existing instructions.
     * @param string $ability_id Ability ID.
     * @param array  $args Ability arguments.
     * @param mixed  $result Ability result.
     * @return string
     */
    public function ability_result_instructions( string $instructions, string $ability_id, array $args, $result ): string {
        if ( strpos( $ability_id, 'cookbook/' ) !== 0 || empty( $result ) ) {
            return $instructions;
        }

        if ( in_array( $ability_id, [ 'cookbook/get-recipe', 'cookbook/save-recipe', 'cookbook/import-recipe', 'cookbook/create-recipe-variation' ], true ) ) {
            return __( 'When presenting Cook App recipes, include the recipe title and link it with view_url when present.', 'cook-app' );
        }

        if ( $ability_id === 'cookbook/search-recipes' ) {
            return __( 'When presenting Cook App search results, show concise recipe matches and link each recipe with view_url when present. If no recipes match, say that no Cook App recipe was found instead of guessing from the database.', 'cook-app' );
        }

        if ( in_array( $ability_id, [ 'cookbook/get-week-plan', 'cookbook/save-week-plan' ], true ) ) {
            return __( 'When presenting Cook App week plans, summarize planned meals by day and meal slot. Link planned recipes with their view_url when present, and link to the planner using the returned url.', 'cook-app' );
        }

        return $instructions;
    }

    /**
     * Register the Cook App ability category.
     */
    public function register_ability_categories(): void {
        if ( ! function_exists( 'wp_register_ability_category' ) ) {
            return;
        }

        wp_register_ability_category(
            'cook-app',
            [
                'label'       => __( 'Cook App', 'cook-app' ),
                'description' => __( 'Abilities for working with Cook App recipes and week plans.', 'cook-app' ),
            ]
        );
    }

    /**
     * Register Abilities API actions for recipes and week plans.
     */
    public function register_abilities(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }

        wp_register_ability(
            'cookbook/search-recipes',
            [
                'label'               => __( 'Search Cook App Recipes', 'cook-app' ),
                'description'         => __( 'Searches Cook App recipes and returns matching recipe summaries.', 'cook-app' ),
                'category'            => 'cook-app',
                'input_schema'        => $this->recipe_search_input_schema(),
                'output_schema'       => $this->recipe_search_output_schema(),
                'execute_callback'    => [ $this, 'ability_search_recipes' ],
                'permission_callback' => [ $this, 'can_read_abilities' ],
                'meta'                => [
                    'annotations'  => [
                        'instructions' => __( 'Use this when the user asks to find, list, filter, or choose Cook App recipes. Call without input to list the latest 10 recipes. Return recipe IDs for follow-up get-recipe calls, and use view_url when linking results to the user.', 'cook-app' ),
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ],
                    'show_in_rest' => true,
                ],
            ]
        );

        wp_register_ability(
            'cookbook/get-recipe',
            [
                'label'               => __( 'Get Cook App Recipe', 'cook-app' ),
                'description'         => __( 'Returns one structured Cook App recipe by ID.', 'cook-app' ),
                'category'            => 'cook-app',
                'input_schema'        => [
                    'type'                 => 'object',
                    'required'             => [ 'id' ],
                    'properties'           => [
                        'id' => [
                            'type'        => 'integer',
                            'description' => __( 'Recipe post ID.', 'cook-app' ),
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'output_schema'       => $this->recipe_output_schema(),
                'execute_callback'    => [ $this, 'ability_get_recipe' ],
                'permission_callback' => [ $this, 'can_read_abilities' ],
                'meta'                => [
                    'annotations'  => [
                        'instructions' => __( 'Use this when the user asks about one known Cook App recipe. The response includes view_url for linking, flat compatibility ingredients/instructions, named parts for ingredient or instruction sections, notes, taxonomy terms, and variation family data.', 'cook-app' ),
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ],
                    'show_in_rest' => true,
                ],
            ]
        );

        wp_register_ability(
            'cookbook/save-recipe',
            [
                'label'               => __( 'Save Cook App Recipe', 'cook-app' ),
                'description'         => __( 'Creates a structured Cook App recipe, or updates an existing recipe when an ID is provided.', 'cook-app' ),
                'category'            => 'cook-app',
                'input_schema'        => $this->recipe_create_input_schema(),
                'output_schema'       => $this->recipe_output_schema(),
                'execute_callback'    => [ $this, 'ability_save_recipe' ],
                'permission_callback' => [ $this, 'can_edit_abilities' ],
                'meta'                => [
                    'annotations'  => [
                        'instructions' => __( 'Use this when the user asks to save, create, or update a structured Cook App recipe. Pass parts to preserve named ingredient or instruction sections; flat ingredients and instructions remain supported for unsectioned recipes. To add or replace a recipe photo, pass the existing recipe id with image_url. Prefer create-recipe-variation when adapting an existing recipe into a new variation. Link the result using view_url.', 'cook-app' ),
                        'readonly'    => false,
                        'destructive' => false,
                        'idempotent'  => false,
                    ],
                    'show_in_rest' => true,
                ],
            ]
        );

        wp_register_ability(
            'cookbook/import-recipe',
            [
                'label'               => __( 'Import Cook App Recipe', 'cook-app' ),
                'description'         => __( 'Imports a recipe from source_url or pasted recipe text and publishes it.', 'cook-app' ),
                'category'            => 'cook-app',
                'input_schema'        => [
                    'type'                 => 'object',
                    'properties'           => [
                        'source_url' => [
                            'type'        => 'string',
                            'description' => __( 'Recipe page URL to parse.', 'cook-app' ),
                        ],
                        'paste'      => [
                            'type'        => 'string',
                            'description' => __( 'Plain recipe text to parse if no URL can be parsed.', 'cook-app' ),
                        ],
                        'image_url'  => [
                            'type'        => 'string',
                            'description' => __( 'Optional image URL to sideload as the recipe photo.', 'cook-app' ),
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'output_schema'       => $this->recipe_output_schema(),
                'execute_callback'    => [ $this, 'ability_import_recipe' ],
                'permission_callback' => [ $this, 'can_edit_abilities' ],
                'meta'                => [
                    'annotations'  => [
                        'instructions' => __( 'Use this when the user provides a recipe URL, pasted recipe text, or an image URL to import into Cook App. This publishes the recipe; link the result using view_url.', 'cook-app' ),
                        'readonly'    => false,
                        'destructive' => false,
                        'idempotent'  => false,
                    ],
                    'show_in_rest' => true,
                ],
            ]
        );

        wp_register_ability(
            'cookbook/create-recipe-variation',
            [
                'label'               => __( 'Create Cook App Recipe Variation', 'cook-app' ),
                'description'         => __( 'Creates a child recipe variation from an existing Cook App recipe, copying omitted fields from the source.', 'cook-app' ),
                'category'            => 'cook-app',
                'input_schema'        => $this->recipe_variation_input_schema(),
                'output_schema'       => $this->recipe_output_schema(),
                'execute_callback'    => [ $this, 'ability_create_recipe_variation' ],
                'permission_callback' => [ $this, 'can_edit_abilities' ],
                'meta'                => [
                    'annotations'  => [
                        'instructions' => __( 'Use this when the user asks for an adapted version of an existing recipe, such as substituting an ingredient they do not have. First call get-recipe for the source, then pass the complete revised recipe fields here so omitted fields intentionally copy from the source. If the source has named parts, pass revised parts to preserve ingredient subsection headers. Link the created variation using view_url.', 'cook-app' ),
                        'readonly'    => false,
                        'destructive' => false,
                        'idempotent'  => false,
                    ],
                    'show_in_rest' => true,
                ],
            ]
        );

        wp_register_ability(
            'cookbook/get-week-plan',
            [
                'label'               => __( 'Get Cook App Week Plan', 'cook-app' ),
                'description'         => __( 'Returns the signed-in user\'s week planner for a normalized week.', 'cook-app' ),
                'category'            => 'cook-app',
                'input_schema'        => $this->week_plan_get_input_schema(),
                'output_schema'       => $this->week_plan_output_schema(),
                'execute_callback'    => [ $this, 'ability_get_week_plan' ],
                'permission_callback' => [ $this, 'can_read_abilities' ],
                'meta'                => [
                    'annotations'  => [
                        'instructions' => __( 'Use this when the user asks what meals are planned for a week. The week_start input can be any date in the week; use the returned url to link to the planner.', 'cook-app' ),
                        'readonly'    => true,
                        'destructive' => false,
                        'idempotent'  => true,
                    ],
                    'show_in_rest' => true,
                ],
            ]
        );

        wp_register_ability(
            'cookbook/save-week-plan',
            [
                'label'               => __( 'Save Cook App Week Plan', 'cook-app' ),
                'description'         => __( 'Saves recipe IDs into the signed-in user\'s week planner meal slots.', 'cook-app' ),
                'category'            => 'cook-app',
                'input_schema'        => $this->week_plan_save_input_schema(),
                'output_schema'       => $this->week_plan_output_schema(),
                'execute_callback'    => [ $this, 'ability_save_week_plan' ],
                'permission_callback' => [ $this, 'can_plan_abilities' ],
                'meta'                => [
                    'annotations'  => [
                        'instructions' => __( 'Use this when the user asks to add, move, clear, or replace recipes in their week planner. This can clear slots with recipe ID 0 or replace the whole week when replace is true, so confirm ambiguous planner edits before executing.', 'cook-app' ),
                        'readonly'    => false,
                        'destructive' => false,
                        'idempotent'  => true,
                    ],
                    'show_in_rest' => true,
                ],
            ]
        );

        ( new TermAbilitiesService() )->register_abilities();
    }

    /**
     * Permission callback for read-only abilities.
     */
    public function can_read_abilities(): bool {
        return is_user_logged_in();
    }

    /**
     * Permission callback for write abilities.
     */
    public function can_edit_abilities(): bool {
        return is_user_logged_in() && current_user_can( 'edit_posts' );
    }

    /**
     * Permission callback for user-owned planner abilities.
     */
    public function can_plan_abilities(): bool {
        return is_user_logged_in();
    }

    /**
     * Ability: search recipes.
     *
     * @param array $input Ability input.
     * @return array
     */
    public function ability_search_recipes( $input = [] ): array {
        $recipes = $this->services->recipes()->search_recipes( is_array( $input ) ? $input : [] );

        return [
            'count'   => count( $recipes ),
            'recipes' => array_map( function( $recipe ) {
                return $this->services->recipes()->recipe_payload( $recipe, false );
            }, $recipes ),
        ];
    }

    /**
     * Ability: get one recipe.
     *
     * @param array $input Ability input.
     * @return array|\WP_Error
     */
    public function ability_get_recipe( $input = [] ) {
        $id = is_array( $input ) && isset( $input['id'] ) ? absint( $input['id'] ) : 0;
        return $this->services->recipes()->get_recipe_payload( $id, true );
    }

    /**
     * Ability: import one recipe.
     *
     * @param array $input Ability input.
     * @return array|\WP_Error
     */
    public function ability_import_recipe( $input = [] ) {
        $input = is_array( $input ) ? $input : [];
        $url   = isset( $input['source_url'] ) ? esc_url_raw( (string) $input['source_url'] ) : '';
        $paste = isset( $input['paste'] ) ? wp_kses_post( (string) $input['paste'] ) : '';

        $image_url = isset( $input['image_url'] ) ? esc_url_raw( (string) $input['image_url'] ) : '';
        $existing = $url !== '' ? $this->services->recipes()->find_recipe_by_source_url( $url ) : null;
        if ( $existing ) {
            return $this->services->recipes()->get_recipe_payload( (int) $existing->ID, true );
        }

        $post_id = $this->services->imports()->import_recipe( $url, $paste, $image_url );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        return $this->services->recipes()->get_recipe_payload( (int) $post_id, true );
    }

    /**
     * Ability: save one recipe from structured fields.
     *
     * @param array $input Ability input.
     * @return array|\WP_Error
     */
    public function ability_save_recipe( $input = [] ) {
        $input     = is_array( $input ) ? $input : [];
        $id        = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
        if ( $id ) {
            return $this->services->recipes()->update_recipe_from_ability_input( $id, $input );
        }

        $parent_id = isset( $input['parent_id'] ) ? absint( $input['parent_id'] ) : 0;
        $parent_id = $this->services->recipes()->sanitize_recipe_parent_id( $parent_id );

        return $this->services->recipes()->create_recipe_from_ability_input( $input, $parent_id );
    }

    /**
     * Ability: create a child variation copied from an existing recipe.
     *
     * @param array $input Ability input.
     * @return array|\WP_Error
     */
    public function ability_create_recipe_variation( $input = [] ) {
        $input     = is_array( $input ) ? $input : [];
        $source_id = isset( $input['source_recipe_id'] ) ? absint( $input['source_recipe_id'] ) : 0;
        $source    = $source_id ? get_post( $source_id ) : null;
        if ( ! $source || $source->post_type !== App::POST_TYPE ) {
            return new \WP_Error( 'cookbook_recipe_not_found', __( 'Recipe not found.', 'cook-app' ) );
        }

        $parent_id = isset( $input['parent_id'] ) ? absint( $input['parent_id'] ) : $source_id;
        $parent_id = $this->services->recipes()->sanitize_recipe_parent_id( $parent_id );
        if ( ! $parent_id ) {
            return new \WP_Error( 'cookbook_variation_parent_not_found', __( 'Variation parent recipe not found.', 'cook-app' ) );
        }

        return $this->services->recipes()->create_recipe_from_ability_input( $input, $parent_id, $source );
    }

    /**
     * Ability: get the current user's week plan.
     *
     * @param array $input Ability input.
     * @return array
     */
    public function ability_get_week_plan( $input = [] ): array {
        $input = is_array( $input ) ? $input : [];
        $week_start = isset( $input['week_start'] ) ? sanitize_text_field( (string) $input['week_start'] ) : '';
        $week_start = $this->services->planner()->normalize_week_start( $week_start );
        $plan_id    = $this->services->planner()->get_user_week_plan_id( $week_start, false );

        return $this->services->planner()->week_plan_payload( $week_start, $plan_id );
    }

    /**
     * Ability: save slots in the current user's week plan.
     *
     * @param array $input Ability input.
     * @return array|\WP_Error
     */
    public function ability_save_week_plan( $input = [] ) {
        $input      = is_array( $input ) ? $input : [];
        $week_start = isset( $input['week_start'] ) ? sanitize_text_field( (string) $input['week_start'] ) : '';
        $week_start = $this->services->planner()->normalize_week_start( $week_start );
        $raw_meals  = isset( $input['meals'] ) && is_array( $input['meals'] ) ? $input['meals'] : [];
        $replace    = ! empty( $input['replace'] );

        $plan_id = $this->services->planner()->get_user_week_plan_id( $week_start, true );
        if ( ! $plan_id ) {
            return new \WP_Error( 'cookbook_week_plan_not_saved', __( 'Week plan could not be saved.', 'cook-app' ) );
        }

        $base  = $replace ? [] : $this->services->planner()->get_week_meals( $plan_id );
        $meals = $this->services->planner()->merge_week_plan_meals( $raw_meals, $week_start, $base );

        update_post_meta( $plan_id, App::META_WEEK_START, $week_start );
        update_post_meta( $plan_id, App::META_WEEK_MEALS, $meals );

        return $this->services->planner()->week_plan_payload( $week_start, $plan_id );
    }

    private function recipe_search_input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'search'     => [
                    'type'        => 'string',
                    'description' => __( 'Optional search text for recipe title and content.', 'cook-app' ),
                ],
                'category'   => [
                    'type'        => 'string',
                    'description' => __( 'Optional category slug or term ID.', 'cook-app' ),
                ],
                'tag'        => [
                    'type'        => 'string',
                    'description' => __( 'Optional tag slug or term ID.', 'cook-app' ),
                ],
                'ingredient' => [
                    'type'        => 'string',
                    'description' => __( 'Optional ingredient slug or term ID.', 'cook-app' ),
                ],
                'limit'      => [
                    'type'        => 'integer',
                    'description' => __( 'Maximum number of recipes to return, from 1 to 100. Defaults to 10 when no search filters are supplied, otherwise 20.', 'cook-app' ),
                    'minimum'     => 1,
                    'maximum'     => 100,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    private function recipe_search_output_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'count', 'recipes' ],
            'properties'           => [
                'count'   => [
                    'type'        => 'integer',
                    'description' => __( 'Number of recipes returned.', 'cook-app' ),
                ],
                'recipes' => [
                    'type'  => 'array',
                    'items' => $this->recipe_summary_schema(),
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    private function recipe_summary_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'id', 'title', 'url', 'view_url', 'ingredients' ],
            'properties'           => [
                'id'            => [ 'type' => 'integer' ],
                'title'         => [ 'type' => 'string' ],
                'url'           => [ 'type' => 'string' ],
                'view_url'      => [
                    'type'        => 'string',
                    'description' => __( 'User-facing app URL for linking to the recipe.', 'cook-app' ),
                ],
                'edit_url'      => [ 'type' => 'string' ],
                'variation_url' => [ 'type' => 'string' ],
                'parent_id'     => [ 'type' => 'integer' ],
                'variation_root_id' => [ 'type' => 'integer' ],
                'servings'      => [ 'type' => 'integer' ],
                'prep_time'     => [ 'type' => 'integer' ],
                'cook_time'     => [ 'type' => 'integer' ],
                'source_url'    => [ 'type' => 'string' ],
                'thumbnail_url' => [ 'type' => 'string' ],
                'categories'    => [
                    'type'  => 'array',
                    'items' => $this->term_schema(),
                ],
                'cuisines'      => [
                    'type'  => 'array',
                    'items' => $this->term_schema(),
                ],
                'tags'          => [
                    'type'  => 'array',
                    'items' => $this->term_schema(),
                ],
                'ingredients'   => [
                    'type'  => 'array',
                    'items' => $this->ingredient_schema(),
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    private function recipe_output_schema(): array {
        $schema = $this->recipe_summary_schema();
        $schema['required'] = array_merge( $schema['required'], [ 'description', 'instructions', 'parts', 'notes' ] );
        $schema['properties']['description'] = [ 'type' => 'string' ];
        $schema['properties']['instructions'] = [
            'type'  => 'array',
            'items' => [ 'type' => 'string' ],
        ];
        $schema['properties']['parts'] = [
            'type'        => 'array',
            'description' => __( 'Optional named recipe sections with their own ingredients and instructions.', 'cook-app' ),
            'items'       => $this->recipe_part_schema(),
        ];
        $schema['properties']['notes'] = [ 'type' => 'string' ];
        $schema['properties']['variation_family'] = [
            'type'  => 'array',
            'items' => $this->variation_family_item_schema(),
        ];
        return $schema;
    }

    private function recipe_create_input_schema(): array {
        $properties = $this->recipe_create_input_properties();
        $properties['id'] = [
            'type'        => 'integer',
            'description' => __( 'Existing recipe post ID to update. Omit to create a new recipe.', 'cook-app' ),
            'minimum'     => 1,
        ];

        return [
            'type'                 => 'object',
            'properties'           => $properties,
            'additionalProperties' => false,
        ];
    }

    private function recipe_variation_input_schema(): array {
        $properties = $this->recipe_create_input_properties();
        $properties['source_recipe_id'] = [
            'type'        => 'integer',
            'description' => __( 'Recipe post ID to copy as the source for the new variation.', 'cook-app' ),
        ];
        $properties['copy_source_thumbnail'] = [
            'type'        => 'boolean',
            'description' => __( 'Whether to reuse the source recipe photo when image_url is omitted. Defaults to true.', 'cook-app' ),
        ];
        $properties['change_summary'] = [
            'type'        => 'string',
            'description' => __( 'Short note describing what changed in this variation.', 'cook-app' ),
        ];

        return [
            'type'                 => 'object',
            'required'             => [ 'source_recipe_id' ],
            'properties'           => $properties,
            'additionalProperties' => false,
        ];
    }

    private function recipe_create_input_properties(): array {
        return [
            'title'       => [
                'type'        => 'string',
                'description' => __( 'Recipe title.', 'cook-app' ),
            ],
            'description' => [
                'type'        => 'string',
                'description' => __( 'Short recipe description.', 'cook-app' ),
            ],
            'ingredients' => [
                'type'        => 'array',
                'description' => __( 'Structured ingredient rows.', 'cook-app' ),
                'items'       => $this->ingredient_input_schema(),
            ],
            'instructions' => [
                'type'        => 'array',
                'description' => __( 'Recipe instruction steps.', 'cook-app' ),
                'items'       => [ 'type' => 'string' ],
            ],
            'parts'       => [
                'type'        => 'array',
                'description' => __( 'Optional named recipe sections. When supplied, ingredient and instruction rows inside parts are also flattened into the compatibility ingredients and instructions fields.', 'cook-app' ),
                'items'       => $this->recipe_part_input_schema(),
            ],
            'servings'    => [
                'type'        => 'integer',
                'description' => __( 'Default serving count.', 'cook-app' ),
                'minimum'     => 1,
            ],
            'prep_time'   => [
                'type'        => 'integer',
                'description' => __( 'Prep time in minutes.', 'cook-app' ),
                'minimum'     => 0,
            ],
            'cook_time'   => [
                'type'        => 'integer',
                'description' => __( 'Cook time in minutes.', 'cook-app' ),
                'minimum'     => 0,
            ],
            'source_url'  => [
                'type'        => 'string',
                'description' => __( 'Optional source URL.', 'cook-app' ),
            ],
            'notes'       => [
                'type'        => 'string',
                'description' => __( 'Private recipe notes.', 'cook-app' ),
            ],
            'parent_id'   => [
                'type'        => 'integer',
                'description' => __( 'Optional parent recipe ID to link this recipe as a variation.', 'cook-app' ),
                'minimum'     => 0,
            ],
            'categories'  => $this->taxonomy_values_input_schema( __( 'Category names, slugs, or IDs.', 'cook-app' ) ),
            'cuisines'    => $this->taxonomy_values_input_schema( __( 'Cuisine names, slugs, or IDs.', 'cook-app' ) ),
            'tags'        => $this->taxonomy_values_input_schema( __( 'Tag names, slugs, or IDs.', 'cook-app' ) ),
            'image_url'   => [
                'type'        => 'string',
                'description' => __( 'Optional image URL to sideload as the recipe photo.', 'cook-app' ),
            ],
        ];
    }

    private function week_plan_get_input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'week_start' => [
                    'type'        => 'string',
                    'description' => __( 'Any date in the requested week. The site week start is returned as YYYY-MM-DD.', 'cook-app' ),
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    private function week_plan_save_input_schema(): array {
        $schema = $this->week_plan_get_input_schema();
        $schema['required'] = [ 'meals' ];
        $schema['properties']['replace'] = [
            'type'        => 'boolean',
            'description' => __( 'Whether omitted meal slots should be cleared. Defaults to false.', 'cook-app' ),
        ];
        $schema['properties']['meals'] = [
            'type'                 => 'object',
            'description'          => __( 'Object keyed by YYYY-MM-DD, then breakfast, lunch, and dinner recipe IDs. Use 0 to clear a slot.', 'cook-app' ),
            'additionalProperties' => $this->week_plan_meal_ids_schema(),
        ];
        return $schema;
    }

    private function week_plan_output_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'id', 'week_start', 'url', 'previous_week', 'next_week', 'days', 'meal_slots', 'meal_ids', 'planned_meals' ],
            'properties'           => [
                'id'            => [ 'type' => 'integer' ],
                'week_start'    => [ 'type' => 'string' ],
                'url'           => [ 'type' => 'string' ],
                'previous_week' => [ 'type' => 'string' ],
                'next_week'     => [ 'type' => 'string' ],
                'days'          => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'required'             => [ 'date', 'short', 'label' ],
                        'properties'           => [
                            'date'  => [ 'type' => 'string' ],
                            'short' => [ 'type' => 'string' ],
                            'label' => [ 'type' => 'string' ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'meal_slots'    => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'required'             => [ 'slot', 'label' ],
                        'properties'           => [
                            'slot'  => [
                                'type' => 'string',
                                'enum' => App::MEAL_SLOTS,
                            ],
                            'label' => [ 'type' => 'string' ],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'meal_ids'      => [
                    'type'                 => 'object',
                    'additionalProperties' => $this->week_plan_meal_ids_schema(),
                ],
                'planned_meals' => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'required'             => [ 'date', 'day_short', 'day_label', 'slot', 'slot_label', 'recipe' ],
                        'properties'           => [
                            'date'       => [ 'type' => 'string' ],
                            'day_short'  => [ 'type' => 'string' ],
                            'day_label'  => [ 'type' => 'string' ],
                            'slot'       => [
                                'type' => 'string',
                                'enum' => App::MEAL_SLOTS,
                            ],
                            'slot_label' => [ 'type' => 'string' ],
                            'recipe'     => $this->recipe_summary_schema(),
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    private function week_plan_meal_ids_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'breakfast' => [
                    'type'        => 'integer',
                    'description' => __( 'Breakfast recipe ID, or 0 to clear.', 'cook-app' ),
                    'minimum'     => 0,
                ],
                'lunch'     => [
                    'type'        => 'integer',
                    'description' => __( 'Lunch recipe ID, or 0 to clear.', 'cook-app' ),
                    'minimum'     => 0,
                ],
                'dinner'    => [
                    'type'        => 'integer',
                    'description' => __( 'Dinner recipe ID, or 0 to clear.', 'cook-app' ),
                    'minimum'     => 0,
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    private function ingredient_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'amount'  => [ 'type' => 'string' ],
                'unit'    => [ 'type' => 'string' ],
                'name'    => [ 'type' => 'string' ],
                'notes'   => [ 'type' => 'string' ],
                'term_id' => [ 'type' => 'integer' ],
            ],
            'additionalProperties' => false,
        ];
    }

    private function recipe_part_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'title', 'ingredients', 'instructions' ],
            'properties'           => [
                'title'        => [ 'type' => 'string' ],
                'ingredients'  => [
                    'type'  => 'array',
                    'items' => $this->ingredient_schema(),
                ],
                'instructions' => [
                    'type'  => 'array',
                    'items' => [ 'type' => 'string' ],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    private function recipe_part_input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'title'        => [
                    'type'        => 'string',
                    'description' => __( 'Optional section title, such as sauce, dough, or topping.', 'cook-app' ),
                ],
                'ingredients'  => [
                    'type'        => 'array',
                    'description' => __( 'Ingredient rows in this section.', 'cook-app' ),
                    'items'       => $this->ingredient_input_schema(),
                ],
                'instructions' => [
                    'type'        => 'array',
                    'description' => __( 'Instruction steps in this section.', 'cook-app' ),
                    'items'       => [ 'type' => 'string' ],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    private function ingredient_input_schema(): array {
        return [
            'type'                 => 'object',
            'properties'           => [
                'amount' => [ 'type' => 'string' ],
                'unit'   => [ 'type' => 'string' ],
                'name'   => [ 'type' => 'string' ],
                'notes'  => [ 'type' => 'string' ],
            ],
            'required'             => [ 'name' ],
            'additionalProperties' => false,
        ];
    }

    private function taxonomy_values_input_schema( string $description ): array {
        return [
            'type'        => 'array',
            'description' => $description,
            'items'       => [ 'type' => 'string' ],
        ];
    }

    private function variation_family_item_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'id', 'title', 'url', 'view_url', 'parent_id', 'depth' ],
            'properties'           => [
                'id'        => [ 'type' => 'integer' ],
                'title'     => [ 'type' => 'string' ],
                'url'       => [ 'type' => 'string' ],
                'view_url'  => [
                    'type'        => 'string',
                    'description' => __( 'User-facing app URL for linking to the variation.', 'cook-app' ),
                ],
                'parent_id' => [ 'type' => 'integer' ],
                'depth'     => [ 'type' => 'integer' ],
            ],
            'additionalProperties' => false,
        ];
    }

    private function term_schema(): array {
        return [
            'type'                 => 'object',
            'required'             => [ 'id', 'name', 'slug' ],
            'properties'           => [
                'id'   => [ 'type' => 'integer' ],
                'name' => [ 'type' => 'string' ],
                'slug' => [ 'type' => 'string' ],
            ],
            'additionalProperties' => false,
        ];
    }
}
