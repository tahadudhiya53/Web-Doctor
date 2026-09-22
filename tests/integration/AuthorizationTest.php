<?php

namespace Tahadudhiya\WebDoctor\Tests\integration;

use Craft;
use craft\elements\User;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\WebDoctor\controllers\OverviewController;
use Tahadudhiya\WebDoctor\services\Permissions;
use Tahadudhiya\WebDoctor\Tests\_support\TestUser;
use Tahadudhiya\WebDoctor\WebDoctor;
use yii\base\Component;
use yii\web\ForbiddenHttpException;

/**
 * Who Web Doctor lets in. Every check runs against the plugin bootstrapped inside a real Craft
 * application, with a real user identity signed in.
 */
class AuthorizationTest extends TestCase
{
    /** @var string The permission Craft itself demands of anyone reaching the control panel. */
    private const ACCESS_CP = 'accessCp';

    private WebDoctor $plugin;
    private ?Component $consoleRequest = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plugin = new WebDoctor('web-doctor', Craft::$app, WebDoctor::config() + [
            'name' => 'Web Doctor',
            'version' => '1.0.0',
        ]);
    }

    protected function tearDown(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        if ($this->consoleRequest !== null) {
            Craft::$app->set('request', $this->consoleRequest);
            $this->consoleRequest = null;
        }

        parent::tearDown();
    }

    /**
     * Swaps in a real control panel request, so a control panel controller runs the same
     * authorization chain it runs when Craft is serving one.
     */
    private function controlPanelRequest(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['REQUEST_URI'] = '/admin/web-doctor';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->consoleRequest = Craft::$app->getRequest();
        Craft::$app->set('request', new \craft\web\Request());
    }

    private function signIn(bool $admin, array $permissions = []): User
    {
        $user = new TestUser();
        $user->admin = $admin;
        $user->grantedPermissions = $permissions;

        Craft::$app->getUser()->setIdentity($user);

        return $user;
    }

    public function testAnAdminMayView(): void
    {
        $this->signIn(admin: true);

        self::assertTrue($this->plugin->getPermissions()->canView());
    }

    public function testAUserHoldingThePermissionMayView(): void
    {
        $this->signIn(admin: false, permissions: [Permissions::VIEW]);

        self::assertTrue($this->plugin->getPermissions()->canView());
    }

    public function testAUserWithoutThePermissionMayNotView(): void
    {
        $this->signIn(admin: false, permissions: ['someOtherPlugin:doThing']);

        self::assertFalse($this->plugin->getPermissions()->canView());
    }

    public function testNobodySignedInMayNotView(): void
    {
        Craft::$app->getUser()->setIdentity(null);

        self::assertFalse($this->plugin->getPermissions()->canView());
    }

    public function testTheNavItemIsShownToAUserHoldingThePermission(): void
    {
        $this->signIn(admin: false, permissions: [Permissions::VIEW]);

        $item = $this->plugin->getCpNavItem();

        self::assertIsArray($item);
        self::assertSame('Web Doctor', $item['label']);
        self::assertSame('web-doctor', $item['url']);
    }

    public function testTheNavItemLabelFollowsTheConfiguredName(): void
    {
        $this->signIn(admin: true);
        $this->plugin->getSettings()->pluginName = 'Site Health';

        self::assertSame('Site Health', $this->plugin->getCpNavItem()['label'] ?? null);
    }

    public function testTheNavItemIsHiddenFromAUserWithoutThePermission(): void
    {
        $this->signIn(admin: false);

        self::assertNull($this->plugin->getCpNavItem());
    }

    public function testTheControlPanelControllerAdmitsAPermittedUser(): void
    {
        $this->signIn(admin: false, permissions: [self::ACCESS_CP, Permissions::VIEW]);
        $this->controlPanelRequest();

        $controller = new OverviewController('overview', $this->plugin);

        self::assertTrue($controller->beforeAction($controller->createAction('index')));
    }

    public function testTheControlPanelControllerAdmitsAnAdmin(): void
    {
        $this->signIn(admin: true);
        $this->controlPanelRequest();

        $controller = new OverviewController('overview', $this->plugin);

        self::assertTrue($controller->beforeAction($controller->createAction('index')));
    }

    public function testTheControlPanelControllerRefusesAUserWhoOnlyHasControlPanelAccess(): void
    {
        // Reaching the control panel is not the same as being allowed into Web Doctor, so this
        // user gets past Craft's own check and is stopped by Web Doctor's.
        $this->signIn(admin: false, permissions: [self::ACCESS_CP]);
        $this->controlPanelRequest();

        $controller = new OverviewController('overview', $this->plugin);

        $this->expectException(ForbiddenHttpException::class);
        $controller->beforeAction($controller->createAction('index'));
    }
}
