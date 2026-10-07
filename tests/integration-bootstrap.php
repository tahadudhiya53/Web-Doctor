<?php

// Boots the surrounding Craft project so integration tests run against a real app and database.
// Craft loads its own patched Yii and Craft classes, so nothing may be required before this.

$projectRoot = dirname(__DIR__, 3);

if (!file_exists("$projectRoot/bootstrap.php")) {
    fwrite(STDERR, "Integration tests need Web Doctor to sit inside a Craft project. Run them from there.\n");
    exit(1);
}

require "$projectRoot/bootstrap.php";
require "$projectRoot/vendor/craftcms/cms/bootstrap/console.php";

// The host project's autoloader has no reason to know about the plugin's test namespace, and
// only knows the plugin's own namespace once the plugin is installed there.
spl_autoload_register(static function(string $class): void {
    $prefixes = [
        'Tahadudhiya\\WebDoctor\\Tests\\' => __DIR__,
        'Tahadudhiya\\WebDoctor\\' => __DIR__ . '/../src',
    ];

    foreach ($prefixes as $prefix => $base) {
        if (str_starts_with($class, $prefix)) {
            $path = $base . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (file_exists($path)) {
                require $path;
            }

            return;
        }
    }
});

// The audit trail and the diagnostic history record whatever a test does through the real services,
// some of it under the installation's own environment. What the test user did, or did anywhere in a test environment, is
// removed when the run ends, so the host's trail keeps only what people did. Read straight from the
// table, since the plugin's classes are the ones under test.
register_shutdown_function(static function(): void {
    try {
        $db = Craft::$app->getDb();

        foreach (['{{%webdoctor_audit_log}}', '{{%webdoctor_diagnostic_runs}}'] as $table) {
            if ($db->tableExists($table)) {
                $db->createCommand()->delete($table, ['or',
                    ['userName' => \Tahadudhiya\WebDoctor\Tests\_support\TestUser::USERNAME],
                    ['like', 'environment', 'tests-%', false],
                ])->execute();
            }
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, 'The audit entries the tests recorded could not be removed: ' . $e->getMessage() . "\n");
    }
});

// Templates are compiled into a directory of this run's own, removed when it ends. Twig decides
// whether a compiled template is current by comparing modification times to the second, keyed by
// the template's path rather than its contents, so a template changed within the same second
// another process compiled it would otherwise be served from that compile.
$compiledTemplates = sys_get_temp_dir() . '/web-doctor-tests-compiled-' . getmypid() . '-' . bin2hex(random_bytes(4));
Craft::$app->set('path', new class(['compiledTemplates' => $compiledTemplates]) extends \craft\services\Path {
    public string $compiledTemplates = '';

    public function getCompiledTemplatesPath(bool $create = true): string
    {
        if ($create) {
            \craft\helpers\FileHelper::createDirectory($this->compiledTemplates);
        }

        return $this->compiledTemplates;
    }
});
register_shutdown_function(static function() use ($compiledTemplates): void {
    \craft\helpers\FileHelper::removeDirectory($compiledTemplates);
});
