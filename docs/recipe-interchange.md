# Recipe interchange

Settings offers **Export recipes (JSON-LD)** and **Download backup**. Recipe export is a JSON array of standalone [schema.org Recipe](https://schema.org/Recipe) objects. Each has its own `@context` and uses schema.org properties only; it needs no Cook App vocabulary. See the [example recipe export](examples/cook-app-recipes.json).

The full [Cook App backup](backup-format-v1.md) additionally preserves shopping lists, meal plans, cooking history, preferences, exact metadata, and application relationships. It is an application-specific restore contract. Documenting it does not make it an industry standard.

## Interchange fields

Recipe exports include ingredients as strings, flat `HowToStep` instructions, ISO 8601 preparation/cooking/total durations, serving counts, categories, cuisines, and tags. Images are URL arrays, including an empty array when no photo exists. The source URL is supplied as both `url` and `isBasedOn`; recipe notes use a schema.org `Comment` named `Author Notes`, following RecipeSage's convention. Cook App reads that convention on file import as well.

Instruction section names are prefixed to the first instruction in each section to accommodate importers that only read flat step text. Exact section structure and ingredient grouping remain in full backups. Photo URLs need to remain accessible; images are not bundled.

## Compatibility and limits

| Target | Supported path / verification |
| --- | --- |
| RecipeSage | Its JSON-LD file importer. The export matches its Recipe fields, including image arrays, `isBasedOn`, and the `Author Notes` comment convention. |
| Tandoor | Select the RecipeSage import integration. That integration reads an array of recipes, image arrays, and flat step text. |
| Mealie | Its single-recipe JSON import path (`/api/recipes/create/html-or-json`). Supply one object from the exported array per import; the collection file is not a native Mealie ZIP. Tested against recipe-scrapers 15.12.0, the schema parser pinned by Mealie v3.28.0. |

These checks cover recipe parsing and field mapping, not a complete round trip through running instances. Notes are read by RecipeSage using the convention above; Mealie's schema import does not map that comment convention to its notes. Some importers may omit cuisines/tags or reconstruct ingredient quantities from text. Full application state cannot be transferred through this recipe-only format.

Implementation references used for compatibility checks:

- [RecipeSage conversion](https://github.com/julianpoy/RecipeSage/blob/ba6e650c66d6c0660c5a3f20225330b7fb8f3d36/packages/util/server/src/general/jsonLD.ts) and [recipe node collection](https://github.com/julianpoy/RecipeSage/blob/ba6e650c66d6c0660c5a3f20225330b7fb8f3d36/packages/util/server/src/general/collectRecipeNodes.ts).
- [Tandoor RecipeSage adapter](https://github.com/TandoorRecipes/recipes/blob/bb07441b3cd566dbc6c29ab09dc74b741804ef02/cookbook/integration/recipesage.py).
- [Mealie v3.28.0 scraper](https://github.com/mealie-recipes/mealie/blob/v3.28.0/mealie/services/scraper/scraper_strategies.py) and [dependency pin](https://github.com/mealie-recipes/mealie/blob/v3.28.0/pyproject.toml).

The normal PHPUnit suite checks export fields, section flattening, recipe-only re-import, and a synthetic RecipeSage-style export fixture. To independently repeat the Mealie parser check, use a temporary Python environment:

```sh
python3 -m venv /tmp/cook-app-mealie-check
/tmp/cook-app-mealie-check/bin/pip install recipe-scrapers==15.12.0
/tmp/cook-app-mealie-check/bin/python tests/compatibility/check_mealie.py docs/examples/cook-app-recipes.json
```

The fixture in `tests/fixtures/recipesage-export.json` is synthetic, modeled on the referenced exporter; it contains no user data.
