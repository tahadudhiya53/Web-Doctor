<?php

namespace Tahadudhiya\WebDoctor\services;

use Craft;
use craft\helpers\Db;
use DateTimeImmutable;
use RuntimeException;
use Tahadudhiya\WebDoctor\base\RepairActionInterface;
use Tahadudhiya\WebDoctor\enums\IssueStatus;
use Tahadudhiya\WebDoctor\enums\RepairStatus;
use Tahadudhiya\WebDoctor\enums\VerificationStatus;
use Tahadudhiya\WebDoctor\errors\Refusal;
use Tahadudhiya\WebDoctor\helpers\Redaction;
use Tahadudhiya\WebDoctor\helpers\Savepoint;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\Evidence;
use Tahadudhiya\WebDoctor\models\Issue;
use Tahadudhiya\WebDoctor\models\Prerequisite;
use Tahadudhiya\WebDoctor\models\RecommendationCase;
use Tahadudhiya\WebDoctor\models\Repair;
use Tahadudhiya\WebDoctor\models\RepairContext;
use Tahadudhiya\WebDoctor\models\RepairReport;
use Tahadudhiya\WebDoctor\models\SafeException;
use Tahadudhiya\WebDoctor\models\StoredEvidence;
use Tahadudhiya\WebDoctor\records\RepairRecord;
use Tahadudhiya\WebDoctor\WebDoctor;
use Throwable;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\db\IntegrityException;

/**
 * Carries out repairs, one confirmed preview at a time, and keeps the record of each.
 *
 * This service is the safety boundary. A controller checks permissions too, as defence in depth,
 * but nothing here trusts that it did: every call establishes for itself who is asking, from Craft's
 * own signed-in user, and never from anything a caller passes in.
 *
 * A repair goes through two requests. Preparing one reads the installation live and stores exactly
 * what the action would do, what has to be true first, a fingerprint of the state it would act on
 * and a fingerprint of the action's own definition — nothing is changed. Carrying it out takes that
 * stored preview and nothing else, and establishes, in this order, before anything is changed:
 *
 * 1. somebody is signed in and holds "Run repairs";
 * 2. the preview exists, is intact, and belongs to this issue, its check and its site;
 * 3. the issue can be repaired: open, not dismissed, not being repaired, found in this environment
 *    on a site that still exists, and its latest finding is the one the preview was made from;
 * 4. Craft itself allows this person the same action;
 * 5. the preview is waiting and has not expired, and was made in this environment;
 * 6. it was confirmed, exactly the prerequisites shown were acknowledged, and a high-risk repair has
 *    the environment's name typed exactly;
 * 7. nothing else of the same kind is running here, and this preview is carried out once — both by
 *    one conditional write, so two requests confirming at once cannot both get through;
 * 8. then, holding that claim: the issue and its finding read again, every prerequisite the action
 *    checks read again and held, and both fingerprints — the state and the definition — unchanged.
 *
 * Only then is the issue set repairing and the action run. However it ends, the issue goes back to
 * where it stood in the same transaction as the repair's ending: a repair that ran is not a problem
 * that went away, and only the check that found the problem, run again, resolves the issue.
 */
class Repairs extends Component
{
    /** @var int The most repairs the issue page lists. */
    public const HISTORY_LIMIT = 10;

    /** @var RepairActions|null The actions offered. The plugin's own unless injected. */
    public ?RepairActions $actions = null;

    /** @var Issues|null Where the issue is read and set repairing. The plugin's own unless injected. */
    public ?Issues $issues = null;

    /** @var EvidenceStore|null Where the issue's latest finding is read. The plugin's own unless injected. */
    public ?EvidenceStore $evidence = null;

    /** @var Permissions|null Who may carry out repairs. The plugin's own unless injected. */
    public ?Permissions $permissions = null;

    /**
     * @var string|null The environment this installation is running as. Craft's answer unless set,
     * which a test does so that an issue recorded under its own environment can be repaired.
     */
    public ?string $environment = null;

    /**
     * The actions that answer an issue's latest finding. Nothing for a resolved issue, which has
     * nothing left to act on. Offered is not permitted: that is {@see self::refusal()} and
     * {@see self::authorize()}.
     *
     * @param iterable<Evidence> $evidence The evidence the issue's latest finding left.
     * @return list<RepairActionInterface>
     */
    public function available(Issue $issue, iterable $evidence): array
    {
        if ($issue->status === IssueStatus::RESOLVED) {
            return [];
        }

        $finding = RecommendationCase::fromIssue($issue, $evidence);
        $out = [];

        foreach ($this->actions()->forCheck($issue->diagnosticId) as $action) {
            if ($this->applies($action, $finding)) {
                $out[] = $action;
            }
        }

        return $out;
    }

