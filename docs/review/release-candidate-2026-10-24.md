# Release-candidate verification — 2026-10-24

Scope: `main` at `24700af` (#327 merged), the unreleased update-operations and
support work since 0.8.0. The version string is still 0.8.0 and the schema still
has 44 individually configurable settings. This pass measures and checks; it
changes no runtime source, bumps no version and authorizes no release.

## Correctness and static gates

Run in a cloud container on PHP 8.3.6 against the merged tree:

- All 85 standalone suites and PHP syntax lint (182 files) pass.
- WPCS and PHP compatibility (`phpcs.xml`, `phpcompat.xml`) report nothing.
- Scoped PHPStan 2.1.56 reports no errors; static contracts reject 43 invalid
  cases with 46 diagnostics.
- ESLint and Stylelint pass with zero warnings; `npm audit --omit=dev
  --audit-level=high` finds nothing.

`composer install` cannot download zipballs in that container, so WPCS and
PHPCompatibility were resolved from source outside the checkout, and PHPStan ran
from its tagged phar with the locked WordPress stubs. CI remains the gate of
record.

## Performance against 0.8.0

The [performance budget](performance-budget.md) method, repeated on a disposable
WordPress 7.1.3 single site, PHP 8.3.6 with OPcache, MariaDB 10.11, Twenty
Twenty-Five, 40 generated posts, default object cache. `git archive` staged
`v0.8.0` and `origin/main` in separate plugin directories after one activation
of `main` seeded the defaults. A local-only MU harness selected no Keel, 0.8.0 or
`main` per request, supplied a real administrator session cookie held only in a
scratch file, blocked every outbound HTTP call with a deterministic `WP_Error`
and counted the attempts. Cron was disabled. Five warm-up rounds, then 30
measured rounds per route with the three variants in seeded shuffled order;
every response had to be HTTP 200 with Keel markup present.

Server milliseconds, median / nearest-rank p95; queries are medians.

| Route | No Keel | 0.8.0 | main | Queries: none / 0.8.0 / main |
| --- | ---: | ---: | ---: | --- |
| Front page | 59.45 / 81.42 | 64.31 / 86.69 | 63.82 / 81.30 | 22 / 22 / 22 |
| Dashboard | 59.45 / 75.25 | 57.89 / 78.65 | 56.18 / 74.18 | 42 / 34 / 34 |
| Keel settings | — | 50.56 / 70.11 | 51.16 / 69.59 | — / 21 / 21 |
| Updates | 50.80 / 67.35 | 53.01 / 62.88 | 52.65 / 66.91 | 26 / 24 / 26 |
| Site Health status | 50.03 / 67.75 | 58.11 / 74.30 | 59.73 / 74.68 | 28 / 28 / 32 |
| Site Health info | 57.78 / 80.53 | 60.56 / 76.53 | 60.44 / 85.34 | 30 / 25 / 25 |
| Site Health, diagnostics deep link | 52.01 / 70.91 | 55.87 / 74.45 | 57.51 / 75.05 | 28 / 28 / 32 |
| Posts list | 77.54 / 104.54 | 77.45 / 101.15 | 75.91 / 93.23 | 41 / 36 / 36 |
| Plugins | 58.70 / 70.14 | 59.99 / 82.32 | 63.73 / 74.29 | 29 / 26 / 26 |

Every row allocated 8 MiB peak. That run was noisier than an earlier identical
pass (front page 47.97 / 49.18 / 49.06 ms), so treat absolute values as
environment-specific. In the table, Plugins was the only median over the budget's
max(2 ms, 5%) regression threshold (+3.74 ms). Two confirmation runs of front
page, Plugins and Site Health status put Plugins at −1.13 and −1.00 ms against
0.8.0, so the breach did not reproduce. In those runs the bare front-page cost
over no Keel was 1.81 and 1.79 ms; the budget allows 5.

**Queries and outbound traffic.** The front page adds no queries, and its markup
is byte-identical between 0.8.0 and `main`; no new frontend asset loads. With
SAVEQUERIES on, the differences are reads from new features, with no writes:

- Updates, +2: `auto_update_plugins` and `auto_update_themes`, read by the
  extension auto-update policy (#295).
- Site Health status and the diagnostics deep link, +4 net: the stable-check
  cache and its failure marker, the cron report, the two auto-update options and
  the bounded HIBP state row now read on Site Health (#316, #322). Two earlier
  HIBP option reads are gone.

On warm requests Keel attempted no outbound HTTP. One measured Updates sample
attempted four requests with 30 queries. Backdating the update transients
reproduced it with Keel inactive: core re-checks plugins and themes from
`load-update-core.php` once a minute. With its caches cleared, the stable-check
fetch is one blocked request per cold load on both 0.8.0 and `main`, and the
failure marker then suppresses repeats.

**Crowded diagnostics.** Forty synthetic active plugins each registered a
passthrough callback on five authoritative hooks Keel governs (`xmlrpc_enabled`,
`comments_open`, `pings_open`, `get_comments_number`, `wp_revisions_to_keep`).
One forced `keel_defaults_policy_overlap_report( true )` per request, two
warm-up pairs, then ten measured pairs: 0.8.0 3.43 ms median, `main` 3.38 ms
(max 5.52), both 200 rivals on five hooks, zero queries. A repeated ordinary read
reused the request-local result in about 1 µs. The budget is under 20 ms.

**Shipped payload.** The source files that go into the package grew from 744,858
to 895,589 bytes. PHP grew 21%, from 558,765 to 676,442, all in new feature
modules: abilities authorization, extension updates, list-table columns, the
HIBP budget and store, posture export, registration audit, update disclosure,
policy notice and timeline. Four browser assets are new: update-evidence CSS/JS
(Updates and Site Health only, which the accessibility lab asserts) and two
list-table stylesheets, enqueued only on post-type list screens when Admin List
Tables is not Leave unchanged. No runtime dependency was added.

Not measured, as before: persistent object caches, production MySQL tuning,
multisite latency, real HIBP or update-API latency, browser paint and
throughput.

## Accessibility in CI

The Chromium leg of the focused accessibility regression now runs on every pull
request; see [its record](focused-accessibility-2026-10-23.md#continuous-integration--2026-10-24).
Firefox, WebKit, manual screen-reader speech and native Safari keyboard checks
remain manual pre-release passes.

## Remaining before a release

- A Firefox and WebKit pass of `focused-accessibility.mjs` on a machine that has
  those engines, plus the documented manual VoiceOver and Safari keyboard checks.
- The six-row live installer matrix on the release candidate commit.
- The maintainer's WordPress.com settings-save confirmation, still open.
- Version bump, changelog, readme upgrade notice and counterpart sweep, under the
  normal release path.

Counterpart: not applicable — measurements, a test harness option and a CI
workflow; no shared runtime code changed.
