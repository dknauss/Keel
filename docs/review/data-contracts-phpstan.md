# Data-contract and PHPStan pilot

Implemented 2026-10-14, preserving the PHP 7.4 floor. The initial pilot changes
annotations and development tooling only. Its installer/presentation extension
simplifies one equivalent boolean condition; function signatures, hook registration,
storage, filtering and configuration precedence remain unchanged. The subsequent
conflict-diagnostics extension removes one always-true offset check. The notices
extension removes a redundant object check while retaining the screen-ID guard.

## Checked scope

`composer analyse` analyzes `includes/backport-policy.php` and
`includes/settings-policy.php`, plus `includes/backport-install.php` and
`includes/backport-presentation.php`, and the four conflict modules
`conflict-attribution.php`, `conflict-overlap.php`, `conflict-divergence.php` and
`conflict-notices.php`
at PHPStan level 6, with `phpVersion: 70400`.
It scans other includes and keel.php to discover declarations/constants without
executing them. Scanning is not analysis: status and action-handler
bodies outside these eight files are not yet claimed clean. WordPress stubs provide
core declarations; standalone runtime-test doubles are not used as analyzer stubs.

There is no baseline, ignoreErrors list, inline suppression, invented runtime
validation, or inline @var assertion to make analysis green. Level 6 catches
missing iterable value contracts and checks shaped producer/consumer boundaries.
Higher levels are separate work: they expose additional uncertainty around raw
Core offers, possibly-false regex splitting, and correlations between selection
state and selected version. Do not remove defensive branches just because a
normal-data annotation makes them appear unnecessary.

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

- `composer analyse`: all eight pilot modules must pass without suppressed errors.
- `composer test:types`: valid producer-to-consumer and minimal-input fixtures must pass; eleven
  deliberately invalid examples must fail with the expected identifiers: a missing
  update-state key, invalid produced owner, missing diagnostic tier, rollback=false
  plan, invalid stored-result key, unknown return target, invalid health status,
  non-string attribution paths, missing registration field, false divergence marker
  and a notice-screen map incorrectly treated as string values.
  PHPStan analyzes these functions but never executes their WordPress calls. The
  initial contract check failed with the pre-pilot annotations and passed when
  the new annotations were restored, proving the added constraints were exercised.
- Existing coding standards, compatibility scan and standalone behavior suite.
- The package test builds the actual zip and verifies that only runtime files ship.
- The initial pilot preserved all executable tokens. The extension changes only
  one equivalent boolean condition in the verdict renderer. The diagnostic
  extension removes one always-true offset check. The notice extension removes a
  redundant object check while preserving isset on the screen ID; see the records below.
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

## Remaining work

Analyze status/transport and the separate backport action handlers. Model actual validation
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
