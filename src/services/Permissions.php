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

    /**
     * @var string Setting diagnostics running. Separate from {@see self::VIEW} because reading
     * what a previous run concluded costs nothing, while starting a run spends the site's time
     * on demand — so being allowed to look is not the same as being allowed to act.
     */
    public const RUN = 'webDoctor:runDiagnostics';

    /** @var string Reading the Issue Center: what has been found, and what has been done about it. */
    public const VIEW_ISSUES = 'webDoctor:viewIssues';

    /**
     * @var string Changing where an issue stands. Separate from {@see self::VIEW_ISSUES} because
     * an issue carries decisions — that something is being investigated, that something will not
     * be acted on — and a decision recorded against a team's installation is not something
     * everybody who may read the list should be able to make.
     */
    public const MANAGE_ISSUES = 'webDoctor:manageIssues';

    /**
     * @var string Reading what an issue's evidence contains. Separate from {@see self::VIEW_ISSUES}
     * because the contents are the technical detail — file paths, stack traces, database and
     * queue errors — and an installation can reasonably let somebody follow what has been found
     * without showing them the internals it was found in. That is the same line a client-safe
     * report draws. Without it, a reader still sees what kind of evidence an issue rests on.
     */
    public const VIEW_EVIDENCE = 'webDoctor:viewEvidence';

    /**
     * @var string Investigating an issue: running the checks related to it and recording what
     * they find. Separate from {@see self::VIEW_ISSUES} because it spends the site's time on
     * demand, as a diagnostic run does, and separate from {@see self::MANAGE_ISSUES} because
     * looking into a problem is not deciding anything about it.
     */
    public const INVESTIGATE_ISSUES = 'webDoctor:investigateIssues';

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
     * @return array<string, array{label: string, info?: string, nested?: array<string, mixed>}>
     */
    public function definitions(): array
    {
        return [
            self::VIEW => [
                'label' => Craft::t('web-doctor', 'View Web Doctor'),
                'nested' => [
                    self::RUN => [
                        'label' => Craft::t('web-doctor', 'Run diagnostics'),
                    ],
                    self::VIEW_ISSUES => [
                        'label' => Craft::t('web-doctor', 'View issues'),
                        'nested' => [
                            self::MANAGE_ISSUES => [
                                'label' => Craft::t('web-doctor', 'Manage issues'),
                            ],
                            self::VIEW_EVIDENCE => [
                                'label' => Craft::t('web-doctor', 'View evidence'),
                            ],
                            self::INVESTIGATE_ISSUES => [
                                'label' => Craft::t('web-doctor', 'Investigate issues'),
                                'info' => Craft::t('web-doctor', 'Runs the checks related to an issue and records what they find.'),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function canView(): bool
    {
        return $this->can(self::VIEW);
    }

    public function canRun(): bool
    {
        return $this->can(self::RUN);
    }

    public function canViewIssues(): bool
    {
        return $this->can(self::VIEW_ISSUES);
    }

    public function canManageIssues(): bool
    {
        return $this->can(self::MANAGE_ISSUES);
    }

    public function canViewEvidence(): bool
    {
        return $this->can(self::VIEW_EVIDENCE);
    }

    public function canInvestigate(): bool
    {
        return $this->can(self::INVESTIGATE_ISSUES);
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
