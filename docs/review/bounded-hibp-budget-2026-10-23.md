# Bounded HIBP budget verification — 2026-10-23

Status: implemented and verified in [PR #316](https://github.com/dknauss/Keel/pull/316); unreleased. No version bump or release. #313 (`fb121e0`) and #314 (`1d754a9`) are merged and unreleased. AX port is merged as commit `f628cb8d668c581beb43cffa26620783f384657a`; [#315](https://github.com/dknauss/Keel/issues/315) is closed.

## Implemented policy

Fresh lookups share one database bucket per standalone site or current network's main-site options table: burst 10, one credit per 60 seconds, two 30-second admission leases and one lease per cache slot. Cache hits precede admission. Three failed CAS attempts return unknown; no polling, queue or refunds. The existing password adapter still fails open on unknown; local rules, exempt-role ordering, opt-outs, padding, timeout and transport-limit filters remain intact.

The 64 direct-mapped range slots treat a matching validated body as fresh for 12 hours from receipt. Expiry stops reuse; a fixed row may physically
retain its stale body until replacement or uninstall. Fixed limits are 128 KiB raw body, 129 KiB encoded slot and 4 KiB control state. At most 65 non-autoloaded rows exist per scope. The raw-body bound is 8 MiB; total encoded values are at most 8 MiB + 68 KiB. These are payload bounds, excluding database indexes/pages, PHP memory and pre-upgrade legacy entries. Larger operator-authorized transport responses can produce a live verdict without retention.

`keel_hibp_budget_policy` accepts exactly integer `capacity` (1–100) and `interval` (1–3600 seconds); invalid configurations use defaults. Capacity-only changes retain fractional credit and clamp; interval changes convert only whole credits. Policy changes cannot reset leases, samples or namespaces. Invalid existing authority never becomes a new full bucket. Cache eviction cannot replenish the budget. Unknown database adapters, non-InnoDB MySQL tables and caller-owned transactions deny fresh work conservatively.

Slot claims and publication hold a short transaction and fence the control row by byte-exact CAS. No lock spans HTTP. Lost workers cannot release or publish over a newer owner. Connection-state checks preserve another component's transaction: MySQL-compatible BEGIN, including WordPress's SQLite translator, can otherwise implicitly commit it. Fenced MySQL statements use the original pinned native connection, preventing
wpdb reconnect/replay after loss of the transaction lock. SQL failure latches
rollback-only behavior, including deadlock-induced transaction rollback. A local
real-wpdb/MariaDB reproduction changed from accepting/caching a result after KILL
CONNECTION to returning unknown without caching after this fix.

Leases bound valid admissions, **not physical sockets** after arbitrary process suspension or an HTTP transport that ignores timeouts.

Diagnostics contain bounded reasons and timestamps, sampled at most once per scope per five minutes across reason changes and successes. They are not denial counts. Failure samples display for one hour; successful live responses are separate historical evidence. Cache hits do not establish recovery. Network managers see shared details; site administrators get generic coverage information. No posture-export fields were added.

## Real WordPress SQLite lab

[Sanitized independent-worker report](evidence/hibp-budget-sqlite-2026-10-23.json). WordPress 7.1.3, PHP 8.4.27, WordPress SQLite adapter; standalone, no persistent cache. All 15 cases passed:

- Byte-exact CAS, transaction support and caller-write rollback preservation.
- Barrier-synchronized initialization, last credit, two-lease and same-slot races.
- Exactly ten uncontended admissions, eleventh denied; per-admission and aggregate credit conservation.
- Old workers resumed after reservation or HTTP, and direct stale claims, cannot alter replacement slots or release replacement leases. A stale live reply remains unknown and samples unavailability without recording success.
- SIGKILL after reservation/HTTP does not refund credit; expired leases permit recovery. SIGKILL while a publication transaction holds the control row rolls it back; a separate reader sees only committed state.
- Cached breached matches survive exhaustion and cache flush; 100 denied lookups cause no HTTP and at most one sampled diagnostic write. Passive evidence and ordinary bootstrap do no HIBP writes/HTTP.
- 2,048 distinct prefixes populate exactly 64 slots plus state. Non-autoloaded rows and encoded caps hold, including JSON expansion past the encoded cap and valid live bodies over the retention cap.

A cached hit deliberately costs **one authoritative DB query**. In this local run, 100 cached lookups took 49.009 ms total (0.490 ms/lookup), with zero writes or HTTP. One hundred exhausted lookups with alternating diagnostic reasons took 230.589 ms total (2.306 ms/lookup), four reads each and one total diagnostic write. Timings exclude WordPress bootstrap/process startup and use synthetic HTTP; they are local observations, not production latency guarantees. Ordinary bootstrap added zero HIBP queries, writes or HTTP. Whole-worker peak memory includes WordPress and is not an incremental cache measurement.

## MySQL/MariaDB, multisite and persistent cache

[Database matrix run](https://github.com/dknauss/Keel/actions/runs/39998358729), runtime commit `6201731`, and [durable sanitized matrix summary](evidence/hibp-budget-database-matrix-2026-10-23.json): all four cells passed **17 cases each**, plus two additional cases after site-only activation on a child site.

| Database | WordPress / PHP | Main suite | Child site-only |
| --- | --- | --- | --- |
| MySQL 8 | 6.4 / 7.4 | 17 passed | 2 passed |
| MySQL 8 | 7.1.3 / 8.3 | 17 passed | 2 passed |
| MariaDB 10.11 | 6.4 / 7.4 | 17 passed | 2 passed |
| MariaDB 10.11 | 7.1.3 / 8.3 | 17 passed | 2 passed |

Every cell used Redis, two networks with distinct canonical main-site options tables, and independent PHP processes. Besides the SQLite cases, the CI suite proves actual full uninstall with all 65 fixed rows in each network hidden behind stale option-miss caches, preservation of a caller-owned savepoint, autocommit-off denial without authority writes/HTTP, and bounded expected-probe error records.

The connection-loss case kills the publishing worker's MySQL connection while its transaction holds the control-row lock, expires the old lease, admits a replacement, then resumes the old worker. It must return failure without altering the replacement slot, lease, credit or success evidence. This passed on all four cells. A separate SIGKILL case proves normal transaction rollback on process death. The post-case cleanup field describes fixed-row reset; full uninstall is a distinct passed case.

Cached hits still cost one DB read, no writes and no HTTP. Across the four CI cells, cached-hit batches averaged 0.161–0.277 ms/lookup. Exhausted batches with alternating diagnostic reasons averaged 0.517–1.159 ms/lookup, 4.04 queries/lookup including the one sampled diagnostic write, and zero HTTP. Denials avoid transaction/engine probes unless they need a diagnostic write. These remain isolated synthetic-transport observations, not production workload guarantees.

Early database runs exposed fixture setup errors before runtime tests: a newly inserted network inherited core's legacy upload-path default, and updating a missing option could not override its default-value filter. These are lab fixture failures, not passing backend evidence. The fixture was verified locally. A subsequent run exposed the unavailable `mysqli`
connection-status property: fresh admissions conservatively failed. It was
replaced by an autocommit/private-savepoint check, reproduced against local
MariaDB with the real WordPress database adapter, before the passing full lab run. Fenced native connection pinning then closed the separately reproduced reconnect/replay gap.

## Regression gates and remaining limits

All 81 standalone test scripts pass, along with WPCS, PHP compatibility, syntax lint, the scoped PHPStan pilot and 43 invalid static-contract cases. The ordinary PR CI passed PHP 7.4–8.5; [runtime-head CI](https://github.com/dknauss/Keel/actions/runs/39998358766) is green. Catalogs were regenerated; the setting count remains 44 and version remains 0.8.0.

The independent review's lost-lease diagnostic gap and capacity-only fractional-credit loss were fixed and covered. Uninstall now deletes the 65 known names directly after API cleanup, because a stale option-miss cache can otherwise hide rows created by SQL. Legacy prefix entries are neither scanned nor migrated on password requests; existing TTL/uninstall cleanup remains. Deactivation retains the fixed authority. The AX port is merged and #316 records its counterpart commit; the former pending-port release-sweep gate is resolved. Neither implementation is released by this work.

## Two-network SQLite scope check

[Sanitized network-scope report](evidence/hibp-budget-sqlite-multisite-2026-10-23.json): real WordPress, two distinct networks and main-site tables, plus child sites. Adapter/caller-transaction, canonical scope, independent network allowance, cache flush, cached-positive exhaustion and passive-path assertions all passed. This local check used network activation; site-only activation is an additional CI case.

## Pre-merge review

Review found a fail-open gap in capability discovery: a throwing SQLite driver accessor or MySQL table-metadata query could escape the lookup and interrupt a password change. Both capability helpers now catch backend exceptions, return unavailable and restore the prior DB error-suppression state in `finally`. Connection checks also reject a changed mysqli handle before using its status. Regression cases failed with uncaught exceptions before the fix and pass afterwards, covering no HTTP/no authority creation and both prior suppression states. The existing full CI/database matrix remains the merge gate. No version or setting-count change.
