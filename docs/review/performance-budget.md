# Performance budget and refactor baseline

Measured 2026-10-14 on a disposable Studio WordPress 7.1.3 site, PHP 8.4.26,
SQLite, default theme. Compared pre-refactor `949cddd` with PR #253 `e730f9f`.
The table uses the final attribution optimization; the crowded workload is
measured separately below. Normal requests also passed before that fix.

## Method

Same site, settings, content and administrator for both versions. Activate Keel
once to seed installation defaults before sampling. Stage both revisions with
`git archive` in separate plugin directories. A local-only MU harness filters
`option_active_plugins` per request to select no Keel, before, or after. It supplies
an administrator cookie in memory only for the two admin routes. The frontend is
anonymous. Disable cron and short-circuit external HTTP with a deterministic
WP_Error for all variants; count attempted calls. This isolates PHP/database work,
not network latency. No page cache or browser rendering measurements.

For each route, run five warm-up rounds then 30 measured rounds, interleaving the
variants in reproducibly shuffled order. Request `/`, `/wp-admin/index.php`, and
`/wp-admin/options-general.php?page=keel`; require HTTP 200 and Keel settings markup.
At shutdown record elapsed PHP time from REQUEST_TIME_FLOAT, $wpdb->num_queries,
memory_get_peak_usage(true), attempted HTTP calls and wp_using_ext_object_cache().
Record client elapsed time separately. Use medians and nearest-rank p95 (29th of
30). Repeat with the SQLite Object Cache 1.6.5 drop-in enabled and disabled; verify
the actual persistent-cache flag. All requests run sequentially.

The first unseeded exploratory run was discarded: its two extra frontend queries
were missing installation options, not a normal activated installation's cost.
Local raw samples and harness were retained under /tmp during the lab; these
numbers are environment-specific, not production latency promises.

## Results

Server milliseconds (median / p95); queries are medians. Every row allocated
10 MiB peak PHP memory; this coarse allocator measure cannot detect smaller changes.

| Cache | Route | No Keel ms | Before ms | After ms | Queries: none / before / after |
| --- | --- | ---: | ---: | ---: | --- |
| Default object cache | front | 82.81 / 90.01 | 83.44 / 99.15 | 83.85 / 93.53 | 25 / 25 / 25 |
| Default object cache | dashboard | 108.01 / 123.34 | 108.58 / 121.44 | 107.29 / 117.78 | 49 / 42 / 42 |
| Default object cache | settings | — | 98.34 / 147.81 | 96.05 / 133.27 | — / 29 / 29 |
| Persistent SQLite object cache | front | 72.85 / 78.09 | 73.43 / 81.02 | 74.27 / 84.28 | 0 / 0 / 0 |
| Persistent SQLite object cache | dashboard | 88.69 / 105.33 | 90.72 / 102.70 | 90.77 / 100.70 | 9 / 9 / 9 |
| Persistent SQLite object cache | settings | — | 83.10 / 93.25 | 82.71 / 97.20 | — / 7 / 7 |

The refactors added less than 1 ms median on every route and no queries. Normal
frontend and settings requests attempted no external HTTP. Dashboard attempted
two core RSS requests with or without Keel; the harness blocked their transport.
Frontend markup was identical in byte count before/after. Settings markup shrank
with shared controls; byte count is not a DOM equivalence check.

## Significant finding and fix

Forty synthetic active rival plugins each register five passthrough callbacks on
governed filters. A forced overlap-report rebuild measured roughly 115 ms before
and 222 ms after the refactors in the exploratory stress run. The symlink identity
fix resolved Keel's identity repeatedly inside the callback loop; each resolution
also rebuilt all active-plugin filesystem roots.

Compute own identity once per report and memoize normalized roots for the current
site/network active-plugin list. Read both options on every call so activation,
deactivation and switch_to_blog do not inherit stale roots. Keep only the most
recent mapping, with no persistent cache or growing per-site cache. Filesystem
retargeting without changing the plugin list within one request is outside this
memo's contract; normal subsequent requests resolve afresh.

A clean follow-up using one forced rebuild per request, two warm-up pairs and ten
measured pairs found **110.18 ms before vs 7.57 ms fixed** (93% less report time).
This is a deliberately crowded diagnostic workload, not a normal page-load claim.
The new regression test fails before memoization and verifies reuse plus immediate
site/network activation and deactivation invalidation. Existing attribution and
non-execution tests remain applicable.

