# Plugin and theme auto-update policies — 2026-10-21

Development work after merged, unreleased #294. No version, data-version or
stable-tag change, tag, deployment or release is included.

## Policy and scope

`plugin_auto_update_policy` and `theme_auto_update_policy` independently accept
`enabled`, `disabled` and `unset`. Both default to `unset`, including on existing
installations with no stored keys. That passes the incoming decision through
unchanged, including core's null sentinel used to detect forced item policies.
Explicit choices use the documented item filters at priority 10; later callbacks
can override them. Neither WordPress's `auto_update_plugins` nor
`auto_update_themes` opt-in option is rewritten. No installer is invoked.

There is no minor-only promise: plugin/theme version numbers do not establish
compatibility or security scope. Core's filesystem, updater, VCS, requirements and
schedule gates still decide whether anything can be installed.

Plugin/theme files are shared on multisite. Network policy wins; absent that,
the network's main-site Keel setting decides consistently on every blog. Subsite
controls show the effective shared policy and are locked; forged site POSTs retain
the dormant local preference. This site-only lock does not prevent editing or
removing network governance while Network Admin has a subsite as current blog.
Global `AUTOMATIC_UPDATER_DISABLED` / `DISALLOW_FILE_MODS` restrictions lock both
controls and display Disabled. `WP_AUTO_UPDATE_CORE` does not govern extensions.
Site Health's posture reports the shared/configured policy, not dormant preferences.

## Decision report

The Updates screen evaluates up to 200 installed plugin IDs and 200 installed
theme IDs against their existing cached response/no_update metadata. It reproduces
core's baseline from per-item opt-ins, type-wide enablement and offer flags, then
applies each documented item filter once. Removed items are excluded; missing or
malformed cached collections and mismatched identities stay unknown. It counts
enabled/unknown decisions and filter changes, listing at most ten changed IDs per
type. Matching the baseline does not prove no filter enforces the same decision.

The report requires both extension-update capabilities and, on multisite,
`manage_network_options`. It adds no remote fetch, cron scan, result store or
installation probe. Offer payloads, URLs and callback paths are not retained or
printed. Like core's item screens, it executes third-party filter callbacks;
results can vary with context. It reports policy, never installation guarantees.

## Validation

- Full unit suite; WPCS; PHP syntax and 7.4 compatibility; existing scoped PHPStan;
  static type contracts (43 invalid cases / 46 diagnostics).
- Unit tests cover bool/null preservation, missing and invalid policies, explicit
  decisions, main-site/network precedence, configuration restrictions, permissions,
  cached opt-ins, later filter overrides, unknown metadata and evaluation/detail caps.
- Real WordPress 7.1.3 / PHP 8.4 / SQLite multisite probe covers actual filter
  registration, earlier/later callback precedence, existing-install fallback,
  invalid/malformed POSTs, network overrides, a disposable subsite's locked controls,
  effective posture, preserved dormant preferences, editable network governance,
  global restrictions, and unchanged per-item options. All temporary settings,
  callbacks, user context and the fixture site are restored; no updates run.
- The malformed select test first failed with an array-to-string warning promoted
  to an exception. Select sanitization now rejects nonscalar input before casting.
- The subsite posture test first failed by reporting the dormant choice. It now
  resolves the same shared policy as the runtime filters.
- Playwright: main-site controls default to Leave unchanged with all three choices;
  network controls are editable and have accessible names/descriptions; the Updates
  report has no horizontal overflow at 1280px or 390px. Shared rendering/lock tests
  cover both new controls and their configuration markers.
- Independent review identified the subsite/network editing boundary, now fixed
  and covered by the real WordPress probe; final review found no remaining issues.
- The new real WordPress probe is wired into every live installer-matrix row.

The WordPress.org listing screenshots were not re-recorded as a release review;
there is no release in this change. The next release pass should review them
against the two additional controls and report.

All six [live matrix jobs](https://github.com/dknauss/Keel/actions/runs/39699264892)
passed on `1321acb`, including the PHP 7.4 / WordPress 6.4 floor, French locale,
persistent-cache multisite and the existing real backward/forward installer flows.
PR CI passed across PHP 7.4–8.5. The initial matrix probe incorrectly expected an
explicit Enabled policy to bypass the globally disabled updater in four fixtures;
the runtime correctly preserved core's decision. The probe now asserts both locked
and operable expectations. Both variants also passed separately in the local lab.
This final follow-up only records evidence; runtime and test sources are unchanged.
