<?php

namespace Tahadudhiya\WebDoctor\models;

use DateTimeImmutable;
use Tahadudhiya\WebDoctor\enums\AuditAction;
use Tahadudhiya\WebDoctor\enums\AuditObjectType;
use Tahadudhiya\WebDoctor\enums\AuditResult;
use Tahadudhiya\WebDoctor\helpers\Actor;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\SiteName;
use Tahadudhiya\WebDoctor\helpers\StoredTime;
use Tahadudhiya\WebDoctor\records\AuditRecord;
use Tahadudhiya\WebDoctor\services\Diagnostics;

/**
 * One act in the audit trail: what was done, how it ended, who did it, to what, where and when.
 *
 * An entry is a record of an act, not of what the act read or changed: that lives with the act —
 * the repair, the verification, the investigation — and is guarded there. So an entry's details are
 * restricted by shape, not only redacted: a flat set of named values, each a word, a number or a
 * short list of them. A payload — evidence, a preview, a request body — has nowhere to go, so none
 * can arrive by mistake. What does arrive is redacted as the entry is built and again as it is read
 * back.
 *
 * Immutable. The person's name and the site's are kept as they were when the act was done, so an
 * entry still says who and where after the account or the site is deleted.
 */
final class AuditEntry
{
    use NamesUnreadable;

    /** @var int The most named values an entry keeps. */
    public const MAX_DETAILS = 20;

    /** @var int The most characters a text value keeps. */
    public const MAX_TEXT = 500;

    /** @var int The most values a list keeps. */
    public const MAX_LIST = 20;

    /** @var int The most characters a list's text value keeps. */
    public const MAX_LIST_TEXT = 200;

    /** @var string A detail's name: a word, as a column would be named. Anything else is dropped. */
    private const KEY = '/\A[a-zA-Z][a-zA-Z0-9]{0,63}\z/';

    /** @var string The detail an entry stores to say it left something out. */
    public const TRUNCATED_KEY = 'detailsTruncated';

    public readonly string $summary;
    public readonly ?string $objectLabel;
    public readonly ?string $userName;
    public readonly ?string $siteName;

    /** @var array<string, scalar|null|list<scalar|null>> */
    public readonly array $details;

    /** @var bool Whether anything given was left out or cut short to fit. */
    public readonly bool $truncated;

    /**
     * @param array<array-key, mixed> $details
     * @param list<string> $unreadable The fields a stored row held something other than a value of,
     * named; empty for an entry being written. A field named here is null rather than a guess.
     */
    public function __construct(
        public readonly ?int $id,
        public readonly ?AuditAction $action,
        public readonly ?AuditResult $result,
        string $summary,
        public readonly ?AuditObjectType $objectType,
        public readonly ?string $objectId,
        ?string $objectLabel,
        public readonly ?int $issueId,
        public readonly ?int $userId,
        ?string $userName,
        public readonly string $environment,
        public readonly ?int $siteId,
        ?string $siteName,
        array $details,
        public readonly ?DateTimeImmutable $occurredAt,
        public readonly array $unreadable = [],
    ) {
        $this->summary = self::fit(Redaction::redactString($summary), 1000);
        $this->objectLabel = $objectLabel === null || $objectLabel === '' ? null : self::fit(Redaction::redactString($objectLabel), 255);
        $this->userName = $userName === null || $userName === '' ? null : self::fit(Redaction::redactString($userName), 255);
        $this->siteName = $siteName === null || $siteName === '' ? null : self::fit(Redaction::redactString($siteName), 255);
        [$this->details, $this->truncated] = self::cleanDetails($details);
    }

