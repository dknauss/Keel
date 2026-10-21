# Keel 0.7.0 candidate verification — 2026-10-20

Candidate preparation only. No release tag, GitHub Release, WordPress.org SVN
commit or production deployment has been made.

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
No publish/SVN dry run was performed: the protected deploy workflow requires a
release tag, and tagging is deliberately deferred until this PR is reviewed and
merged. Verification was not bypassed.

## Remaining publication steps

Review and merge the release PR only after its checks pass. Run the counterpart
sweep again before tagging. Then create the release tag and use the protected
release/deployment workflow. Verify the published GitHub ZIP, WordPress.org
version, SVN tag and listing assets afterwards. This record does not authorize or
claim publication.
