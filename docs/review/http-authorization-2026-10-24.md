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

See [machine-readable results](http-authorization-results-2026-10-24.json).
The final run passed 135 cases across ten actor/site combinations, including
127 HTTP denials. No authentication tokens or settings payloads are retained.

- Missing/invalid nonce, a nonce from another user's session, insufficient
  capabilities, anonymous requests and multisite delegation are rejected by the
  real request path. Core sometimes uses HTTP 500 for `wp_die()` denials with no
  explicit status; those outcomes are recorded, not reported as a successful save.
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

No Keel runtime defect was exposed. Harness assumptions failed during setup:
`DISALLOW_FILE_MODS` also denies `update_core`, so it was replaced by download and
automatic-updater guards; the initial assumed options group was wrong, so the
harness now reads the real form. A stricter capability assertion also confirmed
that core denies `view_site_health_checks` to the delegated multisite site admin.
Failed setup runs were not counted as passing.

## Reproduction and cleanup

Use only disposable localhost sites with this checkout linked and Keel active.
Add a temporary MU wrapper defining `KEEL_HTTP_AUTH_LAB` as true and requiring
`tests/integration/fixtures/http-authorization.php`. Run
`tests/integration/http-authorization-setup.php` through the runner's WP-CLI
`eval-file`. Setup refuses an existing backup or fixture login. Then run:

```sh
NODE_PATH=/path/to/lab/node_modules node tests/integration/http-authorization.mjs http://localhost:8889 http://localhost:8890
```

The browser harness requires Playwright; no dependency is added to the plugin.
It keeps authentication material in memory and writes only redacted assertions.

In a finally/cleanup step, run the same setup PHP through `eval-file` with the
argument `cleanup`, then remove exactly the temporary MU wrapper. Cleanup restores
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
