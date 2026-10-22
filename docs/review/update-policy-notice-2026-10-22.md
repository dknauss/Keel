# Persistent Keel update policy notice — 2026-10-22

Development after #297 merged as `b4eb1e9`. No version/data-version bump,
new defaults, tag, release or deployment. The schema remains at 44 settings.

## Presentation and scope

WordPress’s own policy text and controls are unchanged. A native, non-dismissible
inline information notice is rendered immediately afterward using
`after_core_auto_updates_settings` at priority 9, before the existing Keel reports.
It remains in place rather than moving to the page’s admin-notice area. It has a
bold “Keel update policy” label; no alert announcement, timer, custom CSS or JS.

The notice names the current X.X release branch and explains the configured
minor/all/manual/inherit choice. Minor policy does not enable major upgrades;
it does not claim that nobody else can install them or that manual installation
is mandatory. Inheritance explicitly leaves WordPress or another component in
control. Configuration restrictions override the saved choice and get attribution
before network or site policy. Malformed policy/version inputs remain unavailable.

Core’s already-filtered minor/major/development flags are used only to acknowledge
a difference from configured policy. The notice does not run selectors, policy
filters, HTTP requests, refreshes, installation probes or writes. Policy does not
guarantee delivery; cron, hosting and other filters may affect it.

`update_core` is required to render. The direct setting link requires
`manage_options`, or `manage_network_options` for a network-managed policy.
The existing stable anchor is used on both settings screens. The notice does not
change multisite policy resolution or grant settings-management authority.

## Verification

- Standalone boundary tests cover all four choices, unexpected filtered decisions
  (including development releases), malformed data, capability checks, direct
  site/network links, and configuration taking precedence over saved choices.
  Missing-feature and unexpected-development-decision tests failed before fixes.
- Real WordPress 7.1.3/PHP 8.4 Studio multisite probe passes on disposable
  `keel-test`; settings and user context restored afterward. Throwing sentinels
  guard policy filters, HTTP and option writes during rendering. The probe is
  included in all six live installer matrix rows.
- Browser: real Network Updates screen retains core’s exact maintenance/security
  sentence. Notice follows `.auto-update-status` and precedes the delivery timeline.
  Checked 1280px desktop and 390px mobile: no horizontal overflow, native spacing,
  readable text and visible keyboard-link focus. Direct link lands at the policy
  anchor with the current `minor` selection. No console errors (jQuery Migrate log only).
  Captures retained locally under `.playwright-mcp/keel-update-policy-notice-*.png`;
  WordPress.org listing screenshots are unchanged.
- Independent code review: no findings.
