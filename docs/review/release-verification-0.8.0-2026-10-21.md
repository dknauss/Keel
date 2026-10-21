# Keel 0.8.0 release candidate — 2026-10-21

Candidate preparation only. No v0.8.0 tag, GitHub release, WordPress.org upload
or SVN commit has been made.

## Scope

#287 merged as `9f83f314d0a8bf62368799aa33f993b629949c87` and #288 as
`e9451f8fd1148f9f5a35919aebd0022c4913a9f7`, after green CI/live matrices and clear
reviews. 0.8.0 adds bounded, cached Site Health attribution for autoloaded options
and cron pressure/core-update delivery evidence. Reports include ownership
confidence and explicit uncertainty; no observed jobs, schedules, update policy
or autoload flags are changed. Sensitive values/arguments/error data are omitted
from report caches and output. See the detailed [autoload](autoload-attribution-2026-10-21.md)
and [cron](cron-attribution-2026-10-21.md) verification records for scope and limits.

Header version, asset version, stable tag and POT metadata are synchronized to
0.8.0. The upgrade notice is 284 characters (limit 300). Saved settings, the 42
controls, PHP 7.4 minimum and WordPress 6.4 minimum are unchanged. No migration
or new default is introduced.

## Release checks

Results are recorded below as checks finish. Validation is not bypassed.

## Publication sequence

Review and merge the release PR after its checks pass. Recheck the counterpart
sweep. Before an authorized tag push, create a GitHub draft release for that tag
so release.yml uploads into the draft instead of publishing immediately. Keep
the draft unpublished and withhold approval of the real WordPress.org deployment
until the protected deployment dry run succeeds. Only then publish the reviewed
GitHub draft and approve the real deployment. Create/push the release tag only
with authorization. The protected
WordPress.org deployment dry run must run before publishing. Its workflow requires
a stable tag, so that tagged workflow has not been dispatched during this
pre-tag preparation. The WordPress.org dry run does not test SVN authentication;
only a real commit does. Verify the published GitHub and WordPress.org artifacts
and listing assets after publication.

## Local and packaged checks

Full PHP suite, WPCS, PHPCompatibility, scoped PHPStan, 43 negative static contract
cases / 46 diagnostics and all PHP syntax checks pass. The counterpart sweep
since v0.7.0 reports no pending ports. Screenshot review is current; existing
listing screens are unchanged and the new report desktop/mobile captures are
linked from the feature records. Competitive benchmark labels track this candidate
with an explicit disclaimer: historical competitor measurements were not rerun.

The packaged ZIP was installed on disposable Studio WordPress 7.1.3 / PHP 8.4 /
SQLite. The public GitHub v0.7.0 ZIP was installed first, and all 42 preferences
saved with deliberate opt-outs and an existing menu-width choice. Updating to the
candidate preserved the entire array, order and types, including both opt-outs;
both new async tests registered and their background collectors completed.

Uninstall then removed settings, report caches and scheduled collector jobs.
Fresh ZIP activation seeded all 42 defaults exactly as the schema specifies.
Real packaged autoload and cron SQL probes passed. Protected search passed 24
actual WordPress assertions on the fresh default configuration. The shortcode
probe passed reader/editor/capability, unchanged-content and singular/feed/REST
checks. These probes intentionally require their features enabled; the preceding
opt-out upgrade fixture correctly left those features disabled.

Fresh Chromium verification found no page errors; all four Keel settings assets
carry `ver=0.8.0`. Both new authenticated Site Health AJAX endpoints returned 200
with successful pending reports, as expected on a cold cache. The feature records
supply the full nonce/capability/axe/mobile checks for the unchanged renderers.

`npx pressship verify` passed with `ok=true`, validation enabled and no readme
findings. Its cached checker again appended duplicate constant warnings after JSON,
causing `plugin_check.invalid_json`; direct Plugin Check 2.1.0 was therefore run
on the installed package and returned **zero errors, two reviewed warnings**:

1. `update_modification_detected`, `includes/backports.php`: observes core storing
   offers and refreshes Keel's own status cache; does not rewrite core offers.
2. `PluginCheck.Security.DirectDB.UnescapedDBParameter`, `includes/content.php:466`:
   trusted WordPress-built search clauses/table name, not raw request interpolation.

No new diagnostic-module warnings or suppressions were introduced. Verification
was not bypassed. Package contents match the staged tree byte-for-byte: **45
files, 253,990 bytes**; runtime PHP, CSS/JS, POT, readme, LICENSE, CONTRIBUTING and
SECURITY are present. Tests, docs, dependencies and listing assets are excluded.

SHA-256: `96412ab1b5e3cee5cba20832ed31e3b093cdeda8d08e72ed29a61728c81bc669`.
This checksum identifies the local candidate artifact, not a published download.

Independent release review found the missing draft-hold step in the publication
sequence; it is corrected above. The final review has no outstanding findings.
Candidate CI and a versioned six-row MySQL/Redis live matrix are recorded below.
