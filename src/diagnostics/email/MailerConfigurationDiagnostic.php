<?php

namespace Tahadudhiya\WebDoctor\diagnostics\email;

use Craft;
use craft\helpers\App;
use craft\helpers\MailerHelper;
use craft\mail\transportadapters\Smtp;
use craft\models\MailSettings;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticStatus;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Throwable;

/**
 * Whether Craft is configured well enough to send an email.
 *
 * Nothing is sent. A diagnostic that sends a test message delivers real mail to a real address
 * every time anybody runs a check, and reaches an external server while doing it — so this
 * check answers the question that can be answered from configuration alone, and says plainly
 * that it has not proved delivery. Sending belongs to something a person asks for on purpose.
 *
 * What it can establish is most of what actually goes wrong: no sender address, a transport
 * type that no longer exists because the plugin providing it was removed, an SMTP host with
 * authentication switched on and no credentials behind it, or credentials that are references
 * to environment variables this environment does not define.
 *
 * Those credentials are reported as present or missing and in no other form. The user name is
 * treated the same way as the password, because a great many mail services use an API token as
 * the user name.
 */
class MailerConfigurationDiagnostic extends Diagnostic
{
    public const ID = 'email.configuration';

    /**
     * @var MailSettings|null The settings to inspect. Craft's own unless a caller supplies
     * others, which is how an incomplete or credential-bearing configuration is exercised
     * without a site having to be configured with one.
     */
    public ?MailSettings $settings = null;

    public function name(): string
    {
        return Craft::t('web-doctor', 'Mailer configuration');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::EMAIL;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Checks Craft’s mail settings and the presence of the credentials they depend on, without sending anything.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        try {
            $settings = $this->mailSettings();
        } catch (Throwable $e) {
            return $this->unknown(
                Craft::t('web-doctor', 'Craft’s mail settings could not be read.'),
                [Evidence::fromThrowable($e, $this->id())],
            );
        }

        $fromEmail = $this->parse($settings->fromEmail);
        $transportSettings = $settings->transportSettings ?? [];

        $evidence = [
            $this->evidence(EvidenceType::CONFIGURATION, Craft::t('web-doctor', 'Mail settings'), [
                'transportType' => $settings->transportType,
                'fromEmail' => Redaction::presence($fromEmail),
                'fromName' => Redaction::presence($this->parse($settings->fromName)),
                'replyToEmail' => Redaction::presence($this->parse($settings->replyToEmail)),
                'template' => Redaction::presence($settings->template),
                'siteOverrides' => count($settings->siteOverrides),
                // Craft sends mail as part of the request that triggers it rather than through
                // the queue, so a stopped queue does not stop mail — but a plugin that queues
                // its own mail is affected by it, which is why this is recorded here.
                'runQueueAutomatically' => Craft::$app->getConfig()->getGeneral()->runQueueAutomatically,
            ]),
        ];

        if ($transportSettings !== []) {
            $evidence[] = $this->transportEvidence($settings->transportType, $transportSettings);
        }

        $transportError = $this->transportError($settings->transportType, $transportSettings);

        if ($transportError !== null) {
            return $this->fail(
                Craft::t('web-doctor', 'Craft’s mail transport could not be built.'),
                [...$evidence, $transportError],
                recommendation: Craft::t('web-doctor', 'Choose a transport under Settings → Email, or reinstate the plugin that provided this one.'),
                severity: Severity::HIGH,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Craft falls back to PHP’s own mail function when it cannot build the configured transport, so mail may be leaving by a route nobody chose — or not at all.'),
            );
        }

        $problems = $this->problems($settings->fromEmail, $fromEmail, $settings->transportType, $transportSettings);

        if ($problems !== []) {
            return $this->result(
                DiagnosticStatus::FAIL,
                Craft::t('web-doctor', 'Craft’s mail settings are incomplete: {problems}.', ['problems' => implode('; ', $problems)]),
                severity: Severity::HIGH,
                description: Craft::t('web-doctor', 'Mail that Craft tries to send will fail, including the messages people need in order to get back into the site.'),
                evidence: $evidence,
                recommendation: Craft::t('web-doctor', 'Complete these settings under Settings → Email, and define any environment variables they refer to in this environment.'),
                confidence: Confidence::CONFIRMED,
            );
        }

        return $this->pass(
            Craft::t('web-doctor', 'Craft’s mail settings are complete. Delivery itself was not tested.'),
            $evidence,
        );
    }

