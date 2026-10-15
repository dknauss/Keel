# Data-contract and PHPStan pilot

Implemented 2026-10-14, preserving the PHP 7.4 floor. The initial pilot changes
annotations and development tooling only. Its installer/presentation extension
simplifies one equivalent boolean condition; function signatures, hook registration,
storage, filtering and configuration precedence remain unchanged. The subsequent
conflict-diagnostics extension removes one always-true offset check. The notices
extension removes a redundant object check while retaining the screen-ID guard.
The status/transport, action-handler and password-policy extensions change
annotations and development tooling only.

## Checked scope

`composer analyse` analyzes `includes/backport-policy.php` and
`includes/settings-policy.php`, plus `includes/backport-install.php` and
`includes/backport-presentation.php`, and the four conflict modules
`conflict-attribution.php`, `conflict-overlap.php`, `conflict-divergence.php` and
`conflict-notices.php`, plus `backport-status.php`, `backport-actions.php` and
`password-hibp.php`, `password-policy.php`, `settings-controls.php`,
`updates-screen.php`, `updates.php`, `lifecycle.php`, `email.php` and `assets.php` at PHPStan level 6,
with `phpVersion: 70400`.
It scans other includes and keel.php to discover declarations/constants without
executing them. Scanning is not analysis: loading facades and other
bodies outside these eighteen files are not yet claimed clean. WordPress stubs provide
core declarations; standalone runtime-test doubles are not used as analyzer stubs.

There is no baseline, ignoreErrors list, inline suppression, invented runtime
validation, or inline @var assertion to make analysis green. Level 6 catches
missing iterable value contracts and checks shaped producer/consumer boundaries.
Higher levels are separate work: they expose additional uncertainty around raw
Core offers, possibly-false regex splitting, and correlations between selection
state and selected version. Do not remove defensive branches just because a
normal-data annotation makes them appear unnecessary. Level 6 also permits some
partially incorrect unions, nullable results and mixed-field assumptions; the
fixtures must not imply those are fully enforced.

## Shared contracts

The aliases live once in phpstan.neon.dist. Public PHPDocs retain readable array
shapes; @phpstan-param / @phpstan-return connect shared contracts across modules.

| Alias | Guaranteed structure |
| --- | --- |
| KeelBlocker | code and translated text strings |
| KeelMinorUpdateState | policy/operable booleans, option/keel/constant/filter owner, ordered blockers |
| KeelUpdateScreenOffer | unknown/none/hidden/visible state and the raw manual value |
| KeelLadderRung | version, same-line flag and partial-package flag |
| KeelInstallPlan | offer object; relaxed-ownership boolean and attempt_rollback always true |
| KeelOverlapReport | separate structural and unconfirmed hook-to-plugin-list maps |
| KeelPasswordVerdict | true on acceptance, otherwise WP_Error |
| KeelVersionStatus | latest, outdated, insecure or unknown after runtime verdict validation |
| KeelPolicyRegistration | callback (nullable for registry reads), integer priority/argument count, string or keyed-string-array setting |

A Core update offer remains an object until the existing installer validates it;
this increment does not claim its optional fields have been validated by a lookup.
Likewise get_core_updates() normally supplies strings, but the manual-offer reader
only checks field presence. Its return annotation therefore leaves manual as mixed
for malformed cached data. The alias is not a runtime validator and cannot make an
untrusted transient safe. Configuration locks already return string|null, so no
artificial array wrapper was introduced there.

## Tooling and compatibility

PHPStan and php-stubs/wordpress-stubs are require-dev only. WordPress does not load
the Composer autoloader. Vendor files, static-analysis configuration, fixtures,
scripts and caches are excluded from the release archive; caches use build/phpstan.
The runtime's existing PHP compatibility scan and real PHP-version matrix remain
independent of PHPStan and require no vendor directory.

The pilot deliberately stays on PHPStan's 2.1 patch line (~2.1.0, locked to 2.1.56).
The initial 2.3.1 trial attempted to load its bundled Turbo extension, which this
machine's signed PHP rejected with a macOS library-validation warning. The small
pilot needs no native accelerator. Upgrade the analyzer in a separately verified
tooling change rather than patch vendor files or weaken host signing restrictions.
WordPress declarations stay on the declared minimum's 6.4 patch line (~6.4.0,
locked to 6.4.7). Newer optional APIs need guarded use and appropriate declarations
when expanding analysis. Both dev dependencies support the existing PHP floor.

