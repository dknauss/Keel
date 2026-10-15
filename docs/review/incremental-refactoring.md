# Incremental architecture work

Reviewed 2026-10-14. Keel stays procedural, schema-driven and integrated through
WordPress hooks. Improve boundaries without a framework rewrite, runtime Composer
dependencies, or changing defaults as a side effect of cleanup.

## First increment: shared settings controls

`includes/settings-controls.php` renders inputs for both site and network screens.
It owns field dispatch, discrete choice labels, the checkbox and slider helpers,
and control attributes. Screens retain authorization, row/group layout, dependency
visibility, help and warning placement, lock explanations, and save handling.

Presentation differences are deliberate: the site screen uses a preview slider,
stacked role checkboxes, legends and unit IDs; Network Admin keeps its range
selects and inline role checkboxes. Stored preferences and enforced display values
remain distinct. This extraction does not change their lock or save semantics.
The network wrapper remains available to existing callers.

Before/after checks compare parsed markup, including input names, selected and
checked values, attributes, and labels; whitespace and attribute order are not
behavior. Keep behavioral tests for locks, omitted unsupported fields, escaping,
role labels and save preservation. The screenshot change detector must include
this module, because future edits here can change either screen.

## Second increment: backport module boundaries

Implemented 2026-10-14. `backports.php` remains the compatibility entry point and
registers the hooks after loading the modules:

| Module | Responsibility |
| --- | --- |
| `backport-status.php` | Stable-check HTTP/cache constants and operations; version status and branch tips |
| `backport-policy.php` | Offers, filesystem/update blockers, selection state and core's update ladder |
| `backport-presentation.php` | Site Health results, notices, action links and ladder markup |
| `backport-actions.php` | AJAX report, enable-minor-updates and notice-dismissal request handlers |
| `backport-install.php` | Existing request validation, install planning and explicit upgrader execution |

Consumers continue loading the facade, not individual modules. Function names,
parameters and bodies are preserved; this increment does not replace WordPress's
selector, alter cache TTLs, change hook priorities, or weaken installer checks.
The screenshot detector follows the extracted presentation module. A loading test
pins all eight hook registrations and verifies that inclusion itself performs no
option reads, HTTP calls or writes. No installer is invoked by the lab checks.

The 33 moved function bodies matched their pre-extraction hashes. The local
multisite loaded the new modules, evaluated minor-update state and ladder
selection with zero attempted mail sends, and retained the install action at
priority 10. Site Health loaded as Super Admin with HTTP 200 and no JavaScript
errors or PHP fatal output. The disposable lab was stopped and removed after
verification. Existing behavioral tests continue to cover outages, offers,
refusals and explicit install planning. The scheduled update matrix was not
manually dispatched as part of this extraction.

## Third increment: password and HIBP modules

Implemented 2026-10-14. `security.php` remains the public loading entry point and
loads two declaration-only modules before bootstrap registers the policy hooks:

- `password-policy.php`: role enforcement/exemption discovery, shared validation,
  profile/reset/REST adapters and REST user context resolution (8 functions).
- `password-hibp.php`: bounded padded range requests, response parsing, per-prefix
  caches, failure/success diagnostics, opt-outs and verdict filtering (11 functions).

Capability, session, Jetpack/XML-RPC, header and anonymous-REST access policies
remain in security.php. All 37 existing security/password function bodies and
public names matched their pre-extraction hashes. Hook registration remains in
bootstrap.php, including priorities and argument counts. A misplaced legacy HIBP
comment was removed; no enforcement or failure policy changed.

The module-loading test requires the existing security entry point twice and
checks all 19 password callbacks remain available without option reads, network
requests, writes or new hook registration. Existing password/role/REST/HIBP tests
remain the behavioral guards. The [password lab record](password-extraction-lab.md)
documents real WordPress roles, profile/reset hooks and REST updates with mocked
HIBP transport. It is a local single-site test, not a new live multisite or
external-service compatibility matrix.

## Fourth increment: conflict diagnostic boundaries

Implemented 2026-10-14. `conflicts.php` remains the compatibility entry point,
retaining the three shared identity markers and loading declaration-only modules:

| Module | Responsibility |
| --- | --- |
| `conflict-attribution.php` | Reflect callbacks and resolve plugin/theme/must-use provenance and display labels |
| `conflict-overlap.php` | Register owned policy callbacks, classify shared hooks, cache reports and fingerprint overlap |
| `conflict-divergence.php` | Observe actual filter runs, persist mismatches, validate fingerprints and invalidate records |
| `conflict-notices.php` | Compose notice state, render it and process per-user dismissal |

