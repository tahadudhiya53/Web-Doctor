<?php

namespace Tahadudhiya\WebDoctor\verifications;

use Tahadudhiya\WebDoctor\base\VerificationActionInterface;
use Tahadudhiya\WebDoctor\repairs\CreateStorageDirectories;
use Tahadudhiya\WebDoctor\repairs\RetryFailedJobs;

/**
 * The single list of the verification actions Web Doctor ships with: exactly one for each repair it
 * ships with, checking that what the repair did holds.
 */
final class CoreVerificationActions
{
    /**
     * @var array<string, class-string<VerificationActionInterface>> Which action verifies which
     * shipped repair, written out rather than found by name.
     */
    public const FOR_REPAIR = [
        RetryFailedJobs::ID => RetriedJobsSettled::class,
        CreateStorageDirectories::ID => StorageDirectoriesPresent::class,
    ];

    /**
     * @return list<VerificationActionInterface>
     */
    public static function all(): array
    {
        return array_values(array_map(static fn(string $class): VerificationActionInterface => new $class(), self::FOR_REPAIR));
    }
}
