<?php

namespace Tahadudhiya\WebDoctor\models;

use Craft;
use Tahadudhiya\WebDoctor\enums\Confidence;
use Tahadudhiya\WebDoctor\enums\EvidenceType;
use Tahadudhiya\WebDoctor\helpers\Redaction;

/**
 * Everything one problem is weighed against: the issue being explained, what each check reported
 * about the installation this time and the evidence it left, the errors those checks ran into, the
 * failed queue jobs they read, and the other problems already known nearby with their history.
 *
 * Root-cause rules read the case through the finders here and never through the database, so a
 * rule's answer depends on what was gathered and on nothing else — and the same case always gets
 * the same answer. Everything is put in a fixed order as it arrives, because a rule that stopped
 * at the first match would otherwise answer differently when the same facts came in another order.
 */
final class CorrelationCase
{
    /** @var list<DiagnosticResult> */
    private readonly array $results;

    /** @var list<IssueSnapshot> */
    private readonly array $issues;

    /** @var list<array{signature: ErrorSignature, results: list<DiagnosticResult>}>|null */
    private ?array $errors = null;

    /**
     * @param IssueSnapshot $issue The problem being explained.
     * @param iterable<DiagnosticResult> $results What each check reported this time.
     * @param iterable<IssueSnapshot> $issues Other problems known nearby: those the checks' findings
     * landed on, and those already open in related areas.
     * @param array<string, int> $issueIds The issue each check's findings are recorded on, by check.
     * @param string $environment Where the checks ran, which is part of an error's identity.
     * @param int|null $siteId The site they ran against, for the same reason.
     * @param string|null $root The installation root, so errors are recognised as they are grouped.
     */
    public function __construct(
        public readonly IssueSnapshot $issue,
        iterable $results = [],
        iterable $issues = [],
        public readonly array $issueIds = [],
        public readonly string $environment = 'unknown',
        public readonly ?int $siteId = null,
        private readonly ?string $root = null,
    ) {
        $byId = [];

        foreach ($results as $result) {
            $byId[$result->diagnosticId] ??= $result;
        }

        ksort($byId, SORT_STRING);
        $this->results = array_values($byId);

        $others = [];

        foreach ($issues as $other) {
            if ($other->id !== $issue->id) {
                $others[$other->id] ??= $other;
            }
        }

        ksort($others, SORT_NUMERIC);
        $this->issues = array_values($others);
    }

    /**
     * What a check reported, where it ran.
     */
    public function result(string $diagnosticId): ?DiagnosticResult
    {
        foreach ($this->results as $result) {
            if ($result->diagnosticId === $diagnosticId) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @return list<DiagnosticResult>
     */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * What the check that raised the issue reported this time, where it ran.
     */
    public function origin(): ?DiagnosticResult
    {
        return $this->result($this->issue->diagnosticId);
    }

    /**
     * The evidence of one type, from one check or from all of them, with the result it supports.
     *
     * @return list<array{0: Evidence, 1: DiagnosticResult}>
     */
    public function evidence(EvidenceType $type, ?string $diagnosticId = null): array
    {
        $out = [];

        foreach ($this->results as $result) {
            if ($diagnosticId !== null && $result->diagnosticId !== $diagnosticId) {
                continue;
            }

            foreach ($result->evidence() as $item) {
                if ($item->type === $type) {
                    $out[] = [$item, $result];
                }
            }
        }

        return $out;
    }

    /**
     * The errors the checks ran into, each once, with every check that ran into it — recognised
     * the way error grouping recognises them, so an observation of one leads to its group.
     *
     * @return list<array{signature: ErrorSignature, results: list<DiagnosticResult>}>
     */
    public function errors(): array
    {
        if ($this->errors !== null) {
            return $this->errors;
        }

        $errors = [];

        foreach ($this->results as $result) {
            foreach (ErrorSignature::allIn($result->evidence(), $this->root) as $signature) {
                $errors[$signature->identity()] ??= ['signature' => $signature, 'results' => []];
                $errors[$signature->identity()]['results'][] = $result;
            }
        }

        ksort($errors, SORT_STRING);

        return $this->errors = array_values($errors);
    }

    /**
     * The failed queue jobs the failed-jobs check read, with the error Craft recorded for each
     * where the reader may see it. Where they may not, the check records only that there was one,
     * and a job whose error cannot be read says nothing about why it failed.
     *
     * @return list<array{0: Evidence, 1: DiagnosticResult, 2: string|null}> The job, the result, and
     * the recorded error where it can be read.
     */
    public function failedJobs(): array
    {
        $out = [];

        foreach ($this->evidence(EvidenceType::QUEUE_JOB, 'queue.failedJobs') as [$job, $result]) {
            $error = $job->get('error');
            $readable = is_string($error) && $error !== '' && !in_array($error, [Redaction::PRESENT, Redaction::MISSING, Redaction::INVALID, Redaction::UNKNOWN], true);
            $out[] = [$job, $result, $readable ? $error : null];
        }

        return $out;
    }

    /**
     * Other problems still open nearby, in the order of their IDs.
     *
     * Only open ones: an issue somebody ignored or ruled out, or one that has resolved, is a
     * decision or an outcome rather than a problem standing now, and must not lend its history to
     * a cause. The finding that landed on it this time is still weighed, as a result.
     *
     * @return list<IssueSnapshot>
     */
    public function issues(): array
    {
        return array_values(array_filter($this->issues, static fn(IssueSnapshot $i): bool => $i->status->isOpen()));
    }

    /**
     * Any issue the case knows of, open or not, so a cause can name the issue a finding landed on.
     */
    public function issue(int $id): ?IssueSnapshot
    {
        foreach ($this->issues as $issue) {
            if ($issue->id === $id) {
                return $issue;
            }
        }

        return null;
    }

    // Observations -----------------------------------------------------------

    /**
     * A check's result, as an observation leading to the check and the issue its finding is on.
     */
    public function observeResult(DiagnosticResult $result): Observation
    {
        $name = $result->name !== '' ? $result->name : $result->diagnosticId;

        return new Observation(
            kind: Observation::RESULT,
            label: $result->summary !== '' ? sprintf('%s: %s', $name, $result->summary) : sprintf('%s: %s', $name, $result->status->label()),
            diagnosticId: $result->diagnosticId,
            issueId: $this->issueIdFor($result->diagnosticId),
            confirmed: $this->established($result),
            at: $result->finishedAt,
        );
    }

    /**
     * A piece of evidence, quoting only the values the rule looked at.
     *
     * @param list<string> $keys The entries that met the condition, quoted as the detail.
     */
    public function observeEvidence(Evidence $evidence, DiagnosticResult $result, array $keys = []): Observation
    {
        $quoted = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $evidence->data)) {
                $quoted[] = sprintf('%s: %s', $key, self::describe($evidence->data[$key]));
            }
        }

