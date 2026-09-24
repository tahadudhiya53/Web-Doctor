<?php

namespace Tahadudhiya\WebDoctor\diagnostics\php;

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
 * The handful of PHP settings that decide whether Craft works properly.
 *
 * This is deliberately not a survey of `php.ini`. It looks at four things, each of which
 * explains a failure developers otherwise spend a long time chasing: too little memory for an
 * update or a large query, an upload limit that silently truncates the thing being uploaded,
 * an execution limit that kills a migration part way, and an opcode cache configured to throw
 * away the docblock comments Craft reads at runtime.
 *
 * The last one is the reason this check exists at all. With `opcache.save_comments` off, Craft
 * fails in ways that look like anything but a PHP setting.
 */
class PhpConfigurationDiagnostic extends Diagnostic
{
    public const ID = 'php.configuration';

    /** @var int The memory Craft's documentation asks for, in bytes. */
    private const RECOMMENDED_MEMORY_LIMIT = 256 * 1024 * 1024;

    /** @var int Below this, an update or a long migration is likely to be cut short, in seconds. */
    private const MINIMUM_EXECUTION_TIME = 30;

    public function name(): string
    {
        return Craft::t('web-doctor', 'PHP configuration');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::PHP;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Checks the PHP settings Craft depends on: memory, execution time, upload limits and opcode cache comments.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        $settings = $this->settings();

        $opcacheLoaded = $settings['opcache'];
        $saveComments = !$opcacheLoaded || $settings['opcache.save_comments'];

        $evidence = [
            $this->evidence(EvidenceType::CONFIGURATION, Craft::t('web-doctor', 'PHP configuration'), [
                // The web server and the command line routinely run different builds with
                // different settings, so a finding is only about the one it looked at.
                'sapi' => $settings['sapi'],
                'memory_limit' => $settings['memory_limit'] ?? Redaction::UNKNOWN,
                'max_execution_time' => $settings['max_execution_time'] ?? Redaction::UNKNOWN,
                'upload_max_filesize' => $settings['upload_max_filesize'] ?? Redaction::UNKNOWN,
                'post_max_size' => $settings['post_max_size'] ?? Redaction::UNKNOWN,
                'opcache' => $opcacheLoaded,
                'opcache.save_comments' => $saveComments,
            ]),
        ];

        if (!$saveComments) {
            return $this->fail(
                Craft::t('web-doctor', 'The opcode cache is discarding docblock comments, which Craft reads at runtime.'),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Set opcache.save_comments to 1 and restart PHP.'),
                severity: Severity::HIGH,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Craft and its plugins read information from docblocks while running, so with this setting off they fail in ways that do not look like a PHP setting at all.'),
            );
        }

        // Asked after the opcode cache, which is a definite failure and stays one whatever
        // else could not be read: uncertainty never outranks something already established.
        // A setting PHP would not report is not a setting with a comfortable value, so judging
        // the rest and passing would be this check calling the configuration fine on the
        // strength of the parts it could read.
        $unreadable = array_keys(array_filter(
            ['memory_limit' => null, 'max_execution_time' => null, 'upload_max_filesize' => null, 'post_max_size' => null],
            static fn(mixed $ignored, string $name): bool => $settings[$name] === null,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($unreadable !== []) {
            return $this->unknown(
                Craft::t('web-doctor', 'PHP would not report these settings, so its configuration could not be judged: {settings}.', [
                    'settings' => implode(', ', $unreadable),
                ]),
                $evidence,
            );
        }

        $memoryLimit = App::phpSizeToBytes($settings['memory_limit']);
        $executionTime = (int)$settings['max_execution_time'];
        $uploadMax = App::phpSizeToBytes($settings['upload_max_filesize']);
        $postMax = App::phpSizeToBytes($settings['post_max_size']);

        $problems = [];

        // A memory limit of -1 comes back as a negative byte count and means no limit at all,
        // which is not something to warn about.
        if ($memoryLimit > 0 && $memoryLimit < self::RECOMMENDED_MEMORY_LIMIT) {
            $problems[] = Craft::t('web-doctor', 'memory_limit is {limit}, below the 256M Craft asks for', ['limit' => $settings['memory_limit']]);
        }

        // Zero means no limit, which is the command line's normal state.
        if ($executionTime > 0 && $executionTime < self::MINIMUM_EXECUTION_TIME) {
            $problems[] = Craft::t('web-doctor', 'max_execution_time is {seconds}s, short enough to cut an update short', ['seconds' => $executionTime]);
        }

        if ($postMax > 0 && $uploadMax > $postMax) {
            $problems[] = Craft::t('web-doctor', 'upload_max_filesize ({upload}) is larger than post_max_size ({post}), so uploads are cut off at the smaller of the two', [
                'upload' => $settings['upload_max_filesize'],
                'post' => $settings['post_max_size'],
            ]);
        }

        if ($problems !== []) {
            return $this->warning(
                Craft::t('web-doctor', 'PHP is configured in a way that will limit Craft: {problems}.', ['problems' => implode('; ', $problems)]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Adjust these settings for the PHP that runs Craft and restart it.'),
                severity: Severity::MEDIUM,
                confidence: Confidence::CONFIRMED,
            );
        }

        return $this->pass(Craft::t('web-doctor', 'The PHP settings Craft depends on are within range.'), $evidence);
    }

    /**
     * The settings this check judges, exactly as PHP reports them.
     *
     * Protected so a test can state a server's configuration rather than needing a PHP built
     * with it. This is the whole of what the check reads from outside itself; everything after
     * it is judgement.
     *
     * A setting PHP declines to report comes back as null rather than as an empty string,
     * because an empty string normalises to zero and zero means "no limit" — which would turn
     * "could not read it" into "there is nothing to worry about".
     *
     * @return array{sapi: string, memory_limit: string|null, max_execution_time: string|null, upload_max_filesize: string|null, post_max_size: string|null, opcache: bool, 'opcache.save_comments': bool}
     */
    protected function settings(): array
    {
        $read = static function(string $name): ?string {
            $value = ini_get($name);

            return $value === false || $value === '' ? null : $value;
        };

        return [
            'sapi' => PHP_SAPI,
            'memory_limit' => $read('memory_limit'),
            'max_execution_time' => $read('max_execution_time'),
            'upload_max_filesize' => $read('upload_max_filesize'),
            'post_max_size' => $read('post_max_size'),
            'opcache' => extension_loaded('Zend OPcache') || extension_loaded('opcache'),
            'opcache.save_comments' => App::phpConfigValueAsBool('opcache.save_comments'),
        ];
    }
}
