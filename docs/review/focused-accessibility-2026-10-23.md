# Focused accessibility verification — 2026-10-23

Scope: Updates disclosures and evidence tables, settings locks, dependent controls,
menu-width slider, and narrow-screen RTL layout. Baseline: merged #313 (`fb121e0`),
unreleased. Version remains 0.8.0 and the schema remains 44 individually configurable settings.
This is a focused WCAG 2.1 A/AA regression pass, not a conformance certification.

## Findings and fixes

| Finding | Evidence | Fix |
| --- | --- | --- |
| Locked checkboxes swallow page-navigation keys (2.1.1, 2.4.3). | On Chromium, Page Down with a visible locked checkbox focused left scrollY at 956; the assertion failed. After the fix it advanced to 1684. Space still cannot change the checkbox. | Cancel Space on locked checkboxes, preserve their navigation keys; let Escape through on all locked controls. Keep value-changing keyboard operations blocked on locked sliders, selects and number inputs. Server-side lock enforcement is unchanged. |
| WebKit cannot horizontally scroll a focused evidence region with arrow keys (2.1.1). | At 320px, the plugin/theme table region was 298px wide with 342px content. Two Right presses left scrollLeft at 0 before the fix. Afterwards the positions were 40 and 44; Left returned to 4. | An Updates-only, dependency-free script implements physical Left/Right scrolling on the focused region. It cancels the native event only when the offset moves, handles negative RTL offsets, and ignores modifiers and descendant controls. |

No settings, collectors, update actions or stored policies were added or changed.
There is no counterpart change: Agency Experience has neither Keel's shared
locked-control handler nor these Updates-screen evidence regions (checked its
settings scripts and includes). The existing AX menu slider is a different path.

## Browser evidence

The committed regression is `tests/integration/focused-accessibility.mjs`.
It drives real WordPress 7.1.3 / PHP 8.4 / SQLite single-site and multisite labs.
The labs load the checkout through their existing plugin symlinks. Read filters
supply two configuration locks, three network locks, initially hidden XML-RPC and
Remember Me dependents, and explicitly synthetic update evidence. English and
Arabic core language packs are installed; cookie-selected `ar` loads real RTL
admin styles, not just an added CSS class. Keel strings fall back to English.

The browser checks cover:

- Native disclosure Enter/Space activation, collapsed default state, and Tab from
  the expanded diagnostic summary into a named scroll region.
- Table accessible names and captions, visible focus, physical arrow scrolling
  where content overflows, and containment of horizontal overflow.
- Locked controls remaining focusable, described by existing lock/help elements,
  refusing value edits, allowing Tab away, and preserving Page Down and Escape.
- Lock-summary links revealing hidden controls and focusing the actual intended
  control above the admin bar; the harness waits for native scrolling to settle.
- XML-RPC dependent rows appearing/disappearing with their controller, matching
  `aria-expanded` and `aria-controls`, and menu-slider value text matching its readout.
- Widths 1280, 782, 390 and 320 CSS pixels plus 640px reflow, on Site Defaults,
  Network Policy and single-site/Network Updates. The 640px check is a reflow
  proxy for a 1280px viewport at 200% zoom, not actual browser/OS zoom testing.
- Scoped axe WCAG 2.0/2.1 A/AA checks and JavaScript error collection.

[Machine-readable browser results](focused-accessibility-results-2026-10-23.json).

Results: Chromium 156.0.8078.4, Firefox 157.0 and Playwright WebKit 27.2 pass
30 browser/site/locale/screen combinations, including 120 width checks and 30
640px reflow checks. Scoped axe scans report zero violations; `color-contrast`
remains incomplete on clipped table content, so this is not a complete automated
contrast audit. #313's computed text/focus contrast evidence remains applicable
to the unchanged colors. No JavaScript page errors occurred.

Synthetic lab captures, not live-site evidence or WordPress.org listing assets:
[RTL Network Updates](images/focused-a11y-rtl-updates-2026-10-23.png) and
[RTL Site Defaults](images/focused-a11y-rtl-settings-2026-10-23.png).

## Native Safari and assistive-technology boundary

Native Safari's accessibility tree exposes Policy details and Update diagnostics
as summaries with an off/on state. The local Updates page and its accessible
names were inspected. Native interaction was interrupted by changing app state;
that inspection alone is not a completed native Safari keyboard pass.

VoiceOver initially presented its first-run welcome/tutorial. It was launched,
but its speech/caption output could not be captured reliably through the available
UI tools. No announcements are inferred from the browser accessibility tree.
System Settings subsequently confirmed VoiceOver off. VoiceOver Utility and the
lab browser tabs were used only for this check; no remote site was changed.
Manual VoiceOver/NVDA speech, real Safari keyboard completion, browser zoom,
Windows high contrast and touch usability remain unverified. WebKit automation
is recorded separately from native Safari, and Arabic layout checks do not claim
Arabic speech or a translated Keel interface.

Apple documents the caption-panel command in its
[VoiceOver command reference](https://support.apple.com/en-mide/guide/voiceover/cpvokys01/mac).

## Reproduction and cleanup

Use disposable local Studio labs: first single-site, second multisite, with Keel
active and the checkout symlinked. Install the Arabic core language through Studio
WP-CLI (`language core install ar`). In each lab, create a temporary MU-plugin
wrapper requiring the checkout's `tests/integration/fixtures/focused-accessibility.php`
by absolute path. The fixture refuses non-local hostnames, supplies read-only
settings/locale filters and loads the demo's HTTP/download/install guards.
Do not install it on a real site.

With Playwright, its Chromium/Firefox/WebKit binaries, and axe-core installed in an
external Node module directory:

```sh
NODE_PATH=/path/to/node_modules node tests/integration/focused-accessibility.mjs \
  http://localhost:8889 http://localhost:8890
```

The script does not submit settings. It writes browser accessibility snapshots,
geometry, axe results and WebKit desktop/mobile screenshots into a temporary
directory printed on success. Remove both temporary MU-plugin wrappers afterward;
stop the multisite lab if it was started for this pass. Language packs may remain
as reusable lab tooling. Production WordPress.com settings-save confirmation
remains a separate maintainer task.

## Local regression checks

All 77 standalone suites, WPCS, PHP compatibility, scoped PHPStan, PHP syntax and
JavaScript syntax checks pass. Static contracts reject 43 invalid cases with 46
diagnostics. The asset-provider assertion failed before the new keyboard script
was enqueued, then passed. Independent review found no actionable issues in the
runtime, fixture and regression changes. The six-row live installer matrix was not
rerun: this branch changes keyboard handling and asset loading, not installation.
#313's post-merge CI is green; final branch CI is checked on the pull request.
