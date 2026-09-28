<?php

namespace Tahadudhiya\WebDoctor\repairs;

use Craft;
use craft\helpers\FileHelper;
use RuntimeException;
use Tahadudhiya\WebDoctor\base\RepairAction;
use Tahadudhiya\WebDoctor\diagnostics\storage\StoragePathsDiagnostic;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\enums\RepairRisk;
use Tahadudhiya\WebDoctor\errors\Refusal;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\Prerequisite;
use Tahadudhiya\WebDoctor\models\RecommendationCase;
use Tahadudhiya\WebDoctor\models\RepairContext;
use Tahadudhiya\WebDoctor\models\RepairReport;

/**
 * Creates the storage directories the storage check found missing.
 *
 * Only directories that do not exist, and only the ones the check itself names: the list and each
 * directory's state are read through the check, so the repair cannot act on a directory the check
 * would not have reported. Created with Craft's own directory helper, which applies the directory
 * permissions Craft is configured with — exactly what Craft does when it creates them on demand.
 *
 * Nothing that exists is touched. A directory Craft cannot write to is a question of ownership and
 * permissions on the server, which is not something to change from a web request.
 */
class CreateStorageDirectories extends RepairAction
{
    public const ID = 'storage.createDirectories';

    /**
     * @var StoragePathsDiagnostic|null The check whose directories and states are acted on. Craft's
     * own paths unless a caller supplies another, which is how a test points it at a directory of
     * its own.
     */
    public ?StoragePathsDiagnostic $check = null;

    public function name(): string
    {
        return Craft::t('web-doctor', 'Create the missing storage directories');
    }

    public function description(): string
    {
        return Craft::t('web-doctor', 'Creates the storage directories the check found missing, empty, with Craft’s own directory helper and the directory permissions Craft is configured to use. Directories that exist are not touched.');
    }

    public function diagnosticId(): string
    {
        return StoragePathsDiagnostic::ID;
    }

    public function recommendation(): ?string
    {
        return 'storage.createDirectories';
    }

    public function risk(): RepairRisk
    {
        return RepairRisk::LOW;
    }

    public function riskReason(): string
    {
        return Craft::t('web-doctor', 'Only directories that do not exist are created, and empty; nothing that exists is changed.');
    }

    public function isApplicable(RecommendationCase $finding): bool
    {
        return $finding->diagnosticId === StoragePathsDiagnostic::ID
            && $finding->where(EvidenceType::FILESYSTEM, static fn(Evidence $e): bool => is_array($e->get('states')) && in_array('missing', $e->get('states'), true)) !== [];
    }

    public function prerequisites(RepairContext $context): array
    {
        $missing = $this->missing();
        $blocked = array_keys(array_filter($missing, fn(string $path): bool => !$this->creatable($path)));

        return [
            Prerequisite::checked(
                'directoriesMissing',
                Craft::t('web-doctor', 'At least one of Craft’s storage directories is missing now.'),
                $missing !== [],
                $missing === [] ? Craft::t('web-doctor', 'None is missing.') : implode(', ', array_keys($missing)),
            ),
            Prerequisite::checked(
                'parentsWritable',
                Craft::t('web-doctor', 'PHP can create each missing directory: the nearest directory above it exists and is writable.'),
                $blocked === [],
                $blocked === [] ? null : Craft::t('web-doctor', 'Cannot be created: {list}', ['list' => implode(', ', $blocked)]),
            ),
        ];
    }

    public function preview(RepairContext $context): RepairReport
    {
        $missing = $this->missing();
        ksort($missing, SORT_STRING);

        return new RepairReport(
            summary: Craft::t('web-doctor', '{count, plural, =1{Creates one directory} other{Creates # directories}}. Nothing else is changed.', ['count' => count($missing)]),
            items: array_map(
                static fn(string $label, string $path): string => Craft::t('web-doctor', 'Create “{label}”: {path}', ['label' => $label, 'path' => $path]),
                array_keys($missing),
                array_values($missing),
            ),
            state: [$this->state(Craft::t('web-doctor', 'Storage directories before the repair'), ['toCreate' => $missing])],
            fingerprint: RepairReport::fingerprintOf($missing),
        );
    }

    public function execute(RepairContext $context, RepairReport $preview): RepairReport
    {
        $missing = $this->missing();
        ksort($missing, SORT_STRING);

        if (!hash_equals((string)$preview->fingerprint, RepairReport::fingerprintOf($missing))) {
            throw new Refusal(Craft::t('web-doctor', 'The missing directories changed just before they were created, so none was. Preview the repair again.'));
        }

        $created = [];

        foreach ($missing as $label => $path) {
            if (!FileHelper::createDirectory($path)) {
                throw new RuntimeException(sprintf('The "%s" storage directory could not be created.', $label));
            }

            $created[] = Craft::t('web-doctor', 'Created “{label}”: {path}', ['label' => $label, 'path' => $path]);
        }

        return new RepairReport(
            summary: Craft::t('web-doctor', '{count, plural, =1{Created one directory} other{Created # directories}}.', ['count' => count($created)]),
            items: $created,
            state: [$this->state(Craft::t('web-doctor', 'Storage directories after the repair'), ['created' => array_keys($missing)])],
        );
    }

    public function verifyWith(): array
    {
        return [StoragePathsDiagnostic::ID];
    }

    public function verification(): string
    {
        return Craft::t('web-doctor', 'The storage check finds every storage directory present.');
    }

    /**
     * The directories the check reports missing now, by the label the check gives them.
     *
     * @return array<string, string>
     */
    private function missing(): array
    {
        $check = $this->check();

        return array_filter($check->paths(), static fn(string $path): bool => $check->stateOf($path) === 'missing');
    }

    /**
     * Whether the nearest directory that exists above a path is one PHP can create inside.
     */
    private function creatable(string $path): bool
    {
        // A link whose target has gone reads as missing, but creating a directory there would fail
        // or follow the link somewhere else. It is left for a person.
        if (is_link($path)) {
            return false;
        }

        $parent = dirname($path);

        while (!file_exists($parent) && dirname($parent) !== $parent) {
            $parent = dirname($parent);
        }

        return is_dir($parent) && is_writable($parent);
    }

    /**
     * Every directory the check looks at, with its state, as evidence.
     *
     * @param array<string, mixed> $extra
     */
    private function state(string $label, array $extra): Evidence
    {
        $check = $this->check();
        $states = array_map(static fn(string $path): string => $check->stateOf($path), $check->paths());
        ksort($states, SORT_STRING);

        return new Evidence(EvidenceType::FILESYSTEM, $label, self::ID, ['states' => $states] + $extra);
    }

    private function check(): StoragePathsDiagnostic
    {
        return $this->check ??= new StoragePathsDiagnostic();
    }
}
