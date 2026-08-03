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

[GPL-2.0-or-later](LICENSE) — the same terms as WordPress itself. Keel is a de-branded
evolution of [Better by Default](https://github.com/WPYEG/Better-by-Default) (WPYEG),
whose sole author also licenses the portions carried over here under GPL-2.0-or-later,
with further defaults adapted from the Pixel Managed Platform plugin — itself a hard
fork of [10up Experience](https://github.com/10up/10up-experience) by 10up
(GPL-2.0-or-later), from which several of those defaults ultimately descend. 10up
retains its copyright and marks; Keel is not affiliated with or endorsed by 10up. Full
attribution is in the Credits section of `readme.txt`.