## Verification

- `composer analyse`: all eighteen pilot modules must pass without suppressed errors.
- `composer test:types`: valid producer-to-consumer and minimal-input fixtures must pass; forty
  deliberately invalid examples must fail with the expected identifiers: a missing
  update-state key, invalid produced owner, missing diagnostic tier, rollback=false
  plan, invalid stored-result key, unknown return target, invalid health status,
  non-string attribution paths, missing registration field, false divergence marker,
  a notice-screen map incorrectly treated as string values, invalid produced version
  status, tri-state lookup misread as a record, a raw failure misread as text,
  assumed control-flow fallthrough after the AJAX action, boolean password failure
  instead of an error, a scalar prepared user, missing range values, missing choice
  type, non-array range labels, and non-boolean inputs/string return consumers
  for each of the four update-policy callbacks, an invalid activation flag, a seed
  map consumed as text, three lifecycle booleans consumed as strings, a non-array
  mail payload, incorrect mail result/From/policy consumers, non-callable public
  style providers, mandatory provider arguments and a registry consumed as CSS. The checker compares exact
  per-case diagnostic multisets, including multiple errors from generic contracts.
  PHPStan analyzes these functions but never executes their WordPress calls. The
  initial contract check failed with the pre-pilot annotations and passed when
  the new annotations were restored, proving the added constraints were exercised.
- Existing coding standards, compatibility scan and standalone behavior suite.
- The package test builds the actual zip and verifies that only runtime files ship.
- The initial pilot preserved all executable tokens. The extension changes only
  one equivalent boolean condition in the verdict renderer. The diagnostic
  extension removes one always-true offset check. The notice extension removes a
  redundant object check while preserving isset on the screen ID. Shared-control
  analysis removes a redundant array_values on an existing list; see the records below.
- CI runs analysis and contract checks once in the existing lint/unit job, not in
  each dependency-free PHP compatibility job. The release workflow mirrors these
  gates; the existing release-workflow regression test enforces that parity.

## Installer and presentation extension — October 14, 2026

Review of merged #257 found that full-state parameter aliases unnecessarily
required owner/policy/operability fields in consumers that do not read them.
Keep the complete KeelMinorUpdateState producer contract, but use projections:
KeelSelectionState requires operability (blockers are optional on the operable
branch), KeelRouteState needs policy/operability/blockers, KeelScheduleState needs
only policy/operability, and KeelBlockerState needs only blockers. Code-only
consumers accept keyed records without unused text; text projection accepts text
without an unused code. The full producer still emits complete ordered records. Positive
fixtures include the minimal arrays already supported by the runtime suite.
The owner rejection fixture now tests a malformed produced state, rather than
pretending an ignored owner field is meaningful to the selector.

Additional checked contracts:

- KeelBackportResult: string code, string messages with integer or string keys,
  and success/error type; null means no usable record. Do not claim a list for
  the reader: existing validation accepts keyed third-party message arrays.
- KeelBackportHealthResult: the complete output record, good/recommended/critical
  status, the Security badge and fixed keel_backport test ID.
- KeelBackportTestRegistration: the owned async entry while retaining arbitrary
  other registry entries. No assumption that third-party tests share our schema.
- The return map contains only site-health and updates, each with its fixed path;
  submitted return tokens stay strings because unknown input uses the fallback.
- The installer finish helper accepts the already-handled null outcome and is
  annotated never because every path redirects and exits. No native never type
  or new guard was introduced; PHP 7.4 compatibility is unchanged.

All nine initial expanded-analysis findings are resolved without suppressions or
baseline changes. One finding identified a redundant !operable test: after
policy && operable has failed, the next branch's policy=true already entails
operable=false. That branch now checks policy alone. No defensive branch or null
check was removed. In disposable copies, the original standalone backport-status
harness passed both revisions; forced policy/operability combinations produced
byte-for-byte identical verdicts in all four states. All other runtime executable
tokens matched the merged pilot; no native signatures, hooks, permissions, nonce
checks, redirects, offer validation, filesystem execution or stored data changed.

The expanded negative fixture adds bad result-key, return-target and health-status
cases. The existing compiler regression runner checks exact identifiers for all
seven cases; valid fixtures cover typed results, registry entries and partial
consumer states. Full unit/packaging, coding-standard, compatibility and syntax
checks remain applicable. This is local/static verification, not a new real-core
installer run or browser/performance claim.

