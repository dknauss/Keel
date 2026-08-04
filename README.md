# Keel

**Sane, individually-toggleable defaults for every new WordPress site.**

Keel flips a menu of sensible security, update, privacy, UX, and performance defaults
onto any WordPress install — each one a switch under **Settings → Keel**. Nothing is
hidden and nothing is all-or-nothing: you can see exactly what the plugin does to your
site, in one place, and turn any piece off.

> **Status: pre-release (`0.1.0-dev`).** Feature-complete for review as of
> 2026-08-04 — 37 defaults, the Site Health surface, and multisite-aware seeding are
> all in. What is left before a wordpress.org submission is packaging and
> verification, not features. See [ROADMAP.md](ROADMAP.md) for the milestones and
> [TODO.md](TODO.md) for what's in flight.

## What makes it different

Most "disable it" plugins close the front door and leave a side one open. Measured
against nine of the most-installed ones on wordpress.org — every cell a live HTTP or
PHP probe, not a readme claim — Keel is the only plugin in the field where *comments
are off* is true below the presentation layer:

| `get_comments()` with comments disabled | Disable Comments | …RB | Simply DC | ASE | **Keel** |
| --- | --- | --- | --- | --- | --- |
| approved comments returned | 1 | 1 | 1 | 1 | **0** |

The same pattern runs through the rest: closing the REST API also means removing the
discovery link that advertises it, and disabling comments also means the comment feed
stops answering. The full comparison, and the cases where Keel makes a deliberate
trade instead, is in
[docs/competitive-teardown-matrix.md](docs/competitive-teardown-matrix.md).

Keel keeps oEmbed reachable when the REST gate is closed — alone among the four
plugins measured that close REST outright — so other sites can still embed your posts
instead of silently degrading them to bare links.

## How it's built

One array — `keel_defaults_schema()` — is the single source of truth. It drives both
the settings screen and the bootstrap that wires each *enabled* default to its
WordPress hook. Adding a default is one array entry plus one `if`-block in bootstrap;
no new settings-page code. A default is an opinionated filter behind a toggle.

Two things it does that a settings screen usually does not:

- **Site Health reports the posture**, read-only — every default and its current
  state, so the site's actual configuration is legible without clicking through
  tabs.
- **It notices when another plugin is setting the same defaults.** Two plugins can
  both set a session length; WordPress keeps whichever ran last and the loser's
  settings screen goes on displaying a value the site does not use, with no error
  anywhere. Keel reports the collision and names what is contesting what — it does
  not tell you which plugin to keep, because a plugin answering that is arguing for
  its own retention. Keel also stays off a hook entirely when its setting would only
  repeat what WordPress already does.

## Install

Copy the plugin folder into `wp-content/plugins/` and activate, or install the built
zip. On activation the documented defaults are seeded; then visit **Settings → Keel**.

## Licence and credits

[GPL-2.0-or-later](LICENSE) — the same terms as WordPress itself. Keel is a de-branded
evolution of [Better by Default](https://github.com/WPYEG/Better-by-Default) (WPYEG),
whose sole author also licenses the portions carried over here under GPL-2.0-or-later,
with further defaults adapted from the Pixel Managed Platform plugin — itself a hard
fork of [10up Experience](https://github.com/10up/10up-experience) by 10up
(GPL-2.0-or-later), from which several of those defaults ultimately descend. 10up
retains its copyright and marks; Keel is not affiliated with or endorsed by 10up. Full
attribution is in the Credits section of `readme.txt`.