        return new Observation(
            kind: Observation::EVIDENCE,
            label: Craft::t('web-doctor', '{label}, recorded by {check}', [
                'label' => $evidence->label !== '' ? $evidence->label : $evidence->type->label(),
                'check' => $result->name !== '' ? $result->name : $result->diagnosticId,
            ]),
            detail: $quoted === [] ? null : implode('; ', $quoted),
            diagnosticId: $result->diagnosticId,
            issueId: $this->issueIdFor($result->diagnosticId),
            evidenceDigest: $evidence->digest(),
            confirmed: $this->established($result),
            at: $evidence->observedAt ?? $evidence->recordedAt,
        );
    }

    /**
     * An error, leading to the group it was counted against. Established when any check that
     * recorded it established its own result: the exception is then the fact that result rests on.
     *
     * @param array{signature: ErrorSignature, results: list<DiagnosticResult>} $error
     */
    public function observeError(array $error): Observation
    {
        $signature = $error['signature'];
        $names = array_map(static fn(DiagnosticResult $r): string => $r->name !== '' ? $r->name : $r->diagnosticId, $error['results']);
        $first = $error['results'][0] ?? null;
        $established = false;

        foreach ($error['results'] as $result) {
            $established = $established || $this->established($result);
        }

        return new Observation(
            kind: Observation::ERROR,
            label: Craft::t('web-doctor', '{class}, run into by {checks}', [
                'class' => $signature->shortClass(),
                'checks' => implode(', ', $names),
            ]),
            detail: $signature->sample !== '' ? $signature->sample : $signature->message,
            diagnosticId: $first?->diagnosticId,
            errorFingerprint: $signature->fingerprint($this->environment, $this->siteId),
            confirmed: $established,
        );
    }

    /**
     * A failed queue job, quoting the error Craft recorded for it.
     *
     * @param array{0: Evidence, 1: DiagnosticResult, 2: string|null} $job
     */
    public function observeJob(array $job): Observation
    {
        [$evidence, $result, $error] = $job;

        return new Observation(
            kind: Observation::EVIDENCE,
            label: Craft::t('web-doctor', 'Failed job “{job}”, failed {count, plural, =1{once} other{# times}}', [
                'job' => $evidence->label,
                'count' => (int)$evidence->get('occurrences', 1),
            ]),
            detail: $error,
            diagnosticId: $result->diagnosticId,
            issueId: $this->issueIdFor($result->diagnosticId),
            evidenceDigest: $evidence->digest(),
            confirmed: $this->established($result),
            at: $evidence->observedAt,
        );
    }

    /**
     * Another problem known to the Issue Center, as it stood.
     */
    public function observeIssue(IssueSnapshot $issue): Observation
    {
        return new Observation(
            kind: Observation::ISSUE,
            label: $issue->title,
            diagnosticId: $issue->diagnosticId,
            issueId: $issue->id,
            at: $issue->firstDetected,
        );
    }

    /**
     * The issue a check's findings are recorded on, where there is one.
     */
    public function issueIdFor(string $diagnosticId): ?int
    {
        if ($diagnosticId === $this->issue->diagnosticId) {
            return $this->issue->id;
        }

        return $this->issueIds[$diagnosticId] ?? null;
    }

    /**
     * Whether a result established what it reported. A check that only suspects a fact says so
     * with a lower confidence, and quoting it cannot turn the suspicion into proof.
     */
    private function established(DiagnosticResult $result): bool
    {
        return $result->status->isConclusive() && $result->confidence === Confidence::CONFIRMED;
    }

    /**
     * A value, as a line of text a reader can follow.
     */
    private static function describe(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => (string)$value,
            default => Evidence::encode($value),
        };
    }
}