## Conflict-diagnostics extension — October 14, 2026

Analyze attribution, overlap and divergence bodies separately at the same level
and PHP target. No new dependencies, baseline or suppressed findings. The initial
scan found three issues: two missing WordPress constant declarations and an
always-true isset on element zero of explode's non-empty result.

The scan-only tests/phpstan/wordpress-constants.php declares WP_PLUGIN_DIR and
HOUR_IN_SECONDS, which the pinned core stubs omit. It is never bootstrapped or
shipped. The plugin directory is widened to string with dynamicConstantNames;
its placeholder must not turn site-specific paths into analyzer assumptions.

The public divergence map now records true markers precisely, retaining integer
or string keys from extension filters. The expectation map remains mixed after
its existing null removal: filtering does not validate third-party values as
booleans. Raw transient data likewise remains untrusted until existing checks;
no full stored-record shape is asserted. The observer's template preserves its
input type because every return passes the same value through unchanged.
Registration settings accept keyed arrays as the existing array_map does;
competing-plugin output is a map of lists. Callback reflection continues accepting
mixed inputs, including unavailable or incomplete callback forms.

Positive fixtures exercise these boundaries and negative fixtures reject non-string
plugin paths, a misspelled registration field and false public divergence markers.
The static runner now checks ten exact expected failures. Runtime verification
compares the existing attribution/overlap/divergence regression harnesses before
and after the offset simplification; all other runtime executable tokens match.
Full standalone/packaging, coding standards, PHP compatibility and syntax checks
remain required. No new real-site, browser or timing claim is made.

## Conflict-notices extension — October 14, 2026

Add conflict-notices.php at level 6 with the same PHP 7.4 target, dependencies
and no suppressions or baseline. The notice fingerprint, renderer, screen map
and dismissal handler are now analyzed, bringing the scoped total to eight files.

The existing screen-map contract is array<string,bool>: callers may extend its
screen IDs; true offers dismissal and false keeps a persistent notice. Document
the extension hook and replace the stale renderer comment naming exactly three
screens. No fixed-key shape or new wrapper is needed. Valid fixtures accept
custom screens and the opaque fingerprint; the eleventh negative fixture rejects
treating dismissal flags as display strings.

The only initial finding was isset.property after an is_object check narrowed
core's nullable screen to WP_Screen, whose ID is declared non-null. Remove the
redundant is_object conjunct, retaining isset on the ID and the empty-string
fallback. This also preserves behavior for null, missing/null IDs and unusable
non-object inputs. No native signature, capabilities, nonce verification, user
metadata, redirects, fingerprints, notice text or dismissibility rules change.

The notice regression harness covers seven unavailable-screen cases, a valid
screen's identical output and custom dismissible/persistent placement. It passes
against disposable copies of both the original and updated renderer; all other
runtime executable tokens match. Full standalone/packaging, static contracts,
coding standards, compatibility and syntax checks remain required. No new real-site,
browser or performance claim is made.

## Status and transport extension — October 14, 2026

Add backport-status.php (stable-check HTTP/cache and version status) and
password-hibp.php (bounded breach lookup, caching and diagnostics) at the same
level and PHP target. Ten modules are now analyzed. No runtime statements,
native signatures, requests, timeouts, privacy boundaries, caching, invalidation
or fail-open policy change; executable tokens match the parent in all runtime files.

Resolve four initial declaration findings: add the stale-refresh callback's void
return annotation, specify HIBP failure array values, and declare WPINC and
MINUTE_IN_SECONDS in the existing scan-only constant file. No new dependency,
bootstrap execution, baseline or suppression is introduced.

KeelVersionStatus describes the validated latest/outdated/insecure/unknown verdict.
The stable-check reader's old string-to-string annotation was too strong: cached
arrays bypass the fresh HTTP validation. Return array<array-key,mixed>, and let
the stale-map checker accept those raw records because it only tests key presence.
The HIBP failure reader similarly checks only array-ness and non-empty kind; do
not promise first/seen/count fields or numeric timestamps. The tri-state lookup
continues to return bool|null, distinct from the public fail-open boolean wrapper.