Bootstrap retains observer, notice and invalidation timing. The initial extraction
preserved all 36 function bodies and signatures. A subsequent, separate commit
fixes the lab-discovered symlink self-attribution bug: an installed `keel-defaults`
slug may point at a source folder named `keel`. Resolve self identity through the
same active-plugin attribution map before falling back to the source basename.
Otherwise Keel's own passive observers can be reported as rival plugins. This
changes diagnostics, not the filtered policy value; the other 35 bodies remain
unchanged. A new regression test failed before this fix and passes afterward.

The subsequent performance pass found repeated filesystem attribution in crowded
reports. Own identity is now resolved once per report and active-plugin roots are
memoized against the current site/network plugin lists. The [performance budget](performance-budget.md)
records paired measurements, the fix and investigation thresholds.

The facade-loading test verifies all 36 callbacks and unchanged identity markers
without reads, writes or filter execution on inclusion. Screenshot review follows
all diagnostic modules, with invalidation tests. Existing tests retain theme,
mu-plugin, symlink, callback-identity, non-execution, cache and divergence coverage.
The [conflict lab record](conflict-diagnostics-lab.md) documents the real WordPress
and browser pass and its limits.

## Fifth increment: settings policy resolution

Implemented 2026-10-14. `settings-policy.php` owns four declaration-only helpers:
configuration-lock notes, the background-update disable constant, the core policy
constant mapping, and the locked control value. All four public names, signatures
and function bodies are unchanged. The main entry point loads policy immediately
after schema and before site/network consumers. The settings-screen entry point
also requires it once for direct consumers that previously obtained the helpers
there. Policy loading does not pull in screen registration.

Network override resolution and the uncached effective getter remain in network.php
and schema.php. Saved site preferences, network policy, constant precedence and
hidden form values keep their existing semantics. No settings cache, dependency,
new hook or enforcement change is introduced. Shared screen controls, sanitization
and help text remain in their existing modules. Screenshot tracking now follows
settings-policy.php, including a regression for invalidation when it changes.

The isolated loading test verifies four helpers without option reads, translation
or hook registration; existing core-policy truth tables, site/network render and
save tests cover behavior. The [lab record](settings-policy-lab.md) records real
WordPress checks and the paired performance-budget recheck.

## Sixth increment: data-contract and static-analysis pilot

Implemented 2026-10-14. Shared array contracts now describe update state, blocker
records, visible offers, ladder rungs, install plans, diagnostic tiers and callback
registrations. PHPStan level 6 checks backport policy and configuration locks at
the PHP 7.4 target, without a baseline or ignored errors. Positive/negative static
examples verify the contracts work; the dependency-free runtime matrix remains.
The [pilot record](data-contracts-phpstan.md) documents exact checked versus scanned
scope, untrusted-data limits, tooling constraints, verification and follow-ups.
Expansion to other bodies and higher levels remains on the backlog. PHP 8.x floor
research is separately tracked and does not change this increment's compatibility.

## Seventh increment: installer and presentation analysis

Implemented 2026-10-14. Extend the existing level-6, PHP 7.4-targeted pilot to
backport-install.php and backport-presentation.php. Add precise stored-result,
Site Health and fixed-return-map contracts, and replace unnecessarily broad
full-state consumer requirements with the fields each consumer actually reads.
Keep raw offers unvalidated until the existing runtime checks. Seven negative
static cases and valid producer/minimal-consumer examples exercise the contracts.

A single redundant boolean conjunct in the verdict renderer was simplified;
all four policy/operability combinations render identically in a differential
run of the existing regression harness. Other runtime executable tokens and
all native signatures remain unchanged. The [pilot record](data-contracts-phpstan.md)
contains the findings, checked/scanned scope and verification limits. The next
increment covers diagnostics; status and separate action-handler analysis remain open.

## Eighth increment: conflict-diagnostics analysis

Implemented 2026-10-14. Add attribution, overlap and divergence to the level-6,
PHP 7.4-targeted pilot (seven modules total). Describe public true-only divergence
markers and preserve the observer's input type. Keep filtered expectations and
raw stored data untrusted; callback reflection still accepts mixed inputs.
Scan-only core constant declarations fill gaps in the pinned stubs without
loading WordPress. Ten static rejection cases exercise the contracts.

