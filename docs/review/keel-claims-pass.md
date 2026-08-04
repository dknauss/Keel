# Keel — review pass 1: claim versus code

> **Status: partial.** Covers claims reachable from user-facing copy — README,
> readme.txt, help text, and the architecture description. The ~132 docblocks in
> `security.php`, `admin-ux.php`, `content.php` and `settings-page.php` are the
> bulk of the surface and are **not** covered. Written down mid-pass rather than
> held in a conversation, because everything this week that was not written down
> had to be rediscovered.

Baseline `85f9fab`, suite and lint green. Method: every falsifiable claim in a
docblock, help string, readme line or roadmap entry checked against the code
that implements it. Reports only; no fixes.

## Verified

| claim | source | result |
|---|---|---|
| "More than 30 sane defaults" | `keel.php`, `readme.txt` | 37 — accurate |
| Every default is a switch on the settings screen | `README.md` | all 5 schema types render |
| Every schema key has display copy | — | 37 / 37, no orphans |
| Every schema key is wired outside the schema | — | none unreferenced |
| `disable_comments` removes: admin menu, admin-bar node, dashboard widget, comment feeds, comment blocks (inserter *and* rendered), reports zero | `strings.php` | all 7 verified |
| Deactivation keeps settings, uninstall removes them | `readme.txt` | verified — no deactivation hook; `uninstall.php` deletes |
| `keel_defaults_schema()` is the single source of truth | `README.md` | verified — read by settings page, bootstrap, Site Health, lifecycle |
| wp-config constants win, and the screen says so | help text | verified — 6 lock references in `settings-page.php` |

## Findings

### 1. "One array entry plus one `if`-block" is no longer literally true

`README.md` and `keel.php`'s own header comment both describe the architecture as
one schema entry plus one bootstrap `if`-block per default.

Measured: 37 settings, but only **25** `if ( keel_defaults_enabled( … ) )` blocks
and 5 `keel_defaults_get()` reads in `bootstrap.php`. The remainder are wired
inside other blocks, share a block with a sibling setting, or are read at the
point of use rather than at bootstrap.

Not a defect — several groupings are deliberate, and the XML-RPC family
genuinely belongs in one block. But the claim is now a description of the
*intent* rather than of the code, and it is the first thing a new contributor
reads. It should either be softened to "usually one entry and one block" or the
exceptions should be named.

**Severity: low. Documentation drift, no behavioural consequence.**

## Not yet covered by this pass

Docblock-level claims in `security.php` (834 lines), `admin-ux.php`, `content.php`
and `settings-page.php` — roughly 132 docblocks in total, of which this pass
sampled the ones reachable from user-facing copy. The remainder is the bulk of
the work and has not been done.
