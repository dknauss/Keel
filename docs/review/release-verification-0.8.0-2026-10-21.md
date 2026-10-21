# Keel 0.8.0 verification — candidate and publication, 2026-10-21

The candidate preparation below preceded publication. The completed publication
and artifact checks are recorded in the follow-up at the end.

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

## Planned publication sequence (candidate checkpoint)

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
It predates #290 (SECURITY.md wording); the published checksums are recorded below.

Independent release review found the missing draft-hold step in the publication
sequence; it is corrected above. The final review has no outstanding findings.
Candidate CI and a versioned six-row MySQL/Redis live matrix are recorded below.

## Pre-tag dry run and cleanup

`npx pressship release <staged-package> --slug keel-defaults --dry-run` completed
successfully. It printed the 0.8.0 SVN checkout/add/tag-copy/update/status/commit
**command plan only**; it did not execute those SVN operations, verify credentials
or rehearse the protected deployment workflow. No validation bypass flags were
used; the separate `verify` run above supplies the actual validation evidence.
The protected, tagged deployment dry run remains a mandatory publication gate.

The disposable Studio release QA site was deleted after checks. The local
candidate ZIP and checksum remain available for review; no remote site was changed.

## Versioned candidate CI and live matrix

Candidate `cb5930e7f373189ecc504f44968118d441139d5a`:

- [CI 39661173904](https://github.com/dknauss/Keel/actions/runs/39661173904):
  lint/unit/static checks and PHP 7.4, 8.0–8.5 passed.
- [Live matrix 39661115607](https://github.com/dknauss/Keel/actions/runs/39661115607):
  all six rows passed: WordPress 6.4/PHP 7.4, French, Redis-backed multisite,
  WordPress 7.0, and operable-updater minor/major policy cases. These exercise
  real MySQL diagnostic probes plus backward/forward core installer paths.

The follow-up commit adds results to this excluded record only; packaged files
are identical to the candidate checked above. Final CI is visible on
[release PR #289](https://github.com/dknauss/Keel/pull/289). GitHub review threads
and review summaries were empty when checked; independent review is clear.

Release preparation is complete. The release PR still requires review and merge;
no tag, GitHub publication, WordPress.org upload or SVN commit was performed.
The tagged protected deployment rehearsal remains a publication gate.

## Publication and artifact verification — 2026-10-21

[Release PR #289](https://github.com/dknauss/Keel/pull/289) merged as
`a44bcb9`. A final packaging check then found the shipped `SECURITY.md` still
called 0.8.0 an unpublished candidate;
[#290](https://github.com/dknauss/Keel/pull/290) removed that wording and merged
as `3681221232d0db193241ce8330c6a9fdeb1181e2` with green PR CI and no review
comments. [Merged-main CI](https://github.com/dknauss/Keel/actions/runs/39662947104)
passed on that commit. The annotated `v0.8.0` tag points at it.

### Publication order

1. Created the GitHub release as a draft before tagging, then pinned its target
   to `3681221` so publishing the draft could not tag a later `main`. Pushed
   `v0.8.0`. The [tag-triggered workflow](https://github.com/dknauss/Keel/actions/runs/39663749966)
   reran every CI gate, built the ZIP and uploaded it into the unpublished draft.
2. That workflow's own deploy job then waited at the protected `wordpress-org`
   environment. It shares the per-tag deploy concurrency group, so a dry run
   dispatched behind it could not start. The waiting deployment was **rejected,
   not approved**: it ran zero steps, so GitHub records the run as failed while
   its release job succeeded. Nothing reached WordPress.org from it.
3. The protected [WordPress.org dry run](https://github.com/dknauss/Keel/actions/runs/39665779673)
   was dispatched from `v0.8.0` with `dry_run=true` and approved separately. Its
   log records `DRY_RUN: true`, confirms **0.8.0 was not yet in SVN**, rehearsed the
   full trunk copy, adds, tag copy and asset staging, and reported "Dry run: Files
   not committed." As with every SVN rehearsal, it could not prove the commit
   credential.
4. Downloaded the draft ZIP; a local rebuild from the tag decompresses to the same
   45 files byte-for-byte. Generated and uploaded `SHA256SUMS` from that ZIP.
   Published the [GitHub release](https://github.com/dknauss/Keel/releases/tag/v0.8.0)
   as Latest at **14:14 MDT** (20:14:09 UTC), after the dry run passed.
5. Dispatched the real [WordPress.org deployment](https://github.com/dknauss/Keel/actions/runs/39673063116)
   from `v0.8.0` with `dry_run=false`; the maintainer approved its gate. The log
   records `DRY_RUN: false` and committed **WordPress.org SVN revision 3759061**,
   which also proves the SVN credential.

### Published artifacts

The GitHub ZIP and checksum were downloaded again through the release;
`shasum -a 256 -c SHA256SUMS` passes and GitHub's asset digest agrees. WordPress.org's
versioned ZIP and the unversioned `keel-defaults.zip` were also fetched. All three
contain exactly **45 files** with identical decompressed paths and bytes; archive
hash differences are packaging differences, not runtime differences.

| Public artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| GitHub `keel.zip` | 253973 | `71e11a171874b9a0747cad7ebafec4b89b403191bbfa6958907dfeee4520ed16` |
| WordPress.org `keel-defaults.0.8.0.zip` | 251321 | `48ed51c264b17991d10ac70180b28a426b439ac8a2638a00684cdeec960f49ad` |
| WordPress.org default `keel-defaults.zip` | 251321 | `48ed51c264b17991d10ac70180b28a426b439ac8a2638a00684cdeec960f49ad` |

The WordPress.org API reports **0.8.0**, WordPress minimum **6.4**, PHP minimum
**7.4** and tested **7.1.3** (the source readme declares the 7.1 branch).

SVN `trunk` and `tags/0.8.0` plugin headers and readmes match the tagged source.
All **11 deployed assets** match byte-for-byte: five screenshots, two banners,
three icons and `assets/blueprints/blueprint.json`. This is integrity verification,
not a fresh browser launch of the published preview; the API's `preview_link`
remains empty, and no Live Preview launch is claimed.

Release complete. Plugin Check's two reviewed warnings remain disclosed above;
no checks were bypassed. No production WordPress installation was changed by
artifact verification. Field observation of the two new reports remains separate work.
