<?php

namespace Tahadudhiya\WebDoctor\models;

use Craft;
use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use Tahadudhiya\WebDoctor\enums\ConditionRole;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\records\RootCauseRecord;
use Tahadudhiya\WebDoctor\rules\RootCauseRules;
use Throwable;

/**
 * A cause that could explain a problem, how firmly it is held, and every reason for that.
 *
 * A candidate, not a verdict. It says what it would explain, which evidence was found for it and
 * which against it, what was looked for and not found, how the confidence follows from those, what
 * to do, and what to look at next. Nothing here is certain unless it says Confirmed, and it says
 * Confirmed only on the terms {@see self::confidenceFor()} sets out.
 *
 * Built by a rule when a problem is weighed, and read back from the table the same shape — through
 * redaction, so what leaves the database is held to the rules that apply now.
 */
final class RootCause implements JsonSerializable
{
    /**
     * @var list<Confidence> The steps a cause can be held at, least firmly first. Informational is
     * not one: something offered only for context is not a cause.
     */
    public const LADDER = [Confidence::POSSIBLE, Confidence::LIKELY, Confidence::HIGH, Confidence::CONFIRMED];

    public readonly string $title;
    public readonly string $statement;
    public readonly string $problem;
    public readonly string $recommendation;
    public readonly ?string $limitation;

    /** @var list<string> */
    public readonly array $reasoning;

    /** @var list<string> */
    public readonly array $nextSteps;

    /**
     * @param string $ruleId The rule it was weighed under.
     * @param string $title The cause, in a line.
     * @param string $statement What it would mean, and what it would explain.
     * @param string $problem The problem it was weighed against, as it read then.
     * @param list<ConditionOutcome> $conditions Every condition of the rule, as found.
     * @param list<string> $reasoning How the confidence follows from the conditions, in order.
     * @param list<array{id: int, title: string, severity: string}> $relatedIssues Other issues the
     * evidence for it came from, as they stood then.
     * @param list<string> $nextSteps What to look at to tell whether it is right.
     * @param string|null $limitation Why the rule never holds it more firmly, where it does not.
     * @param int $position Where it sits among the causes weighed together, most firmly held first.
     */
    public function __construct(
        public readonly string $ruleId,
        string $title,
        string $statement,
        string $problem,
        public readonly Confidence $confidence,
        public readonly array $conditions,
        array $reasoning,
        public readonly array $relatedIssues,
        string $recommendation,
        array $nextSteps,
        ?string $limitation = null,
        public readonly int $position = 0,
        public readonly ?int $id = null,
        public readonly ?int $investigationId = null,
        public readonly ?DateTimeImmutable $recordedAt = null,
    ) {
        $this->title = Redaction::redactString($title);
        $this->statement = Redaction::redactString($statement);
        $this->problem = Redaction::redactString($problem);
        $this->recommendation = Redaction::redactString($recommendation);
        $this->limitation = $limitation === null || $limitation === '' ? null : Redaction::redactString($limitation);
        $this->reasoning = array_map(static fn(string $line): string => Redaction::redactString($line), $reasoning);
        $this->nextSteps = array_map(static fn(string $line): string => Redaction::redactString($line), $nextSteps);
    }

    /**
     * How firmly a cause is held, from what was found for and against it. The whole rule, stated
     * once and shown to the reader alongside every cause:
     *
     * - what it requires was found: possible;
     * - and at least one supporting signal: likely;
     * - and every supporting signal, where it has at least two: high;
     * - never above the rule's own ceiling, whatever else was found;
     * - confirmed only when evidence that establishes it was found, recorded by a check that
     *   established it, with nothing counting against it;
     * - and one step lower for each thing that counts against it, never below possible.
     *
     * The ceiling holds whatever the rest arrived at, so nothing — confirming evidence
     * included — can carry a cause past it. A ceiling of high is the one that lets
     * confirmation through: confirmed is reached by evidence, never granted by a ceiling.
     *
     * @param list<ConditionOutcome> $conditions
     */
    public static function confidenceFor(array $conditions, Confidence $ceiling = Confidence::HIGH): Confidence
    {
        $for = array_values(array_filter($conditions, static fn(ConditionOutcome $c): bool => in_array($c->role, [ConditionRole::SUPPORTING, ConditionRole::CONFIRMING], true)));
        $found = count(array_filter($for, static fn(ConditionOutcome $c): bool => $c->met()));
        $against = count(array_filter($conditions, static fn(ConditionOutcome $c): bool => $c->role === ConditionRole::CONTRADICTING && $c->met()));
        $confirmed = array_filter($conditions, static fn(ConditionOutcome $c): bool => $c->confirms()) !== [];

        if ($confirmed && $against === 0) {
            return self::capped(Confidence::CONFIRMED, $ceiling);
        }

        $step = match (true) {
            count($for) >= 2 && $found === count($for) => 2,
            $found >= 1 => 1,
            default => 0,
        };

        // Held to the ceiling before anything counting against it steps it down, so each thing
        // against a cause costs it a step below what it could be held at, not below what it
        // would have been without the ceiling; and held to it again at the end, whatever else.
        $step = min($step, max(0, min(2, (int)array_search(self::capped(Confidence::HIGH, $ceiling), self::LADDER, true))));

        return self::capped(self::LADDER[max(0, $step - $against)], $ceiling);
    }

