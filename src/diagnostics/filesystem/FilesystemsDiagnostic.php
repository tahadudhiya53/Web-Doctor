<?php

namespace Tahadudhiya\WebDoctor\diagnostics\filesystem;

use Craft;
use craft\base\FsInterface;
use craft\fs\MissingFs;
use craft\models\Volume;
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\SafeException;
use Throwable;

/**
 * Whether each asset volume can actually reach the storage behind it.
 *
 * A volume whose filesystem is unreachable does not announce itself. Assets keep existing as
 * records, listings keep working, and the files are simply not there — which looks like missing
 * content rather than like a credential that expired or a bucket that was renamed.
 *
 * The probe is a read and nothing else. Web Doctor does not write a test file into a volume it
 * was asked to look at, does not create a directory and does not list one recursively: those
 * are all things that either change somebody's storage or cost real money on a large bucket.
 * Asking whether the root directory is there answers the question without doing any of that,
 * and at shallow depth even that is left alone and the configuration is reported on its own.
 */
class FilesystemsDiagnostic extends Diagnostic
{
    public const ID = 'filesystem.volumes';

    public function name(): string
    {
        return Craft::t('web-doctor', 'Asset filesystems');
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::FILESYSTEM;
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Checks that every asset volume has a filesystem behind it and that the filesystem can be reached, without writing anything.');
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        try {
            $volumes = $this->volumes();
            $filesystems = $this->filesystems();
        } catch (Throwable $e) {
            return $this->unknown(
                Craft::t('web-doctor', 'The configured filesystems could not be read.'),
                [Evidence::fromThrowable($e, $this->id())],
            );
        }

        if ($volumes === [] && $filesystems === []) {
            return $this->skipped(Craft::t('web-doctor', 'No filesystems or asset volumes are configured.'));
        }

        // A probe reaches the network, so it is work the depth is allowed to decline.
        $probe = $context->depth->isAtLeast(DiagnosticDepth::NORMAL);

        // Sorted so two runs of the same site are comparable whatever order Craft's services
        // return things in.
        $handles = array_values(array_filter(array_map(static fn(FsInterface $fs): ?string => $fs->handle, $filesystems)));
        sort($handles, SORT_STRING);
        usort($volumes, static fn(Volume $a, Volume $b): int => strcmp((string)$a->handle, (string)$b->handle));

        $evidence = [
            $this->evidence(EvidenceType::FILESYSTEM, Craft::t('web-doctor', 'Configured filesystems'), [
                'filesystems' => $handles,
                'volumes' => count($volumes),
                'probed' => $probe,
            ]),
        ];

        $missing = [];
        $unreachable = [];

        foreach ($volumes as $volume) {
            $reason = null;

            try {
                $fs = $this->filesystemFor($volume);
            } catch (Throwable $e) {
                // Why it could not be resolved is the part somebody needs, so it is recorded
                // rather than reduced to the fact that it was not.
                $fs = null;
                $reason = SafeException::from($e)->summary();
            }

            if ($fs === null || $fs instanceof MissingFs) {
                $missing[] = $volume->handle;
                $evidence[] = $this->volumeEvidence($volume, array_filter([
                    'filesystem' => $volume->getFsHandle(),
                    'state' => 'missing',
                    'reason' => $reason,
                ], static fn(mixed $value): bool => $value !== null));
                continue;
            }

            if (!$probe) {
                $evidence[] = $this->volumeEvidence($volume, ['filesystem' => $fs->handle, 'state' => 'notProbed']);
                continue;
            }

            try {
                $reachable = $fs->directoryExists('');
                $evidence[] = $this->volumeEvidence($volume, [
                    'filesystem' => $fs->handle,
                    'state' => $reachable ? 'reachable' : 'unreachable',
                ]);

                if (!$reachable) {
                    $unreachable[] = $volume->handle;
                }
            } catch (Throwable $e) {
                // A filesystem that throws rather than answering is unreachable in the only
                // sense that matters here, and the exception is what says why.
                $unreachable[] = $volume->handle;
                $evidence[] = $this->volumeEvidence($volume, [
                    'filesystem' => $fs->handle,
                    'state' => 'unreachable',
                    'reason' => SafeException::from($e)->summary(),
                ]);
            }
        }

        sort($missing, SORT_STRING);
        sort($unreachable, SORT_STRING);

        if ($missing !== []) {
            return $this->fail(
                Craft::t('web-doctor', 'Asset volumes have no filesystem behind them: {handles}.', ['handles' => implode(', ', $missing)]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Point each of these volumes at an existing filesystem under Settings → Filesystems, or restore the filesystem it used to use.'),
                severity: Severity::HIGH,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Craft keeps the volume and its asset records, so the assets appear to exist while no file behind them can be read or written.'),
            );
        }

        if ($unreachable !== []) {
            return $this->fail(
                Craft::t('web-doctor', 'Asset volumes could not be reached: {handles}.', ['handles' => implode(', ', $unreachable)]),
                $evidence,
                recommendation: Craft::t('web-doctor', 'Check the credentials, bucket or path each filesystem is configured with, and that this environment can reach it.'),
                severity: Severity::HIGH,
                confidence: Confidence::CONFIRMED,
                description: Craft::t('web-doctor', 'Assets on these volumes cannot be read or written, which shows up as missing images rather than as a storage failure.'),
            );
        }

        if (!$probe) {
            return $this->info(
                Craft::t('web-doctor', 'Every asset volume has a filesystem behind it. Reachability was not tested at this depth.'),
                $evidence,
            );
        }

        return $this->pass(
            Craft::t('web-doctor', 'Every asset volume has a filesystem behind it and could be reached.'),
            $evidence,
        );
    }

    /**
     * The volumes and filesystems this installation is configured with, in Craft's own order.
     * Protected, with the one below, so a test can state a broken storage arrangement rather
     * than needing one.
     *
     * @return Volume[]
     * @throws Throwable where the configuration cannot be read.
     */
    protected function volumes(): array
    {
        return Craft::$app->getVolumes()->getAllVolumes();
    }

    /**
     * @return FsInterface[]
     * @throws Throwable where the configuration cannot be read.
     */
    protected function filesystems(): array
    {
        return Craft::$app->getFs()->getAllFilesystems();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function volumeEvidence(Volume $volume, array $data): Evidence
    {
        return $this->evidence(EvidenceType::FILESYSTEM, (string)$volume->name, ['volume' => $volume->handle] + $data);
    }

    /**
     * The filesystem behind a volume, or null where the volume names none.
     *
     * Allowed to throw: a volume whose filesystem cannot be resolved is a finding, and the
     * reason is recorded with it rather than discarded here.
     *
     * @throws Throwable where the filesystem cannot be resolved.
     */
    protected function filesystemFor(Volume $volume): ?FsInterface
    {
        return $volume->getFs();
    }
}
