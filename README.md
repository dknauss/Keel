# Keel

![Keel banner](.wordpress-org/banner-1544x500.png)

[![WordPress.org](https://img.shields.io/wordpress/plugin/v/keel-defaults?label=WordPress.org&logo=wordpress&logoColor=white&color=21759b)](https://wordpress.org/plugins/keel-defaults/) [![CI](https://github.com/dknauss/Keel/actions/workflows/ci.yml/badge.svg)](https://github.com/dknauss/Keel/actions/workflows/ci.yml) [![Latest Tag](https://img.shields.io/github/v/tag/dknauss/Keel?include_prereleases)](https://github.com/dknauss/Keel/tags) [![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

**A clear, maintainable baseline for secure WordPress sites.** Keel provides 39 independent defaults for security, updates, privacy, content, email, media, and wp-admin. It is designed for people responsible for many sites, or for sites where predictable operations matter.

**Current release: `0.6.5`.**

## Try it first

No install or hosting required: WordPress Playground opens a disposable WordPress site with Keel enabled and a sample post ready to inspect.

- **[Try the current stable release](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/Keel/main/playground/blueprint-stable.json)** — the published plugin package.
- **[Try the current `main` build](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/Keel/main/playground/blueprint-hosted.json)** — the rolling build for testing changes before a release.

The stable demo follows each release; the rolling demo is rebuilt after successful pushes to `main`. See [the Playground blueprint notes](playground/README.md) for their contracts and a quick availability check.

## What it does

Keel exposes each choice under **Settings → Site Defaults**, explains it in place, and reports the effective posture in **Tools → Site Health**. It does not edit or delete existing content (new uploads do get lowercase filenames), and each default can be switched off independently.

- Reduce accidental exposure: anonymous user discovery, author archives, comments, pingbacks, attachment pages, raw HTML, security headers, AI connectors, and more.
- Make routine maintenance safer: conservative core update settings, visible update blockers, email-delivery checks, revision retention, and upload filename normalization.
- Protect non-production copies: outgoing mail is suppressed outside production by default, preventing a staging or local copy from sending to real recipients.
- Make operations legible: Site Health lists the active state of every default and flags attributable policy overlaps with other plugins.
- Support multisite deliberately: a Super Admin can apply a network policy without overwriting each site's stored choice; sites see enforced settings as locked.

### See all available WordPress core updates

Keel independently checks WordPress.org's stable-check status for the installed core version. If a release line has a known vulnerability and a same-line fix exists, Keel names that exact patch, explains what WordPress's Updates screen is offering, and lets an authorized administrator deliberately install only that patch through WordPress's upgrader with rollback enabled.

This is intentionally distinct from asking whether a newer major release exists. A secure same-line patch may be hidden as an automatic-update offer, absent from the Updates screen, or unavailable because WordPress.org has not published one. Keel reports the uncertainty or blocker rather than implying that an update is available when it is not. Details: [core update and stable-check behavior](docs/wordpress-default-settings.md#5-update-policy).

## Screens

<img src=".wordpress-org/screenshot-1.png" alt="Site Health identifies a vulnerable WordPress version, the same-line fix, and the releases WordPress offers" width="900">

<img src=".wordpress-org/screenshot-4.png" alt="Settings to control each Keel default" width="900">

<img src=".wordpress-org/screenshot-2.png" alt="Password policy help" width="900">

<img src=".wordpress-org/screenshot-3.png" alt="Site Health information listing Keel's active configuration" width="900">

## Technical guide

### Design

`keel_defaults_schema()` is the configuration source of truth. It drives the settings UI, validation, Site Health, and the bootstrap hooks that apply enabled defaults. Settings are per-site; network policy and selected `wp-config.php` constants are evaluated as higher-priority effective values, without overwriting the stored site configuration.

Keel avoids cosmetic-only controls. For example, disabling comments also closes query and feed surfaces, while a closed REST API stops advertising itself but retains oEmbed so other sites can still embed posts. The [per-setting reference](docs/wordpress-default-settings.md) documents the behavior, scope, tradeoffs, constants, and filters for every setting.

### Integration and operations

- **Requirements:** WordPress 6.4+, PHP 7.4+.
- **External services:** optional password breach screening uses HIBP's k-anonymous range API; the core security-status check uses WordPress.org's stable-check API. See the [WordPress.org listing](https://wordpress.org/plugins/keel-defaults/) for full disclosures.
- **Configuration:** prefer the settings screen. Deployment-level constants and documented filters are available when code ownership is appropriate; see [the reference](docs/wordpress-default-settings.md).
- **Multisite:** network policy is at **Network Admin → Settings → Network Policy**. It is read-time policy, so removing it restores each site's previous setting.
- **Compatibility:** Keel identifies attributable shared policy hooks without executing third-party callbacks. Treat a reported overlap as a prompt to compare configuration, not as proof that another plugin must be removed.

### Development and verification

The test suite is standalone PHP scripts in [`tests/`](tests/), run with `php tests/<name>.php`. On every push and pull request, CI runs syntax, coding-standards, and PHP-compatibility checks, plus the unit tests on each PHP version from 7.4 through 8.5; pushes to `main` also build the plugin zip for the rolling Playground demo. A separate scheduled matrix exercises live WordPress installer and backport paths. The release package is built with `bash bin/build-zip.sh build`.

Useful references:

- [Contributing](CONTRIBUTING.md)
- [Default behavior and technical reference](docs/wordpress-default-settings.md)
- [Environment detection](docs/environment-detection.md)
- [Competitive behavioral probes](docs/competitive-teardown-matrix.md)
- [Security policy](SECURITY.md)
- [Release roadmap](ROADMAP.md)

## Install

Install from the [WordPress.org directory](https://wordpress.org/plugins/keel-defaults/), or place the plugin folder in `wp-content/plugins/` and activate it. Then visit **Settings → Site Defaults**.

## License and credits

[GPL-2.0-or-later](LICENSE). Keel is a de-branded evolution of [Better by Default](https://github.com/WPYEG/Better-by-Default), with additional defaults adapted from the Pixel Managed Platform plugin, itself a fork of [10up Experience](https://github.com/10up/10up-experience). See the WordPress.org readme for complete attribution.
