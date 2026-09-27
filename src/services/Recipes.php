<?php

namespace Tahadudhiya\WebDoctor\services;

use Tahadudhiya\WebDoctor\events\RegisterRecipesEvent;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\recipes\CoreRecipes;
use Tahadudhiya\WebDoctor\recipes\Recipe;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Every recipe Web Doctor knows about. It holds recipes; running one is an investigation's job.
 *
 * Held to the diagnostic registry's rules: an ID has a diagnostic ID's shape and is checked at
 * registration, and the first registration keeps it, so which recipe answers to an ID never
 * depends on plugin load order. Web Doctor's own come first, in the order they are offered; those
 * other plugins contribute follow, by ID.
 *
 * Another plugin contributes a recipe by handling {@see self::EVENT_REGISTER_RECIPES}:
 *
 * ```php
 * Event::on(Recipes::class, Recipes::EVENT_REGISTER_RECIPES, function(RegisterRecipesEvent $event) {
 *     $event->recipes[] = new Recipe(
 *         id: 'myPlugin.syncFailing',
 *         title: 'Sync Doctor',
 *         symptom: 'Entries are not syncing',
 *         description: 'Looks at the queue the sync runs on and the plugin’s own checks.',
 *         category: DiagnosticCategory::PLUGINS,
 *         primary: [RelatedArea::check('myPlugin.connection', 'The sync reads from the remote API.')],
 *     );
 * });
 * ```
 */
class Recipes extends Component
{
    /** @event RegisterRecipesEvent Fired so other plugins may contribute recipes. */
    public const EVENT_REGISTER_RECIPES = 'registerRecipes';

    /**
     * @var bool Whether the recipes Web Doctor ships with are part of this registry. The plugin
     * turns this on for the registry it hands out; one built directly starts empty.
     */
    public bool $includeCoreRecipes = false;

    /** @var array<string, Recipe> Registered recipes, keyed by ID. */
    private array $recipes = [];

    /** @var list<string> The IDs of Web Doctor's own, in the order they are offered. */
    private array $core = [];

    private bool $loaded = false;

    /**
     * Adds a recipe. The first registration of an ID keeps it.
     *
     * @throws InvalidArgumentException if the recipe is malformed or its ID is taken.
     */
    public function register(Recipe $recipe): void
    {
        if (!Diagnostics::isValidId($recipe->id)) {
            throw new InvalidArgumentException(sprintf(
                'The recipe ID "%s" is not a valid ID. Recipe IDs take the shape of diagnostic IDs — dot-separated, '
                . 'at least two segments, each starting with a lowercase letter — for example "queue.jobsFailing".',
                $recipe->id,
            ));
        }

        if (isset($this->recipes[$recipe->id])) {
            throw new InvalidArgumentException(sprintf('The recipe ID "%s" is already registered.', $recipe->id));
        }

        if (trim($recipe->title) === '' || trim($recipe->symptom) === '') {
            throw new InvalidArgumentException(sprintf('The recipe "%s" must say what it is called and what symptom it is for.', $recipe->id));
        }

        // A recipe is the areas it looks at, and one that looks at nothing would run an
        // investigation of nothing.
        if ($recipe->primary === []) {
            throw new InvalidArgumentException(sprintf('The recipe "%s" must name at least one area to look at.', $recipe->id));
        }

        $this->recipes[$recipe->id] = $recipe;
    }

    /**
     * Every registered recipe: Web Doctor's own in the order they are offered, then any other
     * plugin's, by ID.
     *
     * @return list<Recipe>
     */
    public function all(): array
    {
        $this->load();

        $contributed = array_diff_key($this->recipes, array_flip($this->core));
        ksort($contributed, SORT_STRING);

        $ordered = [];

        foreach ($this->core as $id) {
            if (isset($this->recipes[$id])) {
                $ordered[] = $this->recipes[$id];
            }
        }

        return [...$ordered, ...array_values($contributed)];
    }

    public function get(string $id): ?Recipe
    {
        $this->load();

        return $this->recipes[$id] ?? null;
    }

    /**
     * Asks other plugins for their recipes, once, the first time the registry is read.
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        // Web Doctor's own first, so the first-wins rule reserves their IDs.
        if ($this->includeCoreRecipes) {
            foreach (CoreRecipes::all() as $recipe) {
                try {
                    $this->register($recipe);
                    $this->core[] = $recipe->id;
                } catch (Throwable $e) {
                    SafeException::log('A recipe Web Doctor ships with could not be registered', $e);
                }
            }
        }

        if (!$this->hasEventHandlers(self::EVENT_REGISTER_RECIPES)) {
            return;
        }

        $event = new RegisterRecipesEvent();

        // A contributor that throws — building a recipe out of something that is not an area, say —
        // costs its own recipes and whatever handlers would have run after it, never the recipes
        // already contributed or Web Doctor's own, which are registered before anyone is asked.
        try {
            $this->trigger(self::EVENT_REGISTER_RECIPES, $event);
        } catch (Throwable $e) {
            SafeException::log('A plugin failed while contributing recipes', $e);
        }

        foreach ($event->recipes as $recipe) {
            // One plugin's mistake must not take the registry down with it.
            try {
                if (!$recipe instanceof Recipe) {
                    throw new InvalidArgumentException(sprintf(
                        'Only recipes may be contributed to Web Doctor; %s is not one.',
                        get_debug_type($recipe),
                    ));
                }

                $this->register($recipe);
            } catch (Throwable $e) {
                SafeException::log('A contributed recipe could not be registered', $e);
            }
        }
    }
}
