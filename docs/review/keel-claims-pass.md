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

## Pass 1b — docblock claims

Every claim below was checked against the code it documents, and against
WordPress core (7.0.2) where it asserts something about core's behaviour.

### Verified

| Claim | Where | Checked against |
| --- | --- | --- |
| `demo.*` XML-RPC methods are always dropped, no toggle | `bootstrap.php:114` | unconditional — no enclosing `if` |
| REST auth filter runs after core's own auth | `security.php:621` | core registers app-password auth at 90, cookie auth at 100; Keel at `PHP_INT_MAX` |
| `unfiltered_html` clamp "has the final say" | `security.php:11` | registered at `PHP_INT_MAX - 1` |
| Session clamp at priority 50, not 10 | `security.php:92` | registered at 50 |
| `is_super_admin()` guarded by `is_multisite()` to avoid recursion | `security.php:19` | guard present; decisions read `$user->roles` and `$allcaps` only |
| HIBP sends only a 5-character prefix | `security.php:821` | `substr( $hash, 0, 5 )`; suffix compared locally |
| HIBP fails open at every step | `security.php:825` | error, non-200, truncated and malformed bodies all `return false` |
| Only whole, well-formed bodies are cached | `security.php:828` | completeness checked before `trim()`; `set_transient` after validation |
| No privileged role can be exempted from the UI | `security.php:336` | editor/author/contributor/shop manager/admin all hold a listed sensitive cap; subscriber holds none |
| Unknown or empty role set enforces the policy | `security.php:298` | `empty( $roles ) → return true` |
| One validator behind profile, reset and REST | `security.php:387` | all three callbacks delegate to `keel_defaults_validate_password()` |
| Every `GET /wp/v2/comments` asks for type `comment` | `content.php:~82` | core sets `$query_params['type']['default'] = 'comment'` |
| Core 6.9 stores editorial Notes as comment type `note` | `content.php:35` | `note` is a real comment type in core |
| Core defaults `wp_attachment_pages_enabled` to `0` on new installs | `admin-ux.php:772` | `schema.php` populates `0`; `canonical.php` redirects on it |

A mechanical sweep also cross-checked every hook-shaped identifier cited in a
comment against the hooks the plugin actually registers. No comment claims a
hook the code does not use; the six remaining references are core options and
capabilities, correctly described.

### 2. The oEmbed carve-out docblock understated its own protection — fixed

`keel_defaults_public_rest_routes()` explains why `oembed/1.0` stays reachable
when anonymous REST is closed. It said the carve-out is safe because *"with
`disable_author_archives` on"* the author fields are stripped, and added that if
archives are not hidden, oEmbed "says nothing the post's own byline does not".

That describes the code as it was before the fix. `keel_defaults_strip_oembed_author()`
is registered in two places — `bootstrap.php:91` under `disable_rest`, and
`bootstrap.php:416` under `disable_author_archives` — so the fields are stripped
whenever the carve-out is actually in play, independent of the archive setting.

The docblock therefore documented a leak that no longer exists. The risk is not
cosmetic: the next person auditing this either believes anonymous callers can
still read nicenames through oEmbed, or "fixes" a registration that is already
there. The reasoning for keeping the two registrations independent has been
written down in its place.

**Severity: low behaviourally (code was already correct), moderate for an audit
reading the file as its source of truth. Fixed in this commit.**

## Not yet covered

- `settings-page.php` and the remaining `admin-ux.php` docblocks below the
  attachment-redirect section.
- Passes 2-4: test integrity, pattern conformance, complexity and duplication.
