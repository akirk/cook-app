"""Duration comparison regressions across Mealie API versions."""
import unittest

from check_mealie_api import recipe_minutes


class RecipeMinutesTest(unittest.TestCase):
    def test_structured_seconds_with_empty_display_fields(self):
        for camel, snake, seconds in [
            ("prepTime", "prep_time", 300),
            ("performTime", "perform_time", 600),
            ("totalTime", "total_time", 900),
        ]:
            with self.subTest(field=camel):
                self.assertEqual(recipe_minutes({camel: None, camel + "Seconds": seconds}, camel, snake), seconds / 60)
                self.assertEqual(recipe_minutes({snake: None, snake + "_seconds": seconds}, camel, snake), seconds / 60)

    def test_zero_seconds_takes_precedence_over_display_text(self):
        self.assertEqual(recipe_minutes({"prepTimeSeconds": 0, "prepTime": "5 minutes"}, "prepTime", "prep_time"), 0)

    def test_legacy_display_and_iso_durations(self):
        self.assertEqual(recipe_minutes({"prepTime": "1 hour 5 minutes"}, "prepTime", "prep_time"), 65)
        self.assertEqual(recipe_minutes({"prep_time": "PT5M"}, "prepTime", "prep_time"), 5)

    def test_null_seconds_falls_back_to_display(self):
        self.assertEqual(recipe_minutes({"prepTimeSeconds": None, "prepTime": "5 minutes"}, "prepTime", "prep_time"), 5)

    def test_fractional_minutes_are_not_truncated(self):
        self.assertEqual(recipe_minutes({"prepTimeSeconds": 330}, "prepTime", "prep_time"), 5.5)


if __name__ == "__main__":
    unittest.main()
