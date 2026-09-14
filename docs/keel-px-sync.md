# Keel ↔ PX sync

Keel shares lineage and several code paths with Pixel Managed Platform (PX), a
private plugin from the same maintainer. A fix in one is usually a fix the other
needs. Before this process existed, ports happened when somebody remembered to ask,
so a bug fixed here could stay live in PX until someone thought to check.

## Every pull request records its counterpart

The description carries a `## Counterpart` section with exactly one line:

    Counterpart: ported — we-are-pixel/pixel-experience#344
    Counterpart: pending — dknauss/Keel#123 (the port, and what is left)
    Counterpart: not applicable — Keel-only WordPress.org deploy

- **ported** names the pull request or commit in the other repository carrying the
  same change, open or merged.
- **pending** names where the port is tracked, so it can be found again. "Later" is
  not a tracker.
- **not applicable** gives the reason in a few words. Most pull requests are this,
  and the reason is the useful part.

Decide by whether the other plugin has the same code path, not by whether the diff
would apply to it cleanly.

PX is private. Reference its pull requests by number only, and keep anything about a
PX client or deployment out of this repository.

## The check

`.github/workflows/counterpart.yml` runs `bin/check-counterpart` on every pull
request event, including edits to the description. It fails when the line is
missing, repeated, unfilled, or lacks what its kind needs: a reference for `ported`
and `pending`, a reason for `not applicable`. Bot pull requests are exempt.
`tests/counterpart-check.php` covers the accepted and rejected forms. PX carries the
same checker, byte for byte.

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
  (PX: `includes/classes/CoreUpdates/`).
- The core auto-update policy, translation updates and `wp-config.php` locks:
  `includes/settings-page.php`, `includes/bootstrap.php`
  (PX: `includes/classes/Security/Settings.php`).
- The admin menu width, saved and previewed: `includes/admin-ux.php`,
  `assets/css/settings.css` (PX: `AdminCustomizations/Operations.php`,
  `assets/css/operations-settings.css`).
- Security defaults: REST user discovery, XML-RPC, application passwords, password
  strength and breach screening, author enumeration, security headers.
- Comments, the environment indicator, and Site Health reporting of any of the above.
- CI and release gates.

PX keeps the feature-by-feature record in its parity matrix. A pull request there
that changes parity updates the matrix row in the same change.