    /**
     * Reads a stored row back through the constructor, so what was redacted going in is redacted
     * again coming out.
     *
     * Every field is read strictly. A value that is not one — an action, result or object this
     * version does not know, a moment that is not one, details that are not what Web Doctor writes,
     * an ID that is not an ID, a user or site named by ID but not by name — is named in
     * `$unreadable` and read as null, never as some other valid value: an unknown result is not "no
     * result", a broken moment is not the epoch, and broken details are not "no details". The entry
     * is still returned, so a log with a damaged row says so rather than quietly leaving it out.
     */
    public static function fromRecord(AuditRecord $record): self
    {
        $unreadable = [];
        $action = AuditAction::tryFrom((string)$record->action);
        $result = AuditResult::tryFrom((string)$record->result);
        $objectType = AuditObjectType::tryFrom((string)$record->objectType);
        $occurredAt = StoredTime::read($record->occurredAt);
        $issueId = self::id($record->issueId);
        $userId = self::id($record->userId);
        $siteId = self::id($record->siteId);
        $userName = is_string($record->userName) && $record->userName !== '' ? $record->userName : null;
        $siteName = is_string($record->siteName) && $record->siteName !== '' ? $record->siteName : null;
        [$details, $detailsRead] = self::readDetails($record->details);

        foreach ([
            'action' => $action === null,
            'result' => $result === null,
            'objectType' => $objectType === null,
            'objectId' => !is_string($record->objectId) || $record->objectId === '',
            'summary' => $record->summary === '',
            'environment' => trim($record->environment) === '',
            'occurredAt' => $occurredAt === null,
            'issueId' => $issueId === false,
            // Both or neither are written; a name with no ID is somebody whose account has gone.
            'user' => $userId === false || ($userId !== null && $userName === null),
            'site' => $siteId === false || ($siteId !== null && $siteName === null),
            'details' => !$detailsRead,
        ] as $field => $broken) {
            if ($broken) {
                $unreadable[] = $field;
            }
        }

        return new self(
            id: (int)$record->id,
            action: $action,
            result: $result,
            summary: $record->summary,
            objectType: $objectType,
            objectId: is_string($record->objectId) && $record->objectId !== '' ? $record->objectId : null,
            objectLabel: is_string($record->objectLabel) ? $record->objectLabel : null,
            issueId: $issueId === false ? null : $issueId,
            userId: $userId === false ? null : $userId,
            userName: $userName,
            environment: $record->environment,
            siteId: $siteId === false ? null : $siteId,
            siteName: $siteName,
            details: $details,
            occurredAt: $occurredAt,
            unreadable: $unreadable,
        );
    }

    /** Whether every field was read back as it was written. */
    public function isIntact(): bool
    {
        return $this->unreadable === [];
    }

    /**
     * The details as they are stored: the named values, and the mark saying something was left out.
     *
     * @return array<string, scalar|null|list<scalar|null>>
     */
    public function storedDetails(): array
    {
        return $this->truncated ? $this->details + [self::TRUNCATED_KEY => true] : $this->details;
    }

    /**
     * Restricts details to what an entry may carry and redacts them: named values, each a scalar
     * or a short list of scalars, text cut to length. Anything else is dropped, and its being
     * dropped is said.
     *
     * @param array<array-key, mixed> $details
     * @return array{0: array<string, scalar|null|list<scalar|null>>, 1: bool}
     */
    public static function cleanDetails(array $details): array
    {
        $kept = [];
        $cut = false;

        foreach ($details as $key => $value) {
            // One slot is kept for the mark that says something was left out.
            if (!is_string($key) || $key === self::TRUNCATED_KEY || preg_match(self::KEY, $key) !== 1 || count($kept) >= self::MAX_DETAILS - 1) {
                $cut = true;

                continue;
            }

            if (is_array($value)) {
                if (!array_is_list($value)) {
                    $cut = true;

                    continue;
                }

                $list = [];

                foreach ($value as $item) {
                    if (count($list) >= self::MAX_LIST || !self::isScalar($item)) {
                        $cut = true;

                        continue;
                    }

                    $list[] = is_string($item) ? self::fit($item, self::MAX_LIST_TEXT, $cut) : $item;
                }

                $kept[$key] = $list;

                continue;
            }

            if (!self::isScalar($value)) {
                $cut = true;

                continue;
            }

            $kept[$key] = is_string($value) ? self::fit($value, self::MAX_TEXT, $cut) : $value;
        }

        // By key and by value: a detail named after a credential is withheld whatever it holds,
        // and a credential quoted inside a value is withheld wherever it appears.
        /** @var array<string, scalar|null|list<scalar|null>> $redacted */
        $redacted = Redaction::redact($kept);

        return [$redacted, $cut];
    }