    /**
     * Why an issue cannot be repaired here, or null where it can. Reads only; writes nothing.
     *
     * A repair changes this installation, so it is carried out only where the issue was found: an
     * issue from another environment describes somewhere else, and one whose site has been deleted
     * describes nothing that is left.
     */
    public function refusal(Issue $issue): ?string
    {
        // An issue left repairing by a repair that is no longer running is not being repaired, and
        // the next preview puts it back where it stood; refusing it here would leave it stuck.
        $status = $issue->status === IssueStatus::REPAIRING && !$this->isBeingRepaired($issue->id)
            ? null
            : Issues::repairRefusal($issue->status);

        if ($status !== null) {
            return $status;
        }

        $here = $this->environment();

        if ($issue->environment !== $here) {
            return Craft::t('web-doctor', 'This issue was found in the “{found}” environment and this installation is running as “{here}”. A repair here would change a different environment, so it has to be carried out where the issue was found.', [
                'found' => $issue->environment,
                'here' => $here,
            ]);
        }

        // Craft soft-deletes sites, so a site in the trash still has its ID on the issue.
        if (($issue->siteId === null && $issue->siteName !== null) || ($issue->siteId !== null && !$this->siteExists($issue->siteId))) {
            return Craft::t('web-doctor', 'The site this issue was found on, “{site}”, has been deleted, so there is nothing left to repair it against.', [
                'site' => $issue->siteName ?? '#' . $issue->siteId,
            ]);
        }

        return null;
    }

    /**
     * Establishes that the person signed in now may carry out repairs — and, given an action, that
     * Craft itself would let them do the same thing — and returns who they are.
     *
     * The identity is Craft's, read here. Nothing a caller passes in can stand for it.
     *
     * @throws Refusal
     */
    public function authorize(?RepairActionInterface $action = null): int
    {
        try {
            $user = Craft::$app->getUser()->getIdentity();
        } catch (Throwable $e) {
            SafeException::log('Who is asking for a repair could not be established', $e);
            $user = null;
        }

        if ($user === null || $user->id === null) {
            throw new Refusal(Craft::t('web-doctor', 'Repairs can only be carried out by somebody signed in.'));
        }

        if (!$this->permissions()->can(Permissions::RUN_REPAIRS)) {
            throw new Refusal(Craft::t('web-doctor', 'Carrying out a repair needs the “Run repairs” permission.'));
        }

        if ($action !== null && !$this->authorized($action)) {
            throw new Refusal($action->authorization() !== '' ? Redaction::redactString($action->authorization()) : Craft::t('web-doctor', 'Craft does not allow you to do this.'));
        }

        return (int)$user->id;
    }

    /**
     * What an action said about itself when it was previewed: everything that decides what the
     * preview means, and nothing that describes the installation's state. Two previews with the same
     * definition asked the person to agree to the same thing.
     *
     * Built from the contract's own answers, canonically ordered — never from the action's code or a
     * serialization of the object.
     *
     * @param list<Prerequisite> $prerequisites
     */
    public static function definitionOf(RepairActionInterface $action, array $prerequisites): string
    {
        return hash('sha256', 'd1|' . Evidence::encode([
            'id' => $action->id(),
            'diagnosticId' => $action->diagnosticId(),
            'recommendation' => $action->recommendation(),
            'name' => $action->name(),
            'risk' => $action->risk()->value,
            'riskReason' => $action->riskReason(),
            'verification' => $action->verification(),
            'verifyWith' => $action->verifyWith(),
            'prerequisites' => array_map(static fn(Prerequisite $p): array => [$p->id, $p->kind, $p->description], $prerequisites),
        ]));
    }

    /**
     * Reads what an action would do for an issue, live, and keeps it as a preview waiting to be
     * confirmed. Changes nothing in the installation.
     *
     * @throws Refusal if the person may not, the issue or the action does not exist, the issue cannot
     * be repaired here, the action does not answer it, or the preview cannot be made.
     */
    public function prepare(int $issueId, string $actionId): Repair
    {
        $userId = $this->authorize();
        $action = $this->actions()->get($actionId)
            ?? throw new Refusal(Craft::t('web-doctor', 'Web Doctor has no repair called “{action}”.', ['action' => $actionId]));
        $this->authorize($action);

        $issue = $this->currentIssue($issueId);
        $context = $this->contextFor($issue, $action, $userId);
        [$prerequisites, $preview] = $this->read($action, $context);

        $now = new DateTimeImmutable();
        $record = new RepairRecord();
        $record->issueId = $issue->id;
        $record->issueTitle = $this->fit($issue->title, 255);
        $record->diagnosticId = $issue->diagnosticId;
        $record->findingRunId = $issue->latestRunId;
        $record->action = $action->id();
        $record->actionName = $this->fit(Redaction::redactString($action->name()), 255);
        $record->risk = $action->risk()->value;
        $record->riskReason = Redaction::redactString($action->riskReason());
        $record->status = RepairStatus::PREVIEWED->value;
        $record->verificationStatus = VerificationStatus::NONE->value;
        $record->verifyWith = Evidence::encode($action->verifyWith());
        $record->verificationNote = Redaction::redactString($action->verification());
        $record->environment = $this->fit($context->environment, 255);
        $record->siteId = $issue->siteId;
        $record->preview = Evidence::encode($preview);
        $record->fingerprint = (string)$preview->fingerprint;
        $record->definitionFingerprint = self::definitionOf($action, $prerequisites);
        $record->prerequisites = Evidence::encode($prerequisites);
        $record->acknowledged = Evidence::encode([]);
        $record->previewedBy = $userId;
        $record->previewedAt = $this->forDb($now);

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $this->supersede($issue->id, $action->id(), $userId);
            $this->save($record);
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        $this->logAction($record, 'previewed', $userId);

        return Repair::fromRecord($record);
    }

