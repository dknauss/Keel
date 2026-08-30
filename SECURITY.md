# Security Policy

## Supported versions

The current release is `0.5.10`, published on wordpress.org as `keel-defaults`.
Install it from the plugin directory; GitHub releases and the rolling `latest`
build remain available for anyone who prefers them.

Note that the plugin folder changed from `keel` to `keel-defaults`, and WordPress
identifies a plugin by its folder. A site installed from a GitHub release made
before that change keeps the old folder until it is removed by hand, and will not
update it — so check which copy is active before reporting against a version. The
changelog says which release moved it.

Security fixes target the latest release and land on `main`. Older releases may
receive them when the issue is severe and a safe backport is practical; with two
releases out, that has not come up yet.

## Reporting a vulnerability

Please do **not** open a public issue or pull request for a suspected
vulnerability.

Use GitHub's private vulnerability reporting on this repository — the **Report a
vulnerability** button under the Security tab. If that is unavailable, contact
the maintainer privately through the contact options on their GitHub profile, and
include only the minimum detail needed to establish a private channel.

A useful first report includes:

- the affected version or commit;
- what an attacker gains, in one sentence;
- the WordPress and PHP versions, and whether it is multisite;
- whether authentication is needed, and if so at what role;
- which Keel defaults were switched on, since almost everything here is a toggle
  and most of the code does not run unless something enabled it.

That last point matters more for this plugin than for most. Keel ships 39
independent defaults — 16 toggles on out of the box, 14 off and opt-in, and 9
settings that are not toggles at all — so "with default settings" and "with this
default enabled" are very different reports. The ones most likely to matter here,
including the REST gate and the Classic editor, are among the opt-in ones.

Please avoid publishing exploit details until a fix is available and disclosure
is coordinated.

## Expected response

- Initial acknowledgement: within 7 days.
- Triage and an update on direction: within 14 days of acknowledgement.
- Fix timing depends on severity, reproducibility, and release risk.

This is a small project maintained by one person. These are targets, not a
service-level agreement, and they are the same ones the sibling plugins state.

## What Keel does that a reviewer will want to know about

Two behaviours are worth stating up front, because both look alarming from the
outside and neither is what it first appears.

**An outbound network request when a password is set.** With
`require_strong_passwords` on, Keel checks new passwords against the Have I Been
Pwned range API. It sends the **first five characters of a SHA-1 hash** and
nothing else — not the password, not the full hash. The response is a list of
suffixes compared locally. The request asks for padding so its size cannot reveal
how many matches it held, and the whole check **fails open**: an unreachable,
truncated, or malformed response allows the password rather than locking people
out of password changes during an outage.

It can be switched off entirely with the `KEEL_DISABLE_HIBP` constant or the
`keel_disable_hibp` filter.

**Capability removal.** `limit_unfiltered_html_to_admins` removes
`unfiltered_html` from non-Administrators. It runs inside the `user_has_cap`
filter, so it decides from `$user->roles` and the already-resolved capability map
rather than calling `current_user_can()`, which would re-enter the same filter and
recurse. Super Admins keep the capability on multisite, and the `is_super_admin()`
call is guarded by `is_multisite()` for the same recursion reason.

## Out of scope

- Behaviour of other plugins that Keel warns about but does not change.
- WordPress core defaults Keel deliberately leaves alone. Keel changes what its
  own switches say it changes and nothing else; a core behaviour it has no
  setting for is not a Keel issue.
- The `keel_*` filters being used to weaken a default. Those are documented
  extension points; a site that filters a guard off has made a choice.
