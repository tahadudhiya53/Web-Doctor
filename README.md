# Web Doctor

A diagnostic plugin for Craft CMS. It runs a set of read-only checks over an installation — Craft,
PHP, the database, plugins, the queue, storage, mail and configuration — and reports what each one
found, with the evidence behind it, on a dashboard in the control panel. Problems that persist are
tracked as issues, so the same failure found on Monday and again on Friday is one thing with a
history rather than two reports.

> **Early development.** This README documents only what the plugin does today.

## Features

- **Health dashboard** in the control panel, grouped by category, showing each check's status,
  severity, summary, evidence summary, timestamp and duration.
- **A health score** with the weights and per-check penalties that produced it shown beside it.
- **An Issue Center** that turns warnings and failures into issues which persist across runs,
  deduplicated by a stable fingerprint, with first seen, last seen, how many times, and a history
  of what has happened to each one.
- **Issues that resolve themselves from evidence** — an issue closes when the check that raised it
  runs again and no longer reports the problem, never because somebody pressed a button.
- **Evidence kept behind every issue** — the facts each check recorded, attributed to the run,
  environment and site they were gathered in, stored once per distinct fact however many runs see
  it, and inspectable on the issue's page with every withheld value clearly marked.
- **Investigations** — from an issue's page, run the check that raised it again alongside the
  checks related to it, chosen from a fixed, published list of what is related to what, each with
  the reason it was chosen. What each check reported, the evidence it left, what else is open
  nearby and a timeline of the whole investigation are kept against the issue.
- **Recipes** — start from a symptom rather than an issue: "I have a 500 error", "Email is not
  being sent", "Queue jobs are failing or not running", "The database is failing", "Something
  broke after a deployment". Each recipe runs the checks that cover what its symptom most often
  comes from, as an investigation, and what they find becomes issues like any other finding.
- **Possible causes, with the evidence for and against them** — each investigation weighs what it
  found against a fixed list of known causes, and says for each one that fits how firmly it is
  held, why, and what counts against it.
- **Recommendations** — every warning and failure a shipped check reports comes with what to do
  about it, chosen by a fixed rule from the evidence behind it: the problem, the evidence, the
  likely cause where one was weighed, the action, its risk and why, what to have in place first,
  and which checks to run again to tell whether it worked.
- **Repairs, previewed and confirmed** — for the two findings Web Doctor can safely put right
  itself through Craft's own API — missing storage directories, and failed queue jobs — an issue's
  page offers a repair. Nothing changes until you have seen exactly what would change and confirmed
  it; everything is read again before it runs; and a repair that ran does not resolve the issue.
  There is no "fix everything".
