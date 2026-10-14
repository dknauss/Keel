# Settings policy extraction lab

Verified 2026-10-14 on disposable local WordPress 7.1.3, PHP 8.4.26, SQLite.
Compared merged #253 (`5c92ba6`) with the settings-policy extraction. All four
moved function bodies and signatures matched their pre-extraction hashes.

## Behavior

- Single-site real HTTP responses: before/after policy values, lock messages and
  rendered setting controls matched across nine states: default, core all/manual/
  minor, background updates disabled, file modifications disabled, both disabling
  constants together, revisions zero, and unfiltered HTML disabled.
- Assertions checked expected constant values, not just equality. Background
  disabling takes precedence over core all; AUTOMATIC_UPDATER_DISABLED is named
  when both disabling constants apply. Hidden stored values and visible locked
  controls retain matching attributes.
- Converted the disposable site into a real subdirectory multisite and created
  a second site. Checked immediate site/network option changes in one request,
  network override on both sites, switch_to_blog/restore_current_blog behavior,
  restoration of saved site preferences after removing the network lock, and
  that network policy does not overwrite stored site preferences.
- Repeated all nine HTTP constant states on both the site settings and network
  policy screens, with a real network override. Both versions returned HTTP 200
  and matching controls/values/lock notes. Constants still take priority in the
  controls when network policy is present.
- The new loading test exposes all four helpers independently without option
  reads, translations or screen hook registration. Existing standalone policy,
  render, network save, sanitization and screenshot-guard suites pass.

## Performance budget recheck

Same single-site setup and method as [the performance budget](performance-budget.md),
using merged #253 as before and the extraction as after. Five warm-up rounds and
30 measured rounds per route/variant, interleaved. Persistent cache was SQLite
Object Cache 1.6.5; the default-cache pass disabled its drop-in and confirmed that
persistent caching was off. Measurements preceded multisite conversion.

| Cache | Route | Before median / p95 ms | After median / p95 ms | Queries before / after |
| --- | --- | ---: | ---: | ---: |
| Default | front | 84.50 / 91.09 | 84.78 / 99.56 | 25 / 25 |
| Default | dashboard | 113.86 / 132.78 | 112.86 / 133.45 | 42 / 42 |
| Default | settings | 101.90 / 119.38 | 101.16 / 120.71 | 29 / 29 |
| Persistent | front | 69.83 / 76.38 | 69.04 / 76.31 | 0 / 0 |
| Persistent | dashboard | 87.98 / 96.48 | 89.74 / 96.58 | 9 / 9 |
| Persistent | settings | 94.55 / 105.75 | 93.00 / 104.83 | 7 / 7 |

All median and p95 regression budgets pass. Peak allocated PHP memory remains
10 MiB in all variants; no additional HTTP calls or queries. Frontend markup byte
count is unchanged. Default-cache frontend overhead is 1.22 ms vs no Keel;
persistent-cache overhead is 0.45 ms. No new frontend assets or runtime dependency.

## Scope and cleanup

These are real WordPress HTTP and option operations on disposable local sites,
not a production MySQL/Redis profile or a live WordPress.com compatibility test.
Control attributes were parsed from served HTML; no new visual browser/screenshot
review is claimed. UI click/save handlers were not moved; existing tests retain
nonce, capability and sanitization coverage. External HTTP was blocked during
measurement. No production site changed. Lab removed after verification.
