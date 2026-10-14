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

## Remaining work, in order

1. **Separate backport responsibilities.** Split remote stable-check/cache access,
   version/offer and policy evaluation, notice/ladder presentation, and action
   handlers into cohesive modules. Preserve public function names first. Keep
   prepare/validate separate from upgrader execution. Verify outage recovery,
   stale offers, filesystem refusals, nonce/capability checks, redirects and
   same-line-only installation against the existing live matrix. Do not invoke
   an update as a side effect of rendering or diagnostics.
2. **Extract password and HIBP handling.** Separate it from HTTP headers, sessions,
   XML-RPC and REST access policies in security.php. Keep password entry points
   and role scoping consistent; preserve breach lookup privacy, response bounds,
   cache behavior and failure policy. Use the existing password and HIBP tests
   plus real WordPress entry-point checks.
3. **Split conflict diagnostics internally.** Separate callback/plugin attribution,
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
