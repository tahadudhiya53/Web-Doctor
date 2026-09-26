<?php

namespace Tahadudhiya\WebDoctor\services;

use Craft;
use RuntimeException;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\models\ConditionOutcome;
use Tahadudhiya\WebDoctor\models\CorrelationCase;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\RootCause;
use Tahadudhiya\WebDoctor\models\RootCauseAnalysis;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\records\RootCauseRecord;
use Tahadudhiya\WebDoctor\rules\RootCauseRule;
use Tahadudhiya\WebDoctor\rules\RootCauseRules;
use Throwable;
use yii\base\Component;

/**
 * Weighs what was found against the known causes, and keeps what it concluded.
 *
 * Weighing reads nothing but the case it is given ({@see CorrelationCase}), so the same findings
 * always produce the same causes in the same order. Which causes there are is
 * {@see RootCauseRules}'s answer; how firmly each is held is {@see RootCause::confidenceFor()}'s.
 * Nothing here decides either.
 *
 * It offers candidates, never a verdict. A cause is confirmed only on evidence that establishes
 * it, and every cause carries what counts against it alongside what counts for it.
 */
class RootCauses extends Component
{
    /**
     * Weighs a case against every known cause.
     *
     * A rule that breaks costs the analysis that rule, not the others, and is counted in what comes
     * back rather than dropped: a shorter list of causes has to say it is shorter.
     *
     * @param list<RootCauseRule>|null $rules The rules to weigh, the known ones unless given.
     */
    public function analyse(CorrelationCase $case, ?array $rules = null): RootCauseAnalysis
    {
        $rules ??= RootCauseRules::all();
        $causes = [];
        $failed = [];

        foreach ($rules as $rule) {
            try {
                $cause = $rule->assess($case);
            } catch (Throwable $e) {
                SafeException::log(sprintf('The root-cause rule %s could not be weighed', $rule->id), $e);
                $failed[] = $rule->id;

                continue;
            }

            if ($cause !== null) {
                $causes[] = $cause;
            }
        }

        usort($causes, [RootCause::class, 'compare']);
        sort($failed, SORT_STRING);

        return new RootCauseAnalysis(
            causes: array_map(static fn(RootCause $cause, int $at): RootCause => $cause->at($at), $causes, array_keys($causes)),
            weighed: count($rules),
            failed: $failed,
        );
    }

    /**
     * Keeps the causes an investigation weighed, all of them or none.
     *
     * Called inside the investigation's own finishing transaction, where this one is a savepoint:
     * the causes are committed with the investigation's final state or not at all.
     *
     * Everything is redacted on the way in although every part of a cause redacted itself as it
     * was built: this is a serialization boundary, and every one of those redacts.
     *
     * @param list<RootCause> $causes
     */
    public function record(int $investigationId, array $causes): void
    {
        if ($causes === []) {
            return;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($causes as $cause) {
                $this->save($this->toRecord($investigationId, $cause));
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }
    }

    /**
     * The causes an investigation weighed, most firmly held first.
     *
     * @return list<RootCause>
     */
    public function forInvestigation(int $investigationId): array
    {
        $out = [];

        foreach (RootCauseRecord::find()->where(['investigationId' => $investigationId])->orderBy(['position' => SORT_ASC])->all() as $record) {
            if ($record instanceof RootCauseRecord) {
                $out[] = RootCause::fromRecord($record);
            }
        }

        return $out;
    }

    /**
     * The most firmly held cause of each of these investigations, keyed by investigation, in one
     * query. An investigation that found none, or never got as far as weighing, is left out.
     *
     * @param list<int> $investigationIds
     * @return array<int, RootCause>
     */
    public function leading(array $investigationIds): array
    {
        if ($investigationIds === []) {
            return [];
        }

        $out = [];

        foreach (RootCauseRecord::find()->where(['investigationId' => $investigationIds, 'position' => 0])->all() as $record) {
            if ($record instanceof RootCauseRecord) {
                $out[(int)$record->investigationId] = RootCause::fromRecord($record);
            }
        }

        return $out;
    }

    private function toRecord(int $investigationId, RootCause $cause): RootCauseRecord
    {
        $encode = static fn(mixed $value): string => Evidence::encode(Redaction::redact($value));
        $outcomes = static fn(array $list): ?string => $list === []
            ? null
            : Evidence::encode(Redaction::redact(array_map(static fn(ConditionOutcome $c): array => $c->jsonSerialize(), $list)));

        $record = new RootCauseRecord();
        $record->investigationId = $investigationId;
        $record->position = $cause->position;
        $record->ruleId = $this->fit($cause->ruleId, 64);
        $record->confidence = $cause->confidence->value;
        $record->title = $this->fit(Redaction::redactString($cause->title), 255);
        $record->statement = Redaction::redactString($cause->statement);
        $record->problem = Redaction::redactString($cause->problem);
        $record->supporting = $outcomes($cause->supporting());
        $record->conflicting = $outcomes($cause->conflicting());
        $record->unmet = $outcomes($cause->unmet());
        $record->reasoning = $encode($cause->reasoning);
        $record->relatedIssues = $cause->relatedIssues === [] ? null : $encode($cause->relatedIssues);
        $record->recommendation = Redaction::redactString($cause->recommendation);
        $record->nextSteps = $encode($cause->nextSteps);
        $record->limitation = $cause->limitation === null ? null : Redaction::redactString($cause->limitation);

        return $record;
    }

    /**
     * @throws RuntimeException if the row will not save, because a caller that thinks it kept a
     * cause and did not is worse than one that knows it failed.
     */
    private function save(RootCauseRecord $record): void
    {
        if (!$record->save()) {
            throw new RuntimeException(sprintf(
                'A Web Doctor root cause could not be saved: %s',
                Redaction::redactString(json_encode($record->getErrors()) ?: 'unknown error'),
            ));
        }
    }

    private function fit(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }
}
