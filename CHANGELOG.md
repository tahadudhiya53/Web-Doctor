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
- Added the “View Web Doctor”, “Run diagnostics”, “View issues”, “Manage issues”, “View evidence”, “Investigate issues” and “Run repairs” permissions.
- Added the `webdoctor/status` console command.
- Added plugin settings, which can be overridden from `config/web-doctor.php`.
- Added `Diagnostics::EVENT_REGISTER_DIAGNOSTICS` and `Recipes::EVENT_REGISTER_RECIPES`, so other plugins can register checks and recipes.

### Security
- Credentials are redacted everywhere Web Doctor records, stores or displays text.
- Evidence contents and error details are shown only to users with “View evidence”.
- Malformed request values (IDs, depths, pages, filters) are refused rather than coerced.
- A repair checks the signed-in user’s permissions itself, and Craft’s own permission for the same action, whatever page asked for it.
- A repair runs only if the repair and the installation’s state are both exactly what was previewed, and only for the exact acknowledgements shown.
