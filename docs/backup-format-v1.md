# Cook App backup format, version 1

This document defines the Cook App extension used in JSON-LD cookbook exports. The [example export](examples/cook-app-backup-v1.json) contains a recipe, a shopping list and item, a meal plan, cooking history, taxonomy entries, and preferences. The implementation is [BackupService](../src/BackupService.php).

## Namespace and context

The Cook App namespace is `https://github.com/akirk/cook-app#`. The prefix `cookApp` maps to that namespace; for example, `cookApp:backup` expands to `https://github.com/akirk/cook-app#backup`. Inside the backup section, an embedded `@vocab` maps unqualified property names to the same namespace. Thus `posts` expands to `https://github.com/akirk/cook-app#posts`.

```json
{
  "@context": {
    "@vocab": "https://schema.org/",
    "cookApp": "https://github.com/akirk/cook-app#"
  },
  "@graph": [],
  "cookApp:backup": {
    "@context": { "@vocab": "https://github.com/akirk/cook-app#" },
    "format": "cook-app-backup",
    "version": 1,
    "posts": [],
    "terms": [],
    "preferences": { "units": "metric", "household": [] }
  }
}
```

The namespace identifies terms; it is not a remote context endpoint. Cook App's context mappings are embedded in each export. This repository document publishes their meanings and the version 1 storage contract. No separate vocabulary service is required.

## Document structure

`@graph` contains schema.org `Recipe` nodes. These expose `name`, `description`, `recipeYield`, `prepTime`, `cookTime`, `recipeIngredient`, `recipeInstructions`, `recipeCategory`, `recipeCuisine`, and `keywords`, plus optional `image` and `url`. Times are ISO 8601 durations in minutes. Instructions use `HowToStep`, or named `HowToSection` nodes when recipe parts have instructions. Recipe identifiers have the form `urn:cook-app:recipe:<original-post-id>`.

`cookApp:backup` contains the application-specific restore records:

| Term | JSON value and meaning |
| --- | --- |
| `backup` | Object containing the Cook App restore payload. |
| `format` | Required string `cook-app-backup`. |
| `version` | Required integer `1`; other versions are rejected. |
| `posts` | Required array of post records, including recipes and other cookbook entries. |
| `terms` | Required array of taxonomy records. |
| `preferences` | Optional object with `units` and `household`; exports include it. |
| `units` | String `metric` or `imperial`. |
| `household` | Array of original ingredient term IDs kept as household ingredients. |

The importer reads the literal keys shown here. It does not perform general JSON-LD expansion or accept arbitrary alternate prefixes for `cookApp:backup`. A full backup requires each parsed recipe to have a unique, nonempty `@id` and exactly one corresponding recipe post record.

## Post records

| Term | JSON value and meaning |
| --- | --- |
| `id` | Required positive integer, unique among posts in this file; original post ID. |
| `type` | Required string: `cb-recipes`, `cb-shopping-list`, `cb-week-plan`, or `cb-cooked-entry`. |
| `recipe` | Required for `cb-recipes`: string matching a Recipe node's `@id`. |
| `title` | String for non-recipe entries; recipes obtain it from the graph's `name`. |
| `content` | String containing post content; recipes obtain it from the graph's `description`. |
| `status` | String: `publish`, `private`, `draft`, or `pending`; shopping entries also support `cb_checked`. Unsupported values restore as `publish`. |
| `parent` | Integer original parent post ID; `0` means no parent. Shopping items are child posts of a shopping list, using the same post type. |
| `date` | String in `YYYY-MM-DD HH:MM:SS` format, representing WordPress local post time. |
| `meta` | Required object of allowlisted metadata keys and their stored values. Empty exports can encode this as `[]`. |
| `terms` | Required object mapping taxonomy names to arrays of original term IDs; empty exports can encode this as `[]`. |
| `image_url` | String photo URL for non-recipe records. Recipe records use the graph's `image`. Image files are not embedded. |

Exports include `status`, `parent`, and `date`. Recipe exports omit `title`, `content`, and `image_url` from the post record to avoid duplicating the graph. Exact recipe metadata remains in `meta` and is used when restoring a full backup rather than reconstructed from the graph.

