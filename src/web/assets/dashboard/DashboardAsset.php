<?php

namespace Tahadudhiya\WebDoctor\web\assets\dashboard;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * What the health dashboard needs in the browser: the colours that tell one outcome from
 * another, and enough script to show that a run is under way.
 */
class DashboardAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['css/dashboard.css'];
        $this->js = ['js/dashboard.js'];

        parent::init();
    }
}