    /**
     * A confidence held to a rule's ceiling. A ceiling of high admits confirmed, for the reason
     * {@see confidenceFor()} gives; a lower one admits nothing above itself.
     */
    public static function capped(Confidence $confidence, Confidence $ceiling): Confidence
    {
        $limit = $ceiling->rank() >= Confidence::HIGH->rank() ? Confidence::CONFIRMED : $ceiling;

        return $confidence->rank() > $limit->rank() ? $limit : $confidence;
    }

    /**
     * The ceiling of the rule with this ID, where one is still written out.
     */
    private static function ceilingOf(string $ruleId): ?Confidence
    {
        static $ceilings = null;

        if ($ceilings === null) {
            $ceilings = [];

            foreach (RootCauseRules::all() as $rule) {
                $ceilings[$rule->id] = $rule->ceiling;
            }
        }

        return $ceilings[$ruleId] ?? null;
    }

    /**
     * Orders causes most firmly held first; then those with less against them; then those with
     * more for them; then by rule ID. Never by the order they were found in, nor by the order the
     * rules were handed over in, so the same findings always read the same way.
     */
    public static function compare(self $a, self $b): int
    {
        return [$b->confidence->rank(), $a->against(), $b->found(), $a->ruleId]
            <=> [$a->confidence->rank(), $b->against(), $a->found(), $b->ruleId];
    }

    /**
     * The same cause at a given place in the order.
     */
    public function at(int $position): self
    {
        return new self(
            ruleId: $this->ruleId,
            title: $this->title,
            statement: $this->statement,
            problem: $this->problem,
            confidence: $this->confidence,
            conditions: $this->conditions,
            reasoning: $this->reasoning,
            relatedIssues: $this->relatedIssues,
            recommendation: $this->recommendation,
            nextSteps: $this->nextSteps,
            limitation: $this->limitation,
            position: $position,
            id: $this->id,
            investigationId: $this->investigationId,
            recordedAt: $this->recordedAt,
        );
    }

    /**
     * The evidence for it: what it requires and what supports or establishes it, as found.
     *
     * @return list<ConditionOutcome>
     */
    public function supporting(): array
    {
        return array_values(array_filter($this->conditions, static fn(ConditionOutcome $c): bool => $c->role->isFor() && $c->met()));
    }

    /**
     * The evidence against it.
     *
     * @return list<ConditionOutcome>
     */
    public function conflicting(): array
    {
        return array_values(array_filter($this->conditions, static fn(ConditionOutcome $c): bool => $c->role === ConditionRole::CONTRADICTING && $c->met()));
    }

    /**
     * What would have supported it, looked for and not found. Said rather than left out, because a
     * reader weighing a cause needs to know what its confidence is missing.
     *
     * @return list<ConditionOutcome>
     */
    public function unmet(): array
    {
        return array_values(array_filter($this->conditions, static fn(ConditionOutcome $c): bool => in_array($c->role, [ConditionRole::SUPPORTING, ConditionRole::CONFIRMING], true) && !$c->met()));
    }

    /** How many signals for it were found, beyond what it requires. */
    public function found(): int
    {
        return count(array_filter($this->conditions, static fn(ConditionOutcome $c): bool => in_array($c->role, [ConditionRole::SUPPORTING, ConditionRole::CONFIRMING], true) && $c->met()));
    }

    /** How many things found count against it. */
    public function against(): int
    {
        return count($this->conflicting());
    }