Valid fixtures cover verdicts, unknown lookup results, raw caches and malformed
update offers. Three additional negative cases reject an out-of-domain produced
status, lookup misread as a record and failure record misread as text (fourteen
cases total). At level 6, omitted union members and assumed raw field shapes may
still pass; higher-level checking is a separate increment, not a claimed guarantee.

A fresh ten-module scan exceeded the old 512M worker cap. Smaller batches did
not resolve it, so retain the original batching and set only composer analyse to
768M. A fresh scan passed in 9.20 seconds with PHPStan reporting 541.42 MB used
on this machine. Contract fixtures retain their 512M cap. CI and release checks
already use the Composer script; installed WordPress has no analyzer or new
memory requirement. These are development-tool measurements, not runtime metrics.

Full standalone/packaging, existing HIBP/backport behavior suites, coding standards,
PHP compatibility, syntax and static contracts remain the verification gates.
No fresh real-site, browser, external-service or runtime performance claim is made.

## Backport action-handler extension — October 14, 2026

Add backport-actions.php to the same level-6, PHP 7.4-targeted scope (eleven
modules). The initial scan found exactly three missing return annotations.
Document all three callbacks as never: Site Health responds through the core
terminating JSON helpers, enabling minor updates redirects and explicitly exits,
and dismissal ends through wp_die. Core stubs already model those termination
semantics; no new stub, dependency, baseline or suppression is needed.

These are PHPDoc contracts, not PHP 8.1 native never signatures. Positive static
fixtures accept all three as callable():never without invoking them. The fifteenth
rejection case catches an assumed return to the caller after the AJAX handler.
No new data shape, helper wrapper or request validation is introduced.

All runtime executable tokens match the parent. Capability checks, nonce actions
and field names, network-option writes, transient invalidation, per-user/version
dismissals, response codes, JSON payloads and redirect destinations are unchanged.
Existing loading and backport regressions plus the full standalone/packaging,
coding standards, compatibility, syntax and static-contract gates remain required.
This does not claim new real-WordPress handler execution, browser checks or timing.
A fresh eleven-module scan passes at the unchanged 768M analysis cap; fixture
checks retain their 512M cap.

## Password-policy adapter extension — October 14, 2026

Add password-policy.php at level 6 with the same PHP 7.4 target (twelve modules).
Resolve six initial annotation findings: void returns for the profile/reset hooks,
generics for the two REST request parameters, and endpoint-map input/output value
types. No native signatures or runtime statements change.

REST parameters remain mixed rather than claiming that sanitization or authorization
has run. Use a covariant read projection of WP_REST_Request<array<string,mixed>>
because these adapters only inspect requests; a caller's narrower optional-password
shape remains accepted. The endpoint registry is array<array-key,mixed>, preserving
third-party metadata, sparse handlers and unrelated routes without asserting a
complete handler/sanitizer schema. No inline assertion or invented validation.

KeelPasswordVerdict is true|WP_Error. The prepared-user adapter's template retains
the concrete input object on success and adds WP_Error on rejection. User-context
annotations accept ordinary objects, whose optional context fields are guarded,
and the existing false result of a failed get_userdata lookup;
unknown roles still enforce the policy. Profile/reset error mutation, trimming,
breach-first screening, exemptions, sanitizer ordering, application-password
exclusion and the pre-authorization context privacy gate are unchanged.

Positive fixtures cover raw and narrower REST requests, failed user lookup,
prepared-object identity (including a custom class), profile/reset callbacks and
mixed endpoint metadata.
The seventeenth rejection case rejects scalar prepared users; the sixteenth rejects
a false validator result. These produce multiple diagnostics, so the runner now
checks exact sorted identifier multisets per case and the total diagnostic count
(seventeen cases, twenty diagnostics), rather than overwriting duplicate messages.
The existing level-6 union/nullability limits remain explicit.

All runtime executable tokens match the parent. Existing password-entry-point,
role/exemption, REST-context and HIBP suites plus full standalone/packaging, coding
standards, compatibility, syntax and static-contract checks remain the gates.
No new dependency, baseline, suppression, wrapper, real-site/browser execution or
runtime performance claim. A fresh twelve-module scan passes at the unchanged
768M cap; fixture checks retain their 512M cap.

## Shared settings-control extension — October 15, 2026

