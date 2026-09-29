<?php

namespace Tahadudhiya\WebDoctor\verifications;

use Craft;
use Tahadudhiya\WebDoctor\base\VerificationAction;
use Tahadudhiya\WebDoctor\diagnostics\storage\StoragePathsDiagnostic;
use Tahadudhiya\WebDoctor\models\Repair;
use Tahadudhiya\WebDoctor\models\VerificationCondition;
use Tahadudhiya\WebDoctor\repairs\CreateStorageDirectories;

/**
 * That the storage directories a repair created are still there, and that Craft can write to them.
 *
 * Only the directories the repair recorded creating, located and read through the storage check
 * itself — the same paths and the same reading of each — so this and the check cannot disagree
 * about which directory is meant or what state it is in. A label is not trusted on its own: the path
 * Craft names for it now has to be the path the repair's preview recorded for it, or what is looked
 * at would be some other directory, which cannot be told to be the one the repair made.
 */
class StorageDirectoriesPresent extends VerificationAction
{
    public const ID = 'storage.directoriesPresent';

    /**
     * @var StoragePathsDiagnostic|null The check whose paths and states are read. Craft's own paths
     * unless a caller supplies another, which is how a test points it at a directory of its own.
     */
    public ?StoragePathsDiagnostic $check = null;

    public function name(): string
    {
        return Craft::t('web-doctor', 'The directories the repair created are still there and writable');
    }

    public function repairAction(): string
    {
        return CreateStorageDirectories::ID;
    }

    public function conditions(Repair $repair): array
    {
        $created = $this->recorded($repair, 'created');

        if (!is_array($created) || !array_is_list($created) || $created === [] || count(array_filter($created, 'is_string')) !== count($created)) {
            return [
                VerificationCondition::undetermined('directoriesPresent', Craft::t('web-doctor', 'Each directory the repair created still exists.'), Craft::t('web-doctor', 'Which directories the repair created cannot be read from its record.')),
            ];
        }

        $check = $this->check();
        $paths = $check->paths();
        $before = $this->recordedBefore($repair, 'toCreate');
        $before = is_array($before) ? $before : [];
        $states = [];

        foreach ($created as $label) {
            $path = $paths[$label] ?? null;
            $states[$label] = match (true) {
                // A path Craft no longer names, one that is not absolute, or one that is not where the
                // repair created the directory, is not one this can say anything about.
                $path === null || preg_match('#\A(/|[A-Za-z]:[\\\\/])#', $path) !== 1 => 'unnamed',
                ($before[$label] ?? null) !== $path => 'unnamed',
                default => $check->stateOf($path),
            };
        }

        ksort($states, SORT_STRING);
        $in = static fn(string ...$wanted): array => array_keys(array_filter($states, static fn(string $s): bool => in_array($s, $wanted, true)));

        $gone = $in('missing', 'notADirectory');
        $unknown = $in('unknown', 'unnamed');
        $notWritable = $in('notWritable');
        $present = $in('writable', 'notWritable');

        return [
            match (true) {
                $gone !== [] => VerificationCondition::notHeld('directoriesPresent', Craft::t('web-doctor', 'Each directory the repair created still exists.'), Craft::t('web-doctor', 'Not there as a directory: {list}', ['list' => implode(', ', $gone)])),
                $unknown !== [] => VerificationCondition::undetermined('directoriesPresent', Craft::t('web-doctor', 'Each directory the repair created still exists.'), Craft::t('web-doctor', 'Cannot be looked at, or no longer at the path the repair created: {list}', ['list' => implode(', ', $unknown)])),
                default => VerificationCondition::held('directoriesPresent', Craft::t('web-doctor', 'Each directory the repair created still exists.'), implode(', ', $present)),
            },
            match (true) {
                $notWritable !== [] => VerificationCondition::notHeld('directoriesWritable', Craft::t('web-doctor', 'Craft can write to each directory the repair created.'), Craft::t('web-doctor', 'Not writable: {list}', ['list' => implode(', ', $notWritable)])),
                $present === [] || $unknown !== [] => VerificationCondition::undetermined('directoriesWritable', Craft::t('web-doctor', 'Craft can write to each directory the repair created.'), Craft::t('web-doctor', 'Not every directory could be looked at.')),
                default => VerificationCondition::held('directoriesWritable', Craft::t('web-doctor', 'Craft can write to each directory the repair created.')),
            },
        ];
    }

    private function check(): StoragePathsDiagnostic
    {
        return $this->check ??= new StoragePathsDiagnostic();
    }
}
