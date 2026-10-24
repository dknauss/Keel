# Keel 0.9.0 verification — candidate, 2026-10-24

This record covers the release candidate. Publication and artifact checks are
added at the end once the tag is authorized and pushed.

## Scope

`main` at `9443be0` (#336 merged) plus this release PR. 0.9.0 carries everything
merged since `v0.8.0`, #291 to #336: plugin and theme auto-update policies, the
Updates-screen policy notice, Site Health update diagnostics, the sanitized JSON
export, the update data disclosure, the registration audit, Abilities API
permission overrides, Admin List Tables, the bounded HIBP budget, the comment
write-path refusal, the HTTP 403 and multisite authority fixes, and the login
breach re-check. The readme changelog lists each one.

Header version, asset version, stable tag and POT metadata are synchronized to
0.9.0. The upgrade notice is 281 characters (limit 300). The schema has 44
settings, up from 42: `plugin_auto_update_policy` and `theme_auto_update_policy`
are new, and `admin_list_tables` replaces `helper_list_columns`. PHP 7.4 and
WordPress 6.4 minimums are unchanged. Both new policies default to Leave
unchanged, so no update behavior changes on upgrade.

Two behaviors do change for existing sites, and the upgrade notice names both:

- A site that had Admin List Columns on reads as Tidy + helper columns. The stored
  option is not rewritten; the legacy value resolves in memory.
- With Password Strength on (the default), a successful login re-checks the
  password against Have I Been Pwned at most once every 30 days per account. It
  warns and never blocks.

## Re-check of what merged after the RC pass

The [release-candidate pass](release-candidate-2026-10-24.md) measured `24700af`.
#328 to #336 merged after it. Each ran the PR gates that now include the
Chromium accessibility lab and the HTTP authorization lab. Their runtime effects:

- #331 adds one `pre_comment_approved` callback. It runs only when a comment is
  submitted, so read paths are unaffected.
- #334 changes Site Health and notice copy only.
- #335 adds work at login (one HIBP range lookup per account per 30 days, under
  the shared budget) and, on admin screens, reads the current user's
  `keel_password_breached` meta. User meta is primed with the user, so that adds
  no query. The Users view count and Site Health test read user meta once each,
  only on those screens.
- #336 (merged to `main` after this branch was cut) makes the breached-password
  Site Health check page through every flagged account before it picks a
  severity, so an administrator outside the ten listed rows still makes it
  critical. It runs only on Site Health. It is in the release once this PR
  merges; its own PR records its tests and lab run.

The performance harness from the RC pass was a scratch tool and was not rerun.
Nothing above touches the front end, whose markup and query count were identical
between 0.8.0 and `24700af`.

## Local and packaged checks

Run in a cloud container on PHP 8.3.6:

- All 86 standalone PHP suites pass on the release branch, including
  `build-package.php`, `docs-consistency.php`, `readme-spec.php` and
  `i18n-catalogs.php`. The regenerated POT changed only its version and date, so
  no string is missing from the catalog.
- The package builds to **66 files, 942,519 bytes** uncompressed.
- The published GitHub `v0.8.0` ZIP was installed on a disposable WordPress 7.1.3
  (SQLite) site. Seven deliberate choices were saved: Admin List Columns on,
  Protected Search Results and Broken Shortcodes off, comments left on, a 240px
  menu, five revisions and two exempt roles. Installing the candidate over it
  kept the stored array identical, in the same key order. Each choice resolved
  the same way, Admin List Columns resolved to Tidy + helper columns, and the two
  new policies resolved to Leave unchanged.
- Uninstall removed every `keel` option, both login re-check user meta keys and
  all scheduled Keel events. A fresh activation then stored all 44 defaults
  exactly as the schema specifies.

### Plugin Check 2.1.0

Run with WP-CLI on the installed candidate: **zero errors, eleven warnings**. 0.8.0
shipped with two reviewed warnings. All eleven were reviewed:

1. `update_modification_detected`, `includes/backports.php`: unchanged from 0.8.0.
   Keel refreshes its own status cache when core stores offers and does not rewrite
   them.
2. `update_modification_detected`, `includes/bootstrap.php` (new): the
   `auto_update_plugin` and `auto_update_theme` filters behind the two new
   policies. They default to Leave unchanged, which returns core's decision.