- **Repairs verified, not assumed** — once a repair has run, Web Doctor runs the check that found
  the problem again, with the checks around it, reads whether what the repair did still holds, and
  compares the evidence before and after. The answer is Verified, Verification failed ("repair
  completed but verification failed") or Inconclusive, with every reason given.
- **History** — every diagnostic run kept as it finished, with its health score and the arithmetic
  behind it when it covered every check; every repair kept, previewed or carried out, with who did
  it, what it changed and how it ended; and an audit trail of who ran checks, investigated,
  repaired, verified, and moved or resolved issues — each filterable by date, user, environment and
  site. A change Web Doctor makes and its entry in the trail are written together, so neither stands
  without the other.
- **Errors grouped rather than listed** — every exception a check runs into is recognised by its
  type, its message with the values that change between occurrences taken out, where it was thrown
  and the exceptions behind it, so the same error seen again is counted against one group, with
  how often and between when it was seen, which checks ran into it and which issues it relates to.
- **Centralised redaction** — credentials are removed wherever Web Doctor writes anything down,
  recognised by the key they sit under, by their shape, or by being the value of one of the
  environment's own credentials. No check has to remember to do it.
- **Manual runs** — every check or a selection of them, at a chosen depth. Opening the dashboard
  runs nothing.
- **Diagnostic depth** (shallow / normal / deep) so a run can be bounded.
- **Eight permissions**, so reading results, running checks, reading issues, changing them,
  reading what their evidence contains, investigating them, repairing them and reading the audit
  trail are each granted separately.
- **Environment- and site-scoped results**, so one environment's answers are never shown as
  another's.
- **`php craft webdoctor/status`** for confirming an installation from a script.
- **Extension points** other plugins can register their own checks and recipes through.

### The checks

| ID | Category | What it answers |
|---|---|---|
| `craft.version` | Craft | Which Craft and edition is running, and whether an update skipped a version it had to pass through |
| `craft.application` | Craft | Whether Craft is installed and serving, or still in maintenance mode |
| `php.version` | PHP | Whether PHP meets the version Craft declares it requires |
| `php.extensions` | PHP | Whether the extensions Craft marks required are loaded, and which recommended ones are not |
| `php.configuration` | PHP | Memory, execution time, upload limits, and whether the opcode cache keeps docblock comments |
| `database.connection` | Database | Whether Craft can reach its database, and whether the server version is supported |
| `database.migrations` | Database | Whether migrations are pending, or the schema is newer than the code |
| `database.charset` | Database | Whether the database's character set matches Craft's configuration, and whether one sampled table accepts four-byte characters |
| `plugins.installed` | Plugins | What is installed, at which versions, editions and states |
| `plugins.health` | Plugins | Plugins that never loaded, plugins whose code is gone, and licensing problems |
| `queue.backlog` | Queue | How much work is queued, how long the oldest job has waited, and whether a running job has overrun its own limit |
| `queue.failedJobs` | Queue | How many jobs have failed, and what the most recent of them were, grouped by job |
| `filesystem.volumes` | Filesystem | Whether every asset volume has a filesystem behind it that can be reached |
| `storage.paths` | Storage | Whether Craft's storage, runtime, template and log directories exist and are writable |
| `email.configuration` | Email | Whether Craft's mail settings are complete and the credentials they refer to exist |
| `environment.configuration` | Environment | Which environment Craft thinks it is in, and whether its settings suit that |
| `projectConfig.pendingChanges` | Project Config | Whether the project config files have been applied |
| `projectConfig.integrity` | Project Config | Whether those files can be applied at all, or the schema versions disagree |

Every check reads and nothing more: nothing is sent, written or created, no log file is searched,
and no credential is ever recorded as a value — passwords, keys, tokens and DSNs are reported as
`Present`, `Missing`, `Invalid` or `Unknown`.

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later

## Versioning

Web Doctor's major version matches the Craft major version it supports:

- **5.x** is for Craft CMS 5.

Versions follow [semantic versioning](https://semver.org/). The plugin's schema version is
separate. It only tells Craft when to run database migrations, and does not follow the release
version.

## Installation

```bash
composer require tahadudhiya53/craft-web-doctor
php craft plugin/install web-doctor
```

Installing creates the tables listed under [What is stored](#what-is-stored). Uninstalling drops
them and leaves nothing else behind.

## Usage

1. Open **Web Doctor** in the control panel. It shows what the last run concluded — opening it
   does not run anything.
2. Choose a depth: shallow skips the expensive work, normal is the default, deep does the most.
   A request that names no depth runs at normal; one that names anything other than `shallow`,
   `normal` or `deep` — for checks, an investigation or a recipe — is refused without running or
   recording anything, as is an issue ID that is not a whole number, or a choice of checks in any
   shape but the form's. The same holds for reading: a list asked for with a status, severity,
   sort, date or page that is not one is refused rather than shown some other way.
3. Press **Run all checks**, or tick individual checks and press **Run selected checks**.
4. Read the results. The health score sits at the top, with **How this score was calculated**
   beneath it listing the weights and what each check subtracted.

Checks run in the request the form submits, so a run takes as long as the checks take. Every
warning and failure the run reports is recorded in the Issue Center at the same time.

Settings live at **Settings → Plugins → Web Doctor** — one setting, the name the control panel
calls Web Doctor — and can be overridden from a `config/web-doctor.php` file:

```php
<?php

return [
    'pluginName' => 'Site Health',
];
```

## Permissions

Under a **Web Doctor** heading in a user group's permissions:

| Permission | ID | Allows |
|---|---|---|
| View Web Doctor | `webDoctor:view` | Reaching Web Doctor in the control panel |
| Run diagnostics | `webDoctor:runDiagnostics` | Setting checks running from the dashboard |
| View issues | `webDoctor:viewIssues` | Reading the Issue Center and the errors the checks ran into |
| Manage issues | `webDoctor:manageIssues` | Changing where an issue stands |
| View evidence | `webDoctor:viewEvidence` | Reading what an issue's evidence contains, and what an error said and where it was thrown |
| Investigate issues | `webDoctor:investigateIssues` | Starting an investigation of an issue, or running a recipe |
| Run repairs | `webDoctor:runRepairs` | Previewing, carrying out and verifying a repair of an issue |
| View audit trail | `webDoctor:viewAuditTrail` | Reading the audit log: who did what with Web Doctor, and when |

A non-admin also needs Craft's own **Access Web Doctor** permission (under "Access the control
panel") to reach the section at all.

Each is nested under the one it depends on and checked separately: running checks and starting
investigations spend the site's time, changing an issue records a decision, and evidence carries
internals — file paths, stack traces, database errors — that somebody following an issue may not
need. Without "View evidence", a reader still sees what kind of evidence an issue rests on. A
failed queue job's error is read only when the checks run in dev mode or are run by an admin, as
Craft shows it; once read it is evidence, so granting "View evidence" grants reading it.
Reading a finished investigation, and the Recipes page, needs only "View issues". Admins pass, as
they do elsewhere in Craft.

"Run repairs" is the only permission that lets somebody change the installation, and it is granted
by nothing else. A repair also needs whatever Craft itself requires for the same action: retrying
queue jobs needs access to Craft's Queue Manager utility, exactly as retrying them in Craft does.
Verifying a repair needs "Run repairs" and nothing from Craft, because it only runs checks.

"View audit trail" is nested under "View issues", and both are needed to read the audit log: every
entry is about something in the Issue Center, and the log says what people did, which is a
different thing to show somebody from what was found. The repair history needs only "View issues".

## Running checks from code

```php
use Tahadudhiya\WebDoctor\WebDoctor;
use Tahadudhiya\WebDoctor\enums\DiagnosticDepth;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;

$engine = WebDoctor::getInstance()->getDiagnosticEngine();

$run = $engine->runAll(DiagnosticContext::current());
$one = $engine->run('queue.failedJobs', DiagnosticContext::current(DiagnosticDepth::DEEP));
```

## Adding a check from another plugin

Extend `Diagnostic`, declare a permanent ID scoped to your own plugin, and return a result:

```php
use Tahadudhiya\WebDoctor\base\Diagnostic;
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\enums\Severity;
use Tahadudhiya\WebDoctor\models\DiagnosticContext;
use Tahadudhiya\WebDoctor\models\DiagnosticResult;

class OrphanedOrdersDiagnostic extends Diagnostic
{
    public const ID = 'myPlugin.orphanedOrders';

    public function name(): string
    {
        return 'Orphaned orders';
    }

    public function category(): DiagnosticCategory
    {
        return DiagnosticCategory::COMMERCE;
    }

    public function run(DiagnosticContext $context): DiagnosticResult
    {
        return MyPlugin::getInstance()->getOrders()->countOrphaned() > 0
            ? $this->fail('Orders have no customer behind them.', severity: Severity::HIGH)
            : $this->pass('Every order has a customer.');
    }
}
```

Register it from your plugin's `init()`:

```php
use Tahadudhiya\WebDoctor\events\RegisterDiagnosticsEvent;
use Tahadudhiya\WebDoctor\services\Diagnostics;
use yii\base\Event;

Event::on(
    Diagnostics::class,
    Diagnostics::EVENT_REGISTER_DIAGNOSTICS,
    fn(RegisterDiagnosticsEvent $event) => $event->diagnostics[] = new OrphanedOrdersDiagnostic(),
);
```

Guard it with `class_exists(Diagnostics::class)` if Web Doctor is optional for your plugin.

Notes worth knowing:

- An ID is permanent. It is dot-separated, at least two segments, each starting with a lowercase
  letter and continuing in letters and digits. Malformed IDs are refused at registration, and the
  first valid registration of an ID keeps it — a later plugin cannot replace an existing check.
- Report a problem by returning a result, not by throwing. An exception means the check itself
  broke, which the engine records as an `error` result while the rest of the run continues.
- The base class provides `pass()`, `info()`, `warning()`, `fail()`, `skipped()` and `unknown()`.
- **Status** says what happened (`pass`, `info`, `warning`, `fail`, `error`, `skipped`,
  `unknown`); **severity** says how much it matters (`info`, `low`, `medium`, `high`,
  `critical`). They are independent — `fail` + `low` is an ordinary result, and `critical` is
  never a status.

## Adding a recipe from another plugin

A recipe holds no diagnostic logic: it names the areas worth looking at for a symptom, each with
the reason, and the checks registered in those areas do the looking. Register one from your
plugin's `init()`:

```php
use Tahadudhiya\WebDoctor\enums\DiagnosticCategory;
use Tahadudhiya\WebDoctor\events\RegisterRecipesEvent;
use Tahadudhiya\WebDoctor\investigations\RelatedArea;
use Tahadudhiya\WebDoctor\recipes\Recipe;
use Tahadudhiya\WebDoctor\services\Recipes;
use yii\base\Event;

Event::on(Recipes::class, Recipes::EVENT_REGISTER_RECIPES, function(RegisterRecipesEvent $event) {
    $event->recipes[] = new Recipe(
        id: 'myPlugin.ordersStuck',
        title: 'Orders Doctor',
        symptom: 'Orders are stuck in processing',
        description: 'Looks at the order checks, then at the queue orders are processed on.',
        category: DiagnosticCategory::COMMERCE,
        // Looked at at every depth.
        primary: [
            RelatedArea::check('myPlugin.orphanedOrders', 'Orders with no customer cannot be processed.'),
        ],
        // Added at normal depth and deeper.
        related: [
            RelatedArea::category(DiagnosticCategory::QUEUE, 'Orders are processed by queue jobs.'),
        ],
        leads: ['The payment gateway’s own dashboard, for payments that never reached the site.'],
    );
});
```

A recipe ID has the same shape and the same first-wins rule as a check ID, so a plugin cannot
replace one of Web Doctor's own recipes. A malformed recipe is logged and skipped without stopping
anyone else's.

## The Issue Center

**Web Doctor → Issues** lists the problems that have been found, filtered by status, severity,
check, site, environment and when they were last seen, and sorted by any of those. Each issue has
a page of its own showing what the check reported, what evidence it rests on, where it came from,
and everything that has happened to it.

### What becomes an issue

A `warning` or a `fail` — the two results that say something about the site is wrong. A check that
`error`ed or returned `unknown` does not become an issue. Both count against the health score,
because an unanswered question is not a clean bill of health, but neither asserts that a problem
exists, and a list of problems that may not be there is a list nobody can act on.

### How the same problem is recognised

Each issue has a fingerprint built from the check, the environment, the site, and the affected
component and plugin. The same problem found again updates the issue that already describes it:
the count goes up, the last-seen date moves, and the wording, severity and result are refreshed to
the current reading.

Only what identifies a problem goes into the fingerprint. The wording, the severity and whether
the check warned or failed all describe how the problem looks right now, so none of them is part
of its identity — a check reporting "3 jobs have failed" and then "17 jobs have failed", or
warning and then failing, is reporting one problem twice. Raising a fresh issue each time would
split one problem's history in two.

### Status, and what "resolved" means

| Status | Set by | Meaning |
|---|---|---|
| New | Web Doctor | Found, and nobody has looked at it |
| Confirmed | A person | Looked at and accepted as real |
| Investigating | A person | Somebody is working out what is going on |
| Ignored | A person, with a reason | Seen, and deliberately not acted on for now |
| Won't fix | A person, with a reason | Seen, and deliberately never going to be acted on |
| Resolved | Web Doctor only | The check that raised it ran again and no longer reports it |
| Repairing | Web Doctor only | A repair of it is being carried out |

**Nobody can mark an issue resolved.** The control panel offers no such control and the service
refuses the request if one is made. An issue resolves when a later run of the same check reaches a
conclusion that is not the problem, and the run that established that is recorded against it. The
detail page says in as many words that this is an observation and not a verification: nothing has
confirmed that the underlying cause was addressed. The one stronger claim is **Repair verified**,
which an issue's resolution says only when a repair of it was verified (see Verifying a repair).

Resolution also takes an *answer*, never the absence of one. A check that errored, was skipped, or
could not tell resolves nothing — it established neither that the problem is there nor that it has
gone. And a clean run never overturns a decision somebody made: an issue that was ignored stays
ignored, though it still counts the times it is seen.

An issue that is found again after resolving comes back as new, keeping its count and its history.

### Evidence

Every fact a check records is evidence: what kind of fact it is, what observed it, the value
itself, when it was true, where it can be found again (a table, a queue job, the file and line an
exception came from), and notes on how it was gathered. The engine attributes each piece to the
check, run, environment and site it was gathered in — a check cannot claim those itself.

The evidence behind a warning or a failure is kept against the issue it raised. Evidence from a
passing check, or from one that errored or could not tell, is not stored: it stays with the latest
run on the dashboard.

What is kept is bounded:

- **Once per distinct fact.** The same evidence found by the next run is counted, not stored again —
  even if the moment it describes has moved on; that moment is updated rather than stored twice.
  A new reading — 17 failed jobs where there were 3 — is a new fact, and the old one moves to the
  issue's earlier evidence rather than being overwritten.
- **A size limit per fact.** The value may occupy at most 16 KiB once encoded and its notes 4 KiB;
  a string is cut at 2,000 characters, a structure at 100 entries and six levels deep. Evidence
  that had to be cut short says so.
- **A limit per issue.** An issue holds at most 100 facts, and the ones seen least recently go
  first, so the evidence behind the latest finding is never what is removed. The limit is the
  `maxPerIssue` property of the `evidence` component.

The issue page shows the evidence behind the latest finding, then everything earlier, a page at a
time. Values Web Doctor withheld are marked **Redacted** — in evidence and in anything else a check
wrote, such as an issue's title. They were removed before anything was stored and cannot be
recovered. A value cut short is marked as such, and each piece of evidence
says how many values in it were withheld.

### Investigations

An issue's page has an **Investigate** section. Before anything runs it shows what an
investigation would look at and why; somebody with "Investigate issues" can then start one at a
chosen depth.

Which checks run is decided deterministically from the kind of problem — the category of the check
that raised it — using one written-out list of relationships, each with its reason. A database
problem, for example, also looks at the queue (Craft's default queue keeps its jobs in the
database), plugins (they bring tables and migrations of their own) and the environment (connection
settings usually come from it). An email problem looks at the environment and at the queue's
failed jobs and backlog; a failing request looks at PHP, plugins, the database, the queue, Craft,
configuration and the environment. The check that raised the issue always runs first.

- **Shallow** runs only the issue's own check and the rest of its category. **Normal** adds the
  related areas. **Deep** runs the same checks as normal and asks each to go further.
- A finding that names a plugin brings the plugin checks in.
- A related area that no installed check covers is listed as such, and each plan also names what
  is worth inspecting by hand — Craft's logs, a mail provider's delivery log — that no check reads.
- An investigation runs at most 25 checks (`maxChecks` on the `investigations` component).

The checks run in the request, against this environment and the site the issue was found on. An
issue found in another environment, or on a site that has since been deleted (including one Craft
has moved to the trash), is not investigated here, and the page says why. Their findings go
through the Issue Center like any other run: a related check that finds a problem raises or updates its own issue, and if the issue's own check
no longer reports the problem the issue is observed clear.

Each investigation page shows its status — completed; partly completed when a check broke or could
not tell, or when the investigation stopped part-way with some results already recorded; or could
not finish when it stopped before recording any — what the issue's own check reported this time,
every check performed with its result and reason, the checks that could not be completed, related signals
(problems the related checks found, and other issues already open in the same environment and site,
in an area the investigation looked at or naming the same plugin), the evidence each check recorded
and a timeline. What was observed is then weighed against the known causes (see below).

An investigation that stops records why by the kind of error only; the details are in Craft's logs.
An investigation keeps at most 100 pieces of evidence (`maxEvidence`), and an issue keeps its 20
most recent investigations (`maxPerIssue`). Investigations are deleted with their issue.

### Recipes

The **Recipes** page starts from a symptom rather than an issue. Each recipe says what it looks at
and why before anything runs; somebody with "Investigate issues" can run it at a chosen depth.

| Recipe | Symptom | Looks at first | Then, at normal depth and deeper |
|---|---|---|---|
| 500 Error Doctor | I have a 500 error | PHP, Craft, plugins, the database | Storage, the queue, configuration, the environment |
| Email Doctor | Email is not being sent | The mailer's configuration, the environment | Failed queue jobs, the queue backlog |
| Queue Doctor | Queue jobs are failing or not running | Failed jobs, the backlog and long-running jobs | PHP's limits, the database connection, plugins |
| Database Doctor | The database is failing | The connection, migrations, the character set | The environment, pending project config, failed queue jobs, plugins |
| Deployment Doctor | Something broke after a deployment | Craft, PHP, plugins, project config, migrations, the environment | The queue, filesystems, storage, the database connection |

What each recipe reports is what its checks establish, and no more. The Queue Doctor counts failed
jobs at every depth, but reads their errors and recognises the same job failing repeatedly only at
normal depth and deeper. The Database Doctor's character-set check samples the one table Craft uses
as its indicator, not every table. The Deployment Doctor looks at the state of the installation now:
Web Doctor records no deployments, so it cannot say what a deployment changed or when.

A recipe runs as an investigation, through the same engine and the same Issue Center as any other.
Its checks run in the request, against this environment and the site in view — where a run from the
dashboard would — so what it finds raises or updates the same issues such a run would. Its page shows
every check with its result and reason, the problems found (each linked to its issue), other issues
open nearby, the errors the checks ran into, the evidence they recorded and a timeline.

A symptom is not an issue, and the known causes each explain an issue, so a recipe weighs them for
the most serious problem its checks found — the highest severity, and of equals the first it ran —
and says which problem that was. A recipe that finds nothing says there was nothing to weigh.

What no check here can inspect is stated as a lead rather than left out. In particular, Web Doctor
does not capture the exceptions a site throws while serving requests and does not read logs, so the
500 Error Doctor points to Craft's web log for the failing request's exception and trace. The Email
Doctor reports mail settings only as present or missing, and never sends a message.

The depth is shallow, normal or deep; a request that names none runs at normal, and one that names
anything else is refused without running anything. A recipe keeps its 20 most recent investigations
in each environment and site (`maxPerRecipe` on the `investigations` component); the page lists the
five most recent of each. The `investigations` component's bounds — `maxChecks`, `maxEvidence`,
`maxPerIssue`, `maxPerRecipe`, `relatedLimit` — are refused with an `InvalidConfigException` when
set below what they can mean (one, or zero for `maxEvidence`), rather than read as another number.

Recipe runs are not serialised: the run button is disabled once a form is submitted, but that is a
convenience in the browser, not a guarantee, and two requests started together run two
investigations.

### Possible causes

The last thing an investigation does is weigh what it found against Web Doctor's fixed list of
known causes. Nothing is inferred: each cause is written out with what it requires, what supports
it, what establishes it and what counts against it, and the same findings always produce the same
causes in the same order. The causes are:

| Cause | What it rests on |
|---|---|
| The database is refusing the credentials Craft connects with | A failed connection, the server refusing the login, whether a user and password are configured |
| Craft cannot reach the database server | A failed connection, an error saying the server could not be reached, other database checks breaking |
| The database's character set cannot store some of what is written to it | The character-set check, errors and failed queue jobs recording a value refused for its characters |
| Nothing is taking jobs off the queue | How long the oldest job has waited, whether anything is running, whether Craft runs the queue itself |
| Queue jobs are running out of memory | Failed jobs whose recorded error is PHP running out of memory, PHP's memory limit, repeated failures |
| A deployment has not been finished here | Pending migrations and project config changes, schema version mismatches, a recorded deployment, problems first seen together |
| Several of these problems come from the same place | Other problems naming the same plugin or component, the plugin's health, errors thrown from its code, problems first seen together |
| One error is behind several checks | The same error run into by the issue's own check and others |
| A setting this environment depends on is missing | A required setting the issue's own check recorded as missing |

A cause is offered only for a problem it could explain, and only when what it requires was found.
Only problems still open, in the same environment and site, count as history for it — an issue
somebody ignored or ruled out does not. How firmly it is held follows one published rule, shown on
the page beside every cause:

- **Possible** — what the cause requires was found;
- **Likely** — and at least one signal that supports it;
- **High confidence** — and every signal that supports it, where it has at least two;
- **Confirmed** — evidence that establishes it was found, recorded by a check that itself
  established it, and nothing counts against it.

Each thing found that counts against a cause lowers it one step, never below Possible, and some
causes are never held above a stated level — the character-set cause, for instance, stays at
Likely because only one table is sampled. For each cause the page shows the problem, the evidence
(each fact linked to the check, evidence, error or issue it came from), the reasoning, the
confidence, the contradicting evidence, what was looked for and not found, related issues, a
recommended action and what to investigate next. What the evidence contains is shown only to users
with "View evidence". A cause is a candidate, not a verdict, and causes are kept only for an
investigation that finishes.

Web Doctor does not read logs or record requests or deployments, so nothing here correlates by
request, and the deployment cause can use a recorded deployment only when a check contributes one.

### Recommendations

Every warning and failure a Web Doctor check reports gets a recommendation, on the dashboard (under
the result, collapsed), on the issue's page, and on an investigation's page for each problem its
checks found. Each is laid out in the order a reader acts on it:

- **Problem** — the finding, and what it means;
- **Evidence** — the facts that selected the recommendation, each linked to where it was recorded;
- **Likely cause** — the cause an investigation weighed, where the advice acts on one;
- **Recommended action**, and why this action rather than another;
- **Before you start** — what has to be in place first, such as a current backup;
- **Risk** — low, medium or high, for carrying the action out (not for the problem), with the
  reason;
- **Verification** — what shows it worked, and which checks to run again, the one that found the
  problem first. An issue is still resolved only when that check runs again and no longer reports
  it;
- **Automatic repair** — never automatic. Where Web Doctor has a repair for the advice, the issue's
  page says so and offers it with a preview (see Repairs below); otherwise the action is done by
  hand.

Nothing is composed on the spot. Each recommendation is written out in one list, selected by the
evidence the check records, and where a check can report more than one kind of problem, the check's
own order decides which advice is given. A finding no rule covers — a check contributed by another
plugin, say — gets no recommendation: the page says so and shows the check's own advice, labelled
as the check's. Pass, info, error, unknown and skipped results get none: they establish nothing to
act on.

Once an investigation holds a cause as Likely or firmer, the advice for that cause comes first, in
the cause's own words, with the risk and verification written for it; a cause held only as Possible
is a lead to look into, not something to act on. On an issue's page the causes are the ones weighed
by its newest investigation that finished, and on an investigation's page only the problem the
causes were weighed for is advised on for them. A resolved issue has nothing to recommend.

Some of the advice is deliberately cautious. Failed queue jobs are to be read before they are
retried, and retried only when running them again is safe. An asset volume whose files cannot be
found is to have its filesystem checked first, and its asset records are never to be deleted to
clear the finding. Project config is applied through `php craft up` or
`php craft project-config/apply`, never by editing the database to match.

A rule that cannot be applied is said on the page ("could not be worked out"), never read as no
rule covering the finding, and no rule after it for the same check is given in its place: the
check's own order decides which advice applies. A weighed cause that is read back without the
evidence it was found on is not acted on, and a check an investigation kept only part of the
evidence of is not advised on from what remains; the issue's own page advises on it.

Recommendations are worked out again each time a page is shown and are not stored. Showing them
reads the finding's evidence and, on an issue's page, its newest investigation's causes; it runs no
check and writes nothing.

### Repairs

For a few findings Web Doctor can carry out the fix itself, through Craft's own API. It offers two:

| Repair | For | Risk | What it does |
|---|---|---|---|
| Create the missing storage directories | `storage.paths` reporting a missing directory | Low | Creates the directories the check names as missing, empty, with Craft's own directory helper and the directory permissions Craft is configured to use. Nothing that exists is touched. |
| Retry the failed queue jobs | `queue.failedJobs` reporting failed jobs | Medium | Puts up to 50 of this queue's oldest failed jobs back in the queue with Craft's own retry — the one Utilities → Queue Manager uses. Whatever runs the queue then runs them. |

Applying project config, running migrations, changing file permissions and deleting anything are
deliberately not offered: each can remove data, replace configuration made elsewhere, or reach
beyond the installation, and Craft's own tools for them already show what they will do.

A repair takes two steps. **Preview this repair**, on the issue's page, reads the installation live
and shows exactly what would change, the state it read, its risk and why, and what has to be true
first. It changes nothing. **Carry out this repair**, on the preview's page, is the confirmation:

- every prerequisite only a person can know — that a job is safe to run twice, that what its error
  names has been dealt with — has to be ticked, exactly those shown and each once;
- a high-risk repair also needs the name of the environment it will change typed exactly, with no
  change of case or spacing (neither shipped repair is high-risk);
- a preview can be confirmed for 15 minutes, only once, and only in the environment it was made in.
  Previewing the same repair again replaces your earlier preview, which stays in the history as
  "Replaced by a newer preview".

Before anything is changed, Web Doctor then establishes, itself — not relying on the page that asked
— that you are signed in, hold "Run repairs" and have Craft's own permission for the same action;
that the issue is still open and neither dismissed nor already being repaired; that it was recorded
in this environment, on a site that still exists, and has not been found again since the preview;
that the repair still answers its latest finding; that nothing else of the same kind is being
carried out here; that every prerequisite it can check still holds; that the repair itself — its
name, risk, reasons, prerequisites and how it is verified — is the one you previewed; and that what
it would act on is still exactly what the preview showed. If anything has changed, nothing is
carried out and you are asked to preview it again. What a repair acts on always comes from the
installation, read live — never from anything a request sends. A failed job retried by somebody else, or one that failed
again, since the preview is enough to stop it. A job that ran out of memory is never retried — raise
its memory limit first.

While a repair runs the issue shows **Repairing**; when it finishes, cleanly or not, the issue goes
back to where it stood, and its history records both. **A repair that ran does not resolve the
issue.** It is recorded as "Awaiting verification", and the issue resolves only when the check
that found the problem runs again and no longer reports it — the repair's page lists the checks to
run. A repair that fails part-way is recorded as failed, with its kind of error (never its message)
and a note that it may have made some of its changes.

### Verifying a repair

A repair carried out cleanly is verified straight away, in the same request, and its page offers
**Verify again** to anybody with "Run repairs" — a retried job, for one, can only be told to have
worked once whatever runs the queue has run it. Verifying:

- runs the check that found the problem, then the other checks the repair names, then the rest of
  the problem's area — each once, in that order, with the reason shown beside it;
- reads what that particular repair should have left true: that the directories it created are
  still there, where it created them, and writable; or that the jobs it retried have run and none
  failed again (see below);
- compares the evidence the issue last recorded with what the check records now — what is still
  the same, what is no longer recorded, and what is new — by what each fact says, never by when it
  was seen;
- looks for problems and errors that appeared since the repair started.

Other issues its checks find or clear are recorded as any run's are. The issue being verified is
settled by the answer alone, which is one of three:

| Answer | When |
|---|---|
| Verified | The check that found the problem answered and reports nothing, with evidence for its answer; every other check answered; everything the repair should have left true holds; and nothing new appeared since the repair. The issue is resolved as **Repair verified**. |
| Verification failed | The check that found the problem still reports it, or something the repair should have left true conclusively does not hold. The page says **Repair completed, but verification failed**, and the issue stays open — or opens again. |
| Inconclusive | Anything short of both, each reason listed: a check that could not answer or is not registered here; no evidence to compare; a retried job that has not run, or whose run cannot be confirmed; no verification action for that kind of repair; a problem or error that appeared since the repair; findings that could not be recorded; or the issue changing while it was verified. It leaves the issue where it stands. |

A retried job counts as having run only on Craft's word: Craft's queue signals, after a job's own
code finished without an error, that it ran, and Web Doctor notes that in Craft's cache for jobs a
repair retried. A job that has simply left the queue table is not taken to have run — releasing a
job by hand removes it too — so without that note, or if the cache was cleared, the verification is
inconclusive.

Only the most recent repair carried out for an issue, cleanly, in the environment and on the site it
was carried out for, can be verified, and one issue is verified at a time. Verifying changes nothing
in the installation. Each verification is written to Craft's log and kept in
`webdoctor_verifications` — the checks and what each said, the conditions as read, up to ten pieces
of evidence before and after with the comparison, and the errors met — the most recent 20 per
repair. What evidence contains and the conditions' details need "Run repairs" or "View evidence".

A repair's ending, its lock being released and its issue being put back are written together, once.
If that cannot be written, none of it is: the repair is left "Being carried out", holding its place,
and after an hour it is read as stopped — the next preview or confirmation ends it as "Stopped
without an ending", never as succeeded, and puts the issue back. A repair ended that way whose
request later finishes changes nothing. An issue left "Repairing" with no repair under way is put back
the same way, so an issue can never be stuck.

Every preview, repair and refusal is written to Craft's log with the repair, the issue, the
environment and the user. Repairs are kept in `webdoctor_repairs` — the preview, the prerequisites
as read, what was acknowledged, what it did, the state before and after, and who previewed and
carried it out — and outlive the issue they were for. Nothing in that history is deleted: expired
and replaced previews stay, and a row that cannot be read back in full can be read but is never
carried out.

### History and the audit trail

The **History** page, beside the overview, keeps every run set going from the overview as it
finished: who ran it, where, at what depth, and what each check answered. A run that covered every
check registered at that moment also keeps its health snapshot — the score, the weights it was worked
out with and the penalty each result cost — exactly as the overview showed it then; a partial run
keeps no score, as the overview shows none. Nothing is worked out again later from the checks as they
are now. It can be filtered by environment, site, dates and runs with a score, and put oldest first;
reading it needs "View Web Doctor", as reading the overview does. Runs are kept for 365 days
(`retainDays` on the `history` component). Each issue's own history — when it appeared, changed, was
moved, resolved or came back — is on its page, and has been since it was first found.

The **Repairs** page lists every repair kept — previewed, carried out, failed or replaced — newest
first, with its risk, how it ended, what verifying it established, who previewed it and who carried
it out, and when. It can be filtered by repair, result, verification, user, environment, site and
the dates it was previewed between, and put oldest first. Each repair's page is reachable from there on
its own, so a repair whose issue has since been deleted can still be read; it can no longer be
carried out or verified. Who previewed and carried out a repair, and the site it was for, are kept by
name beside their IDs, so the history still says who and where after an account or a site is deleted.
What a repair would change and the state before are kept when it is previewed, and what it did and
the state after when it ends; neither is read again later from the issue or the installation.

The **Audit log** page records what was done with Web Doctor:

| Action | Recorded when |
|---|---|
| Diagnostics started, completed | Somebody runs checks from the dashboard |
| Investigation started, completed | An issue is investigated, or a recipe run |
| Recommendations generated | A dashboard run or an investigation produced recommendations for its findings — the rule each finding was given |
| Repair previewed | A repair is previewed |
| Repair carried out | A repair ends, succeeded or failed — including one ended as stopped without an ending |
| Verification run | A repair is verified, with its answer: Succeeded for Verified, Failed for Verification failed, Inconclusive for Inconclusive |
| Issue resolved | A check run or a verified repair resolves an issue |
| Issue status changed | Somebody moves an issue, with the reason they gave; or an issue comes back, because a check reported it again or verifying a repair of it failed |

Each entry says who did it — the signed-in user, read from Craft, or that nobody was signed in — what
it was done to, with a link where that has a page, the environment and site, when, and how it ended:
Succeeded, Partly, Failed, Inconclusive, or No result for a beginning or a decision. It can be
filtered by action, result, user, environment, site and dates, and put oldest first; acts in the
same second keep the order they happened in. An issue's page links to the entries about it.

Who acted is never guessed. Nobody being signed in — the console, the queue — is recorded as that; an
identity Craft cannot give, or one without a usable ID and username, is an error, and a change that
cannot say who made it does not happen. Dates are days where the reader is, in Craft's time zone,
across clock changes; a time zone that cannot be resolved is an error rather than UTC.

A change Web Doctor makes — an issue moved or resolved, a repair previewed or ended, a verification
applied — is recorded in the same transaction as the change: if the entry cannot be written, the
change does not happen either. That work started or finished — a run, an investigation, the
recommendations they produced — is recorded on its own, and a trail that cannot be written is logged
rather than allowed to stop the work. Requests that were refused changed nothing, and are written to
Craft's log rather than the audit trail. Opening a page is never recorded.

An entry holds a short summary and a few named values — counts, IDs, statuses, a reason somebody
typed — never evidence, a preview or a payload: anything else handed to it is left out, and the entry
says so. Everything is redacted as the entry is made and again as it is read. Entries are kept for
365 days (`retainDays` on the `audit` component); older ones are removed as new ones are written.
Nothing else updates or deletes an entry, and an entry outlives the user, issue and site it names.
Retention removes at most 500 entries or runs at a time (`pruneBatch`), oldest first, and a
retention period or batch that is not one is refused before anything is removed.

Everything kept is read back strictly. A stored value that is not one Web Doctor writes — a status,
result, action or moment it does not know, details or a preview that are not what was written — is
shown as "Could not be read" and never as another value: an unknown result is not "No result", a
broken moment is not the epoch or now. A repair, verification or issue that cannot be read in full
is shown as far as it can be and can no longer be carried out, verified, investigated or changed.

### Errors

The **Errors** page, beside Issues, lists the exceptions Web Doctor's checks have run into: a check
that broke, or a check that caught the exception that stopped it answering. Each is shown with its
type, how many times it has been seen, when it was first and last seen, which checks ran into it,
and which issues it is related to. An issue's page shows the errors run into by the check behind
it, and an investigation's page shows the errors its checks ran into.

The same error happening again is counted rather than listed again. What makes two occurrences one
error is:

- **the exception's class** — an anonymous class is named by what it extends;
- **its message, with the values that vary between occurrences taken out** — record IDs, UUIDs,
  timestamps, IP and email addresses, memory addresses, hashes and random tokens, SQL `IN` lists,
  amounts in a unit, a URL's query string and the IDs in its path, and a deploy tool's release
  directory. Each is replaced by what kind of value it was, so `Element 4812 could not be saved`
  and `Element 77 could not be saved` are both `Element {id} could not be saved`;
- **where it was thrown**, as a file relative to the installation (or to `vendor/`) and a line;
- **the exceptions behind it**, normalised the same way;
- **the calls at the top of its trace**, where a trace was recorded, without files or lines;
- **the environment and site** it happened in.

Nothing is removed for merely being a number or a name: an error code, an HTTP status, a SQLSTATE,
a table, column, class or host name, or a path inside the installation is often the only thing
that tells two causes apart, and merging two different errors would be the worse mistake. For the same
reason the line stays part of it: an error that moves line because the code around it changed is
counted as a new error rather than risk merging it with a neighbour. Which check ran into an error
is not part of it, so a database refusing connections is one error however many checks hit it.

Each result that recorded an error is one occurrence. An error is recorded whatever the result's
status — a check that broke raises no issue, but a check that breaks the same way on every run is
exactly what grouping is for. An error is related to the issue that check's findings are recorded
on in that environment and site, if the check has raised one.

What an error said, where it was thrown and its trace are shown only to users with "View evidence".
Everybody who may read issues sees its type, counts, dates, checks and related issues. Messages are
redacted before they are grouped and again when they are stored. Each environment and site keeps at
most 500 errors (`maxGroups` on the `errors` component); the least recently seen go first, and a
deleted site's errors are kept apart from the installation-wide ones. A single run that meets more
distinct errors than that records the ones already kept first, then new ones in the order it met
them, up to the limit, and says how many more it did not record.

### What is stored

Issues, their history and their evidence live in three tables: `webdoctor_issues`,
`webdoctor_issue_events` and `webdoctor_evidence`. Investigations and their timelines live in
`webdoctor_investigations` and `webdoctor_investigation_steps`, and the causes each weighed in
`webdoctor_root_causes`. Repairs live in `webdoctor_repairs`, and their verifications in
`webdoctor_verifications`; both are kept when their issue is deleted. The audit trail lives in
`webdoctor_audit_log` and the diagnostic history in `webdoctor_diagnostic_runs`; both outlive the
users, issues and sites they name. Evidence and investigations are deleted with the issue they support, and
causes with their investigation. Errors live in `webdoctor_error_groups`, with the checks that
ran into each in `webdoctor_error_sources`; an error outlives the issues it relates to, and deleting
an issue only drops the link.

Deleting a site does not delete the issues found while looking at it, or their evidence. Most
findings are about the installation and merely stamped with whichever site was in view, so the
link to the site is dropped and the site's name is kept, leaving the finding readable rather than
erasing it.

History records the moments worth keeping: the issue appearing, changing, coming back, being moved
through its lifecycle, being observed clear. A run that finds an issue again unchanged is counted,
not listed, so the history stays readable however long the issue has been open.

## Redaction

Everything Web Doctor writes down — evidence, the wording of a result, what is stored, what is
logged — goes through one redaction helper. A credential is recognised three ways:

- **By its key** — `password`, `apiKey`, `DB_PASSWORD`, `clientSecret`, `accessToken`, `auth`, a
  DSN, a session ID, the user name half of a database or mail login, and environment-style names
  ending in `_KEY`, `_PASS` or `_AUTH` — including form and array keys such as `config[password]`.
  A key is judged word by word, so `keyword` and `author` are left alone.
- **By its shape** — private keys, AWS access key IDs, JSON web tokens, Stripe, GitHub, GitLab,
  Slack, Google and SendGrid keys, Slack and Discord webhook URLs, credentials in a URL, and
  `Authorization` headers — wherever they appear, including loose in an error message or inside
  a JSON body an error message quotes.
- **By its value** — the values of the environment's own credentials are looked for in any text Web
  Doctor records, so a password quoted by a driver with nothing beside it is still caught. Values
  shorter than eight characters, and values that are a single plain word, are not looked for this
  way, because replacing them would mangle ordinary text; they are still caught by key.

A secret is replaced outright, never masked into something that hints at it. Where the question
is only whether something is configured, the answer is `Present`, `Missing`, `Invalid` or
`Unknown`.

## How the score works

A run starts at 100. Every result that leaves a question open — a warning, a failure, a check
that errored, a check that could not tell — subtracts the weight of its severity: `info` 0,
`low` 2, `medium` 6, `high` 15, `critical` 30. Passes, informational results and skipped checks
subtract nothing, and the floor is 0.

## Current limitations

- **A score is shown only when the run covered every registered check.** A partial run shows its
  results and states how many checks it covered instead.
- **Results are the latest answer, not a history.** They are kept in Craft's cache, scoped to the
  environment and site. Clearing the cache means the dashboard reports that nothing has been run.
- **Runs are synchronous.** There is no scheduling and no queued execution yet, so a deep run on
  a large site holds the request open.
- **Only a control panel run records issues.** `webdoctor/status` reports the installation; it
  does not run checks, so nothing on the command line updates the Issue Center yet.
- **An issue's resolution is an observation unless a repair of it was verified.** Without a
  verified repair, Web Doctor can say the check stopped reporting the problem, not that the cause
  was addressed.
- **A check that finds several problems at once and does not distinguish them** — through the
  affected component or plugin — gets one issue covering all of them, with the current detail in
  its latest result.
- **Issue history is kept, but not pruned.** There is no retention policy yet, so an installation
  diagnosed on a schedule for a long time will accumulate rows.
- **Investigations run synchronously**, in the request that starts them, and are bounded by the
  number of checks they may run rather than queued.
- **Errors are the ones Web Doctor's checks run into.** Web Doctor does not capture the exceptions
  a site throws while serving requests or running queue jobs, and does not read Craft's logs, so an
  error the checks never touch is not grouped here. The error text a failed queue job recorded is
  reported by `queue.failedJobs` as evidence, not grouped as an error.
- **Investigations do not read logs.** Where logs would help, the plan says so.
- **Recommendations cover Web Doctor's own checks.** A check contributed by another plugin gets
  none. They are not stored: the audit trail records which rule each finding was given when a run
  or an investigation produced it, not the advice itself, and not what a page showed afterwards.
- **The audit trail records what Web Doctor does, not everything around it.** Refused requests are
  in Craft's log instead; there are no incidents yet to record actions on; and the log's user filter
  offers only accounts that still exist, so a deleted user's entries are found without it.
- **History is kept for runs from the overview.** Investigations, recipes and verifications run
  checks too; what they found is kept with them, not in the diagnostic history. There are no trends
  or comparisons between runs yet.
- **History has no per-site permission.** As with the Issue Center, anybody who may read a history
  reads every environment's and site's; the filters keep them apart, they do not hide them.
- **Two repairs.** Web Doctor can create missing storage directories and retry failed queue jobs.
  Repairs and their verifications run synchronously, in the request that asks for them, and other
  plugins cannot yet contribute repairs or verification actions.
- **Verification is asked for, not scheduled.** A retried job's verification stays inconclusive
  until somebody verifies again after the queue has run it; nothing verifies it later on its own.
- **"Appeared since the repair" is read to the second.** A problem or error first seen in the same
  second the repair started counts as new, so the verification is inconclusive rather than verified.
- **A retried job's run is known only where Craft's cache is shared.** A queue worker using a
  different cache from the control panel — a separate server with its own file cache — leaves no
  note the control panel can read, so those retries stay inconclusive. Notes last seven days.
- **A preview is confirmed in the language it was made in.** What a repair says about itself is part
  of what you confirm, so a preview made in one control panel language and confirmed in another is
  refused and has to be made again. Web Doctor ships in English only.
- **No upgrade path yet.** Web Doctor is unreleased: its schema is created by its install migration
  alone, and a schema change is applied by reinstalling, which drops what it stored.
- **`email.configuration` reads Craft's mail settings.** A mailer replaced wholesale in
  `config/app.php` is not what it inspects.
- **Checks establish what they say and no more.** `filesystem.volumes` proves a volume can be
  read, not written. `email.configuration` proves the settings are complete, not that mail is
  delivered. `database.charset` samples one table. `queue.failedJobs` reads the most recent
  failures only — none at shallow depth, ten at normal, fifty at deep — and says so when the
  sample is not the whole set.
- **If a check cannot read what it came to read, it reports `unknown`, never `pass`.**

## Development

The plugin is developed inside a Craft project as a Composer path repository.

```bash
composer install          # inside the plugin directory
composer test             # unit and security tests
composer test-integration # integration tests; needs the project's database
composer phpstan          # static analysis
composer check-cs         # coding standards
composer fix-cs           # coding standards, applied
```

Integration tests boot the surrounding Craft project, so they need its database in reach. Under
DDEV, run them in the container:

```bash
ddev exec -d /var/www/html/plugins/Web-Doctor composer test-integration
```

## License

See [LICENSE.md](LICENSE.md).
