# Release Notes for Web Doctor

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- Web Doctor installs as a Craft CMS 5 plugin, with a health dashboard in the control panel.
- A health dashboard showing what the last run concluded: the health score with the weights and
  per-check penalties behind it, counts by severity and status, and every check's status,
  severity, summary, evidence summary, timestamp and duration, grouped by category. Opening it
  runs nothing; checks run when a user asks for them, all of them or a selection, at a chosen
  depth. A score is shown only when the run covered every registered check. Results from checks
  that no longer exist stay visible in a section of their own and count toward nothing. Evidence
  is summarised, never displayed.
- Eighteen built-in checks covering Craft, PHP, the database, plugins, the queue, project config,
  asset filesystems, storage directories, the mailer and the environment. They read only: nothing
  is sent, written or created, no log file is searched, and no credential is ever recorded as a
  value. Each result states what it actually verified rather than implying more.
- Diagnostic depth (shallow, normal, deep), so a run can bound the work it does.
- A diagnostic core other plugins can contribute to: the contract a check is written against, a
  registry with a permanent-ID policy, and an engine that isolates a check that fails so the rest
  of the run still reports.
- “View Web Doctor” and “Run diagnostics” permissions, the second nested under the first and
  checked separately.
- Plugin settings, overridable from a `config/web-doctor.php` file.
- A `php craft webdoctor/status` command reporting the plugin's version, schema version and
  settings validity, exiting non-zero when the settings are invalid.

### Fixed

- `queue.failedJobs` counted and sampled failures from every queue channel rather than only the
  one it was inspecting, so an installation running more than one queue component saw another
  queue's failures reported as this one's.
