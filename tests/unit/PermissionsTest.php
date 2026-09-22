<?php

namespace Tahadudhiya\WebDoctor\Tests\unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\services\Permissions;

/**
 * What Web Doctor asks Craft to guard. Whether a given user holds one of these is Craft's
 * answer to give, and is covered where a real user exists.
 */
class PermissionsTest extends TestCase
{
    public function testEveryPermissionIsNamespacedToWebDoctor(): void
    {
        foreach (array_keys((new Permissions())->definitions()) as $permission) {
            self::assertStringStartsWith('webDoctor:', $permission);
        }
    }

    public function testViewingWebDoctorIsAPermissionOfItsOwn(): void
    {
        $definitions = (new Permissions())->definitions();

        self::assertArrayHasKey(Permissions::VIEW, $definitions);
        self::assertNotSame('', $definitions[Permissions::VIEW]['label']);
    }

    public function testOnlyPermissionsWithSomethingBehindThemAreDeclared(): void
    {
        // Permissions arrive with the features they guard. Declaring one early would offer an
        // administrator a switch that changes nothing.
        self::assertSame([Permissions::VIEW], array_keys((new Permissions())->definitions()));
    }
}
