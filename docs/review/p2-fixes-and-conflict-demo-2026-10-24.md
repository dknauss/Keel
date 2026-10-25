# P2 fixes and hosted conflict demo verification — 2026-10-24

Base: `9ec7efc` (`main`). No version bump, release or production change.

## Breached-password severity

Site Health previously decided severity from only the ten displayed flagged
accounts. An older flagged administrator could therefore leave the result at
`recommended`. The display remains ten rows; the severity check now reads older
flags in pages of 100, stops at the first account with `manage_options`, and
retains current-site membership scoping. An ID tie-breaker keeps equal flag
timestamps on consistent pages. Additional pages skip the total-count query.

The standalone regression failed before the fix and passed afterwards. It puts
the administrator beyond the first 100 flags, verifies that the result becomes
critical without expanding the display, and then moves that administrator to
another site and verifies that this site's result remains recommended.

A fresh disposable SQLite WordPress 6.8.3 install, PHP 8.4.27, loaded this checkout
through a plugin symlink. With 111 newly created flagged accounts and the oldest
one an administrator, real `WP_User_Query` and capability resolution returned
`critical` and exactly ten display rows. Removing that administrator's flag
returned `recommended`. Repeated with equal flag timestamps and the lowest-ID
account as the administrator, the result still became critical. All 111 fixture accounts were deleted in a `finally`
block; no outbound breach lookup was required or performed for these synthetic
flags.

## Deterministic coding standards

The standalone login-screening and helper-column tests both declared `WP_User`.
PHPCS duplicate-class detection depended on worker assignment: a full local
`composer lint` failed, and processing both files in one worker consistently
reproduced the warning. Both now load `tests/stubs/wp-user.php`, following the
existing shared `WP_Error` fixture pattern. No sniff was disabled.

Verification passed:

- `composer test`
- `composer lint`
- `composer analyse`
- `composer lint:compat`
- `composer test:types` — 43 invalid cases rejected, 46 diagnostics
- `bash bin/lint-syntax.sh` — 204 files parse on PHP 8.4.27
- `npm run lint`
- `vendor/bin/phpcs --standard=phpcs.xml --parallel=1 tests/helper-columns.php tests/login-breach-screening.php tests/stubs/wp-user.php includes/password-login-screening.php`
- `python playground/test-conflict-demo.py` — official schema validation and
  embedded-source parity (temporary Python environment with `jsonschema`)
- `git diff --check`

Counterpart: not applicable — AX has no login breach re-screening or flagged-account
severity report; the duplicated standalone test fixture is Keel-only.

## Hosted Playground demo — before merge

Opened the public README link in headless Google Chrome 155.0.8059.85 using
Playwright, with a fresh browser context:

[Conflict diagnostics demo](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/Keel/main/playground/blueprint-conflict-diagnostics.json).

The published rolling build booted on WordPress 7.1.3 and landed on `/wp-admin/`.
The Plugins screen explicitly showed these four plugins active:

| Plugin | Displayed version |
| --- | --- |
| Keel Defaults, rolling main build | 0.8.0 |
| Classic Editor | 1.7.0 |
| Disable Comments | 2.9.0 |
| Disable XML-RPC | 1.0.1 |

The Dashboard displayed an amber `notice-warning` naming XML-RPC Remote Publishing
(`xmlrpc_enabled`) as not taking effect, naming Classic Editor and Disable
Comments as shared-hook owners, and accounting for one untraceable setting.

Expanded **Tools → Site Health → A setting is not taking effect** and verified:

- XML-RPC Remote Publishing is recorded as not taking effect.
- Classic Editor is named on `use_block_editor_for_post`.
- Disable Comments is named on `comments_open`, `pings_open` and
  `get_comments_number`.
- `xmlrpc_enabled` is also listed as code Keel cannot trace to a plugin.
- `host-revision-limit.php` is listed on `wp_revisions_to_keep` as informational
  must-use code, with the explanation that it does not raise a notice.

Zero browser console errors and zero page errors were recorded. No CORS or
blueprint-execution error occurred. Background analytics requests were aborted,
and dashboard/news and Site Health REST-documentation fetches failed; neither
prevented installation, activation or the verified diagnostic screens.

