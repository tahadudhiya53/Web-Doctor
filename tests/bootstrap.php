<?php

// Resolves the autoloader whether the plugin has its own dependencies or is tested from a
// consuming Craft project, which is how the path-repository development setup works. The
// plugin's own comes first: it is the only one that knows the test namespace.

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

foreach ($autoloadCandidates as $autoload) {
    if (file_exists($autoload)) {
        require $autoload;

        $yiiClass = dirname($autoload) . '/yiisoft/yii2/Yii.php';

        if (file_exists($yiiClass)) {
            require $yiiClass;
        }

        // Craft::t() falls back to plain placeholder substitution when Craft::$app is null, so
        // loading the class alone is enough for unit tests that never boot an app.
        $craftClass = dirname($autoload) . '/craftcms/cms/src/Craft.php';

        if (file_exists($craftClass)) {
            require $craftClass;
        }

        return;
    }
}

fwrite(STDERR, "Could not find a Composer autoloader. Run `composer install` first.\n");
exit(1);
