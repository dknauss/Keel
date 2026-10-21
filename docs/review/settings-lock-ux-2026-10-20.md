# Settings lock UX verification — 2026-10-20

Result: both remaining 0.7.0 lock UX items are implemented. Locked settings
carry a lock icon and visible “Locked” text. A linked summary groups configuration
and network policy separately, counting each supported setting once with
configuration taking precedence. The network screen summarizes only configuration
locks; network policy itself remains editable there.

## Regression tests

`tests/settings-render.php` renders unlocked, configuration-locked and mixed-policy
site screens, and the actual network template. It checks counts, markers on grouped
toggles and sliders, overlap precedence, unsupported-setting exclusion, local link
targets and omission of the empty summary. The eight new feature assertions failed
before implementation and pass afterwards. Existing tests retain coverage of
locked controls preserving saved preferences and refusing forged writes.

The full PHP suite, PHPCS, PHPCompatibility, scoped PHPStan, static contracts,
PHP syntax lint and JavaScript syntax checks passed.

## Real browser lab

Disposable local Studio WordPress 7.1.3 / PHP 8.4 / SQLite network with the branch
symlinked as the network-active Keel plugin. No production site was changed.

Fixture:

- `WP_AUTO_UPDATE_CORE=false`, `WP_POST_REVISIONS=6`,
  `DISALLOW_UNFILTERED_HTML=true`: three configuration locks.
- Network policy manages REST User Discovery, XML-RPC Pingbacks, Admin Menu Width
  (240px), and Remember Me Length: four network locks.
- The site blocks XML-RPC and disables Remember Me, hiding two of the locked
  dependents until their summary links are followed.

Chromium with Playwright and axe-core 4.11, on both screens:

- Correct marker and link counts: seven on Site Defaults, three on Network Policy.
- No axe WCAG 2.0/2.1 A/AA or best-practice violations in the plugin's `.wrap`
  at 1280px, 640px and 390px. No horizontal overflow or JavaScript page errors.
- Enter on a summary link reveals the hidden dependent, scrolls to it and focuses
  its locked control. The controller's `aria-expanded` changes to true. Both the
  grouped XML-RPC toggle and the separate Remember Me row were exercised.
- ArrowRight on the network-locked menu-width slider leaves its value unchanged.
- The same checks passed in a real `he_IL` admin locale with WordPress's RTL
  styles loaded. Setting `.rtl` on an English page alone is not an RTL lab.

The first browser pass caught native fragment navigation discarding the intended
control focus. Summary navigation now sets the fragment and restores control
focus in the next animation frame; the browser regression checks this.

No manual VoiceOver/NVDA or Windows high-contrast pass was performed. This is a
focused regression pass, extending the broader 2026-10-19 settings audit.

## Reproduce

Use a disposable Studio network with the fixture above and an Administrator who
is a Super Admin. Install `playwright` and `@axe-core/playwright` in a local Node
module directory; `NODE_PATH` can point at that directory's `node_modules`.

```sh
node tests/integration/settings-lock-ux.mjs http://localhost:8889
```

`PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH` optionally selects an already-installed
Chromium binary. Change the admin user's locale to `he_IL` (with that core
language installed) and repeat for RTL. After clearing the fixture locks, run:

```sh
node tests/integration/settings-lock-ux.mjs http://localhost:8889 --unlocked
```

The script changes no WordPress options. It creates a temporary directory for
browser captures and prints its location. Studio's local auto-login supplies the
session; no cookies or credentials are saved in the test or this record.

## Screenshots and release state

Listing screenshots 4 and 5 were retaken with the real fixture and reviewed.
Screenshots 1–3 remain the reviewed 2026-10-19 captures: this change alters neither
the patch-status panel, the Passwords help tab nor Site Health Info.
`composer verify:screenshots` is current.

The roadmap and TODO mark both lock improvements complete. Version stays at
0.6.9 pending a release-preparation PR; the two-default upgrade notice remains
staged in TODO. Final candidate checks, version bump, merge and tagging remain.
