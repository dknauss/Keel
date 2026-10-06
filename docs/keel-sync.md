# Keel ↔ Agency Experience sync

Keel shares lineage and several code paths with Agency Experience (AX), a plugin
from the same maintainer. A fix in one is usually a fix the other needs. Before this
process existed, ports happened when somebody remembered to ask, so a bug fixed here
could stay live in the other plugin until someone thought to check.

AX replaced Pixel Managed Platform (PX) as Keel's counterpart in October 2026. It is
derived from PX and has the same shared code paths, listed at the end of this page.

## Every pull request records its counterpart

The description carries a `## Counterpart` section with exactly one line:

    Counterpart: ported — AX commit 1a2b3c4
    Counterpart: pending — dknauss/Keel#123 (the port, and what is left)
    Counterpart: not applicable — Keel-only WordPress.org deploy

- **ported** names the change in the other repository carrying the same change. From
  Keel that is `AX commit <hash>`; from AX it is the Keel pull request,
  `dknauss/Keel#123`.
- **pending** names where the port is tracked, so it can be found again. "Later" is
  not a tracker.
- **not applicable** gives the reason in a few words. Most pull requests are this,
  and the reason is the useful part.

Decide by whether the other plugin has the same code path, not by whether the diff
would apply to it cleanly.

AX is a local-only repository with no pull requests or issues. Reference an AX change
by its commit hash only, track a port that is still to do in a Keel issue, and keep
anything about a client, site or deployment out of this repository. Older lines that
cite `we-are-pixel/pixel-experience#N` or `PX commit <hash>` refer to the predecessor
and no longer resolve. Do not write either on a new pull request. The checker rejects
`PX commit`. It cannot reject the old repository reference, because it accepts any
`owner/repo#N` without knowing which repositories exist, so that one is on the author.

## The check

`.github/workflows/counterpart.yml` runs `bin/check-counterpart` on every pull
request event, including edits to the description. It fails when the line is
missing, repeated, unfilled, or lacks what its kind needs:

- `ported` needs the finished change: `owner/repo#N`, a link to a pull request,
  issue or commit, or `AX commit <hash>`.
- `pending` needs a tracker for the work that is left: `owner/repo#N`, or a link to
  an issue or pull request. A commit, as a hash or a link, is not accepted here,
  because a commit is a finished port.
- `not applicable` needs a reason.

Bot pull requests are exempt.
`tests/counterpart-check.php` covers the accepted and rejected forms. AX carries the
same checker, byte for byte; change both together.

For the check to block a merge, it has to be a required status check in the
repository's branch protection.

## Releases sweep what is still pending

Before tagging, run `bin/counterpart-sweep`. It lists pull requests merged since the
last tag whose line still says `pending`, and exits non-zero if there are any.
Deliver each port, or change its line to `not applicable` with the reason it no
longer applies.

## Shared code paths

Check the other plugin whenever a change touches one of these:

- Core version status and the same-line security patch: `includes/backports.php`
  (AX: `includes/classes/CoreUpdates/`).
- The core auto-update policy, translation updates and `wp-config.php` locks:
  `includes/settings-page.php`, `includes/bootstrap.php`
  (AX: `includes/classes/Security/Settings.php`).
- The admin menu width, saved and previewed: `includes/admin-ux.php`,
  `assets/css/settings.css` (AX: `AdminCustomizations/Operations.php`,
  `assets/css/operations-settings.css`).
- Security defaults: REST user discovery, XML-RPC, application passwords, password
  strength and breach screening, author enumeration, security headers.
- Comments, the environment indicator, and Site Health reporting of any of the above.
- CI and release gates.

AX keeps the feature-by-feature record in its parity matrix,
`docs/keel-feature-matrix.md`. A change there that alters parity updates the matrix
row in the same commit.
