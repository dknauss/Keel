# Release verification — 2026-10-15

Result: no functional release blocker found in the exercised paths. The initial
package had two Plugin Check warnings. The upgrade-notice warning is fixed in
the follow-up below; the intentional update-modification warning remains. This verifies a release candidate; it does
not publish, tag or deploy a release.

## Revision and review

Reviewed and merged [#272](https://github.com/dknauss/Keel/pull/272) as
`25feb0316488b13ba56138acd70233942a6e1724`. No review defects or automated review
comments. All PR checks passed, including PHP 7.4–8.5, lint/unit and counterpart.
Local level-6 analysis, 43 negative contract cases / 46 diagnostics, network policy
and settings rendering checks passed before merge. Runtime source changes in
#272 are PHPDocs only; input guards and handler bodies remain intact.

## Real WordPress and multisite

Disposable Studio WordPress 7.1.3 / PHP 8.4.26 / SQLite. Reflection confirmed the
network sanitizer loaded from the worktree containing the merged commit. Only
Keel was initially active, plus a local mail-capture MU fixture. No production
site was changed and no message was delivered to a real mail transport.

The 48 `check` probes from `tests/integration/verify-behaviors.sh` were replayed
through Studio's WP-CLI tool, with each configuration change followed by a fresh
WordPress load. All passed: header precedence, comments/feeds/blocks, REST gates
and discovery, editor policy, filenames, emoji, author hooks, revisions, menu CSS,
environment hooks, password roles, XML-RPC, update policy and Site Health.
The standalone real-WordPress filter-semantics probe passed too. This was an
adaptation of the existing shell harness to the Studio tool, not a claim that
the shell script itself was invoked unchanged.

The site was converted into a real network, Keel network-activated and a second
site created through WordPress. A local eval-file harness passed 47 assertions:

- New-site seeding through WordPress's actual initialization hook.
- Immediate network option changes, cross-blog reads and context restoration.
- Removal of overrides restores saved site preferences without rewriting them.
- Legacy migration preserves explicit revision choices, including null; missing
  revision keys retain unlimited revisions; migrations are idempotent.
- Actual Administrator and Subscriber roles retain different length enforcement.
- Real profile/reset hooks trim and reject weak passwords.
- WordPress's REST users controller rejects weak updates with 400 and does not
  persist them; a valid update returns 200 and the stored hash verifies.
- Synthetic HIBP clean/breached/outage responses preserve five-character-prefix
  privacy, Add-Padding, 128 KiB bounds, caching, universal breach screening and
  fail-open outage behavior. No real HIBP request was made.

The harness was repeated after enabling PHP debug logging and emitted no warnings.

## Browser and persisted-state checks

Chromium/Playwright, actual WordPress forms, cookies and nonces:

- Main-site slider announces 300px and saves 300; the second site's preference
  remains independent.
- Network form persists managed width 240px and Subscriber exemption; both sites
  resolve those values while retaining their own stored preferences.
- An invalid network nonce returns 403 and does not modify policy.
- Forged options.php submissions on both sites cannot overwrite managed values.
  Locked controls retain aria-disabled and their descriptive lock/help IDs.
- Removing managed keys through the network form restores main-site 300 and
  second-site default. The second-site browser then saves 280 without changing
  main-site 300.
- A real site Administrator can open site settings but receives HTTP 403 from
  Network Policy. This user is not a Super Admin.
- WP_POST_REVISIONS=6 displays a locked value of 6 while retaining the hidden
  saved preference of 10.
- The real edit-user form rejects a trimmed weak password despite weak-password
  confirmation, accepts a strong replacement and persists a verifying hash.
- The actual reset-password form likewise rejects weak input and persists a
  valid replacement. Reset keys and passwords were not retained in this report.
- A fixture rival is attributed correctly and Keel does not report itself.
  Building the report executes zero rival callbacks; an actual filter execution
  increments the callback count and records divergence. The dashboard distinguishes
  overlap from observed disagreement; nonce-bearing dismissal hides the notice.
- Staging suppresses mail and displays its notice; production-like configuration
  does not suppress. A capture fixture prevents actual delivery in either state.
- Site settings on both sites, Network Policy, Site Health Info and Updates load
  without fatal output. Healthy final page checks have no JavaScript console
  errors. Expected 403 responses from negative security tests are not counted as
  unexpected JavaScript failures.
- At a 390px viewport the settings document is 390px wide, with no horizontal
  overflow. This is a responsive smoke check, not a complete accessibility audit.

Studio does not route the original /second/wp-admin/ layout correctly. The lab's
second site was moved to second.localhost on the same port, and its site/home URLs
were aligned. This allowed actual subsite login, settings and save checks and
closes the browser gap recorded in shared-controls-lab.md. It is a lab routing
workaround, not a Keel code change.

After enabling WP_DEBUG, WP_DEBUG_LOG and disabling display, a final pass through
both site screens, Network Policy and Site Health generated no PHP debug-log file
and no unexpected console errors. No PHP fatal, warning, notice or deprecation
was emitted by the logged boundary harness.

## Live backport installer matrix

[Run 38817444461](https://github.com/dknauss/Keel/actions/runs/38817444461)
ran against the exact merged commit and passed all six rows:

- 6.4 floor / PHP 7.4.
- 6.8 / fr_FR / PHP 8.2.
- 6.9 multisite / PHP 8.2.
- 7.0 line / PHP 8.2.
- 6.9 operable updater with minor-only policy.
- 6.9 operable updater with major updates allowed.

This is the repository's actual authenticated installer/rollback/forward matrix,
including live release resolution, stale stable-check refresh, core checksums and
replay refusal. Its GitHub environment uses MySQL 8.0. No live production site was
used for installer testing.

## Distribution and Plugin Check

Built using bin/build-zip.sh. Zip SHA-256:
`c062fcf92b1fc60491d3e72449007f82895dedd082fff49faf7b582dada2f776`.
The archive contains the runtime tree and excludes development dependencies/tests.
A second fresh single-site Studio install installed and activated the zip as
Keel 0.6.8; all 40 schema settings were seeded. Reflection resolved the packaged
network module inside that site's plugin directory, not the source worktree.

Plugin Check 2.1.0 default CLI checks completed against the built package with no
errors and two warnings:

| Warning | Assessment |
| --- | --- |
| update_modification_detected, includes/backports.php | Intentional core-update functionality; retain reviewer explanation. |
| upgrade_notice_limit, readme.txt, historical 0.6.4 notice | Copy exceeds the directory's 300-character limit; trim before directory publication if desired. No runtime impact. |

The initial scan of the development-tree symlink consumed sustained CPU without
returning after more than three minutes and was terminated. Retrying the actual
shipped tree completed in about eleven seconds. No checks or warning codes were
suppressed in the package run. Plugin Check is supplementary to the explicit real
runtime checks above, not a replacement for them.

## Scope, remaining work and cleanup

No code fix was required. No fresh performance-budget run, real external HIBP
latency, production Redis/OPcache/load test or cross-browser matrix is claimed.
JS/CSS linting, broader accessibility work and the major-release screenshot-review
step remain separate work; the screenshot stamp is explicitly not a PR/release
blocker under the current repository policy.

Both disposable Studio sites were stopped and deleted, including their fixture
plugins, MU capture, generated accounts and reset data. The built zip remains at
`/private/tmp/keel-release-package-20261015/keel.zip` for inspection. The temporary
sites never changed production data, hosting configuration or remote WordPress
settings.

## Readiness follow-up — 2026-10-15

The historical 0.6.4 upgrade notice was shortened to 201 plain-text characters
while retaining both fixes and the statement that settings do not change. A new
package was checked using Plugin Check 2.1.0 on another disposable Studio site:
no errors, no upgrade_notice_limit warning, and only the intentional
update_modification_detected warning. No warning code or check was suppressed.
The documentation-check site was stopped and deleted after the check.

The updated package's SHA-256 is
`ffb2de94b2aa87ba63afcc9bcd7bae47fcd8937ac1dc60184296419712d994a1`.
This is a documentation/readme follow-up to the tested runtime revision, not a
new runtime release or a replacement for the historical package hash above.

`bin/counterpart-sweep v0.6.8` reports no pending counterparts. GitHub confirms
v0.6.8 is already published and its release/deploy workflow succeeded;
the WordPress.org plugin-information API reports 0.6.8 too. The wordpress-org
GitHub environment has a required-reviewer protection rule. Stale untagged
0.6.8 notes in TODO/ROADMAP were corrected without changing runtime versions.

Before the next release:

1. Merge this record/readme follow-up after its checks pass.
2. Select a fresh version (0.6.9 is appropriate for the present maintenance scope)
   and synchronize all version metadata, changelog and upgrade notice.
3. Verify the final versioned candidate: green CI, live release matrix and a
   clear counterpart sweep; merge before creating its new tag.
4. Push the new tag to trigger the release workflow, approve the protected
   WordPress.org deployment, then validate GitHub zip, SVN/directory version
   and published links/artifacts. No tag or deployment was performed here.

JS/CSS linting and broader coverage remain separate maintenance work. The
completed functional release checks are no longer an open generic backlog item.
