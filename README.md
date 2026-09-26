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
- **Possible causes, with the evidence for and against them** — each investigation weighs what it
  found against a fixed list of known causes, and says for each one that fits how firmly it is
  held, why, and what counts against it.
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
- **Six permissions**, so reading results, running checks, reading issues, changing them,
  reading what their evidence contains and investigating them are each granted separately.
- **Environment- and site-scoped results**, so one environment's answers are never shown as
  another's.
- **`php craft webdoctor/status`** for confirming an installation from a script.
- **An extension point** other plugins can register their own checks through.

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
| Investigate issues | `webDoctor:investigateIssues` | Starting an investigation of an issue |

A non-admin also needs Craft's own **Access Web Doctor** permission (under "Access the control
panel") to reach the section at all.

Each is nested under the one it depends on and checked separately: running checks and starting
investigations spend the site's time, changing an issue records a decision, and evidence carries
internals — file paths, stack traces, database errors — that somebody following an issue may not
need. Without "View evidence", a reader still sees what kind of evidence an issue rests on.
Reading a finished investigation needs only "View issues". Admins pass, as they do elsewhere in
Craft.

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
| Repairing | Nothing yet | Reserved for repairs, which do not exist |

**Nobody can mark an issue resolved.** The control panel offers no such control and the service
refuses the request if one is made. An issue resolves when a later run of the same check reaches a
conclusion that is not the problem, and the run that established that is recorded against it. The
detail page says in as many words that this is an observation and not a verification: nothing has
confirmed that the underlying cause was addressed.

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
`webdoctor_root_causes`. Evidence and investigations are deleted with the issue they support, and
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
- **An issue's resolution is an observation, not a verification.** Web Doctor can say the check
  stopped reporting the problem. It cannot yet say the cause was addressed.
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