Screenshots and browser transcript are local evidence under
`/tmp/keel-demo-verification/` (`dashboard.png`, `plugins.png`, `site-health.png`,
`browser.log`, `errors.json`). These temporary files are not release artifacts.
The hosted test exercises the published rolling build, not the unmerged P2 fix;
the severity fix was checked separately against the local WordPress install.
The subsequent post-merge verification is recorded below.

## Post-merge CI and rolling Playground verification — 2026-10-25

[PR #336](https://github.com/dknauss/Keel/pull/336) merged as
`9443be08382151027eba3c88ac282d10f52444ad` on 2026-10-24. Checked the completed
post-merge runs for that exact commit on 2026-10-25:

| Workflow | Result |
| --- | --- |
| [CI](https://github.com/dknauss/Keel/actions/runs/40135112659) | Passed, including PHP 7.4–8.5, coding standards, compatibility, PHPStan, static contracts, unit tests, JavaScript/CSS lint, and **Republish the rolling build**. Both the build and upload steps succeeded. |
| [Security analysis](https://github.com/dknauss/Keel/actions/runs/40135112633) | Passed. |
| [HTTP authorization lab](https://github.com/dknauss/Keel/actions/runs/40135112627) | Passed. |
| [Focused accessibility lab](https://github.com/dknauss/Keel/actions/runs/40135112643) | Passed. |

The HIBP budget database lab runs on pull requests or manual dispatch, not pushes
to `main`. Its four MySQL/MariaDB combinations passed before merge; it was not
rerun as a post-merge workflow.

### Published rolling artifact

The [rolling `latest` release](https://github.com/dknauss/Keel/releases/tag/latest)
identified its build as `Rolling build of main (9443be0)`. Its `keel.zip` asset
(ID `673380331`, 314,921 bytes) was uploaded at `2026-10-24T21:39:35Z`.

Downloaded the published ZIP and verified its SHA-256 against the release
asset's digest:

```text
80b2f044026379110d7fa8b7cfaaf1c0fd4b309105a63ec98815e2e3a02ed482
```

All 60 archive files were compared byte-for-byte with their corresponding paths
in merge commit `9443be0`, stripping the archive's `keel-defaults/` prefix. Every
file matched, including `includes/password-login-screening.php` with the paged
severity check and stable timestamp/ID ordering. The shared `WP_User` stub is
development-only: it passed CI and is correctly excluded from the runtime ZIP.

### Fresh hosted installation

Opened the same public conflict-diagnostics demo in a fresh Chrome context using
Playwright. It booted WordPress 7.1.3 and landed on the Dashboard. The Plugins
screen explicitly showed Keel Defaults 0.8.0 (rolling build), Classic Editor
1.7.0, Disable Comments 2.9.1, and Disable XML-RPC 1.0.1 active. Disable Comments
had advanced from 2.9.0 since the earlier pass; the expected findings still
passed.

Confirmed the amber XML-RPC Remote Publishing warning, both named plugin
overlaps, the untraceable `xmlrpc_enabled` callback, and informational
`host-revision-limit.php` attribution in the expanded Site Health panel. Zero
console errors and zero page errors were recorded.

To distinguish the installed code from a cached older demo, opened the plugin
editor for `keel-defaults/includes/password-login-screening.php` and read the
source textarea without saving. After normalizing line endings, that installed
source matched merge commit `9443be0` exactly. This proves the hosted installation
contains the merged runtime fix independently of its unchanged 0.8.0 version
label.

The browser's ZIP-response capture did not observe Playground's worker download,
so no claim is made that a captured browser response matched the release digest.
The published ZIP comparison and the installed-source comparison are the two
separate checks used here. This pass verified delivery and diagnostic rendering;
the synthetic 111-account severity behavior was verified in the local WordPress
lab documented above.

Local evidence is under `/tmp/keel-postmerge-9443be0/`: `keel.zip`,
`artifact-verification.json`, `installed-password-login-screening.php`,
`browser.log`, `errors.json`, and Dashboard, Plugins and Site Health screenshots.
These files are temporary supporting evidence, not release artifacts. No new
version, release or production-site write was performed during verification.
