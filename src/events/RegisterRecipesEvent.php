<?php

namespace Tahadudhiya\WebDoctor\events;

use Tahadudhiya\WebDoctor\recipes\Recipe;
use yii\base\Event;

/**
 * Fired once, the first time the recipe registry is asked for anything, so other plugins can
 * contribute recipes of their own.
 *
 * A recipe holds no diagnostic logic, only the areas worth looking at for a symptom, so a
 * contributed one can reach nothing a registered check does not already reach, and runs behind the
 * same permission as every investigation.
 */
class RegisterRecipesEvent extends Event
{
    /**
     * @var mixed[] Recipes to add. Handlers add {@see Recipe} instances; the type is not narrowed
     * for the reason {@see RegisterDiagnosticsEvent::$diagnostics} gives, and the registry checks
     * what it is given.
     */
    public array $recipes = [];
}
