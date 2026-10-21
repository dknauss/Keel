# Keel 0.7.0 verification — candidate 2026-10-20, published 2026-10-21

The candidate preparation below preceded publication. The completed publication
and artifact checks are recorded in the 2026-10-21 follow-up at the end.

## Scope and notes

#284 is merged. The candidate includes search privacy and shortcode cleanup with
passive Site Health reporting, both default-on for existing sites without saved
choices; it also includes lock markers, linked lock summaries and accessible names
for network value controls. Typography remains outside Keel's scope.

Version header, asset cache-buster, stable tag, status documents and POT metadata
all state 0.7.0. The changelog is finalized and the upgrade notice is 296 characters
(the directory limit is 300). PHP minimum stays 7.4; WordPress minimum stays 6.4.

## Local release gates

Full PHP tests, PHPCS, PHPCompatibility, scoped PHPStan, 43 negative static
contract cases / 46 diagnostics, and PHP syntax checks pass. The screenshot check
is current: all five images were reviewed, with settings screenshots 4–5 retaken
for #284. The package builds with runtime PHP, CSS/JS, POT, readme, LICENSE,
CONTRIBUTING and SECURITY; tests, development dependencies and listing assets
are excluded.

## Versioned candidate CI and live backport matrix

The [candidate CI](https://github.com/dknauss/Keel/actions/runs/39520487519) passes
lint/unit and PHP 7.4–8.5. The
[live matrix](https://github.com/dknauss/Keel/actions/runs/39520396289) passes all six
rows at `a5e97b922742a8aee0a61eb09f47b1eb16d8e8ee`:

- WordPress 6.4 / PHP 7.4 floor.
- WordPress 6.8 / PHP 8.2 / French locale.
- WordPress 6.9 / PHP 8.2 / real MySQL multisite.
- WordPress 7.0 / PHP 8.2.
- WordPress 6.9 with an operable updater, minor-only policy.
- WordPress 6.9 with an operable updater, major releases permitted.

The follow-up recording these results changes only this excluded documentation
file; the candidate's distributable files are identical. Final PR CI is visible
on [release PR #285](https://github.com/dknauss/Keel/pull/285). The counterpart
sweep reports no pending ports since v0.6.9.

## Packaged runtime, fresh install and upgrade

Fresh ZIP installation on disposable Studio WordPress 7.1.3 / PHP 8.4 / SQLite
reports version 0.7.0, 42 persisted defaults, and both new policies enabled.
All 48 `verify-behaviors.sh` check statements passed, replayed through Studio's
WP-CLI tool with each configuration change followed by a fresh WordPress load.
This is an adaptation of the harness, not a claim it was invoked unchanged.
The standalone real-WordPress filter-semantics probe passed too.

The protected-search probe passes all 24 assertions against actual WordPress
queries, roles, pagination and per-object access. The shortcode probe passes its
reader/editor, registered-handler, unchanged-storage, bounded-observation and
Site Health checks; actual singular/feed HTTP requests clean residue while REST
retains it. No live site or real mail transport was used.

The published v0.6.9 GitHub ZIP was installed into the same disposable site,
with all 40 old preferences saved and no new keys. The candidate ZIP then replaced
it as an ordinary plugin update. Every saved preference still matched the old
fixture, while both new policies resolved to on without saving settings.

A fresh Chromium session confirmed both new checkboxes are checked after that
upgrade, all Keel assets carry `ver=0.7.0`, and real settings forms persist each
new setting's independent opt-out. Re-enabling both persisted all 42 preferences
while retaining the existing menu width and comments choice. No page errors or
horizontal overflow at 1280px and 390px. The dedicated release QA site was removed
after verification. The broader lock UX/axe/RTL pass remains recorded in
[the lock UX record](settings-lock-ux-2026-10-20.md).

## Readme validation and Plugin Check

`npx pressship verify /tmp/keel070-package/keel-defaults --json` passed (`ok=true`),
with validation enabled and no readme findings. Pressship's cached checker emitted
duplicate constant warnings after its JSON output, producing a parser warning
(`plugin_check.invalid_json`). Plugin Check 2.1.0 was therefore also installed
and run directly on the packaged plugin in the disposable Studio site.

Direct Plugin Check reports **zero errors and two warnings**:

1. `update_modification_detected` in `includes/backports.php`: intentional. Keel's
   same-branch security-backport feature adjusts the core-update transient.
2. `PluginCheck.Security.DirectDB.UnescapedDBParameter` at `includes/content.php:466`:
   reviewed. The protected-search preflight combines WordPress's already-built
   `posts_clauses` query fragments and its table name, not raw request parameters.
   SQL fragments cannot be escaped as literal values without destroying the query.
   The existing narrow WPCS exception documents this trust boundary. No new
   suppression or runtime change was added to hide the warning.

Neither warning is omitted from readiness reporting. The updater warning was
already present in the previous release; the query warning applies to the new
search policy and remains visible for reviewer inspection.

## Package integrity

The candidate ZIP contains **42 files**, **238,689 bytes**. Every ZIP entry was
compared byte-for-byte with the staged distribution tree. Required runtime assets
are present; development dependencies, tests, docs and listing images are absent.

SHA-256: `951646aac6621e9fc071a162484c17eabd1a690b2c3b32e1c68de62b89f20b11`

This is a candidate artifact check, not a claim about a future published download.
At the candidate checkpoint no publish/SVN dry run had been performed: the
protected deploy workflow requires a release tag. The completed dry run and
publication are recorded below. Verification was not bypassed.

## Planned publication sequence (candidate checkpoint)

Review and merge the release PR only after its checks pass. Run the counterpart
sweep again before tagging. Then create the release tag and use the protected
release/deployment workflow. Verify the published GitHub ZIP, WordPress.org
version, SVN tag and listing assets afterwards. This record does not authorize or
claim publication.

## Publication and artifact verification — 2026-10-21

[Release PR #285](https://github.com/dknauss/Keel/pull/285) was reviewed with no
findings, no automated comments and no unresolved review threads. It merged as
`80b5edee3754707d646453b571c083fcc4a22f10`; the annotated `v0.7.0` tag points
at that commit. The final PR CI and
[merged-main CI](https://github.com/dknauss/Keel/actions/runs/39648200215) passed.
The merged distribution was byte-identical to the six-row matrix-tested tree.
The counterpart sweep was clear and screenshot review remained current.

### Publication order

1. Pushed `v0.7.0` only after merge and green checks. Created the GitHub release
   as a draft so the tag-triggered builder could upload the ZIP without making
   it public yet.
2. The protected
   [WordPress.org dry run](https://github.com/dknauss/Keel/actions/runs/39648399430)
   succeeded. It confirmed **0.7.0 was not yet in SVN**, staged the runtime and
   listing assets, and explicitly reported “Dry run: Files not committed.” This
   was a new-version rehearsal, not an already-published-version no-op. As with
   every SVN rehearsal, it could not prove the commit credential worked.
3. Downloaded the draft ZIP, matched all 42 files to the tested distribution and
   uploaded `SHA256SUMS`. Published the stable
   [GitHub release](https://github.com/dknauss/Keel/releases/tag/v0.7.0) only after
   the dry run passed, at **11:59 MDT on October 21** (17:59:22 UTC).
4. Approved the actual protected deployment afterwards. The
   [release/deploy workflow](https://github.com/dknauss/Keel/actions/runs/39648385375)
   succeeded and committed **WordPress.org SVN revision 3758838**.

No beta was published. A separate GitHub prerelease would be useful for an
intentional tester-feedback window, especially for the two new defaults, but
there was no such window in this release request. Stable publication followed
the already-completed integration, browser, upgrade and live-matrix checks.
Both the stable ZIP and a checksum are available with user-facing release notes.

### Published artifacts

The ZIP and checksum were downloaded again through their public GitHub URLs;
`shasum -a 256 -c SHA256SUMS` passes. GitHub's asset digest agrees. WordPress.org's
versioned ZIP and the unversioned `keel-defaults.zip` download were also fetched.
All three ZIPs contain exactly **42 files** with identical decompressed paths and
bytes matching the tagged distribution. Differences in archive hashes are
packaging differences, not runtime differences. Development dependencies and
tests are excluded; all runtime assets are present.

| Public artifact | Bytes | SHA-256 |
| --- | ---: | --- |
| GitHub `keel.zip` | 238689 | `6c0543444e7c95455c8bf23cfe7d6031e8b6ae9dcf9e1d78a842c2cc8f1758a9` |
| WordPress.org `keel-defaults.0.7.0.zip` | 236193 | `d00cee1a4b9ad70090da1efc71e9c2825e2e6bfaf3bdedfbb314c8e36239bc31` |
| WordPress.org default `keel-defaults.zip` | 236193 | `d00cee1a4b9ad70090da1efc71e9c2825e2e6bfaf3bdedfbb314c8e36239bc31` |

The WordPress.org API now reports **0.7.0**, WordPress minimum **6.4**, PHP
minimum **7.4**, and tested **7.1.3** (the source readme declares the 7.1 branch).
It initially returned 0.6.9 after SVN committed; verification waited for propagation
and then checked the normal API endpoint and public downloads again.

SVN `trunk` and `tags/0.7.0` plugin headers/readmes match the tagged source.
All **11 deployed assets** match byte-for-byte: five screenshots, two banners,
three icons and `assets/blueprints/blueprint.json`. This is integrity verification,
not a fresh browser launch of the published preview. The API's `preview_link`
remains empty, as in the previous release record; no functional Live Preview
launch is claimed here.

Release complete. Plugin Check's two reviewed warnings remain disclosed above;
no checks were bypassed. No production WordPress installation was changed by
artifact verification. Field observation, the maintainer's WordPress.com settings-save
confirmation and broader manual assistive-technology testing remain separate work.
