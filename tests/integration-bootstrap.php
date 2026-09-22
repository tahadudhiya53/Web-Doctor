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
