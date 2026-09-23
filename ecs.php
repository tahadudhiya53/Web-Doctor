<?php

declare(strict_types=1);

use craft\ecs\SetList;
use PhpCsFixer\Fixer\Operator\TernaryOperatorSpacesFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->parallel();
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __FILE__,
    ]);

    $ecsConfig->sets([SetList::CRAFT_CMS_4]);

    // The ternary fixer misreads the colon in a backed enum declaration as a ternary operator
    // and rewrites `enum Severity: string` to `enum Severity : string`, which is not the style
    // this codebase or Craft's uses. Enums are exempted rather than written the fixer's way.
    $ecsConfig->skip([
        TernaryOperatorSpacesFixer::class => [__DIR__ . '/src/enums'],
    ]);
};
