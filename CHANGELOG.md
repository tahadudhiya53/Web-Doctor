# Release Notes for Web Doctor

## Unreleased

### Added
- Added a health dashboard with a transparent health score, severity and status counts, and results grouped by category.
- Added 18 read-only checks covering Craft, PHP, the database, plugins, the queue, project config, filesystems, storage, email and the environment.
- Added shallow, normal and deep diagnostic depths.
- Added the Issue Center, which tracks warnings and failures across runs with filtering, sorting and paging.
- Added issue statuses. Issues resolve only when their check stops reporting the problem; “Ignored” and “Won’t fix” need a reason.
- Added evidence storage and an evidence inspector on each issue’s page.
- Added investigations, which re-run an issue’s check alongside related checks (up to 25) and keep a timeline.
- Added error grouping and an Errors page.
- Added root-cause analysis against nine known causes, with confidence shown as Possible, Likely, High or Confirmed.
- Added five recipes: 500 Error Doctor, Email Doctor, Queue Doctor, Database Doctor and Deployment Doctor.
- Added recommendations for every warning and failure a built-in check reports, each with its risk and how to verify it.
- Added repairs for missing storage directories and failed queue jobs. Each is previewed, confirmed and checked again before it runs, uses Craft’s own API, and leaves the issue awaiting verification rather than resolved.
- Added repair verification. A repair is verified as soon as it is carried out, and again on request: the check that found the problem and the checks around it run again, what the repair did is checked still to hold, the evidence before and after is compared, and new problems and errors are looked for. The result is Verified, Verification failed or Inconclusive, with the reasons; only a verified repair resolves the issue, as “Repair verified”.
- Added a diagnostic history keeping every run from the overview as it finished, with a health snapshot — the score, its weights and each penalty — for every run that covered every check.
- Added a repair history listing every repair previewed or carried out, filterable by repair, result, verification, user, environment, site and date. A repair's page stays readable after its issue is deleted.
- Added an audit log recording who ran checks, investigated, generated recommendations, previewed, carried out and verified repairs, and moved, resolved or reopened issues, filterable by action, result, user, environment, site and date. Entries are kept for 365 days.
- Added the “View Web Doctor”, “Run diagnostics”, “View issues”, “Manage issues”, “View evidence”, “Investigate issues”, “Run repairs” and “View audit trail” permissions.
- Added the `webdoctor/status` console command.
- Added plugin settings, which can be overridden from `config/web-doctor.php`.
- Added `Diagnostics::EVENT_REGISTER_DIAGNOSTICS` and `Recipes::EVENT_REGISTER_RECIPES`, so other plugins can register checks and recipes.

### Security
- Credentials are redacted everywhere Web Doctor records, stores or displays text.
- Evidence contents and error details are shown only to users with “View evidence”, including what a check threw when it failed to run and what each failed queue job is called.
- Malformed request values (IDs, depths, pages, filters) are refused rather than coerced.
- A repair checks the signed-in user’s permissions itself, and Craft’s own permission for the same action, whatever page asked for it.
- A repair runs only if the repair and the installation’s state are both exactly what was previewed, and only for the exact acknowledgements shown.
- An audit entry is written in the same transaction as the change it records, names the signed-in user as Craft reports it, and holds only short named values, redacted — never evidence or other payloads.
- Stored history is read back strictly: a value that cannot be read is shown as unreadable, never as another value, the epoch or the current time, and a record that cannot be read in full can no longer be acted on.
- A retried queue job is treated as having run only when Craft’s queue reported that it ran without an error; a job that merely left the queue is not.
- Verifying a repair checks the signed-in user’s permission itself, and one issue is verified at a time.
