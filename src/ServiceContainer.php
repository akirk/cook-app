<?php

namespace CookApp;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ServiceContainer {
    private static ?self $default = null;

    private AbilitiesService $abilities;
    private AccessService $access;
    private CookedHistoryService $cooked_history;
    private ImportService $imports;
    private IngredientAdminService $ingredient_admin;
    private PlannerService $planner;
    private RecipeService $recipes;
    private RegistryService $registry;
    private ShoppingListService $shopping_list;
    private StaticArchiveService $static_archive;
    private UserPreferencesService $preferences;

    public function __construct() {
        $this->access           = new AccessService( $this );
        $this->preferences      = new UserPreferencesService( $this );
        $this->recipes          = new RecipeService( $this );
        $this->planner          = new PlannerService( $this );
        $this->shopping_list    = new ShoppingListService( $this );
        $this->cooked_history   = new CookedHistoryService( $this );
        $this->imports          = new ImportService( $this );
        $this->abilities        = new AbilitiesService( $this );
        $this->registry         = new RegistryService( $this );
        $this->static_archive   = new StaticArchiveService( $this );
        $this->ingredient_admin = new IngredientAdminService( $this );
    }

    public static function default(): self {
        if ( self::$default === null ) {
            self::$default = new self();
        }

        return self::$default;
    }

    public function abilities(): AbilitiesService {
        return $this->abilities;
    }

    public function access(): AccessService {
        return $this->access;
    }

    public function cookedHistory(): CookedHistoryService {
        return $this->cooked_history;
    }

    public function imports(): ImportService {
        return $this->imports;
    }

    public function ingredientAdmin(): IngredientAdminService {
        return $this->ingredient_admin;
    }

    public function planner(): PlannerService {
        return $this->planner;
    }

    public function recipes(): RecipeService {
        return $this->recipes;
    }

    public function registry(): RegistryService {
        return $this->registry;
    }

    public function shoppingList(): ShoppingListService {
        return $this->shopping_list;
    }

    public function staticArchive(): StaticArchiveService {
        return $this->static_archive;
    }

    public function preferences(): UserPreferencesService {
        return $this->preferences;
    }
}