Remove only an always-true offset check on explode's non-empty result; existing
diagnostic regression harnesses verify behavior. Other runtime tokens and native
signatures are unchanged. See the [pilot record](data-contracts-phpstan.md) for
scope and verification. The next increment covers notices; status/transport and
action handlers remain open.

## Ninth increment: conflict-notices analysis

Implemented 2026-10-14. Include conflict notice rendering, fingerprinting, screen
placement and dismissal handling in the level-6, PHP 7.4-targeted pilot (eight
modules). Retain the existing extensible screen-to-boolean contract; eleven
negative static cases now include misuse of those flags as display strings.

Simplify only the redundant object check before the screen-ID isset; missing
screens and missing IDs retain their fallback. The extended notice harness passes
against both renderer versions, including custom dismissible/persistent screens.
Other runtime tokens, native signatures, permissions, nonces and stored user
metadata remain unchanged. See the [pilot record](data-contracts-phpstan.md).
The next increment covers status/transport; separate action handlers remain open.

## Tenth increment: status and transport analysis

Implemented 2026-10-14. Add backport-status.php and password-hibp.php at level 6
with the PHP 7.4 target (ten modules). Separate the validated version verdict and
tri-state HIBP result from raw cached maps and failure records. Add missing void
and iterable-value annotations plus scan-only core constants. Fourteen negative
static cases exercise the supported constraints; partial union/nullability checks
remain a higher-level follow-up.

All runtime executable tokens and native signatures remain unchanged. Existing
backport/HIBP suites cover request bounds, padding, malformed responses, unknown
results, cache behavior and outage diagnostics. See the [pilot record](data-contracts-phpstan.md).
The next increment covers backport action handlers; password-policy adapters remain open.

## Eleventh increment: backport action-handler analysis

Implemented 2026-10-14. Include backport-actions.php in the level-6, PHP 7.4-targeted
pilot (eleven modules). Document its three request-ending callbacks with PHPDoc
never contracts; no PHP 8.1 native signature is introduced. Positive fixtures
check callable compatibility, and fifteen negative cases include unreachable
fallthrough after the AJAX action.

All runtime executable tokens, permissions, nonces, writes, JSON responses and
redirects remain unchanged. No extra shape, wrapper, dependency or suppression
is introduced. See the [pilot record](data-contracts-phpstan.md) for scope and
verification. The next increment covers password-policy adapters.

## Twelfth increment: password-policy adapter analysis

Implemented 2026-10-14. Include password-policy.php at level 6 with the PHP 7.4
target (twelve modules). Describe the shared true/error verdict, prepared-object
identity, raw REST parameters, read-only request projection and mixed endpoint
registry. Profile/reset callbacks return void; user context accepts ordinary objects
and failed lookups.
Seventeen negative cases produce twenty precisely checked diagnostics.

All runtime executable tokens, signatures, policy ordering, role exemptions,
profile/reset trimming, REST sanitizer behavior and context privacy remain unchanged.
No runtime wrapper, validation or dependency is introduced. See the
[pilot record](data-contracts-phpstan.md) for contracts and verification limits.
The next increment covers shared settings controls; higher-level analysis remains
separate.

## Thirteenth increment: shared settings-control analysis

Implemented 2026-10-15. Add settings-controls.php at level 6 with the PHP 7.4
target (thirteen modules). Minimal field/copy projections retain optional defaults,
keyed arrays, arbitrary select-like types and raw range/bound values. Twenty
negative cases produce 23 checked diagnostics.

Remove only array_values on the already sequential width list before JSON output.
Ten full-page renders and 321 site/network control renders match byte-for-byte
before/after, including locks, alternate values and keyed range metadata. Other
runtime tokens, native signatures, accessibility attributes, hidden preferences
and save behavior are unchanged. See the [pilot record](data-contracts-phpstan.md).
The large settings pages and a global level increase remain outside this scope.

## Sequence and acceptance criteria

1. **Separate backport responsibilities (completed second increment).** Split remote stable-check/cache access,
   version/offer and policy evaluation, notice/ladder presentation, and action
   handlers into cohesive modules. Preserve public function names first. Keep
   prepare/validate separate from upgrader execution. Verify outage recovery,
   stale offers, filesystem refusals, nonce/capability checks, redirects and
   same-line-only installation against the existing live matrix. Do not invoke
   an update as a side effect of rendering or diagnostics.
