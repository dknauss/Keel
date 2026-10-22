# Runtime registration audit — 2026-10-22

Development work after merged, unreleased #296 (`ab299e8`). No version bump,
release, tag or deployment. The 44-setting schema and defaults are unchanged.

Site Health inspects five registrations: comment feeds, author feeds, author
archives, attachment pages, and the session-length clamp. Bootstrap records actual
callback identities (including the attachment closure) and declared priorities in
memory. Intentionally unregistered policies are inactive, not missing.

Redirect precedence uses the earliest live core canonical callback; the session
clamp must follow priority 10. Zero is a valid priority, ties lose, and harmless
reprioritization passes. Missing comparators are explicit. The audit does not prove
outcomes, assign blame, or cover unrelated hooks, routes or requests.

Only the two known hook arrays are inspected, bounded to 1,000 priority buckets
and 1,000 entries each. Malformed or oversized registries yield unknown coverage.
No callbacks, policy filters, HTTP requests, option reads/writes, repair actions,
reflection or scans are executed by inspection. No evidence is persisted.
A switched blog cannot reuse another site's bootstrap baseline; coverage is
unknown until a fresh request establishes that site's registrations.

## Verification

- Standalone boundary and static hook-precedence tests pass. Regression tests cover
  removed callbacks, safe/unsafe priorities, priority zero, duplicate clamps,
  missing comparators, inactive policies, malformed/oversized registries and access.
- Real WordPress 7.1.3/PHP 8.4 SQLite multisite probe passes on disposable Studio
  keel-test. Throwing sentinels guard callbacks, policy filters, HTTP and options;
  hook snapshots verify no repair or mutation. A second site verifies context scope.
- Native Site Health output inspected in the browser, including 390px layout;
  no page horizontal overflow.
- Full standalone suite, WPCS, PHP compatibility, scoped PHPStan, static contracts
  and syntax checks pass. Independent review found one traversal-bound gap;
  fixed with a failing-first regression covering empty priority buckets.
- The real WordPress probe is included in every live installer matrix row.
