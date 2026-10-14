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

The facade-loading test verifies all 36 callbacks and unchanged identity markers
without reads, writes or filter execution on inclusion. Screenshot review follows
all diagnostic modules, with invalidation tests. Existing tests retain theme,
mu-plugin, symlink, callback-identity, non-execution, cache and divergence coverage.
The [conflict lab record](conflict-diagnostics-lab.md) documents the real WordPress
and browser pass and its limits.

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
4. **Extract settings policy resolution.** Move configuration lock/value resolution
   out of settings-page.php into a module shared by site, network and diagnostic
   consumers. Preserve constant precedence, network policy and the stored site
   preference. Do not introduce a second settings cache that becomes stale after
   update_option().
5. **Strengthen data contracts gradually.** Add precise PHPDoc array shapes for
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