    /** Who did it, as a reader is told: their name as it was, that nobody was signed in, or that it cannot be read. */
    public function userLabel(): string
    {
        return Actor::label($this->userId, $this->userName, $this->isUnreadable('user'));
    }

    /**
     * Where it was done, by the site's name as it was then — the entry is a record of that moment,
     * so it is not looked up again.
     */
    public function siteLabel(): string
    {
        return SiteName::recorded($this->siteId, $this->siteName, $this->isUnreadable('site'));
    }

    /**
     * Where in the control panel the object can be read, or null where it cannot: a run is not
     * kept as a record, and an issue that has been deleted has no page.
     */
    public function cpPath(): ?string
    {
        $id = $this->objectId !== null && preg_match('/\A[1-9]\d{0,17}\z/', $this->objectId) === 1 ? $this->objectId : null;

        return match ($this->objectType) {
            AuditObjectType::ISSUE => $this->issueId === null ? null : 'web-doctor/issues/' . $this->issueId,
            AuditObjectType::REPAIR => $id === null ? null : 'web-doctor/repairs/' . $id,
            AuditObjectType::VERIFICATION => is_int($this->details['repairId'] ?? null) && $this->details['repairId'] > 0 ? 'web-doctor/repairs/' . $this->details['repairId'] . '#verification' : null,
            AuditObjectType::INVESTIGATION => match (true) {
                $id === null => null,
                $this->issueId !== null => sprintf('web-doctor/issues/%d/investigations/%s', $this->issueId, $id),
                is_string($this->details['recipeId'] ?? null) && Diagnostics::isValidId($this->details['recipeId']) => sprintf('web-doctor/recipes/%s/investigations/%s', $this->details['recipeId'], $id),
                default => null,
            },
            AuditObjectType::RUN => is_int($this->details['historyId'] ?? null) && $this->details['historyId'] > 0 ? 'web-doctor/history/' . $this->details['historyId'] : null,
            null => null,
        };
    }

    private static function isScalar(mixed $value): bool
    {
        return $value === null || is_scalar($value);
    }

    private static function fit(string $value, int $length, bool &$cut = false): string
    {
        // Malformed UTF-8 is repaired before it is measured or cut, so a cut never lands inside a
        // character and nothing unstorable reaches the column.
        $value = mb_scrub($value, 'UTF-8');

        if (mb_strlen($value) <= $length) {
            return $value;
        }

        $cut = true;

        return mb_substr($value, 0, $length - 1) . '…';
    }

    /**
     * A stored ID: null where none is stored, the ID where one is, and false where what is stored is
     * not an ID.
     */
    private static function id(mixed $value): int|false|null
    {
        if ($value === null) {
            return null;
        }

        if ((is_int($value) && $value > 0) || (is_string($value) && preg_match('/\A[1-9]\d{0,17}\z/', $value) === 1)) {
            return (int)$value;
        }

        return false;
    }

    /**
     * Stored details: nothing stored is no details; otherwise they have to be the JSON object Web
     * Doctor writes, in the shape it writes — anything else is unreadable, not empty.
     *
     * @return array{0: array<array-key, mixed>, 1: bool} The details, and whether they could be read.
     */
    private static function readDetails(mixed $value): array
    {
        if ($value === null) {
            return [[], true];
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return [[], false];
        }

        $truncated = ($decoded[self::TRUNCATED_KEY] ?? null) === true;
        unset($decoded[self::TRUNCATED_KEY]);
        [, $cut] = self::cleanDetails($decoded);

        // A row in a shape Web Doctor never writes is not one to be trimmed into shape.
        if ($cut) {
            return [[], false];
        }

        return [$truncated ? $decoded + [self::TRUNCATED_KEY => true] : $decoded, true];
    }
}
