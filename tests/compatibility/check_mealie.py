"""Check recipe JSON-LD with Mealie v3.28.0's recipe-scrapers dependency.

Install recipe-scrapers==15.12.0 in a virtualenv, then pass an export path.
This checks schema parsing, not Mealie's database, UI, or full import workflow.
"""
import json
import sys
from importlib.metadata import version

from recipe_scrapers import scrape_html

assert version("recipe-scrapers") == "15.12.0", "Use Mealie v3.28.0's pinned dependency"
recipes = json.load(open(sys.argv[1], encoding="utf-8"))
assert isinstance(recipes, list) and recipes, "Expected a nonempty recipe export"
for recipe in recipes:
    html = '<html><head><script type="application/ld+json">' + json.dumps(recipe) + '</script></head></html>'
    parsed = scrape_html(html, org_url="https://example.com", supported_only=False)
    assert parsed.title() == recipe["name"]
    assert parsed.ingredients() == recipe["recipeIngredient"]
    expected_steps = [step["text"] for step in recipe["recipeInstructions"]]
    assert parsed.instructions() == "\n".join(expected_steps)
    if recipe["image"]:
        assert parsed.image() == recipe["image"][0]
    if recipe["recipeYield"]:
        assert str(recipe["recipeYield"]) in parsed.yields()
    assert parsed.prep_time() == int(recipe["prepTime"][2:-1])
    assert parsed.cook_time() == int(recipe["cookTime"][2:-1])
    expected_minutes = int(recipe["totalTime"][2:-1])
    assert parsed.total_time() == expected_minutes
print(f"Mealie v3.28.0 schema parser: {len(recipes)} recipes passed")
