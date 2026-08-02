# Keel

**Sane, individually-toggleable defaults for every new WordPress site.**

Keel flips a menu of sensible security, update, privacy, UX, and performance defaults
onto any WordPress install — each one a switch under **Settings → Keel**. Nothing is
hidden and nothing is all-or-nothing: you can see exactly what the plugin does to your
site, in one place, and turn any piece off.

> **Status: early development (`0.1.0-dev`).** The base is imported and the identity is
> in place; help-text de-branding, the ported hardening/admin defaults, a rebuilt Site
> Health surface, and multisite-aware seeding are in progress. See the project scope
> for the full plan.

## How it's built

One array — `keel_defaults_schema()` — is the single source of truth. It drives both
the settings screen and the bootstrap that wires each *enabled* default to its
WordPress hook. Adding a default is one array entry plus one `if`-block in bootstrap;
no new settings-page code. A default is an opinionated filter behind a toggle.

## Install

Copy the plugin folder into `wp-content/plugins/` and activate, or install the built
zip. On activation the documented defaults are seeded; then visit **Settings → Keel**.

## Licence & credits

[GPL-3.0-or-later](LICENSE). Keel is a de-branded evolution of
[Better by Default](https://github.com/WPYEG/Better-by-Default) (WPYEG), with further
defaults adapted from the Pixel Managed Platform plugin. See [CREDITS.md](CREDITS.md).
