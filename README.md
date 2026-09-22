# Web Doctor

A diagnostic and maintenance platform for Craft CMS.

Web Doctor is being built to help developers and agencies find out what is wrong with a Craft
site, why it is happening, what evidence supports that conclusion, and what to do about it.

> **Early development.** This README documents only what the plugin currently does. Everything
> described below is implemented; nothing else is.

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later

## Installation

Install the package and then the plugin:

```bash
composer require tahadudhiya53/craft-web-doctor
php craft plugin/install web-doctor
```

## What it currently does

Web Doctor installs as a Craft plugin and establishes its own foundations. It does not yet run
any diagnostics.

**Control panel.** A Web Doctor section appears in the control panel for users who are allowed
to see it. It reports the plugin's own state.

**Settings.** Web Doctor's settings live at **Settings → Plugins → Web Doctor**. One setting is
available: the name the control panel calls Web Doctor. As with any Craft plugin, the settings
can be overridden from a `config/web-doctor.php` file:

```php
<?php

return [
    'pluginName' => 'Site Health',
];
```

Web Doctor's settings never hold credentials. Where it eventually needs to know about one, it
reports whether it is present, never what it is.

**Permissions.** One permission is registered, under a **Web Doctor** heading in a user group's
permissions:

| Permission | ID | Allows |
|---|---|---|
| View Web Doctor | `webDoctor:view` | Reaching Web Doctor in the control panel |

Admins pass, as they do elsewhere in Craft. Further permissions arrive with the features they
guard.

**Command line.** Web Doctor's commands are reached under `webdoctor`:

```bash
php craft webdoctor/status
```

`status` reports the plugin's version, its schema version, and whether its settings are valid.
It exits `0` when they are and non-zero when they are not, so an installation can be confirmed
from a deployment script.

## Database

Web Doctor owns no database tables yet.

## Development

The plugin is developed inside a Craft project, as a Composer path repository.

```bash
composer install          # inside the plugin directory
composer test             # unit tests
composer test-integration # integration tests, run from inside the Craft project
composer phpstan          # static analysis
composer check-cs         # coding standards
composer fix-cs           # coding standards, applied
```

Integration tests boot the surrounding Craft project, so they need its database to be
reachable. In a DDEV setup, run them inside the container. They also run on the Craft
project's own PHPUnit rather than the plugin's, because only one copy of PHPUnit can be
loaded into the process that boots Craft.

## License

See [LICENSE.md](LICENSE.md).
