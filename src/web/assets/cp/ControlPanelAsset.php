<?php

namespace Tahadudhiya\WebDoctor\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * What Web Doctor's control panel pages need in the browser: the colours that tell one outcome
 * from another, and enough script to show that a run is under way.
 *
 * One bundle for every page rather than one each. The health dashboard and the Issue Center show
 * the same vocabulary — statuses, severities — and two stylesheets would eventually disagree
 * about what a critical severity looks like, which is the one thing colour here has to get right.
 */
class ControlPanelAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->css = ['css/web-doctor.css'];
        $this->js = ['js/web-doctor.js'];

        parent::init();
    }
}