3. `UnescapedDBParameter`, `includes/content.php`: unchanged from 0.8.0. The search
   clauses and table name are built by WordPress, not taken from the request.
4. Five `UnescapedDBParameter` warnings, `includes/password-hibp-store.php` (new,
   #316): every value goes through `$wpdb->prepare()`. The table name comes from
   WordPress's own scope, savepoint names are random hex checked against
   `/\A[a-f0-9]{32}\z/`, and transaction commands come from a fixed list. The
   store cannot use the object cache, because it is the authority for the shared
   budget.
5. Three `NonPrefixedHooknameFound` warnings: Keel applies core's own
   `list_table_primary_column`, `query` and `auto_update_{$type}` filters so that
   other plugins' callbacks on them still count. None of them is a hook Keel
   defines.

## Candidate CI and live matrix

- [HIBP budget database lab 40130656812](https://github.com/dknauss/Keel/actions/runs/40130656812):
  passed on `5229780`.
- [Live matrix 40130655002](https://github.com/dknauss/Keel/actions/runs/40130655002)
  on `5229780`: five of six rows passed. `6.9-operable-minor` failed in the
  update-policy notice probe, the only row whose effective policy is `minor`. The
  probe still looked for copy that f4ef852 had shortened. The runtime text was
  right and the probe was stale, so this PR corrects the probe.
- [Live matrix 40135694060](https://github.com/dknauss/Keel/actions/runs/40135694060)
  on `3675a90`: all six rows passed, including `6.9-operable-minor`.
- [Focused accessibility lab 40135696116](https://github.com/dknauss/Keel/actions/runs/40135696116),
  dispatched with `engines=chromium,firefox,webkit` on `3675a90`: passed in all
  three engines, on the single site and the multisite network, in English and
  Arabic. This is the Firefox and WebKit pass the RC record left open; it
  replaces running it on the maintainer's machine.
- PR checks on `3675a90` passed: CI, Counterpart, Security analysis, the HTTP
  authorization lab, the HIBP budget lab and the Chromium accessibility lab.

## Counterpart sweep

Pull requests merged since `v0.8.0` whose Counterpart line says pending:

- #331: delivered (AX commits `75ebc87` and `14f46a2`), and #333 is closed. Its
  line now says ported.
- #302: ported in the AX 0.2.0 preparation, still to merge there. AX's Modified
  helper column now shows the time and sorts. Once it merges, #302's line
  becomes `ported — AX commit <hash>`.
- #335: ported (AX commit `17cec32`); its line now says so, and #323 is closed.

The sweep is clean once #302's line is updated after the AX 0.2.0 merge.

## Before tagging

These need a machine with more than Chromium, or a person:

1. ~~Firefox and WebKit~~: done in CI; see run 40135696116 above.
2. **VoiceOver with Safari**: the documented manual pass, covering the Updates
   policy notice, the Site Health update diagnostics tables, the Breached passwords
   Site Health panel and Users view, and the owner's breach warning.
3. **Native Safari keyboard**: Tab and arrow scrolling through the locked settings
   and the evidence tables, with a visible focus outline. Chromium scrolls tables
   natively, so WebKit-only keyboard fixes cannot be confirmed here.
4. **WordPress.com settings save**: still open from earlier releases.
5. A clean `bin/counterpart-sweep`.

## Publication sequence

Unchanged from 0.8.0, and only with the maintainer's go-ahead at each public step:

1. Merge this PR after green CI. Check merged-main CI.
2. Create the GitHub release as a **draft** pinned to the merge commit, then push
   the annotated `v0.9.0` tag. `release.yml` uploads the ZIP into the draft.
3. Reject the tag workflow's waiting deploy job. Dispatch the protected
   WordPress.org dry run from `v0.9.0` and approve it.
4. Verify that the draft ZIP matches a local rebuild, upload `SHA256SUMS` and
   publish the draft.
5. Dispatch the real WordPress.org deployment (`dry_run=false`) and approve it.
   Verify the SVN revision, both public ZIPs and the listing assets.

No tag, GitHub release, WordPress.org upload or SVN commit has been made.
