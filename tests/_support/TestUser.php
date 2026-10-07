<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use craft\elements\User;

/**
 * A user whose permissions are stated outright rather than stored.
 *
 * Craft answers `can()` from the database, which needs a saved user, and this Craft install is
 * a Solo licence that refuses to create a second one. Overriding the one method Web Doctor
 * relies on keeps the code under test — the permission service, the nav item and the
 * controller's authorization — running exactly as it does in production.
 */
class TestUser extends User
{
    /**
     * @var string What this user is called, so whatever a test records in the audit trail under it
     * can be told from a real person's entries and removed afterwards.
     */
    public const USERNAME = 'web-doctor-tests';

    /** @var string[] Permissions this user holds. */
    public array $grantedPermissions = [];

    public ?string $username = self::USERNAME;

    public function can(string $permission): bool
    {
        return $this->admin || in_array($permission, $this->grantedPermissions, true);
    }
}