## Budget

Apply these budgets to paired runs in the same environment, separately for each
cache mode. Timing budgets are investigation thresholds, not noisy shared-CI gates.
Use at least 30 samples after five warm-ups; rerun a breach to confirm it.

- Normal frontend: zero additional database queries, writes or outbound requests
  for healthy passive conflict observation; no new frontend JS/CSS for diagnostics.
- Refactor regression: median increase no greater than max(2 ms, 5%); p95 increase
  no greater than max(5 ms, 10%) on each route compared with the accepted revision.
- Bare frontend overhead: median no greater than 5 ms above no-Keel on this fixture.
- Peak allocated memory: no increase above 1 MiB without an explained need; retain
  raw samples if the allocator's 2 MiB steps obscure a smaller change.
- Queries and outbound calls: no increase for a structural refactor. Cold-cache
  feature traffic (stable-check, HIBP) is measured separately, never hidden in a
  warm average. Preserve existing bounded timeouts and negative caching.
- Crowded diagnostics: one forced rebuild under 20 ms median on this 40-plugin,
  200-callback fixture; repeated ordinary report reads reuse the request-local result.
- Shipped payload: no new runtime dependencies; investigate runtime source growth
  above 5% and any new browser asset. Comments/test growth is reviewed separately.

Do not generalize SQLite timings to production MySQL or Redis. This pass does not
measure real HIBP latency, cold external update checks, network multisite latency,
OPcache deployment behavior, frontend paint metrics, or throughput under load.

## Recheck triggers

Repeat this matrix after changes to bootstrap, diagnostics, option resolution,
cache behavior or frontend assets. Run the correctness suite first. Keep benchmark
traffic separate from test/CI work so contention does not distort comparisons.

## Counterpart completion

Port completed 2026-10-14 in AX commit `d62da33`, resolving #254. AX already
resolved its installed identity once per report; the port adds the same bounded
root memo against live site/network option values. Its real WordPress 7.1.3 /
PHP 8.4.26 crowded report measured 160.63 ms median before and 7.57 ms after, with
identical results, zero report queries/foreign callback execution and unchanged
allocated peak memory. A real two-site multisite verified activation/deactivation
and site switching. These are separate counterpart measurements, not a revision
of the Keel baseline above.

## Refactor checkpoint — 2026-10-15

The planned structural splits, measured attribution fix and counterpart port are
complete. The PHPStan pilot now covers nineteen modules through network policy.
Recent annotation increments preserve executable tokens and do not provide new
runtime speed measurements; this checkpoint does not replace the baseline above.
Do not infer full performance coverage from static-analysis coverage.

Pause routine static-analysis expansion in favor of release/boundary verification.
Re-run paired budgets when their triggers apply. Broader real-WP/multisite/browser
checks, separate JS/CSS linting and targeted tooling/comment cleanup remain open.
Cold external traffic, production MySQL/Redis, OPcache, browser paint and throughput
remain unmeasured here; prioritize them only when a release/change needs that evidence.

## Typography investigation — 2026-10-17

The [compatibility pass](typography-compatibility-2026-10-17.md) measures the
candidate core patch against native released core, not against its previous patch
revision. A roughly 40 KB quote-heavy function call adds 2.5–2.6 ms at both 6.4
and 7.1.3. This warrants profiling before adoption. These function benchmarks do
not replace the route budget above, prove a page-budget breach/pass or authorize
a new Keel feature. Plain prose is effectively unchanged; all formatting batches
add zero SQL queries, writes or attempted HTTP calls. Runtime remains unchanged.

### Follow-up — 2026-10-19

The [separate feature plugin](https://github.com/dknauss/wp-texturize-inline-quote-fix/pull/2) and core revision `b290bb5f1b` close the
locale-cache gap and implement the profiling follow-up. Paired WordPress 7.1.3
~40 KB HTML timings are native 2.708 ms, previous patch 4.310 ms, optimized patch
3.252 ms; quote-heavy timings are 6.415 / 8.967 / 7.552 ms. This reduces added
function cost about 66% / 55%; 6.4 confirms the result. The feature renderer
measures essentially the same as optimized core. Zero batch queries/writes/HTTP,
unchanged coarse allocation; route budgets, bootstrap and throughput remain
unmeasured here. The repeated 10,260-row matrix matches all frozen outputs.
Keel's runtime and its accepted route budget are unchanged.
