<?php

namespace Tahadudhiya\WebDoctor\models;

use Craft;
use craft\base\Model;

/**
 * Web Doctor's own configuration. Craft stores it in Project Config and a
 * `config/web-doctor.php` file overrides it, so nothing here may hold a secret: credentials
 * belong in the environment, and Web Doctor reports only whether they are present.
 */
class Settings extends Model
{
    /** @var string What the control panel calls Web Doctor. */
    public string $pluginName = 'Web Doctor';

    protected function defineRules(): array
    {
        return [
            [['pluginName'], 'required'],
            [['pluginName'], 'string', 'max' => 255],
            [['pluginName'], 'trim'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'pluginName' => Craft::t('web-doctor', 'Plugin name'),
        ];
    }
}
