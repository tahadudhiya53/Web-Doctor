# Web Doctor

A diagnostic plugin for Craft CMS. It runs a set of read-only checks over an installation — Craft,
PHP, the database, plugins, the queue, storage, mail and configuration — and reports what each one
found, with the evidence behind it, on a dashboard in the control panel.

> **Early development.** This README documents only what the plugin does today.

## Features

- **Health dashboard** in the control panel, grouped by category, showing each check's status,
  severity, summary, evidence summary, timestamp and duration.
- **A health score** with the weights and per-check penalties that produced it shown beside it.
- **Manual runs** — every check or a selection of them, at a chosen depth. Opening the dashboard
  runs nothing.
- **Diagnostic depth** (shallow / normal / deep) so a run can be bounded.
- **Two permissions**, so being allowed to read results is separate from being allowed to run.
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

## Installation

```bash
composer require tahadudhiya53/craft-web-doctor
php craft plugin/install web-doctor
```

## Usage

1. Open **Web Doctor** in the control panel. It shows what the last run concluded — opening it
   does not run anything.
2. Choose a depth: shallow skips the expensive work, normal is the default, deep does the most.
3. Press **Run all checks**, or tick individual checks and press **Run selected checks**.
4. Read the results. The health score sits at the top, with **How this score was calculated**
   beneath it listing the weights and what each check subtracted.

Checks run in the request the form submits, so a run takes as long as the checks take.

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

Running is nested under viewing and checked separately, because reading what a previous run
concluded costs nothing while starting a run spends the site's time on demand. Admins pass, as
they do elsewhere in Craft.

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
- **Checks establish what they say and no more.** `filesystem.volumes` proves a volume can be
  read, not written. `email.configuration` proves the settings are complete, not that mail is
  delivered. `database.charset` samples one table. `queue.failedJobs` reads the most recent
  failures only — none at shallow depth, ten at normal, fifty at deep — and says so when the
  sample is not the whole set.
- **If a check cannot read what it came to read, it reports `unknown`, never `pass`.**
- **Web Doctor owns no database tables.**

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
