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
- An Issue Center: warnings and failures become issues that persist across runs, identified by a
  stable fingerprint so the same problem found again updates one record rather than raising
  another. Each carries its severity, where it came from, when it was first and last seen, how
  many times, the evidence behind it, and a history of what has happened to it. The
  list filters by status, severity, check, site, environment and date, sorts by any of those, and
  pages.
- Issue statuses, with resolution established rather than asserted: an issue resolves only when
  the check that raised it runs again and no longer reports the problem, recorded against the run
  that established it. Nobody can mark an issue resolved by hand. Somebody who has decided an
  issue needs no action says so with “Ignored” or “Won't fix”, and gives a reason.
- Evidence kept behind every issue: each fact a check recorded, with its type, source, value, when
  it was true, where it can be found again and how it was gathered, attributed by the engine to
  the check, run, environment and site that produced it. Stored once per distinct fact — the same
  evidence found again is counted, and a new reading keeps the old one as earlier evidence —
  bounded to 16 KiB per fact and 100 facts per issue, and deleted with its issue.
- An evidence inspector on each issue's page, showing the evidence behind the latest finding and
  a paged history of earlier evidence. Withheld values are marked “Redacted”, values cut short
  are marked as such, and each piece of evidence says how many of its values were withheld.
- New evidence types for the state of the system, the database, the queue, a plugin and a
  deployment, alongside the existing, more particular ones. The shipped checks use them where
  they describe the evidence more accurately.
- “View Web Doctor”, “Run diagnostics”, “View issues”, “Manage issues” and “View evidence”
  permissions, each nested under the one it depends on and checked separately.
- Plugin settings, overridable from a `config/web-doctor.php` file.
- A `php craft webdoctor/status` command reporting the plugin's version, schema version and
  settings validity, exiting non-zero when the settings are invalid.

### Fixed

- `queue.failedJobs` counted and sampled failures from every queue channel rather than only the
  one it was inspecting, so an installation running more than one queue component saw another
  queue's failures reported as this one's.
- An issue's identity included the status of the result that raised it, so a check that warned
  and then failed about the same thing closed one issue and opened another, splitting one
  problem's history in two.
- Two requests reconciling the same finding at once could both try to create the issue, and the
  one that lost turned a database constraint into an unhandled error.
- An issue's status could change without the history entry recording it, leaving a decision
  nobody could account for.
- Deleting a site deleted every issue found while looking at it, including findings about the
  installation that merely happened to be stamped with that site.
- The issue counts shown beside the list were counted over every environment, so they disagreed
  with the list they labelled.
- The issue list and dashboard pushed the control panel sideways on a narrow screen, and the
  bracketed redaction marker was shown as text in issue titles and on the dashboard rather than
  marked as a withheld value.
- A page number past the end of the results reported a range no row occupied.
