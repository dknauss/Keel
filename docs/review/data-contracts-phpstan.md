# Data-contract and PHPStan pilot

Implemented 2026-10-14, preserving the PHP 7.4 floor. This increment changes
annotations and development tooling, not runtime statements, function signatures,
hook registration, storage, filtering, or configuration precedence.

## Checked scope

`composer analyse` analyzes `includes/backport-policy.php` and
`includes/settings-policy.php` at PHPStan level 6, with `phpVersion: 70400`.
It scans other includes and keel.php to discover declarations/constants without
executing them. Scanning is not analysis: installer, presentation and diagnostic
bodies are not yet claimed clean. WordPress stubs provide core declarations;
standalone runtime-test doubles are not used as analyzer stubs.

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
when expanding analysis. Both new dev dependencies support the existing PHP floor.

## Verification

- `composer analyse`: both pilot modules must pass without suppressed errors.
- `composer test:types`: a valid producer-to-consumer fixture must pass; four
  deliberately invalid examples must fail with the expected identifiers: a missing
  update-state key, invalid owner, missing diagnostic tier and rollback=false plan.
  PHPStan analyzes these functions but never executes their WordPress calls. The
  contract check fails with the parent annotations and passes when the new
  annotations are restored, proving the added constraints are exercised.
- Existing coding standards, compatibility scan and standalone behavior suite.
- The package test builds the actual zip and verifies that only runtime files ship.
- Executable-token comparison against the parent revision ignores comments and
  whitespace and verifies every shipped PHP file is unchanged. No fresh site load
  or timing claim is needed for a change with identical executable tokens.
- CI runs analysis and contract checks once in the existing lint/unit job, not in
  each dependency-free PHP compatibility job. The release workflow mirrors these
  gates; the existing release-workflow regression test enforces that parity.

## Remaining work

Analyze the installer and presentation bodies as the next update-policy increment,
then diagnostic attribution/overlap/divergence separately. Model actual validation
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