    /**
     * Reads a stored row. A confidence this version does not have reads as the least firm one
     * there is: not knowing how firmly a cause was held is not a reason to hold it firmly.
     */
    public static function fromRecord(RootCauseRecord $record): self
    {
        $conditions = [];

        foreach (['supporting' => ConditionRole::SUPPORTING, 'conflicting' => ConditionRole::CONTRADICTING, 'unmet' => ConditionRole::SUPPORTING] as $column => $fallback) {
            foreach (self::decode($record->$column) as $stored) {
                if (is_array($stored)) {
                    $conditions[] = ConditionOutcome::fromArray($stored, $fallback);
                }
            }
        }

        $related = [];

        foreach (self::decode($record->relatedIssues) as $issue) {
            if (is_array($issue) && is_numeric($issue['id'] ?? null)) {
                $related[] = [
                    'id' => (int)$issue['id'],
                    'title' => Redaction::redactString(is_string($issue['title'] ?? null) ? $issue['title'] : ''),
                    'severity' => is_string($issue['severity'] ?? null) ? $issue['severity'] : '',
                ];
            }
        }

        $confidence = Confidence::tryFrom((string)$record->confidence);
        $confidence = $confidence !== null && in_array($confidence, self::LADDER, true) ? $confidence : Confidence::POSSIBLE;
        // A row is data, and data can say anything: a cause read back is held to its rule's
        // ceiling as firmly as one just weighed. A rule removed since has no ceiling to apply.
        $ceiling = self::ceilingOf((string)$record->ruleId);
        $confidence = $ceiling === null ? $confidence : self::capped($confidence, $ceiling);

        return new self(
            ruleId: (string)$record->ruleId,
            title: (string)$record->title,
            statement: (string)($record->statement ?? ''),
            problem: (string)($record->problem ?? ''),
            confidence: $confidence,
            conditions: $conditions,
            reasoning: array_values(array_map('strval', array_filter(self::decode($record->reasoning), 'is_string'))),
            relatedIssues: $related,
            recommendation: (string)($record->recommendation ?? ''),
            nextSteps: array_values(array_map('strval', array_filter(self::decode($record->nextSteps), 'is_string'))),
            limitation: $record->limitation,
            position: (int)$record->position,
            id: (int)$record->id,
            investigationId: (int)$record->investigationId,
            recordedAt: self::time($record->dateCreated),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $outcomes = static fn(array $list): array => array_map(static fn(ConditionOutcome $c): array => $c->jsonSerialize(), $list);

        return [
            'ruleId' => $this->ruleId,
            'title' => $this->title,
            'statement' => $this->statement,
            'problem' => $this->problem,
            'confidence' => $this->confidence->value,
            'supporting' => $outcomes($this->supporting()),
            'conflicting' => $outcomes($this->conflicting()),
            'unmet' => $outcomes($this->unmet()),
            'reasoning' => $this->reasoning,
            'relatedIssues' => $this->relatedIssues,
            'recommendation' => $this->recommendation,
            'nextSteps' => $this->nextSteps,
            'limitation' => $this->limitation,
            'position' => $this->position,
        ];
    }

    /**
     * The sentences that say how the confidence follows from what was found, in the order the
     * ladder applies them. Written from the conditions, so they cannot drift from the arithmetic.
     *
     * @param list<ConditionOutcome> $conditions
     * @return list<string>
     */
    public static function explain(array $conditions, Confidence $ceiling, Confidence $confidence, ?string $limitation): array
    {
        $required = array_values(array_filter($conditions, static fn(ConditionOutcome $c): bool => $c->role === ConditionRole::REQUIRED));
        $for = array_values(array_filter($conditions, static fn(ConditionOutcome $c): bool => in_array($c->role, [ConditionRole::SUPPORTING, ConditionRole::CONFIRMING], true)));
        $found = array_values(array_filter($for, static fn(ConditionOutcome $c): bool => $c->met()));
        $against = array_values(array_filter($conditions, static fn(ConditionOutcome $c): bool => $c->role === ConditionRole::CONTRADICTING && $c->met()));
        $describe = static fn(array $list): string => implode('; ', array_map(static fn(ConditionOutcome $c): string => $c->description, $list));

        $lines = [Craft::t('web-doctor', 'What this cause requires was found: {conditions}.', ['conditions' => $describe($required)])];

        $lines[] = $for === []
            ? Craft::t('web-doctor', 'This cause has nothing further to look for.')
            : Craft::t('web-doctor', '{found} of {total} signals that would support it were found.', ['found' => count($found), 'total' => count($for)]);

        foreach ($conditions as $condition) {
            if ($condition->role === ConditionRole::CONFIRMING && $condition->met()) {
                $lines[] = $condition->confirms()
                    ? Craft::t('web-doctor', 'Found, and recorded by a check that established it: {condition}. That establishes the cause if nothing counts against it.', ['condition' => $condition->description])
                    : Craft::t('web-doctor', 'Found, but only from checks that did not establish it: {condition}. That supports the cause without proving it.', ['condition' => $condition->description]);
            }
        }

        if ($ceiling !== Confidence::HIGH && $confidence !== Confidence::CONFIRMED) {
            $lines[] = $limitation === null
                ? Craft::t('web-doctor', 'This cause is never held more firmly than {ceiling}.', ['ceiling' => $ceiling->label()])
                : Craft::t('web-doctor', 'This cause is never held more firmly than {ceiling}: {reason}', ['ceiling' => $ceiling->label(), 'reason' => $limitation]);
        }

        if ($against !== []) {
            $lines[] = Craft::t('web-doctor', '{count, plural, =1{One thing found counts against it} other{# things found count against it}}, lowering it one step for each: {conditions}.', [
                'count' => count($against),
                'conditions' => $describe($against),
            ]);
        }

        $lines[] = Craft::t('web-doctor', 'So it is held as {confidence}.', ['confidence' => $confidence->label()]);

        return $lines;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function time(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