### Metadata vocabulary

These property names are the stored WordPress keys. Under the backup context they also expand into the Cook App namespace. Unknown metadata keys are rejected. Array-valued metadata must be arrays; other metadata must have scalar JSON values. Nested recipe instructions must be arrays of strings.

| Property | Meaning / stored shape |
| --- | --- |
| `_recipe_servings` | Serving count, commonly a numeric string. |
| `_recipe_prep_time`, `_recipe_cook_time` | Preparation and cooking time in minutes. |
| `_recipe_ingredients` | Array of ingredient objects. |
| `_recipe_instructions` | Array of instruction strings. |
| `_recipe_parts` | Array of sections with `title`, `ingredients`, and `instructions`. |
| `_recipe_source_url` | Original recipe URL. |
| `_recipe_notes` | Recipe notes, including Markdown. |
| `_cookbook_shopping_items` | Array of shopping item objects. |
| `_cookbook_shopping_item_amount`, `_cookbook_shopping_item_unit`, `_cookbook_shopping_item_notes` | Amount, unit, and notes for a shopping item post. |
| `_cookbook_shopping_item_source_recipe_id` | Original source recipe post ID. |
| `_cookbook_shopping_item_source_recipe_title` | Source recipe title retained for display. |
| `_cookbook_shopping_item_source_recipes` | Array of source recipe objects with `id` and `title`. |
| `_cookbook_shopping_household_reminders` | Array of household reminder objects. |
| `_cookbook_week_start` | Meal plan's starting date. |
| `_cookbook_week_meals` | Object mapping dates to meal-slot objects whose values are integer recipe IDs; `0` means an empty slot. |
| `_cookbook_cooked_recipe_id` | Original recipe post ID for a cooking history entry. |
| `_cookbook_cooked_date`, `_cookbook_cooked_note` | Cooking date and note. |

Ingredient and shopping objects can contain `amount`, `unit`, `name`, `notes`, `term_id`, and `term_ids`. The first four describe the ingredient; `term_id` and `term_ids` refer to ingredient taxonomy entries. Shopping objects can also use `source_recipe_id` and `source_recipes` (objects with `id` and `title`); nested `recipe_id` is treated as a recipe reference. These arrays preserve stored application data rather than defining separate schema.org entities.

## Taxonomy records

Each record has a required positive integer `id` (unique among terms), a `taxonomy` string, a nonempty `name` string, and an integer `parent` (`0` for no parent). Supported taxonomies are `recipe_category`, `recipe_cuisine`, `recipe_tag`, and `recipe_ingredient`. Exports include all terms in these taxonomies, including ancestors and household-only terms.

## References and restore behavior

Original IDs belong to the file, not the destination installation. Posts and terms have separate ID spaces. Restore creates new posts under the signed-in user, then remaps post parents, taxonomy assignments, recipe references in metadata, meal slots, ingredient references, and household preferences. Existing taxonomy terms are reused by name within their taxonomy; new terms are created as needed. Parent relationships cannot contain cycles or cross post types / taxonomies. Taxonomy assignments must point to included terms in the matching taxonomy.

Missing parent references become `0`. Missing recipe references in metadata become `0`; missing ingredient references become `0` or are removed from `term_ids`. Unresolved household IDs are omitted. The required `recipe` graph link is validated and cannot be missing. Strings are sanitized during restore, so the backup preserves stored metadata for transfer but is not a byte-for-byte WordPress database archive.

Restore adds copies without replacing existing posts, and repeated imports create duplicates. Unit preference is replaced; household ingredients are merged. Newly created posts and terms are removed if content restoration fails. Photo URLs are retained without downloading images. Files must be at most 10,485,760 bytes (10 MiB).

Without `cookApp:backup`, the importer accepts schema.org Recipe JSON-LD as a portable recipe import and derives recipe metadata through the recipe parser. Such files do not restore the application-specific lists, plans, history, or preferences.

## Versioning

`version` identifies the backup payload contract independently of the plugin release. This document describes version 1. A future incompatible contract should use a new version and retain this document and example so older exports remain understandable. Consumers should check both `format` and `version` before restoring.