Add only settings-controls.php at level 6 with the PHP 7.4 target (thirteen
modules). The initial trial found eight issues: six iterable-value declarations,
one missing void return and one array_values call on an existing list. No new
dependency, baseline, suppression, native signature, guard or runtime wrapper.

Six small aliases describe the field and display-copy projections each consumer
reads. Choice resolution requires type; direct range rendering requires only its
values collection. The dispatcher retains its optional type and default toggle;
custom select-like types remain accepted. Copy keys are optional, and the range
helper does not impose types on unrelated metadata such as unit. Keyed arrays
stay accepted: existing array_values calls align range values and labels. Stored
preferences, range value entries and numeric bounds remain mixed until existing
casts; these annotations do not claim full schema or conditional-field validation.

The only executable change removes array_values($rpx) before JSON encoding.
$rvalues was already reindexed, and both single-array array_map calls preserve
its sequential integer keys, so $rpx is already a list. No required normalization
of input values or labels is removed. All other executable tokens match across
32 runtime files.

In disposable before/after copies, the original settings-render harness passed
both versions. Ten full-page renders and 321 control renders matched byte-for-byte:
all schema fields, site/network contexts, locked/unlocked presentation, default/
alternate values and a keyed range case. This preserves slider JSON, network
selects, hidden stored preferences, checkbox attributes and accessibility wiring.
It is a local regression/differential check, not a new browser or real-site claim.

Positive static fixtures cover minimal/default fields, role-derived choices,
custom select types, keyed range arrays, numeric strings/floats in raw metadata
and ignored copy metadata. Three new cases reject missing range values, missing
choice type and non-array labels. The runner checks twenty invalid cases with
23 exact diagnostics. Existing compatibility, coding standards, syntax, full
standalone/packaging and static checks remain required. Analysis and fixture
memory caps remain 768M and 512M; a fresh thirteen-module scan passes within
the existing cap. No runtime performance claim is made.

## Updates-screen increment — 2026-10-15

Add only `updates-screen.php` at level 6 (fourteen modules), retaining the PHP
7.4 target and existing analysis/fixture memory caps. The single initial finding
was a PHPDoc string declaration making the defensive `is_string($tip)` check
appear redundant. All three rendering boundaries now document the raw tip as
mixed; narrowing remains at the existing non-empty-string guard. No executable
code, native signatures, dependencies, suppressions or baselines change.

Positive static examples pass an unvalidated tip through all three helpers.
Runtime checks cover null, booleans, integers, floats, arrays and objects through
the same helpers, alongside the existing capability, comparison, installer and
consumed-result checks. Before/after differential rendering verifies identical
markup across 2,592 scenarios (5,184 renders), including pending-result
consumption and capability-denied requests. Executable-token comparison also
confirms the source change is documentation only. These are local stub checks, not browser or real-WordPress verification.
The twenty negative fixture cases and 23 exact diagnostics remain unchanged.

## Update-policy callbacks increment — 2026-10-15

Add only `updates.php` at level 6 (fifteen modules). The four core/translation
callbacks already pass with their existing boolean parameter and return PHPDocs.
No runtime source, native signature, dependency, suppression or baseline changes;
PHP 7.4 and the 768M/512M analysis/fixture caps remain unchanged.

Positive examples feed a boolean through each callback into a boolean consumer.
Eight negative examples reject an array decision input and a version-string
return consumer for each callback. The runner checks 28 invalid cases with
31 exact diagnostics. These examples test static contracts, not runtime coercion
or policy selection. Existing `tests/core-update-policy.php` covers inherit,
minor/all/manual decisions, development-build refusal and translation toggles.
The full standalone suite remains required; no real-site verification is claimed.

## Lifecycle increment — 2026-10-15

Add only `lifecycle.php` at level 6 (sixteen modules). Four initial diagnostics
came from the current data-version literal narrowing the migration comparison,
and the WP_Site annotation making three defensive new-site checks appear redundant.
The public hook input is now documented as mixed; normal WordPress calls still
supply WP_Site. The existing object/property/ID checks remain intact.

`dynamicConstantNames` now includes KEEL_DEFAULTS_DATA_VERSION, widening its
literal to int during analysis. This is an evolving migration target, not a
permanent invariant of 1: the version-specific `$version < 1` guard must remain
when later migrations advance the target. Its runtime value remains 1, and no
executable tokens, native signatures, dependencies, baselines or suppressions change.
PHP 7.4 and the 768M/512M analysis/fixture caps remain unchanged.

