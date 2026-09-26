# Release Notes for Web Doctor

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). The major version
matches the Craft major version the plugin supports, so the first release is 5.0.0 for Craft 5.

## Unreleased

### Added

- A health dashboard in the control panel showing what the last run concluded: the health score
  with the weights and per-check penalties behind it, counts by severity and status, and every
  check's status, severity, summary, evidence summary, timestamp and duration, grouped by
  category. Opening it runs nothing; checks run when a user asks for them, all of them or a
  selection, at a chosen depth. A score is shown only when the run covered every registered check.
  Results from checks that no longer exist stay visible in a section of their own and count toward
  nothing.
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
  many times, the evidence behind it, and a history of what has happened to it. The list filters
  by status, severity, check, site, environment and date, sorts by any of those, and pages.
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
- Redaction applied wherever Web Doctor writes anything down. Credentials are recognised by the
  key they sit under — including the user name half of a database or mail login, session IDs,
  form and array keys, and environment-style names ending in `_KEY`, `_PASS` or `_AUTH` — by their
  shape — private keys, AWS access key IDs, JSON web tokens, Stripe, GitHub, GitLab, Slack, Google
  and SendGrid keys, Slack and Discord webhook URLs, credentials in a URL and authorization
  headers — and by their value, looking for the environment's own credentials in any text Web
  Doctor records.
- Investigations: from an issue's page, run the check that raised it again alongside the checks
  related to it, chosen deterministically from a published list of relationships between kinds of
  problem, each with its reason, at a chosen depth and bounded to 25 checks. Each investigation
  records what every check reported, the evidence it left (bounded, redacted, and shown only to
  users with “View evidence”), other issues open nearby in the same environment and site, and a
  timeline, and is kept against the issue. Findings update the Issue Center like any other run.
  Areas no installed check covers, and leads worth following by hand, are listed rather than
  omitted.
- Error grouping: every exception a diagnostic run or an investigation runs into is recorded
  against a group identified by its class, its message with the values that vary between
  occurrences taken out, where it was thrown, the exceptions behind it and, where a trace was
  recorded, the calls at the top of it — so the same error seen again is counted rather than
  listed. Each group keeps its occurrence count, when it was first and last seen, the checks that
  ran into it and the issues it relates to, bounded to 500 per environment and site.
- An Errors page in the control panel, and an errors section on each issue's page and each
  investigation's page. What an error said, where it was thrown and its trace are shown only to
  users with “View evidence”.
- Root-cause analysis: each investigation weighs what its checks found — their results, the
  evidence they recorded, the errors they ran into, failed queue jobs, configuration and
  environment state, and when related issues were first seen — against a fixed, published list of
  nine known causes. Each cause that fits is kept with the problem it would explain, the evidence
  for it, the reasoning, its confidence (possible, likely, high or confirmed), what counts against
  it, what was looked for and not found, related issues, a recommended action and what to
  investigate next. A cause is confirmed only on evidence recorded by a check that established it,
  with nothing against it.
- “View Web Doctor”, “Run diagnostics”, “View issues”, “Manage issues”, “View evidence” and
  “Investigate issues” permissions, each nested under the one it depends on and checked separately.
- Plugin settings, overridable from a `config/web-doctor.php` file.
- A `php craft webdoctor/status` command reporting the plugin's version, schema version and
  settings validity, exiting non-zero when the settings are invalid.
