# Keel

![Keel banner](.wordpress-org/banner-1544x500.png)

[![WordPress.org](https://img.shields.io/wordpress/plugin/v/keel-defaults?label=WordPress.org&logo=wordpress&logoColor=white&color=21759b)](https://wordpress.org/plugins/keel-defaults/) [![Requires WordPress](https://img.shields.io/wordpress/plugin/wp-version/keel-defaults?label=requires%20WordPress)](https://wordpress.org/plugins/keel-defaults/) [![Tested up to](https://img.shields.io/wordpress/plugin/tested/keel-defaults?label=tested%20up%20to)](https://wordpress.org/plugins/keel-defaults/) [![Requires PHP](https://img.shields.io/wordpress/plugin/required-php/keel-defaults?label=requires%20PHP)](https://wordpress.org/plugins/keel-defaults/) [![CI](https://github.com/dknauss/Keel/actions/workflows/ci.yml/badge.svg)](https://github.com/dknauss/Keel/actions/workflows/ci.yml) [![Live WordPress matrix](https://github.com/dknauss/Keel/actions/workflows/backport-install-matrix.yml/badge.svg)](https://github.com/dknauss/Keel/actions/workflows/backport-install-matrix.yml) [![Latest Tag](https://img.shields.io/github/v/tag/dknauss/Keel?include_prereleases)](https://github.com/dknauss/Keel/tags) [![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

**A clear, maintainable baseline for secure WordPress sites.** Keel provides 42 individually configurable settings for security, updates, privacy, content, email, media, and wp-admin. Some depend on other settings, and configuration or network policy can manage them centrally. It is designed for people responsible for many sites, or for sites where predictable operations matter. Keel is Multisite compatible.

**Current version: `0.8.0`.**

## Try it first

No install or hosting required: WordPress Playground opens a disposable WordPress site with Keel enabled and a sample post ready to inspect.

- **[Try the current stable release](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/Keel/main/playground/blueprint-stable.json)** — the published plugin package.
- **[Try the current `main` build](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/Keel/main/playground/blueprint-hosted.json)** — the rolling build for testing changes before a release.

The stable demo follows each release; the rolling demo is rebuilt after successful pushes to `main`. See [the Playground blueprint notes](playground/README.md) for their contracts and a quick availability check.

## What it does

Keel explains each setting under **Settings → Site Defaults**, with a separate Network Admin policy screen for multisite. Keel reports its current effective posture in **Tools → Site Health**. It does not edit or delete existing content (new uploads do get lowercase filenames), and settings can be adjusted unless configuration or network policy manages them. Some controls appear only when their related feature is enabled.

Keel's settings are intended to:

- **Reduce accidental exposure:** anonymous user discovery, author archives, comments, pingbacks, attachment pages, raw HTML, security headers, AI connectors, and more.
- **Make routine maintenance safer:** conservative core update settings, visible update blockers, email-delivery checks, revision retention, and upload filename normalization.
- **Protect non-production sites:** outgoing mail is suppressed outside production by default, preventing a staging or local copy from sending to real recipients.
- **Make operations legible:** Site Health lists the active state of every default and flags attributable policy overlaps with other plugins.
- **Run a network without flattening every site**: a Super Admin can decide selected defaults for the whole network, while each site keeps its own choices underneath. Enforced settings are visibly locked, and lifting the policy restores every saved local choice.

### See all available WordPress core updates

Keel independently checks WordPress.org's `stable-check` status for the installed core version. If a release line has a known vulnerability and a same-line fix exists, Keel names that exact patch, explains what WordPress's Updates screen is offering, and lets an authorized administrator deliberately install only that same-line patch through WordPress's upgrader with rollback enabled.

This is intentionally distinct from asking whether a newer major release exists. A secure same-line patch may be hidden as an automatic-update offer, absent from the Updates screen, or unavailable because WordPress.org has not published one. Keel reports the uncertainty or blocker rather than implying that an update is available when it is not. Details: [core update and stable-check behavior](docs/wordpress-default-settings.md#5-update-policy).

### Find autoload and cron pressure

Site Health reports the ten largest observed autoloaded options, their byte sizes,
and probable owners with confidence and evidence. It collects names and sizes in
cached background batches, never values. Partial scans are labelled explicitly;
size alone does not prove waste. The report never deletes options or changes their
autoload flags. See the [limits and verification](docs/review/autoload-attribution-2026-10-21.md).

It also adds cached observations of overdue and frequent stored cron events,
probable owners with explicit confidence, core offer-cache age and retained
core-update failure codes. Event arguments and error data never enter the report.
External scheduling, missing timestamps and incomplete coverage remain explicit;
no jobs, schedules or update policy are changed and no installation time is promised.
See the [limits and verification](docs/review/cron-attribution-2026-10-21.md).

## Screens

<img src=".wordpress-org/screenshot-1.png" alt="Site Health identifies a vulnerable WordPress version, the same-line fix, and the releases WordPress offers" width="900">

<img src=".wordpress-org/screenshot-4.png" alt="Settings to control each Keel default" width="900">

<img src=".wordpress-org/screenshot-5.png" alt="Network Admin policy, where a Super Admin can enforce selected Keel defaults across a multisite network" width="900">

<img src=".wordpress-org/screenshot-2.png" alt="Password policy help" width="900">

<img src=".wordpress-org/screenshot-3.png" alt="Site Health information listing Keel's active configuration" width="900">

## Technical guide

### Design

`keel_defaults_schema()` is the configuration source of truth. It drives the settings UI, validation, Site Health, and the bootstrap hooks that apply enabled defaults. Settings are per-site; network policy and selected `wp-config.php` constants are evaluated as higher-priority effective values, without overwriting the stored site configuration.

Keel avoids cosmetic-only controls. For example, disabling comments also closes query and feed surfaces, while a closed REST API stops advertising itself but retains oEmbed so other sites can still embed posts. The [per-setting reference](docs/wordpress-default-settings.md) documents the behavior, scope, tradeoffs, constants, and filters for every setting.

Broken-shortcode cleanup is conservative and paired with recent Site Health evidence. It preserves enclosed text, editable-post visibility and
literal examples; see [scope and limits](docs/wordpress-default-settings.md#broken-shortcodes).

### Integration and operations

- **Requirements:** WordPress 6.4+, PHP 7.4+.
- **External services:** optional password breach screening uses HIBP's k-anonymous range API; the core security-status check uses WordPress.org's stable-check API. See the [WordPress.org listing](https://wordpress.org/plugins/keel-defaults/) for full disclosures.
- **Configuration:** prefer the settings screen. Deployment-level constants and documented filters are available when code ownership is appropriate; see [the reference](docs/wordpress-default-settings.md).
- **Multisite:** a Super Admin uses **Network Admin → Settings → Network Policy** to decide individual settings for every site. Policy is read-time and visibly locked on sites; it never rewrites stored site settings, so removing it restores each site's previous choice.
- **Compatibility:** Keel identifies attributable shared policy hooks without executing third-party callbacks. Treat a reported overlap as a prompt to compare configuration, not as proof that another plugin must be removed.

### Development and verification

The test suite is standalone PHP scripts in [`tests/`](tests/), run with `php tests/<name>.php`. On every push and pull request, CI runs syntax, coding-standards, and PHP-compatibility checks, the scoped PHPStan pilot and its contract examples, plus the unit tests on each PHP version from 7.4 through 8.5; pushes to `main` also build the plugin zip for the rolling Playground demo. A separate scheduled matrix exercises live WordPress installer and backport paths. The release package is built with `bash bin/build-zip.sh build`.

Useful references:

- [Contributing](CONTRIBUTING.md)
- [Default behavior and technical reference](docs/wordpress-default-settings.md)
- [Environment detection](docs/environment-detection.md)
- [AI integrations](docs/ai-integrations.md)
- [Competitive behavioral probes](docs/competitive-teardown-matrix.md)
- [Security policy](SECURITY.md)
- [Release roadmap](ROADMAP.md)

## Install

Install from the [WordPress.org directory](https://wordpress.org/plugins/keel-defaults/), or place the plugin folder in `wp-content/plugins/` and activate it. Then visit **Settings → Site Defaults**.

## License and credits

[GPL-2.0-or-later](LICENSE). Keel is a de-branded evolution of [Better by Default](https://github.com/WPYEG/Better-by-Default), with additional defaults adapted from the Agency Experience plugin, itself a fork of [10up Experience](https://github.com/10up/10up-experience). See the WordPress.org readme for complete attribution.