2. **Extract password and HIBP handling (completed third increment).** Separate it from HTTP headers, sessions,
   XML-RPC and REST access policies in security.php. Keep password entry points
   and role scoping consistent; preserve breach lookup privacy, response bounds,
   cache behavior and failure policy. Use the existing password and HIBP tests
   plus real WordPress entry-point checks.
3. **Split conflict diagnostics internally (completed fourth increment).** Separate callback/plugin attribution,
   structural overlap classification and observed divergence persistence. Retain
   the distinction between sharing a hook and demonstrated disagreement. Never
   execute foreign callbacks just to identify them. Preserve must-use plugin
   attribution, request-local cache behavior and transient invalidation.
4. **Extract settings policy resolution (completed fifth increment).** Move configuration lock/value resolution
   out of settings-page.php into a module shared by site, network and diagnostic
   consumers. Preserve constant precedence, network policy and the stored site
   preference. Do not introduce a second settings cache that becomes stale after
   update_option().
5. **Strengthen data contracts gradually (pilot completed sixth increment; expansion open).** Add precise PHPDoc array shapes for
   update states, offers, lock descriptions and diagnostic reports. Introduce
   PHPStan with WordPress stubs on a small, well-defined module first, then expand.
   Do not hide new issues behind a continually growing baseline. Add native types
   only when compatible with PHP 7.4, WordPress hooks and accepted input behavior.
6. **Improve verification at the boundaries.** Retain fast standalone tests, share
   faithful WordPress stubs where semantics genuinely agree, and expand real-WP
   checks for hook order, multisite saves, locks and browser-visible behavior.
   Consider a separate scheduled/integration job rather than making every pure
   unit test boot WordPress. Add JS/CSS linting as a separate change after choosing
   WordPress-compatible rules. Coverage measurement may identify gaps; assertion
   counts and source-string checks alone do not prove behavior.
7. **Keep comments and tooling contracts current.** Keep invariants and short
   rationale beside code; move lengthy incident history into review/design notes.
   Audit source-reading tests and screenshot/package guards whenever code moves.
   Keep compatibility tooling versions under review and update explanatory claims
   when their assumptions change.

Each increment gets its own review and appropriate tests. Avoid combining policy
changes, copy changes, and structural moves in the same increment. Reconcile
shared paths with Agency Experience and record counterpart status before release.

## Standards and non-goals

WordPress Coding Standards remain primary. PSR-4 becomes useful if a substantial
namespaced class library emerges; it is not a reason to turn hook callbacks into
classes. PSR-3 is an option if a real logging requirement appears. There is no
present need to wrap WordPress HTTP/REST APIs in PSR-7/15/18 interfaces or replace
WordPress formatting with PSR-12/PER. These are conditional choices, not backlog
commitments. No container, autoloading migration, or blanket OOP conversion is
planned.

## Updates-screen static analysis — 2026-10-15

The level-6 pilot now includes `updates-screen.php` (fourteen modules). Raw
branch-tip PHPDocs match the existing string guard through all three rendering
helpers. Executable code, PHP 7.4 compatibility, capability checks and markup stay
unchanged. Static examples and runtime invalid-input checks supplement the existing
Updates-screen offer/result tests and before/after markup comparison. See
[data contracts](data-contracts-phpstan.md) for scope and verification limits.
Remaining module boundaries and higher levels require separate focused increments.

## Update-policy callback analysis — 2026-10-15

The level-6 pilot now includes `updates.php` (fifteen modules). Existing boolean
contracts need no source changes. Positive and negative static fixtures exercise
all four callbacks; 28 invalid cases produce 31 exact diagnostics. Existing
core-update-policy and full standalone tests verify policy behavior. PHP 7.4,
all runtime source and analysis memory caps remain unchanged. Remaining modules
and higher levels stay separate increments; see [data contracts](data-contracts-phpstan.md).

## Lifecycle analysis — 2026-10-15

The level-6 pilot now includes `lifecycle.php` (sixteen modules). Raw public-hook
input annotations and analysis of the evolving migration target preserve all
runtime guards and executable tokens. Static fixtures cover seed maps, boolean
results and activation flags (33 rejected cases / 36 diagnostics). The strengthened
multisite context-stack harness passes original and updated code, including
migration/no-op cases, malformed input and non-main-site restoration. PHP 7.4
and current memory caps remain unchanged. See [data contracts](data-contracts-phpstan.md)
for scope, constant-model rationale and verification limits.
