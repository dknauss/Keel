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

## Pass 1c — settings-page.php

### Verified

| Claim | Where | Checked against |
| --- | --- | --- |
| Dependent settings hide automatically: XML-RPC methods when the endpoint is blocked, Remember Me length when Remember Me is off | Overview help tab | both dependencies declared in the schema and honoured by `keel_defaults_dep_state()` |
| A forged POST cannot exempt a privileged role | `settings-page.php:210` | posted slugs intersected with `keel_defaults_exemptable_roles()`, which excludes every role holding a sensitive cap |
| `WP_AUTO_UPDATE_CORE` supersedes the core-update policy | `config_lock()` | core reads it in `class-core-upgrader.php:293`, ahead of the filters Keel uses |
| `AUTOMATIC_UPDATER_DISABLED` / `DISALLOW_FILE_MODS` stop all background updates | `config_lock()` | `WP_Automatic_Updater::is_disabled()` checks the first directly and the second via `wp_is_file_mod_allowed()` |
| `DISALLOW_UNFILTERED_HTML` already removes the cap from every role | `config_lock()` | `capabilities.php:597` |
| Site Health → Info lists every default under a **Keel** section | help sidebar | section label is `Keel`; the builder iterates the whole schema |
| Site Health → Status flags only what warrants attention | help sidebar | status is `good` unless strong passwords or REST user-discovery is off, and `good` files under Passed tests |
| A locked control is disabled with the reason shown | `config_lock()` docblock | honoured in both the toggle and select branches, each preserving the stored value in a hidden input — **but see finding 4** |

### 3. The Overview help tab contradicted the code about framing — fixed

The tab told admins that anything able to "change behavior or break an integration"
is off by default, and gave *cross-origin framing* as its first example.

Keel ships `X-Frame-Options: SAMEORIGIN` out of the box. `frame_options` defaults
to `SAMEORIGIN`, and its header is registered whenever that value is non-empty —
deliberately outside the `security_headers` toggle, with a comment at
`bootstrap.php:237` saying framing "is the only one of the three that can break a
working site, so it must be switchable without also giving up nosniff."

So the code identifies framing as the risky one and enables it; the help tab
named it as an example of something switched off. An admin whose embedded site
went blank would have read that tab and concluded Keel could not be the cause.

Replaced with the two things that *are* off by default and opt-in, plus an
explicit paragraph naming the framing default, what it breaks, and the exact
control that turns it off. Wording matches the setting's real label and choices.

**Severity: moderate. User-facing copy that was wrong in the direction that
costs debugging time.**

### 4. Sectioned settings ignored wp-config locks — fixed

`keel_defaults_config_lock()` documents an invariant: when a constant supersedes
a setting, "the control is then disabled and the note shown, so the screen never
offers a switch that cannot take effect."

The single-field branch does this. The sectioned branch — the stacked checkboxes
used by the REST and XML-RPC groups — passed a hardcoded `false` for the disabled
argument, never rendered the lock note, and skipped the hidden input that
preserves a stored `yes` across a save.

No sectioned setting is lockable today, so nothing was broken. But the invariant
is documented unconditionally and enforced in one of two places, and the gap is
invisible until someone adds, say, a `KEEL_DISABLE_REST` constant for
`disable_rest` — which is sectioned. The control would render live, the note
would not appear, and saving would silently rewrite the stored preference.

Brought the branch in line with the other one.

**Severity: low today, and latent by design — this is what a review is for.**

### Noted, not changed

The `multiselect` sanitize branch hardcodes `'password_exempt_roles' === $key` to
pick its allow-list. It is the only multiselect, and the allow-list is computed
from roles at runtime so it cannot live in the schema as a literal. But a second
multiselect added later would get an empty allow-list and silently store nothing.
A `choices_callback` in the schema would make it schema-driven like every other
type. Left alone: it is a real pattern deviation, but changing it is a design
call rather than a review finding.

## Not yet covered

- The remaining `admin-ux.php` docblocks below the attachment-redirect section.
- Passes 2-4: test integrity, pattern conformance, complexity and duplication.
