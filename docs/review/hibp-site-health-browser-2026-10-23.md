# HIBP Site Health browser verification — 2026-10-23

Completed on the branch following #317, merged as `c2c967c`. The bounded HIBP runtime from #316 remains unchanged. No production defect was exposed, and no runtime, setting, version or release change is included.

## Environment and scope

Real WordPress 7.1.3 / PHP 8.4.27 / SQLite, disposable standalone and multisite Studio labs, symlinked to this checkout. Only Keel is active; Akismet and Hello Dolly are inactive. An explicitly enabled localhost MU fixture blocks external WordPress HTTP, supplies synthetic configuration, and instruments HIBP requests and fixed-budget SQL writes. Evidence is seeded into the actual canonical database row before browser navigation; the runtime evidence reader and renderer are not mocked.

Playwright 1.64.1 and axe-core 4.14.0: Chromium 156.0.8078.4, Firefox 157.0 and Playwright WebKit 27.2. All views use English. This is synthetic UI evidence, not proof of real password checks, successful HIBP responses or current provider availability.

## Results

[Sanitized machine-readable evidence](evidence/hibp-site-health-browser-2026-10-23.json) retains all 18 site/state reports.

| Synthetic state | Manager presentation | Core result group |
| --- | --- | --- |
| No stored evidence | No sampled failures; explicitly does not establish that screening ran; no validated live response | Good |
| Local allowance exhausted | Sampled skipped lookup; names local allowance, not a provider outage | Recommended |
| Local slots busy | Sampled skipped lookup; names local slots, not a provider outage | Recommended |
| HTTP 429 sample | Sampled unavailable lookup with bounded HTTP reason | Recommended |
| Sample followed by live success | Both retained sample and later historical success; no universal recovery claim | Recommended |
| Success without retained sample | Historical transport evidence, not a current availability guarantee | Good |
| Unreadable authority | Coverage unknown; explicitly does not establish a provider outage | Recommended |
| Code opt-out | Deliberate configuration; other local password rules still apply | Good |
| Password Strength off | Explicitly off; keyboard-activated link reaches the visible setting row | Good |

- **81 authorized panel views:** three engines × nine states × standalone administrator, multisite network manager and delegated site administrator.
- **Nine denied views:** logged-out users on both sites and ordinary multisite site administrators, in all three engines. Core normally does not grant Site Health access to a non-super-admin on multisite. The delegated fixture grants only `view_site_health_checks` to exercise Keel's redacted branch; it does not grant network-management authority.
- Delegated administrators receive generic coverage information without shared reasons, timestamps or failure/success ordering. Opt-outs remain visible configuration rather than shared activity.
- Enter and Space update `aria-expanded` correctly. Native Tab/Shift+Tab returns to the disclosure. Core's Passed tests disclosure is opened by keyboard for Good findings. Tests wait for core's asynchronous completion announcement before checking Tab order.
- **324 layout checks:** 320, 390, 782 and 1280px for every authorized view; no page or panel overflow. Visual review confirms readable wrapping and visible disclosure focus. **81 scoped axe scans** of the disclosure and content found no WCAG 2 A/AA or 2.1 AA violations. No page JavaScript errors occurred in the retained runs.
- **Nine settings-link checks:** Enter activation reaches the Password Strength row in all engines and authorized contexts. The zero-height fragment anchor is intentional; the assertion checks its visible row.
- **1,011 instrumented requests in the retained matrix:** zero HIBP HTTP attempts, zero HIBP authority writes and unchanged canonical-row digests throughout each state. Viewing does not initialize, refill or sample the budget.

Representative narrow-screen captures, containing synthetic evidence:

- [Standalone sample plus later success](evidence/hibp-site-health-later-success-320.png).
- [Delegated administrator with shared details withheld](evidence/hibp-site-health-delegated-admin-320.png).

The unrelated REST, loopback and WordPress.org failures visible in the lab captures follow from the fixture's HTTP block. They are not HIBP findings or a claim that the whole Site Health screen is green.

## Reproduction and cleanup

Use an explicitly disposable localhost Studio site and its `wp_cli` tool. A temporary MU wrapper defines `KEEL_HIBP_BROWSER_LAB=true` and requires `tests/integration/fixtures/hibp-site-health.php` from the checkout. The guard refuses other configured hosts. The browser harness requires the generated local configuration file and accepts only loopback URLs.

1. Run `eval-file /absolute/checkout/tests/integration/hibp-site-health-seed.php CASE` through Studio, using one of the nine allowlisted cases above (`none`, `exhausted`, `busy`, `transport`, `later_success`, `success`, `unavailable`, `optout`, `setting_off`). It saves the original authority before the first seed and creates only the named synthetic administrator.
2. With Playwright and axe-core available through `NODE_PATH`, run `node tests/integration/hibp-site-health-browser.mjs LOCAL_URL LOCAL_SITE_PATH`. The runtime guards stay active; browser tests neither submit settings nor perform a password change.
3. Through Studio, run `eval-file /absolute/checkout/tests/integration/hibp-site-health-cleanup.php`, remove only the `hibp_browser_admin` fixture user, and remove the temporary MU wrapper.

Cleanup was executed and verified on both labs: exact original authority restored, synthetic users deleted, wrapper and fixture-data files removed. The multisite lab was stopped; the previously running standalone lab remains running. No production site was accessed or changed.

PR review corrected the cleanup helper to recreate a saved authority when the final `none` case has deleted its row. A separate real WordPress/SQLite regression check passed restoration of an existing snapshot after deletion and restoration of original absence; the lab's original authority was restored afterward. This changes only the lab helper, not the HIBP runtime or browser results above.

## Gates and limits

PHP coding standards and all standalone regression scripts pass. The browser harness and all PHP fixtures pass syntax checks. Runtime files, assets and version metadata match #317 exactly.

Manual screen-reader speech, native Safari, translations/RTL, browser zoom and a WordPress-version browser matrix were not tested. Playwright WebKit is not a native Safari sign-off. These focused scans do not establish whole-screen WCAG compliance. The separate concurrency/storage proof remains in the [bounded-budget record](bounded-hibp-budget-2026-10-23.md).
