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

## Pass 1d — admin-ux.php

### Verified

| Claim | Where | Checked against |
| --- | --- | --- |
| Lowercasing runs after core sanitization, so only new uploads are affected | `admin-ux.php:105` | registered on `sanitize_file_name` at priority 20 |
| The post editor keeps its Heartbeat; only the dashboard drops it | `admin-ux.php:29` | callback returns unless `index.php` |
| Colour sanitizer strips what could terminate a declaration or open a comment | `admin-ux.php:641` | allowlist excludes `;`, `}`, `:`, `*`; keeps hex, `rgb()`, `var(--x)` and slash notation |
| `esc_url()` would be wrong inside `<style>` | `admin-ux.php:659` | `esc_url_raw()` used, then quotes and parens percent-encoded |
| Host matching uses the host, never the whole URL, so ported installs still match | `admin-ux.php:493` | `wp_parse_url( …, PHP_URL_HOST )`; covered by an explicit-port test |

### 5. The Heartbeat clamp was justified by a false claim about core — docblock fixed

The docblock said the 15-120 clamp matched "the range core itself accepts", and
that a filter returning 600 "would look like it worked and change nothing".

Core accepts **1 to 3600 seconds** — `heartbeat.js` bounds `options.interval` at
initialization, and core's PHP (`wp_heartbeat_settings()`) does not clamp at all.
A filter returning 600 would have worked fine. Keel's own `min( 120, … )` is the
only thing stopping it.

The ceiling is still right, for a reason the docblock never gave: this filter
applies to every admin Heartbeat including the post editor's, post locks expire
after 150 seconds (`wp_check_post_lock_window`), and core's own idle slowdown
stops at 120 for exactly that reason. Above 150 the lock stops being refreshed in
time and two editors overwrite each other.

Behaviour unchanged; the docblock now gives the real reason. This mattered
because `keel_heartbeat_interval` is a public filter documented as clamped "to
the range core accepts" — a site asking for 300s would have been told, wrongly,
that core would have ignored it anyway.

**Severity: low behaviourally, but it misinformed anyone using the filter.**

### 6. An explicitly declared environment was overridden by the host heuristic — fixed

`keel_current_environment()` falls back to a hostname heuristic so a `.test` or
`.ddev.site` install shows as Local. The guard was `! defined( 'WP_ENVIRONMENT_TYPE' )`.

Core resolves `WP_ENVIRONMENT_TYPE` from **either an environment variable or the
constant**, the constant winning (`load.php`, the `getenv()` branch). The
variable is the documented way to set it, and DDEV, Lando and wp-env all
generate one.

So a site that declared itself **staging** through the environment variable, on a
hostname like `client.ddev.site`, was relabelled **Local**. That is the one
failure an environment indicator cannot have: its entire job is to be believed at
a glance, and it was contradicting an explicit declaration.

Fixed with `keel_defaults_environment_is_declared()`, which checks both
mechanisms. A declared value only counts when core would accept it — core
silently falls back to production for anything outside its four names, and
inheriting that fallback would paint a red **Production** badge across somebody's
laptop on the strength of a typo.

Covered by four new assertions in `tests/environment-indicator.php`, which call
`getenv()` for real rather than stubbing it, since that is the call the fix turns
on. **Confirmed failing against the old guard before the fix was restored** —
`A WP_ENVIRONMENT_TYPE environment variable wins over the host heuristic.`

The file also carried a comment claiming "the constant always wins", with no
assertion behind it. Both mechanisms are now actually asserted.

**Severity: moderate. Wrong label on a staging site is the failure this feature
exists to prevent.**

## Pass 2 — test integrity

Pass 1 asked whether the documentation matches the code. Pass 2 asks the harder
question: **do the tests fail when the code breaks?** Reading a test cannot
answer that — this repo has already shipped four tests that passed for the wrong
reason — so this pass was done by mutation instead of by inspection.

Method: break one security-relevant invariant at a time in the plugin source,
run `composer test`, record whether the suite noticed, restore the file. A
mutation the suite does not catch is a claim nothing is defending.

### Result: 12 mutants, 10 killed, 2 survived

Killed on the first run — each of these breaks a documented guarantee, and each
was caught:

| Mutation | Invariant it attacks |
| --- | --- |
| Drop the super-admin exemption | Super Admins keep `unfiltered_html` on multisite |
| `<` → `<=` on the HIBP truncation boundary | a truncated range must never read as "not breached" |
| Treat count-0 rows as matches | HIBP padding rows are not breaches |
| Empty role set stops enforcing | an unknown role set enforces the password policy |
| Drop the `max()` in session length | a remembered login is never shorter than a regular one |
| `DENY` ranked equal to `SAMEORIGIN` | a host's `DENY` is never downgraded |
| Prefix match instead of path boundary | `/oembed/1.0-internal` must not reach through the REST gate |
| Unset only `author_name` | oEmbed must not leak `author_url` |
| Remove the never-overwrite guard | reactivation must not discard a configured site |
| Allow `;` in a CSS colour | a filter value must not terminate its declaration |