    /**
     * Carries out a previewed repair, having established everything the class describes, and
     * returns it as it ended.
     *
     * A refusal changes nothing — not the installation, not the issue, not the preview. A repair
     * whose action fails part-way is recorded as failed, with the issue put back where it stood.
     *
     * @param int $issueId The issue it is reached through, which has to be the one it was made for.
     * @param bool $confirmed Whether the person confirmed it.
     * @param array<mixed> $acknowledged The prerequisites they acknowledged, by ID: exactly those shown,
     * each once. Checked here rather than trusted, since this is the boundary whoever calls it meets.
     * @param string|null $typed What they typed to confirm a high-risk repair.
     * @throws Refusal for every reason it may not be carried out.
     */
    public function execute(int $repairId, int $issueId, bool $confirmed, array $acknowledged = [], ?string $typed = null): Repair
    {
        // A refusal changes nothing, but somebody tried to change the installation and was stopped,
        // which is worth as much in the log as a repair that went through.
        try {
            return $this->carryOut($repairId, $issueId, $confirmed, $acknowledged, $typed);
        } catch (Refusal $e) {
            Craft::info(Redaction::redactString(sprintf(
                'Repair %d of issue %d refused for user %s: %s',
                $repairId,
                $issueId,
                $this->signedInId() ?? '(none)',
                $e->getMessage(),
            )), WebDoctor::LOG_CATEGORY);

            throw $e;
        }
    }

    public function get(int $id): ?Repair
    {
        $record = RepairRecord::findOne(['id' => $id]);

        return $record === null ? null : Repair::fromRecord($record);
    }

