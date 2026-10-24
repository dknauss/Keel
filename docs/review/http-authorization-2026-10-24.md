# Real WordPress HTTP authorization — 2026-10-24

Baseline: #325 merged as `1d858a0`, unreleased. No version or release changes.

## Environment and method

Two pre-existing, running disposable Studio labs: localhost:8889 (single-site,
Keel active as `keel-defaults`) and localhost:8890 (multisite, Keel network active
as `keel`). Both use WordPress 7.1.3, PHP 8.4 and Studio SQLite. Reflection
confirmed the executing settings sanitizer resolves to this checkout.

The harness makes HTTP requests through WordPress admin-post, admin-ajax,
options.php and Network Settings using Playwright's cookie-aware request client.
Studio auto-login establishes the existing owner's session; a temporary local
MU fixture issues real WordPress auth cookies for newly created users. Core
validates these cookies and session-bound nonces on subsequent requests. This
does not test credential login or the password/authentication form.

Actors: subscriber, editor, site administrator, a synthetic delegated site
administrator and the existing owner (super administrator on multisite).
The fixture explicitly maps `update_core` to `read` for the delegated test user,
while leaving `manage_network_options` denied. This makes the #325 boundary
observable despite core normally denying site admins `update_core` on multisite.
Resolved roles and capabilities are recorded without user IDs, cookies or nonces.

The fixture disables cron spawning and automatic updating, blocks downloads and
outbound HTTP, and backs up the three settings under test. These guards intercept
transport; they do not override the tested handler or nonce implementation.

## Results

The [initial results](http-authorization-results-2026-10-24.json) are preserved.
The [review rerun](http-authorization-results-2026-10-24-review.json) passed 135
cases across ten actor/site combinations, including 127 HTTP denials and zero
HTTP 500 responses. No authentication tokens or settings payloads are retained.

- Missing/invalid nonce, a nonce from another user's session, insufficient
  capabilities, anonymous requests and multisite delegation are rejected by the
  real request path. Review found that two Keel permission denials defaulted to
  HTTP 500. Both handlers now explicitly return 403; the harness requires exact
  statuses rather than accepting a range of alternatives.
- Posture export rejects GET with 405 and unauthorized/invalid-nonce POST with
  403. Authorized JSON carries `no-store` and `nosniff` headers.
- Backport/autoload/cron AJAX endpoints reject invalid nonces and unauthorized
  valid-nonce requests. No successful collector run is claimed.
- Denied requests preserve the actual minor-update, site-settings and
  network-settings values. This observation covers those protected values; it
  does not claim every incidental WordPress cache or session row is unchanged.
- Positive controls verify the authorized minor-update request redirects and
  persists `enabled`. Actual settings-form submissions with nested numeric/range
  values persist safe defaults (14 days and the `default` menu-width mode), on
  both the site and network save paths. Single-site nested exemption data is
  discarded. Form group and nonce come from the rendered form.
- HTTP response bodies contain no PHP fatal/conversion warnings in these cases.
  The initial admin-page smoke check reports no JavaScript page errors.

Review corrected the initial conclusion that no runtime defect was exposed:
the initial lab accepted HTTP 500 for permission denials, masking the response
status defect described above. The new standalone status assertion failed before
the fix and passes after it; the stricter HTTP rerun also passes. Network Admin
can reject a request before Keel's save callback, so the direct callback assertion
separately verifies its status code.

Harness assumptions failed during setup:
`DISALLOW_FILE_MODS` also denies `update_core`, so it was replaced by download and
automatic-updater guards; the initial assumed options group was wrong, so the
harness now reads the real form. A stricter capability assertion also confirmed
that core denies `view_site_health_checks` to the delegated multisite site admin.
Failed setup runs were not counted as passing.

## Runner-independent automation

The passes above signed the owner in through Studio's `/studio-auto-login`
route, so they could only run on Studio. A follow-up removed that dependency:
setup now also creates a `keel_http_owner` administrator (a super administrator
on multisite) whose password comes from `KEEL_HTTP_LAB_PASSWORD`, and the
harness signs in through the real `wp-login.php` form. Cleanup revokes that
account's super-admin grant and deletes it with the other fixture users.
`tests/integration/http-authorization-lab.sh` wraps a pass (MU wrapper, setup,
harness, cleanup in an exit trap), and the `HTTP authorization lab` workflow
runs it on every pull request and push to main against two installs built by
`tests/integration/http-authorization-bootstrap.sh`.

Local verification in a cloud container, before the CI job's first run:
WordPress 7.1.3 cloned from its GitHub tag, MariaDB 10.11, PHP 8.3 under
`wp server`, Playwright 1.63.0 Chromium. The single site linked this checkout as
`plugins/keel-defaults` and the network as `plugins/keel`.

- Clean pass: 135 cases, two labs, zero failures. The
  [redacted results](http-authorization-results-2026-10-24-runner-independent.json)
  record the same actors and capabilities as the Studio rerun. Afterwards both
  installs held only their install account, no MU wrapper and no backup option.
- Negative control: restoring the pre-#326 `wp_die()` without a status in
  `includes/backport-actions.php` failed the run at
  `subscriber/minor-bad-nonce: unexpected HTTP 500`. Cleanup still ran and the
  script exited 1.
- Limit: restoring the same defect in `includes/network.php` did not fail the
  HTTP lab, because core's Network Admin rejects those requests before Keel's
  save callback runs. `tests/php-boundaries.php` remains the guard for that
  status code.

## Reproduction and cleanup

Use only disposable localhost installs with this checkout linked and Keel active
(network-active on multisite). Then run:

```sh
NODE_PATH=/path/to/playwright/node_modules bash tests/integration/http-authorization-lab.sh \
  /path/to/single http://127.0.0.1:8889 /path/to/multi http://127.0.0.1:8890 /path/to/new-results.json
```

The script adds a temporary MU wrapper defining `KEEL_HTTP_AUTH_LAB` and
requiring `tests/integration/fixtures/http-authorization.php`, then runs
`tests/integration/http-authorization-setup.php` through WP-CLI `eval-file`.
Setup refuses an existing backup or fixture login. Set `KEEL_HTTP_LAB_WP` when
WP-CLI is not plain `wp`. The harness keeps authentication material in memory
and writes only redacted assertions. The output path is opened exclusively
before any lab mutation; an existing artifact causes an immediate failure
rather than overwriting evidence. The runtime source is verified by SHA-256
against this checkout, irrespective of folder casing/name.

On exit, pass or fail, the script runs the same setup PHP through `eval-file`
with the argument `cleanup`, then removes exactly the temporary MU wrapper. Cleanup restores
the saved presence/value of the minor-update option and both Keel settings scopes,
revokes fixture sessions and deletes only the users it created. The successful
minor-update action intentionally invalidates core's cached update offer; that
cache is not restored. Existing owner sessions and unrelated users are preserved.
Both previously running labs remain running. No source link was repointed.
Cleanup verified all three settings against their saved presence/value and
confirmed fixture users were deleted. Subsequent WordPress reads on both labs
confirmed the backup, all four fixture logins and the MU fixture were absent.

No real install, external service delivery, MySQL HTTP matrix, native-browser
interaction or production request is claimed by this pass. Existing database CI
and standalone tests provide separate evidence. The shared lab workflow is
maintained in `dknauss/agent-skills`; Keel retains its fixtures and results here.

## Repository checks

The full standalone suite, WPCS, PHP compatibility, scoped PHPStan, static
contracts, JS/CSS asset lint, JavaScript syntax check, Composer-lockfile audit,
production npm audit and diff-whitespace check pass. PR CI remains a separate
merge check.