    /**
     * What is missing from the settings, in the words someone fixing it would use.
     *
     * @param array<string, mixed> $transportSettings
     * @return string[]
     */
    private function problems(?string $rawFromEmail, bool|string|null $fromEmail, ?string $transportType, array $transportSettings): array
    {
        $problems = [];

        if (Redaction::presence($fromEmail) === Redaction::MISSING) {
            $problems[] = $rawFromEmail !== null && $rawFromEmail !== ''
                // A setting that is a reference to an environment variable, resolving to
                // nothing, is a different fault from a setting nobody filled in — and it is
                // fixed somewhere else entirely.
                ? Craft::t('web-doctor', 'the sender address refers to an environment variable this environment does not define')
                : Craft::t('web-doctor', 'no sender address is set');
        }

        if ($transportType === Smtp::class) {
            if (Redaction::presence($this->parse($transportSettings['host'] ?? null)) === Redaction::MISSING) {
                $problems[] = Craft::t('web-doctor', 'the SMTP host is not set');
            }

            $authentication = App::parseBooleanEnv($transportSettings['useAuthentication'] ?? null);

            if ($authentication === true) {
                if (Redaction::presence($this->parse($transportSettings['username'] ?? null)) === Redaction::MISSING) {
                    $problems[] = Craft::t('web-doctor', 'SMTP authentication is on with no user name');
                }

                if (Redaction::presence($this->parse($transportSettings['password'] ?? null)) === Redaction::MISSING) {
                    $problems[] = Craft::t('web-doctor', 'SMTP authentication is on with no password');
                }
            }
        }

        return $problems;
    }

    /**
     * Records the transport's settings as presence rather than as values.
     *
     * The host and port are named outright because they are how somebody recognises which
     * service this is. Everything else a transport carries is reported only as present or
     * missing, so a transport added by a plugin cannot leak a credential through a key this
     * check has never heard of.
     *
     * @param array<string, mixed> $transportSettings
     */
    private function transportEvidence(?string $transportType, array $transportSettings): Evidence
    {
        $data = ['transportType' => $transportType];

        foreach ($transportSettings as $key => $value) {
            $data[(string)$key] = in_array($key, ['host', 'port', 'encryptionMethod', 'timeout'], true)
                ? Redaction::redactValue($this->parse(is_scalar($value) ? (string)$value : null))
                : Redaction::presence($this->parse(is_scalar($value) ? (string)$value : $value));
        }

        return $this->evidence(EvidenceType::ENVIRONMENT_VARIABLE, Craft::t('web-doctor', 'Mail transport'), $data);
    }

    /**
     * Evidence of the transport failing to be built, or null where it built.
     *
     * @param array<string, mixed> $transportSettings
     */
    private function transportError(?string $transportType, array $transportSettings): ?Evidence
    {
        if ($transportType === null || $transportType === '') {
            return null;
        }

        try {
            // Builds the adapter only. Nothing here opens a connection or sends a message.
            MailerHelper::createTransportAdapter($transportType, $transportSettings);

            return null;
        } catch (Throwable $e) {
            return Evidence::fromThrowable($e, $this->id());
        }
    }

    /**
     * Craft's own mail settings, read from project config.
     *
     * @throws Throwable where project config cannot be read.
     */
    protected function mailSettings(): MailSettings
    {
        return $this->settings ?? App::mailSettings();
    }

    private function parse(mixed $value): bool|string|null
    {
        if (!is_string($value)) {
            return is_bool($value) ? $value : null;
        }

        try {
            return App::parseEnv($value);
        } catch (Throwable) {
            return null;
        }
    }
}