    /**
     * An issue's repairs, newest first.
     *
     * @return list<Repair>
     * @throws InvalidArgumentException for a limit below one.
     */
    public function forIssue(int $issueId, int $limit = self::HISTORY_LIMIT): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf('A limit of at least 1 is needed; %d was given.', $limit));
        }

        $out = [];

        foreach (RepairRecord::find()->where(['issueId' => $issueId])->orderBy(['previewedAt' => SORT_DESC, 'id' => SORT_DESC])->limit($limit)->all() as $record) {
            if ($record instanceof RepairRecord) {
                $out[] = Repair::fromRecord($record);
            }
        }

        return $out;
    }

    public function environment(): string
    {
        return $this->environment ?? DiagnosticContext::currentEnvironment();
    }

    /**
     * What two repairs running at once may not share: one action in one environment. The queue, the
     * storage directories and whatever else an action acts on belong to the installation, not to the
     * issue that led to it, so two issues' repairs of the same kind still wait for each other.
     */
    public static function lockKey(string $action, string $environment): string
    {
        return hash('sha256', implode('|', ['lock1', $action, $environment]));
    }

    /**
     * The one path a repair is carried out by. Every check is made in this order, each before the
     * next, and nothing is changed until the last of them has passed:
     *
     *  1. authorisation — signed in, holding Run repairs
     *  2. the preview exists, is intact and is still waiting
     *  3. issue and action binding — the issue, its check and the action are the preview's
     *  4. environment and site binding
     *  5. the issue's state
     *  6. its latest finding — the one previewed, and one the action answers
     *  7. Craft's own authorisation for the action
     *  8. the preview's expiry
     *  9. confirmation, acknowledgements, and the typed confirmation of a high-risk repair
     * 10. the claim and its lock
     * 11. the issue and its finding again (3–6), now that nothing else of this kind can run
     * 12. the prerequisites, read live
     * 13. the state fingerprint
     * 14. the definition fingerprint
     *
     * then the issue is set repairing, the action run, and the ending, the issue's restoration and
     * the lock's release written as one transaction.
     *
     * @param array<mixed> $acknowledged
     * @throws Refusal
     */
    private function carryOut(int $repairId, int $issueId, bool $confirmed, array $acknowledged, ?string $typed): Repair
    {
        // 1.
        $userId = $this->authorize();

        // 2.
        $record = RepairRecord::findOne(['id' => $repairId]);

        if (!$record instanceof RepairRecord || $record->issueId === null || (int)$record->issueId !== $issueId) {
            throw new Refusal(Craft::t('web-doctor', 'No such repair of this issue.'));
        }

        $repair = Repair::fromRecord($record);

        if (!$repair->isIntact()) {
            throw new Refusal(Craft::t('web-doctor', 'This repair’s record cannot be read in full, so it cannot be carried out. Preview the repair again.'));
        }

        $this->refuseUnlessWaiting($repair);

        $action = $this->actions()->get($repair->action)
            ?? throw new Refusal(Craft::t('web-doctor', 'The repair “{name}” is no longer available here.', ['name' => $repair->actionName]));

        // 3–6.
        [$issue, $context] = $this->bind($repair, $action, $this->currentIssue($issueId), $userId);

        // 7.
        $this->authorize($action);

        // 8.
        if ($repair->isExpired()) {
            throw new Refusal(Craft::t('web-doctor', 'This preview has expired. Preview the repair again to see what it would do now.'));
        }

        // 9.
        $this->refuseUnlessConfirmed($repair, $confirmed, $acknowledged, $typed);

        // 10.
        $this->claim($record, $context, $acknowledged, $userId);

        try {
            // 11.
            $current = $this->issues()->get($issueId) ?? throw new Refusal(Craft::t('web-doctor', 'No issue exists with the ID {id}.', ['id' => $issueId]));
            [$issue, $context] = $this->bind($repair, $action, $current, $userId);
            // 12–14.
            $preview = $this->recheck($repair, $action, $context);
            $before = $this->issues()->beginRepair($issue->id, $repair->actionName, $userId);
        } catch (Throwable $e) {
            $this->release($record);

            throw $e;
        }

        $this->remember($record, $before);

        $started = microtime(true);
        $outcome = null;
        $failure = null;

        try {
            $outcome = $action->execute($context, $preview);
        } catch (Refusal $e) {
            // An action refuses only before it has changed anything, so this is a refusal like any
            // other: the preview is given back and the issue put back, as one act.
            $this->giveBack($record, $issue->id, $before, $userId);

            throw $e;
        } catch (Throwable $e) {
            SafeException::log(sprintf('The repair %s of issue %d failed while it was being carried out', $action->id(), $issue->id), $e);
            $failure = $e;
        }

        $this->finish($record, $before, $outcome, $failure, (microtime(true) - $started) * 1000, $userId);
        $this->logAction($record, match (true) {
            $record->status === RepairStatus::RUNNING->value => 'ran but its end could not be recorded',
            $failure === null => 'carried out',
            default => 'failed',
        }, $userId);

        return Repair::fromRecord($record);
    }

    /**
     * Steps 3–6: that the issue, its check, the action, the environment, the site, the issue's state
     * and its latest finding are all what the preview was made for.
     *
     * @return array{0: Issue, 1: RepairContext}
     * @throws Refusal
     */
    private function bind(Repair $repair, RepairActionInterface $action, Issue $issue, int $userId): array
    {
        // 3. Issue and action.
        if ($issue->diagnosticId !== $repair->diagnosticId || $action->diagnosticId() !== $issue->diagnosticId) {
            throw new Refusal(Craft::t('web-doctor', 'This repair was previewed for a different check or site than this issue now records, so it cannot be carried out. Preview it again.'));
        }

        // 4. Environment and site.
        $here = $this->environment();

        if ($repair->environment !== $here) {
            throw new Refusal(Craft::t('web-doctor', 'This repair was previewed in the “{previewed}” environment and this installation is running as “{here}”, so it cannot be carried out here.', [
                'previewed' => $repair->environment,
                'here' => $here,
            ]));
        }

        if ($issue->siteId !== $repair->siteId) {
            throw new Refusal(Craft::t('web-doctor', 'This repair was previewed for a different check or site than this issue now records, so it cannot be carried out. Preview it again.'));
        }

        // 5. The issue's state — and, through the same refusal, where it was found.
        $refusal = $this->refusal($issue);

        if ($refusal !== null) {
            throw new Refusal($refusal);
        }

        // 6. The finding.
        $this->refuseUnlessSameFinding($repair, $issue);

        return [$issue, $this->contextFor($issue, $action, $userId)];
    }

    /**
     * The issue as it stands, once anything left behind by a repair that died has been ended and the
     * issue put back.
     *
     * @throws Refusal
     */
    private function currentIssue(int $issueId): Issue
    {
        $this->releaseStopped(['issueId' => $issueId, 'status' => RepairStatus::RUNNING->value]);
        $this->recoverIssue($issueId);

        return $this->issues()->get($issueId)
            ?? throw new Refusal(Craft::t('web-doctor', 'No issue exists with the ID {id}.', ['id' => $issueId]));
    }

    /**
     * What an action is carried out for, having established that it may be: the issue can be
     * repaired here, and the action answers what its latest finding records.
     *
     * @throws Refusal
     */
    private function contextFor(Issue $issue, RepairActionInterface $action, int $userId): RepairContext
    {
        $refusal = $this->refusal($issue);

        if ($refusal !== null) {
            throw new Refusal($refusal);
        }

        if ($action->diagnosticId() !== $issue->diagnosticId) {
            throw new Refusal(Craft::t('web-doctor', '“{name}” answers a different check’s findings, not this issue’s.', ['name' => Redaction::redactString($action->name())]));
        }

        $evidence = array_map(
            static fn(StoredEvidence $stored): Evidence => $stored->evidence,
            $this->evidenceStore()->latest($issue->id, $issue->latestRunId),
        );
        $finding = RecommendationCase::fromIssue($issue, $evidence);

        if (!$finding->isFinding() || !$this->applies($action, $finding)) {
            throw new Refusal(Craft::t('web-doctor', '“{name}” does not answer what this issue’s latest finding records, so there is nothing for it to do.', ['name' => Redaction::redactString($action->name())]));
        }

        return new RepairContext($issue, $finding, $this->environment(), $userId);
    }

    /**
     * A preview answers the finding it was made from. A later run that recorded the issue again —
     * even unchanged — is a new reading the person has not seen.
     *
     * @throws Refusal
     */
    private function refuseUnlessSameFinding(Repair $repair, Issue $issue): void
    {
        if ($issue->latestRunId !== $repair->findingRunId) {
            throw new Refusal(Craft::t('web-doctor', 'This issue has been found again since the repair was previewed. Preview the repair again to see what it would do now.'));
        }
    }

    /**
     * An action's prerequisites and preview, read live and checked for shape.
     *
     * @return array{0: list<Prerequisite>, 1: RepairReport}
     * @throws Refusal if either cannot be read.
     */
    private function read(RepairActionInterface $action, RepairContext $context): array
    {
        try {
            $prerequisites = $action->prerequisites($context);
            $preview = $action->preview($context);
        } catch (Refusal $e) {
            throw $e;
        } catch (Throwable $e) {
            SafeException::log(sprintf('The repair %s could not read the installation', $action->id()), $e);

            throw new Refusal(Craft::t('web-doctor', 'Web Doctor could not read what this repair would change. Nothing was changed. The details are in Craft’s logs.'));
        }

        $ids = [];

        foreach ($prerequisites as $prerequisite) {
            // Two prerequisites under one ID would let one tick stand for both.
            if (isset($ids[$prerequisite->id])) {
                throw new RuntimeException(sprintf('The repair action %s returned two prerequisites called "%s".', $action->id(), $prerequisite->id));
            }

            $ids[$prerequisite->id] = true;
        }

        // Without a fingerprint there is no telling, at confirmation, whether what is carried out is
        // what was shown.
        if ($preview->fingerprint === null) {
            throw new RuntimeException(sprintf('The repair action %s returned a preview with no fingerprint.', $action->id()));
        }

        return [$prerequisites, $preview];
    }

    /**
     * @throws Refusal
     */
    private function refuseUnlessWaiting(Repair $repair): void
    {
        $refusal = match (true) {
            $repair->status === RepairStatus::RUNNING => Craft::t('web-doctor', 'This repair is already being carried out.'),
            $repair->status->wasExecuted() => Craft::t('web-doctor', 'This repair has already been carried out. Preview it again to repair again.'),
            $repair->status !== RepairStatus::PREVIEWED => Craft::t('web-doctor', 'This preview has been replaced by a newer one, so it can no longer be confirmed.'),
            default => null,
        };

        if ($refusal !== null) {
            throw new Refusal($refusal);
        }
    }

    /**
     * Exactly the prerequisites that were shown, each once, and nothing else; and for a high-risk
     * repair the previewed environment's name, exactly as it is spelled.
     *
     * @param array<mixed> $acknowledged
     * @throws Refusal
     */
    private function refuseUnlessConfirmed(Repair $repair, bool $confirmed, array $acknowledged, ?string $typed): void
    {
        if (!$confirmed) {
            throw new Refusal(Craft::t('web-doctor', 'Nothing was carried out: the repair was not confirmed.'));
        }

        $shown = array_map(static fn(Prerequisite $p): string => $p->id, $repair->toAcknowledge());

        if (!array_is_list($acknowledged)) {
            throw new Refusal(Craft::t('web-doctor', 'Nothing was carried out: something was acknowledged that this repair did not ask about.'));
        }

        foreach ($acknowledged as $id) {
            if (!is_string($id) || !in_array($id, $shown, true)) {
                throw new Refusal(Craft::t('web-doctor', 'Nothing was carried out: something was acknowledged that this repair did not ask about.'));
            }
        }

        if (count($acknowledged) !== count(array_unique($acknowledged))) {
            throw new Refusal(Craft::t('web-doctor', 'Nothing was carried out: the same prerequisite was acknowledged more than once.'));
        }

        $missing = array_values(array_filter(
            $repair->toAcknowledge(),
            static fn(Prerequisite $p): bool => !in_array($p->id, $acknowledged, true),
        ));

        if ($missing !== []) {
            throw new Refusal(Craft::t('web-doctor', 'Nothing was carried out. Confirm each of these first: {list}', [
                'list' => implode(' ', array_map(static fn(Prerequisite $p): string => $p->description, $missing)),
            ]));
        }

        if ($repair->risk->requiresTypedConfirmation() && ($typed === null || !hash_equals($repair->environment, $typed))) {
            throw new Refusal(Craft::t('web-doctor', 'Nothing was carried out. This is a high-risk repair: type the name of the environment it will change, “{environment}”, to confirm it.', [
                'environment' => $repair->environment,
            ]));
        }
    }

    /**
     * Moves a waiting preview to running in one conditional write, holding the lock for its kind
     * of repair in this environment.
     *
     * The write only succeeds while the row is still previewed, so of two requests confirming the
     * same preview, one gets it and the other is told it has gone. The lock is a unique column, so
     * of two previews of the same kind confirmed at once, one gets it and the other is told to
     * wait. A lock left by a request that died is taken over once it is old enough to be read as
     * stopped.
     *
     * @param array<mixed> $acknowledged Already checked to be exactly the prerequisites shown.
     * @throws Refusal
     */
    private function claim(RepairRecord $record, RepairContext $context, array $acknowledged, int $userId): void
    {
        $lockKey = self::lockKey((string)$record->action, $context->environment);
        $now = $this->forDb(new DateTimeImmutable());

        for ($attempt = 0; ; $attempt++) {
            try {
                $claimed = Craft::$app->getDb()->createCommand()->update(RepairRecord::TABLE, [
                    'status' => RepairStatus::RUNNING->value,
                    'lockKey' => $lockKey,
                    'executedBy' => $userId,
                    'startedAt' => $now,
                    'acknowledged' => Evidence::encode(array_values($acknowledged)),
                    'dateUpdated' => $now,
                ], ['id' => $record->id, 'status' => RepairStatus::PREVIEWED->value, 'lockKey' => null])->execute();
            } catch (IntegrityException $e) {
                if (!Savepoint::isUniqueViolation($e)) {
                    throw $e;
                }

                if ($attempt === 0 && $this->releaseStopped(['lockKey' => $lockKey])) {
                    continue;
                }

                throw new Refusal(Craft::t('web-doctor', 'Another repair of this kind is being carried out here. Wait for it to finish, then preview this one again.'));
            }

            if ($claimed !== 1) {
                throw new Refusal(Craft::t('web-doctor', 'This repair has already been carried out, or is being carried out now.'));
            }

            $record->refresh();

            return;
        }
    }

    /**
     * Reads the prerequisites and the preview again, live, now that the repair holds its lock, and
     * refuses unless every checkable prerequisite holds, the action's definition is the one that was
     * previewed, and the state is the one that was previewed.
     *
     * @throws Refusal
     */
    private function recheck(Repair $repair, RepairActionInterface $action, RepairContext $context): RepairReport
    {
        [$prerequisites, $preview] = $this->read($action, $context);

        $unmet = array_values(array_filter(
            $prerequisites,
            static fn(Prerequisite $p): bool => !$p->needsAcknowledging() && !$p->met,
        ));

        if ($unmet !== []) {
            throw new Refusal(Craft::t('web-doctor', 'Nothing was carried out, because this no longer holds: {list}', [
                'list' => implode(' ', array_map(static fn(Prerequisite $p): string => $p->description . ($p->detail !== null ? ' (' . $p->detail . ')' : ''), $unmet)),
            ]));
        }

        // 13. What it would act on.
        if (!hash_equals($repair->fingerprint, (string)$preview->fingerprint)) {
            throw new Refusal(Craft::t('web-doctor', 'Nothing was carried out: what this repair would change is no longer what the preview showed. Preview it again to see what it would do now.'));
        }

        // 14. What the person agreed to — the action, its risk, what it asks of them, how it is verified.
        if (!hash_equals($repair->definitionFingerprint, self::definitionOf($action, $prerequisites))) {
            throw new Refusal(Craft::t('web-doctor', 'This repair has changed since it was previewed. Preview it again to see what it would do now.'));
        }

        return $preview;
    }

    /**
     * Gives a claimed preview back, unchanged, after something refused it once it was claimed and
     * before the issue was touched.
     */
    private function release(RepairRecord $record): void
    {
        try {
            $this->releaseCommand($record);
        } catch (Throwable $e) {
            // Left running, it is read as stopped after an hour and its lock is taken over then.
            SafeException::log(sprintf('The repair %d could not be given back after it was refused', $record->id), $e);
        }
    }

    /**
     * Gives the preview back and puts the issue back, as one act, after the action refused having
     * changed nothing — only while the repair still holds its own row, for the reason
     * {@see self::finish()} gives. If that cannot be written, the repair stays running and is ended,
     * and the issue put back, once it is read as stopped.
     */
    private function giveBack(RepairRecord $record, int $issueId, IssueStatus $before, int $userId): void
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            if ($this->releaseCommand($record) !== 1) {
                $transaction->rollBack();

                return;
            }

            $this->issues()->endRepair($issueId, $before, Craft::t('web-doctor', '“{name}” was not carried out.', ['name' => $record->actionName]), $userId);
            $this->commit($transaction);
        } catch (Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }

            SafeException::log(sprintf('Repair %d could not be given back after it was refused', $record->id), $e);
        }
    }

    private function releaseCommand(RepairRecord $record): int
    {
        return Craft::$app->getDb()->createCommand()->update(RepairRecord::TABLE, [
            'status' => RepairStatus::PREVIEWED->value,
            'lockKey' => null,
            'executedBy' => null,
            'startedAt' => null,
            'acknowledged' => Evidence::encode([]),
        ], ['id' => $record->id, 'status' => RepairStatus::RUNNING->value, 'lockKey' => $record->lockKey])->execute();
    }

    /**
     * Where the issue stood before it was set repairing, written as soon as it is known, so that a
     * repair whose request dies part-way can still put the issue back when it is read as stopped.
     */
    private function remember(RepairRecord $record, IssueStatus $before): void
    {
        try {
            Craft::$app->getDb()->createCommand()->update(RepairRecord::TABLE, ['issueStatusBefore' => $before->value], ['id' => $record->id])->execute();
            $record->issueStatusBefore = $before->value;
        } catch (Throwable $e) {
            SafeException::log(sprintf('Where issue %d stood could not be recorded against repair %d', (int)$record->issueId, $record->id), $e);
        }
    }

    /**
     * Records how the repair ended, releases its lock and puts the issue back — one transaction, one
     * conditional write, no fallback. So there is never a repair recorded as ended while its issue is
     * left repairing, nor a running repair without its lock.
     *
     * If the transaction cannot be written — the row, the issue or the commit — nothing of it stands:
     * the repair stays running, holding its lock, its issue repairing. That is the one state a failed
     * ending can leave, and the service recovers from it on its own: once the repair is read as
     * stopped, the next preview, confirmation or claim of its lock ends it as "stopped without an
     * ending" — never as succeeded — and puts the issue back.
     *
     * The write matches only while this repair still holds its own row, running under its lock. A
     * repair whose request outlived the hour and was taken over as stopped finds nothing to write,
     * and so never overwrites how it was ended nor puts back an issue a newer repair now holds.
     */
    private function finish(RepairRecord $record, IssueStatus $before, ?RepairReport $outcome, ?Throwable $failure, float $durationMs, int $userId): void
    {
        $succeeded = $failure === null;
        $note = $succeeded
            ? Craft::t('web-doctor', '“{name}” was carried out. It is not yet verified: the check that found the problem has to run again.', ['name' => $record->actionName])
            : Craft::t('web-doctor', '“{name}” failed part-way.', ['name' => $record->actionName]);
        $columns = [
            'status' => ($succeeded ? RepairStatus::SUCCEEDED : RepairStatus::FAILED)->value,
            // Nothing to verify after a failure: the checks listed say where things stand.
            'verificationStatus' => ($succeeded ? VerificationStatus::PENDING : VerificationStatus::NONE)->value,
            'outcome' => $outcome === null ? null : Evidence::encode($outcome),
            'failure' => $succeeded ? null : $this->failureText($failure),
            'issueStatusBefore' => $before->value,
            'lockKey' => null,
            'finishedAt' => $this->forDb(new DateTimeImmutable()),
            'durationMs' => round($durationMs, 3),
        ];
        $lockKey = (string)$record->lockKey;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            if ($this->writeEnd($record, $columns, $lockKey) !== 1) {
                $transaction->rollBack();
                SafeException::log(sprintf('Repair %d was ended as stopped before it finished; its late ending was not written', $record->id), new RuntimeException('Taken over as stopped.'));
                $this->reread($record);

                return;
            }

            $this->issues()->endRepair((int)$record->issueId, $before, $note, $userId);
            $this->commit($transaction);
        } catch (Throwable $e) {
            if ($transaction->getIsActive()) {
                $transaction->rollBack();
            }

            SafeException::log(sprintf('The end of repair %d could not be recorded; it stays running until it is read as stopped', $record->id), $e);
        }

        $this->reread($record);
    }

    /**
     * The ending, written only while the repair still holds its own row under its own lock. A seam,
     * so a test can make the row fail to write.
     *
     * @param array<string, mixed> $columns
     * @return int How many rows were ended: 1, or 0 where the repair was no longer running under its lock.
     */
    protected function writeEnd(RepairRecord $record, array $columns, string $lockKey): int
    {
        return Craft::$app->getDb()->createCommand()->update(
            RepairRecord::TABLE,
            $columns + ['dateUpdated' => $this->forDb(new DateTimeImmutable())],
            ['id' => $record->id, 'status' => RepairStatus::RUNNING->value, 'lockKey' => $lockKey],
        )->execute();
    }

    /**
     * A seam, so a test can make the commit itself fail and prove nothing of the ending stands.
     */
    protected function commit(\yii\db\Transaction $transaction): void
    {
        $transaction->commit();
    }

    private function reread(RepairRecord $record): void
    {
        try {
            $record->refresh();
        } catch (Throwable $e) {
            SafeException::log(sprintf('Repair %d could not be read back', $record->id), $e);
        }
    }

    /**
     * Whether a repair of this issue is under way and recent enough not to be read as stopped.
     */
    private function isBeingRepaired(int $issueId): bool
    {
        foreach (RepairRecord::find()->where(['issueId' => $issueId, 'status' => RepairStatus::RUNNING->value])->all() as $record) {
            if ($record instanceof RepairRecord && !Repair::fromRecord($record)->hasStopped()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Puts back an issue left repairing with no repair of it under way — one whose repair ended by a
     * route that could not reach the issue — to where its latest repair found it, or confirmed where
     * that is not known. Deterministic, so a repairing issue can never be stuck.
     */
    private function recoverIssue(int $issueId): void
    {
        try {
            $issue = $this->issues()->get($issueId);

            if ($issue === null || $issue->status !== IssueStatus::REPAIRING || $this->isBeingRepaired($issueId)) {
                return;
            }

            $latest = RepairRecord::find()
                ->where(['issueId' => $issueId])
                ->andWhere(['not', ['issueStatusBefore' => null]])
                ->orderBy(['startedAt' => SORT_DESC, 'id' => SORT_DESC])
                ->one();
            $restore = $latest instanceof RepairRecord ? IssueStatus::tryFrom((string)$latest->issueStatusBefore) : null;

            $this->issues()->endRepair(
                $issueId,
                $restore ?? IssueStatus::CONFIRMED,
                Craft::t('web-doctor', 'Put back: no repair of this issue is under way.'),
            );
        } catch (Throwable $e) {
            SafeException::log(sprintf('Issue %d could not be put back from repairing', $issueId), $e);
        }
    }

    /**
     * Ends the repairs left running by requests that died — those matching the condition and running
     * long enough to be read as stopped — releasing their locks and putting their issues back where
     * they stood, each as one act. None is ever read as having succeeded.
     *
     * @param array<string, mixed> $condition
     * @return bool Whether there was one to end.
     */
    private function releaseStopped(array $condition): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();
        $released = false;

        try {
            foreach (Savepoint::committed(RepairRecord::find()->where($condition)) as $holder) {
                if (!$holder instanceof RepairRecord || !Repair::fromRecord($holder)->hasStopped()) {
                    continue;
                }

                $holder->status = RepairStatus::FAILED->value;
                $holder->verificationStatus = VerificationStatus::NONE->value;
                $holder->failure = Craft::t('web-doctor', 'Stopped without an ending: the request carrying it out went away. It may have made some of its changes; run the checks listed to see where things stand.');
                $holder->lockKey = null;
                $this->save($holder);

                if ($holder->issueId !== null) {
                    $this->issues()->endRepair(
                        (int)$holder->issueId,
                        IssueStatus::tryFrom((string)$holder->issueStatusBefore) ?? IssueStatus::CONFIRMED,
                        Craft::t('web-doctor', '“{name}” stopped without an ending.', ['name' => $holder->actionName]),
                    );
                }

                $released = true;
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            SafeException::log('A stopped repair could not be released', $e);

            return false;
        }

        return $released;
    }

    /**
     * A person's earlier unconfirmed preview of the same repair of the same issue, replaced by the one
     * they are making now. Kept as history, and no longer confirmable. Nothing else is touched: other
     * people's previews, other repairs, and anything running or carried out.
     */
    private function supersede(int $issueId, string $actionId, int $userId): void
    {
        RepairRecord::updateAll(
            ['status' => RepairStatus::SUPERSEDED->value],
            ['issueId' => $issueId, 'action' => $actionId, 'previewedBy' => $userId, 'status' => RepairStatus::PREVIEWED->value],
        );
    }

    /**
     * Why a repair failed, as a reader is told it: named by its kind, with the detail in the log,
     * because an exception's message can quote SQL that redaction does not remove.
     */
    private function failureText(Throwable $failure): string
    {
        return Craft::t('web-doctor', 'It stopped with an error ({type}). It may have made some of its changes; run the checks listed to see where things stand. The details are in Craft’s logs.', [
            'type' => (new \ReflectionClass($failure))->getShortName(),
        ]);
    }

    private function applies(RepairActionInterface $action, RecommendationCase $finding): bool
    {
        try {
            return $action->isApplicable($finding);
        } catch (Throwable $e) {
            SafeException::log(sprintf('Whether the repair %s applies could not be established', $action->id()), $e);

            return false;
        }
    }

    private function authorized(RepairActionInterface $action): bool
    {
        try {
            return $action->isAuthorized();
        } catch (Throwable $e) {
            SafeException::log(sprintf('Whether Craft allows the repair %s could not be established', $action->id()), $e);

            return false;
        }
    }

    private function signedInId(): ?int
    {
        try {
            $id = Craft::$app->getUser()->getIdentity()?->id;

            return $id === null ? null : (int)$id;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Every step worth keeping, in Craft's log: what was done, to which issue, where, and by whom.
     */
    private function logAction(RepairRecord $record, string $what, int $userId): void
    {
        Craft::info(Redaction::redactString(sprintf(
            'Repair %d (%s, %s risk) %s for issue %s in the "%s" environment by user %d.',
            $record->id,
            $record->action,
            $record->risk,
            $what,
            $record->issueId ?? '(deleted)',
            $record->environment,
            $userId,
        )), WebDoctor::LOG_CATEGORY);
    }

    private function siteExists(int $siteId): bool
    {
        try {
            return Craft::$app->getSites()->getSiteById($siteId, true) !== null;
        } catch (Throwable $e) {
            SafeException::log('Whether a site still exists could not be established', $e);

            return false;
        }
    }

    /**
     * A seam rather than a private call, so a test can make a repair's row fail to save and prove the
     * issue goes back with it.
     *
     * @throws RuntimeException if the row will not save.
     */
    protected function save(RepairRecord $record): void
    {
        if (!$record->save()) {
            throw new RuntimeException(sprintf(
                'A Web Doctor repair row could not be saved: %s',
                Redaction::redactString(json_encode($record->getErrors()) ?: 'unknown error'),
            ));
        }
    }

    private function forDb(DateTimeImmutable $when): string
    {
        return Db::prepareDateForDb($when) ?? gmdate('Y-m-d H:i:s');
    }

    private function fit(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1) . '…' : $value;
    }

    /**
     * The plugin's own dependencies, or the ones injected. Never a second, unconfigured copy: a repair
     * run against a registry or an Issue Center nobody configured would be carried out on a different
     * footing from the one every page shows, so a missing dependency stops the repair instead.
     *
     * @throws InvalidConfigException
     */
    private function actions(): RepairActions
    {
        return $this->actions ??= WebDoctor::getInstance()?->getRepairActions() ?? throw new InvalidConfigException('Repairs needs the repair actions registry, and Web Doctor is not installed to provide it.');
    }

    private function issues(): Issues
    {
        return $this->issues ??= WebDoctor::getInstance()?->getIssues() ?? throw new InvalidConfigException('Repairs needs the Issue Center, and Web Doctor is not installed to provide it.');
    }

    private function evidenceStore(): EvidenceStore
    {
        return $this->evidence ??= WebDoctor::getInstance()?->getEvidence() ?? throw new InvalidConfigException('Repairs needs the evidence store, and Web Doctor is not installed to provide it.');
    }

    private function permissions(): Permissions
    {
        return $this->permissions ??= WebDoctor::getInstance()?->getPermissions() ?? throw new InvalidConfigException('Repairs needs Web Doctor’s permissions, and Web Doctor is not installed to provide them.');
    }
}
