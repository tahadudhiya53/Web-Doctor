<?php

namespace Tahadudhiya\WebDoctor\services;

use Craft;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use yii\base\Component;
use yii\base\Event;

/**
 * Owns what a user is allowed to do with Web Doctor: the permissions Craft is told about, and
 * the answer to whether the user in front of us holds one. Every permission Web Doctor gains
 * is declared here so there is one place to read the whole access model from.
 */
class Permissions extends Component
{
    /** @var string Reaching Web Doctor at all. Everything Web Doctor gains nests under it. */
    public const VIEW = 'webDoctor:view';

    public function register(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => 'Web Doctor',
                'permissions' => $this->definitions(),
            ];
        });
    }

    /**
     * @return array<string, array{label: string, nested?: array<string, array{label: string}>}>
     */
    public function definitions(): array
    {
        return [
            self::VIEW => [
                'label' => Craft::t('web-doctor', 'View Web Doctor'),
            ],
        ];
    }

    public function canView(): bool
    {
        return $this->can(self::VIEW);
    }

    /**
     * Admins pass, as they do everywhere in Craft. Nobody else passes without the permission,
     * and a request with no identity — a console command, a logged-out visitor — never does.
     */
    public function can(string $permission): bool
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $user !== null && ($user->admin || $user->can($permission));
    }
}
