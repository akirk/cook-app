"""Import a Cook App export into a disposable Mealie v3.28.0 CI service.

Uses the actual HTTP import and read endpoints. Test cases have no photo URLs;
this verifies stored recipe fields, not image downloads or Mealie ZIP import.
Run only against a disposable instance: successful imports create recipes.
"""
import argparse
import json
import os
import re
from pathlib import Path
from urllib.error import HTTPError
from urllib.parse import quote, urlencode
from urllib.request import Request, urlopen


def minutes(value):
    """Compare ISO durations with Mealie's English display durations."""
    text = str(value or "")
    if text.startswith("PT"):
        hours = re.search(r"(\d+)H", text)
        mins = re.search(r"(\d+)M", text)
        return (int(hours[1]) * 60 if hours else 0) + (int(mins[1]) if mins else 0)
    hours = re.search(r"(\d+)\s*hours?", text)
    mins = re.search(r"(\d+)\s*minutes?", text)
    return (int(hours[1]) * 60 if hours else 0) + (int(mins[1]) if mins else 0)


def field(recipe, camel, snake):
    return recipe.get(camel, recipe.get(snake))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("export", type=Path)
    parser.add_argument("--url", required=True, help="URL of a disposable Mealie instance")
    args = parser.parse_args()
    base = args.url.rstrip("/")
    token = None

    def request(path, payload=None, form=False):
        headers = {}
        if token:
            headers["Authorization"] = f"Bearer {token}"
        data = None
        if payload is not None:
            headers["Content-Type"] = "application/x-www-form-urlencoded" if form else "application/json"
            data = (urlencode(payload) if form else json.dumps(payload)).encode()
        req = Request(base + path, data=data, headers=headers)
        try:
            with urlopen(req, timeout=60) as response:
                return json.load(response)
        except HTTPError as error:
            raise RuntimeError(f"{req.get_method()} {path} failed ({error.code}): {error.read().decode()}") from None

    about = request("/api/app/about")
    assert str(about["version"]).lstrip("v") == "3.28.0", "Expected Mealie v3.28.0"
    login = request("/api/auth/token", {
        "username": os.environ.get("MEALIE_TEST_USERNAME", "changeme@example.com"),
        "password": os.environ.get("MEALIE_TEST_PASSWORD", "MyPassword"),
    }, form=True)
    token = login["access_token"]
    recipes = json.loads(args.export.read_text())
    assert isinstance(recipes, list) and len(recipes) >= 2, "Expected flat and grouped test recipes"
    for source in recipes:
        assert not source["image"], "API fixtures should not download external photos"
        slug = request("/api/recipes/create/html-or-json", {
            "data": json.dumps(source), "includeTags": True, "includeCategories": True,
        })
        saved = request("/api/recipes/" + quote(slug, safe=""))
        assert saved["name"] == source["name"], f"Title changed for {slug}"
        assert saved["description"] == source["description"], f"Description changed for {slug}"
        ingredients = field(saved, "recipeIngredient", "recipe_ingredient")
        assert [row.get("display") or row.get("note") for row in ingredients] == source["recipeIngredient"], f"Ingredients changed for {slug}"
        instructions = field(saved, "recipeInstructions", "recipe_instructions")
        assert [row["text"] for row in instructions] == [row["text"] for row in source["recipeInstructions"]], f"Instructions changed for {slug}"
        assert minutes(field(saved, "prepTime", "prep_time")) == minutes(source["prepTime"]), f"Preparation time changed for {slug}"
        assert minutes(field(saved, "performTime", "perform_time")) == minutes(source["cookTime"]), f"Cooking time changed for {slug}"
        assert minutes(field(saved, "totalTime", "total_time")) == minutes(source["totalTime"]), f"Total time changed for {slug}"
        assert field(saved, "recipeServings", "recipe_servings") == float(source["recipeYield"]), f"Servings changed for {slug}"
        assert field(saved, "orgURL", "org_url") == source["url"], f"Source URL changed for {slug}"
        categories = field(saved, "recipeCategory", "recipe_category")
        assert {row["name"] for row in categories} == set(source["recipeCategory"]), f"Categories changed for {slug}"
        assert {row["name"] for row in saved["tags"]} == set(source["keywords"]), f"Tags changed for {slug}"
        print(f"Mealie API: imported and read back {source['name']}")
    print(f"Mealie v3.28.0 API: {len(recipes)} recipes passed")


if __name__ == "__main__":
    main()
