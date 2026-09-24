<?php

namespace Tahadudhiya\WebDoctor\diagnostics\environment;

use Craft;
use craft\helpers\App;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;

/**
 * Which environment Craft thinks it is running in, and whether its settings suit that.
 *
 * The environment name is the only thing that tells Craft — and therefore Web Doctor — whether
 * a setting is reasonable. `devMode` on a developer's machine is how the site is meant to be
 * built; the same setting on a server that calls itself production is a different fact
 * entirely, and the only way to tell the two apart is the name the installation gives itself.
 *
 * That makes any judgement here a judgement about a name, not about a server, so it is reported
 * as one: a finding that says what was assumed, with the confidence such an assumption earns.
 *
 * The security key is reported as present or missing and never in any other form.
 */
class EnvironmentDiagnostic extends Diagnostic
{
    public const ID = 'environment.configuration';

    /**
     * @var string[] Environment names that conventionally mean "somebody is building this
     * right now". Anything else is treated as a served environment, because assuming the
     * safer reading of an unfamiliar name is the only defensible default.
     */
    private const DEVELOPMENT_ENVIRONMENTS = ['dev', 'local', 'development'];

    public function name(): string
    {
        return Craft::t('web-doctor', 'Environment configuration');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::ENVIRONMENT;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Reports the environment Craft is running as and the settings that depend on it, without reporting any value that is a credential.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        $config = $this->settings();
        $environment = $config['environment'];
        $isDevelopment = in_array(strtolower($environment), self::DEVELOPMENT_ENVIRONMENTS, true);

        $settings = $this->evidence(EvidenceType::CONFIGURATION, Craft::t('web-doctor', 'Environment settings'), [
            'environment' => $environment,
            'devMode' => $config['devMode'],
            'allowAdminChanges' => $config['allowAdminChanges'],
            'allowUpdates' => $config['allowUpdates'],
            'runQueueAutomatically' => $config['runQueueAutomatically'],
            'timezone' => $config['timezone'],
        ]);

        // The key itself is never read into evidence in any form — only the answer to whether
        // it is there, which is the answer a developer actually needs.
        $variables = $this->evidence(EvidenceType::ENVIRONMENT_VARIABLE, Craft::t('web-doctor', 'Environment variables'), [
            'CRAFT_ENVIRONMENT' => Redaction::presence($config['craftEnvironmentVariable']),
            'CRAFT_SECURITY_KEY' => Redaction::presence($config['securityKey']),
        ]);

        $evidence = [$settings, $variables];

        if (Redaction::presence($config['securityKey']) === Redaction::MISSING) {
            return $this->fail(
                Craft::t('web-doctor', 'Craft has no security key.'),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Set CRAFT_SECURITY_KEY to the key this installation’s data was encrypted with. Generating a new one makes existing encrypted data unreadable.'),
                severity: Severity::CRITICAL,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Craft encrypts stored credentials and signs data with this key, so without it those values cannot be read back.'),
            );
        }

        if ($config['devMode'] && !$isDevelopment) {
            return $this->warning(
                Craft::t('web-doctor', 'Development mode is on in the “{environment}” environment.', ['environment' => $environment]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Turn off CRAFT_DEV_MODE here, or rename the environment if this is a development machine.'),
                severity: Severity::HIGH,
                // The environment's own name is the only evidence for what kind of environment
                // this is, so the finding is offered as a reading of that name rather than as
                // an established fact about the server.
                confidence: Confidence::POSSIBLE,
                description: Craft::t('web-doctor', 'Development mode shows errors and stack traces to visitors. This environment is not named as a development one, so it is assumed to be served to somebody.'),
            );
        }

        return $this->info(
            Craft::t('web-doctor', 'Running as “{environment}”, with development mode {devMode}.', [
                'environment' => $environment,
                'devMode' => $config['devMode'] ? Craft::t('web-doctor', 'on') : Craft::t('web-doctor', 'off'),
            ]),
            $evidence,
        );
    }

    /**
     * The environment's settings, as Craft has them.
     *
     * Protected so a test can state an environment rather than needing one configured that way.
     * The security key is read here and turned into a presence word before it reaches anything
     * else; no caller of this ever sees it in a result.
     *
     * @return array{environment: string, devMode: bool, allowAdminChanges: bool, allowUpdates: bool, runQueueAutomatically: bool, timezone: string, securityKey: string, craftEnvironmentVariable: mixed}
     */
    protected function settings(): array
    {
        $general = Craft::$app->getConfig()->getGeneral();

        return [
            'environment' => Craft::$app->env,
            'devMode' => $general->devMode,
            'allowAdminChanges' => $general->allowAdminChanges,
            'allowUpdates' => $general->allowUpdates,
            'runQueueAutomatically' => $general->runQueueAutomatically,
            'timezone' => $general->timezone ?? date_default_timezone_get(),
            'securityKey' => $general->securityKey,
            'craftEnvironmentVariable' => App::env('CRAFT_ENVIRONMENT'),
        ];
    }
}
