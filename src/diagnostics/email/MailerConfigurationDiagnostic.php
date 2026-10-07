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
    /** @var string What a setting that could not be resolved is read as: not a value any setting can hold. */
    private const UNREADABLE = "\0unreadable";

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
                'fromEmail' => $this->presence($fromEmail),
                'fromName' => $this->presence($this->parse($settings->fromName)),
                'replyToEmail' => $this->presence($this->parse($settings->replyToEmail)),
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

        $unknown = [];
        $problems = $this->problems($settings->fromEmail, $fromEmail, $settings->transportType, $transportSettings, $unknown);

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

        // A setting that could not be resolved is not one found complete.
        if ($unknown !== []) {
            return $this->unknown(
                Craft::t('web-doctor', 'Some of Craft’s mail settings could not be resolved: {settings}.', ['settings' => implode(', ', $unknown)]),
                $evidence,
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
     * @param list<string> $unknown The settings that could not be resolved, added to.
     * @return string[]
     */
    private function problems(?string $rawFromEmail, bool|string|null $fromEmail, ?string $transportType, array $transportSettings, array &$unknown): array
    {
        $problems = [];
        $judge = function(string $name, bool|string|null $parsed) use (&$unknown): bool {
            $presence = $this->presence($parsed);

            if ($presence === Redaction::UNKNOWN) {
                $unknown[] = $name;
            }

            return $presence === Redaction::MISSING;
        };

        if ($judge('fromEmail', $fromEmail)) {
            $problems[] = $rawFromEmail !== null && $rawFromEmail !== ''
                // A setting that is a reference to an environment variable, resolving to
                // nothing, is a different fault from a setting nobody filled in — and it is
                // fixed somewhere else entirely.
                ? Craft::t('web-doctor', 'the sender address refers to an environment variable this environment does not define')
                : Craft::t('web-doctor', 'no sender address is set');
        }

        if ($transportType === Smtp::class) {
            if ($judge('host', $this->parse($transportSettings['host'] ?? null))) {
                $problems[] = Craft::t('web-doctor', 'the SMTP host is not set');
            }

            $authentication = App::parseBooleanEnv($transportSettings['useAuthentication'] ?? null);

            if ($authentication === true) {
                if ($judge('username', $this->parse($transportSettings['username'] ?? null))) {
                    $problems[] = Craft::t('web-doctor', 'SMTP authentication is on with no user name');
                }

                if ($judge('password', $this->parse($transportSettings['password'] ?? null))) {
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

        // An SMTP transport with no host at all is recorded as missing one, as it is judged.
        if ($transportType === Smtp::class) {
            $transportSettings += ['host' => null];
        }

        foreach ($transportSettings as $key => $value) {
            if (!in_array($key, ['host', 'port', 'encryptionMethod', 'timeout'], true)) {
                $data[(string)$key] = $this->presence($this->parse(is_scalar($value) ? (string)$value : $value));

                continue;
            }

            // Named outright when set; when not, it is missing, in the word every other setting
            // uses for that, rather than an empty value a reader has to interpret.
            $parsed = $this->parse(is_scalar($value) ? (string)$value : null);
            $presence = $this->presence($parsed);
            $data[(string)$key] = $presence !== Redaction::PRESENT ? $presence : Redaction::redactValue($parsed);
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

    /**
     * A setting as Craft resolves it: its value, an environment variable's or an alias's. One that
     * could not be resolved is {@see self::UNREADABLE}, never null, which would read as missing.
     */
    private function parse(mixed $value): bool|string|null
    {
        if (!is_string($value)) {
            return is_bool($value) ? $value : null;
        }

        try {
            return $this->resolve($value);
        } catch (Throwable) {
            return self::UNREADABLE;
        }
    }

    /**
     * Resolves one setting's environment variable or alias. A seam, so a setting that cannot be
     * resolved is testable by stating it.
     */
    protected function resolve(string $value): bool|string|null
    {
        return App::parseEnv($value);
    }

    /**
     * Whether a resolved setting is there: present, missing, or — where it could not be resolved —
     * unknown, which is not missing.
     */
    private function presence(bool|string|null $parsed): string
    {
        return $parsed === self::UNREADABLE ? Redaction::UNKNOWN : Redaction::presence($parsed);
    }
}
