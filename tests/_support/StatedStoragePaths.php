<?php

namespace Tahadudhiya\WebDoctor\Tests\_support;

use RuntimeException;
use Tahadudhiya\WebDoctor\diagnostics\storage\StoragePathsDiagnostic;

/**
 * The storage check looking at the directories the test states — and, where the test says, unable to
 * read where Craft's storage is at all.
 */
class StatedStoragePaths extends StoragePathsDiagnostic
{
    /** @var array<string, string> */
    public array $stated = [];

    public bool $breaks = false;

    public function paths(): array
    {
        if ($this->breaks) {
            throw new RuntimeException('The storage paths could not be read.');
        }

        return $this->stated;
    }
}