### 7. Two survivors — both real gaps, both closed

**`manage_options` could be deleted from the sensitive-capability list and no
test failed.** That list is the guardrail behind the strongest claim in
`security.php`: privileged accounts can never be exempted from the password
policy through the UI.

The cause is instructive. The test's role fixtures are *realistic* — and that is
precisely why they could not prove this. A real administrator holds
`manage_options` **and** `edit_posts`, so with `manage_options` deleted the
assertion "administrator is NOT exemptable" still passed, on the other capability.
The test asserted the conclusion while testing nothing about the mechanism.

The gap is not theoretical: a custom role holding `manage_options` but no
editorial capability — a billing or settings-only "Site Manager", which sites
really do create — would have become exemptable with nothing to catch it.

Closed by asserting each capability independently: one synthetic role per entry,
holding that capability and nothing else, plus a converse case so the loop cannot
pass by rejecting everything. Verified by re-running the mutation and then
deleting three other entries at random — each kills a test that names the missing
capability.

**Suffix matching could be replaced with a substring search and no test failed.**
The file has two assertions that look like they cover this (`latest.example.ca`,
`localhost.example.ca`), and neither does: neither host contains the suffix *with*
its leading dot.

A host like `client.test.agency.com` does — an agency running client staging
under a shared parent domain, which is a real naming pattern and the worst case
to get wrong, since it labels a live client site **Local**. Three such fixtures
added.

**Severity: moderate. Neither was a live defect; both were undefended
invariants, which is the thing a test suite exists to prevent.**

### What this pass does not prove

Mutation testing shows the suite catches the mutations that were *tried*. Twelve
were chosen for being security-relevant and plausible; they are not exhaustive,
and a green result here is evidence, not proof. The mutants and the method are
recorded above so the next pass can extend the set rather than start over.

## Pass 3 — pattern conformance

The brief was to find "deviations from simple repeated coding patterns". Measured
rather than eyeballed, across every convention the plugin implicitly keeps.

### Conformance is high

| Convention | Result |
| --- | --- |
| `defined( 'ABSPATH' ) \|\| exit;` in every include | 11/11 |
| Docblock on every function | 110/110 |
| Toggle read through `keel_defaults_enabled()` | 34 of 36 |
| Function prefix | 106 of 110 |

### 8. Four deviations, all closed

**One naming outlier.** The prefix rule turns out to have three legitimate forms,
which nothing had ever written down:

1. `keel_defaults_*` — everything, by default.
2. `keel_hibp_*` — the breach-lookup subsystem, whose helpers only mean anything
   together.
3. A function whose whole job is exposing a filterable value takes the filter's
   name, so `keel_environments()` applies `keel_environments`. The name *is* the
   hook; a second, different name would make the thing a site filters and the
   thing that reads it look unrelated.

`keel_current_environment()` fitted none of them — no filter of that name, no
subsystem, and its neighbour in the same file used the standard prefix. Renamed
to `keel_defaults_current_environment()`. It is not public API: only
`keel_environments()` appears in the docs, and the rename touches one internal
caller and the tests.

**Two open-coded toggle reads.** `site-health.php` had
`'yes' === keel_defaults_get( … )` twice, where the other 34 call sites use
`keel_defaults_enabled()`. Not wrong — just a second spelling of one idea, and a
second spelling is how a stored value's representation ends up asserted in places
that would not be updated if it ever changed.

**A fourth the review had missed.** Writing the guard immediately failed on
`keel_uninstall_site()` in `uninstall.php`, which the manual pass had not caught
because it only scanned `includes/`. Renamed. That is the argument for the test in
one line: the convention was already leaking somewhere nobody was looking.

### The guard

`tests/naming-conventions.php` enforces all four conventions, and states the
prefix rule in prose where the next contributor will find it. It includes a
converse assertion — that the two filter-named functions really do apply their
own filter — so rule 3 cannot pass by being vacuous, and a scan-found-nothing
check so an empty result cannot pass everything.

Verified failing on all four rules by breaking each in turn: a dropped prefix, an
open-coded toggle read, a removed `ABSPATH` guard, and a deleted docblock. Each
names the file, the line and the rule.

### Noted, not changed

`keel_assert()` has two different meanings depending on the file. In 22 tests it
exits on the first failure; in 5 it increments a counter and reports them all at
the end. Both are defensible — the counter files check many independent items
(readme claims, uninstall keys, 33 headings) where one failure should not hide
the rest, while the exit-first files test sequences where later assertions depend
on earlier state.

But the same name means two things, and someone copying a test file inherits
whichever semantics they happened to copy. Worth a decision at some point:
either name them differently, or standardise on the counter and let each file
report fully. Left alone here because it is a judgement call about test design,
not a defect.

## Not yet covered

Pass 4 — complexity and duplication — then the same sweep for BBD, and PX last.
