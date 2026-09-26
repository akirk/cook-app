# Cook App

- Contributors: akirk
- Tags: recipes, cooking, meal-planner, shopping-list, wp-app
- Requires at least: 6.0
- Requires PHP: 7.4
- Tested up to: 7.1
- Stable tag: 1.0.0
- License: GPL-2.0-or-later
- License URI: https://www.gnu.org/licenses/gpl-2.0.html

A personal cookbook for WordPress: store, import, categorize, scale, plan and shop from your own recipes.

## Description

[Try Cook App in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/akirk/cook-app/main/blueprint.json) · [Try it with demo data](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/akirk/cook-app/main/demo.json) · [Try it in OpenStation](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/akirk/cook-app/main/blueprint-openstation.json), the same app opened in desktop mode with the [OpenStation](https://github.com/WordPress/openstation) plugin.

Cook App gives your WordPress site a private recipe app at `/cook-app/`. Recipes are stored as WordPress content, with structured ingredients, instructions, categories, cuisines, tags, photos, serving sizes, prep and cook times, source URLs, and notes.

It is built for day-to-day cooking rather than public recipe blogging: save recipes from the web, scale portions while you cook, convert between metric and imperial units, track what you have cooked, plan meals for the week, and build a shopping list from the recipes you choose.

### Recipe storage

Each recipe is stored as a `cb-recipes` custom post type with recipe taxonomies for category, cuisine, tag, and ingredient. Shopping lists, week plans, and cooking history are stored as user-authored WordPress content so they remain tied to the signed-in user.

### Import recipes from the web

Paste a recipe URL and Cook App looks for machine-readable schema.org `Recipe` JSON-LD. It supports recipe sections, ingredient groups, instruction sections, and recipe images where the structured data exposes them. If automatic parsing is not enough, paste recipe text and preview the detected title, ingredients, and instructions before saving.

Recipe parsers are extensible through the `cook_app_load_recipe_parsers` action. Optional parser plugins can add support for other source formats without changing Cook App.

Photos can be sideloaded into the WordPress media library and used as the recipe's featured image.

### Cook with scaled ingredients

Recipe pages include live portion scaling. Enter the number of servings you want and Cook App rescales parsed ingredient amounts immediately. Unit display can be switched between metric and imperial globally in settings or per recipe while viewing it.

### Cooking mode and history

Cooking mode gives you a focused view with a large active step, step navigation, ingredient and instruction checkoffs, browser-saved progress, and screen wake lock where supported. When you finish, Cook App can save that you cooked the recipe. You can also add dated "cooked this" entries from recipe pages.

### Meal planning and shopping

Use the week planner to place recipes into breakfast, lunch, and dinner slots. Add a recipe's scaled ingredients, or a full week's planned recipes, to your personal shopping list. The shopping list combines compatible duplicate items and includes a focused shop mode with large tap targets, undo, and quick add.

### Ingredients and variations

Cook App can help you find recipes by ingredients you have on hand, allowing a configurable number of missing ingredients. Ingredient tools let you browse ingredient pages, replace an ingredient from a recipe view, and merge, group, or rename ingredient terms.

Recipe variations let you link adapted recipes to their originals. Use "Edit as variation" to create a prefilled child recipe from an existing one.

### Browser extension import

Cook App integrates with the [Friends browser extension](https://github.com/akirk/browser-extension). After authorizing the extension for your site, use the "Save as Recipe" action on a recipe page and Cook App will import the current page HTML.

### AI and automation

When the WordPress Abilities API is available, Cook App registers abilities for recipes, week plans, and managing ingredients, categories, cuisines, and tags. This allows compatible AI assistants and automations to work with your saved recipes in a structured way.

### Features

- Store personal recipes in WordPress
- Import recipes from URLs or pasted recipe text
- Sideload recipe photos into the media library
- Categorize by category, cuisine, tag, and ingredient
- Support structured ingredients, instructions, and named recipe sections
- Scale servings live on recipe pages
- Convert between metric and imperial units
- Use cooking mode with step navigation and checkoffs
- Track cooking history
- Build a personal shopping list from scaled recipes
- Plan breakfast, lunch, and dinner for the week
- Find recipes by ingredients on hand
- Merge, group, rename, and replace ingredients
- Create and browse recipe variations
- Import from the Friends browser extension
- Integrate with Static Archive for portable recipe exports
- Translatable with the `cook-app` text domain

## Installation

1. Upload the `cook-app` directory to `wp-content/plugins/`
2. Activate the plugin
3. Visit `/cook-app/` on your site
4. Add a recipe manually or open `/cook-app/import` to import one from the web

## Frequently Asked Questions

### Where do I manage recipes?

Visit `/cook-app/` on your site after activating the plugin. The app uses WordPress users and permissions, but it is separate from your active theme.

### Can I import recipes from any website?

Cook App imports pages that expose schema.org `Recipe` JSON-LD. Optional parser plugins can support additional formats. If a site cannot be parsed automatically, you can paste the recipe text and use the preview before saving.

### Does Cook App publish my recipes publicly?

Cook App is designed as a personal app. Recipes are stored in WordPress and viewed through the `/cook-app/` app by users with the required permissions.

### Can I switch between metric and imperial units?

Yes. Set your default in `/cook-app/settings`, or switch units while viewing a recipe.

### Can I make a shopping list from a weekly meal plan?

Yes. Add recipes to the week planner, then add the planned recipes' ingredients to your shopping list.

### Does Cook App work with Static Archive?

Yes. If the Static Archive plugin is active, Cook App can opt recipes into the archive and render structured recipe content as HTML and Markdown.

### Is Cook App translatable?

Yes. The text domain is `cook-app`, and a German translation is included.

## Screenshots

1. A recipe page in Cook App, with its photo and the app navigation for recipes, shopping list, planner, cooking history and ingredients.
2. The same recipe on a phone, where the navigation wraps and the photo, times and servings stack.

## Changelog

### 1.0.0
- Initial release.

## Developer Notes

### App URLs

| URL | Page |
| --- | --- |
| `/cook-app/` | All recipes |
| `/cook-app/new` | Create a recipe |
| `/cook-app/import` | Import a recipe from a URL or pasted text |
| `/cook-app/shopping-list` | Personal shopping list |
| `/cook-app/planner` | Weekly meal planner |
| `/cook-app/cooked` | Cooking history |
| `/cook-app/by-ingredients` | Find recipes by ingredients on hand |
| `/cook-app/manage-ingredients` | Merge, group, and rename ingredients |
| `/cook-app/settings` | Unit preferences |

### Abilities API

When available, Cook App registers these abilities:

- `cookbook/search-recipes`
- `cookbook/get-recipe`
- `cookbook/save-recipe`
- `cookbook/import-recipe`
- `cookbook/create-recipe-variation`
- `cookbook/get-week-plan`
- `cookbook/save-week-plan`
- `cookbook/list-recipe-terms`
- `cookbook/merge-recipe-terms`
- `cookbook/update-recipe-term`
- `cookbook/delete-recipe-terms`

The four term abilities take `type` as `ingredients`, `categories`, `cuisines`, or `tags`. Merging ingredients updates saved ingredient IDs while keeping each recipe's original wording. Deletion accepts only unused entries.

### Static Archive Integration

Cook App integrates with Static Archive by making recipe posts available for archive generation and by rendering structured recipe meta as Static Archive HTML and Markdown.