Positive fixtures cover raw new-site inputs, boolean activation and lifecycle
results, and the existing array<string,mixed> seed map. Five negative examples
reject array activation flags and incorrect seed-map/boolean result consumers.
The runner checks 33 invalid cases with 36 exact diagnostics.

The multisite harness now models the context stack instead of always restoring
site 1. Checks cover explicit revision keys (including null), malformed stores,
current/future version no-ops, invalid hook inputs, exact-page network activation,
numeric-string IDs and restoration to a non-main originating site. Existing
250-site paging, reactivation and per-site/network activation cases remain.
The strengthened harness passes against both original and updated source; runtime
source token comparison is identical. These are local stub checks, not real-site
or browser verification. Full standalone/packaging checks remain required.

## Email increment — 2026-10-15

Add only `email.php` at level 6 (seventeen modules). The three initial diagnostics
require void return annotations on two notice renderers and a value type for
suppressed mail arguments. Use array<array-key,mixed> for the message and observer
payload: extension keys, numeric keys and arbitrary values remain allowed. The
ignored prior pre_wp_mail result is documented as mixed, preserving filter-chain
flexibility; no runtime validation, casts or native signatures are introduced.

Positive static examples cover open maps, empty payloads, recipient arrays,
extension objects and arbitrary prior filter results, plus text/boolean consumers
and notice calls. Four negative examples reject serialized message arguments and
incorrect boolean/text consumers. The runner checks 37 cases / 40 exact diagnostics.
Runtime tests preserve payload keys, values and object identity at the observer,
ignore prior results as before and retain raw suppression-filter boolean coercion.

Existing mail-failure and nonproduction-mail harnesses cover environment gates,
From filtering, custom recommendations, reset detection, suppression and notices.
Before/after capture verifies seven byte-identical notice outputs; executable PHP tokens are
unchanged. PHP 7.4, dependencies, baselines, suppressions and the 768M/512M caps
remain unchanged. These are local stub checks, not real-site or browser validation.

## Asset-provider increment — 2026-10-15

Add only `assets.php` at level 6 (eighteen modules). The initial finding is the
registry return array's unspecified value type. Public registration now documents
callable():mixed: providers are invoked without arguments, and existing code casts
and trims results rather than enforcing string-only returns. The lower-level
registry accepts mixed and returns array<array-key,mixed>, honestly describing
raw entries that are checked by the existing is_callable guard before invocation.
No validation, casts, native signatures or executable tokens change.

Positive fixtures cover zero-argument providers, empty strings, numeric results,
raw registry entries and unknown contexts. Three negative cases reject non-callable
public providers, providers requiring arguments and a registry consumed as CSS.
The runner checks 40 rejected cases / 43 exact diagnostics.

The standalone asset-provider harness checks context hook order/priority, named
string deduplication, repeated closures, invokable objects, array callbacks,
unknown contexts, null reads, invalid raw entries, registration/enqueue before
attachment, no-source handles, trimming, empty-result omission, numeric zero and
exact CSS order/text across admin, login and front contexts. It passes original
and updated source with identical captured CSS; executable tokens also match.
These are local stub checks, not browser or real-WordPress verification. Existing
integration assertions remain available separately. PHP 7.4, dependencies,
baselines, suppressions and 768M/512M caps remain unchanged.

## Remaining work

Choose the next remaining module boundary explicitly before expanding analysis. Model actual validation
before tightening raw offer/transient fields; keep unknown blocker codes possible
at general boundaries. Explore higher levels with explicit findings rather than
broad exclusions. Add native types only after reviewing WordPress hook input
coercion and PHP 7.4 compatibility. Do not replace WordPress arrays with a class
hierarchy solely to satisfy the analyzer.

Research of a PHP 8.x minimum is tracked separately in ROADMAP.md and TODO.md:
measure runtime gains, quantify removable code/tooling costs, establish intended
user and hosting compatibility, compare support lifetimes, and assess migration
impact before proposing a new floor. This pilot does not authorize that change.

References: [PHPStan PHPDoc types](https://phpstan.org/writing-php-code/phpdoc-types),
[configuration](https://phpstan.org/config-reference),
[WordPress stubs](https://github.com/php-stubs/wordpress-stubs), and
[PHP support schedule](https://www.php.net/supported-versions.php).
