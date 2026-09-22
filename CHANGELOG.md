# Release Notes for Web Doctor

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Added

- Web Doctor installs as a Craft CMS 5 plugin, with a control panel section reporting its own
  state.
- Plugin settings, overridable from a `config/web-doctor.php` file.
- A “View Web Doctor” permission controlling access to the control panel section.
- A `php craft webdoctor/status` command reporting the plugin's version, schema version and
  settings validity, exiting non-zero when the settings are invalid.
