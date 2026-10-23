# Updates-screen verification — 2026-10-23

Development verification after #299 and #303–#312; no version bump, tag or release.
This pass includes the TODO/roadmap reconciliation and a disposable Playground demo.

## Findings and changes

1. **Page overflow at 320px.** Before the fix, the plugin/theme summary made the
   document 352px wide at a 320px viewport. Evidence tables now sit in named,
   keyboard-focusable scroll regions, with captions and scoped row/column headers.
   Wide content scrolls inside the table rather than moving the whole page.
2. **Focus visibility.** WordPress removes generic focus outlines. The new scroll
   regions therefore need their own visible focus rule. A versioned, dequeueable
   stylesheet loads only on the Updates screen; the outline is 2px blue.
3. **Failed collection wording.** A validated cached collection error previously
   appeared as “Not read yet.” It now says “Unavailable.” Missing, pending, expired
   and rejected malformed evidence still cannot establish that jobs are absent.
4. **Cold-cache side effect.** The stronger real-WordPress guard failed because
   timeline rendering called the minor-update state helper, which could resolve the
   branch tip again and issue HTTP during its filesystem-ownership check. The
   timeline now passes its already-resolved tip through that check. Existing
   no-argument policy callers retain their behavior; a known unavailable tip does
   not trigger a new lookup. Both warm and cold renders pass guards against HTTP,
   cron reads/scheduling and writes.

The table, failed-collection, and cold-cache regressions failed before their fixes.
No policy choices, saved Screen Options, capabilities, updater actions or version
floors changed. The development schema remains 44 settings.

## Browser and accessibility evidence

Disposable Studio site: WordPress 7.1.3, PHP 8.4, SQLite, branch symlink confirmed.
Only Keel is active; temporary MU-plugin read filters provide clearly labelled
synthetic cases and block outbound HTTP, cron spawning and installations.

- Chromium 156.0.8078.4 and Firefox 157.0: 1440, 1280, 820, 782, 640, 390 and 320px.
  The final document fits every viewport, including a long plugin identifier.
- Eight collection/check scenarios in each engine: partial, complete, missing,
  pending, expired, failed, stale and malformed. Partial/malformed scans leave
  absent hooks Unknown; complete snapshots qualify absence as cached evidence.
  Attempt markers never establish a successful WordPress.org response. Private
  failure payloads do not enter the page.
- Keyboard: the policy link opens the anchored Site Defaults control; Tab reaches
  the named scroll regions; arrows scroll overflowing content. Visible 2px focus
  outlines are verified in both engines. No focus trap was observed.
- 200% CSS zoom at a 1280px viewport does not introduce page overflow. This is a
  layout check, not a claim of an OS/browser accessibility zoom certification.
- `en_US`, `en_CA` and `fr_FR` render at 320px in both engines with the expected
  document language. French core UI uses its installed language pack; Keel falls
  back to English because Keel has no French catalog.
- Scoped axe WCAG 2 A/AA and 2.1 AA scans report no violations. Clipped table
  content leaves color-contrast results incomplete; see the contrast follow-up
  below rather than treating that as a complete automated contrast pass.
- No JavaScript page errors occurred in the local browser matrix.

[Desktop screenshot](images/updates-screen-desktop-2026-10-23.png) ·
[320px screenshot](images/updates-screen-mobile-2026-10-23.png).
The deliberately long identifier makes an unusually tall row; these are fixture
screenshots, not WordPress.org listing assets.

**Limits:** no manual VoiceOver/NVDA speech-output test, Safari/WebKit run or RTL
locale pass. Programmatic names, header scopes and keyboard behavior were checked;
this record does not claim a complete manual WCAG audit.

### Contrast follow-up

Computed foreground/background pairs from the rendered local panels meet 4.5:1;
measured text ratios range from 4.85:1 (description text on the admin background)
to 16.67:1. The focus outline is 4.54:1 against the admin background, exceeding the
3:1 non-text threshold. This arithmetic checks the observed opaque color pairs;
it does not turn clipped/offscreen axe nodes into a complete automated audit.

## Playground review demo

[Open the synthetic Updates-screen demo](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fdknauss%2FKeel%2Fcodex%2Fupdates-screen-verification%2Fplayground%2Fblueprint-updates-screen.json).

The demo installs this unreleased branch through the CORS proxy and lands on the
Updates screen. Its prominent fixture notice explains that all evidence is
synthetic. Two links switch stale/recent attempt markers; partial schedule evidence
and plugin/theme decisions changed by code are visible. A demo-only MU-plugin
blocks WordPress HTTP and both download/install hooks. It is not runtime plugin
code. No release asset is published.

The hosted demo loaded successfully in the visible in-app browser, with the policy
notice, all three named tables and synthetic changed-item rows visible in its
accessibility tree. Official Blueprint schema and disposable Playground PHP checks
also pass. The hosted tab reported zero console errors. The active plugin renders all three
named tables and its Site Defaults link in the nested WordPress frame.

## Real WordPress, compatibility and final checks

The existing timeline, extension-policy and policy-notice integration probes now
cover real capability gates (including network authority), unknown cache evidence,
failed and future markers, preserved opt-ins, secret exclusion and observational
side effects. All three pass on the disposable single-site lab and on a separate WordPress
7.1.3 / PHP 8.4 / SQLite multisite lab with Keel network-active. Both browser engines
also pass the seven-width/eight-state matrix on Network Updates (English). The
unmanaged policy link reaches the main-site control; after setting temporary
network management, its provenance changes to “Keel network policy” and keyboard
activation reaches the actual anchored network control. The temporary policy is
then removed.

- All 77 standalone suites, WPCS, PHP compatibility, PHPStan and type contracts
  pass locally (43 invalid contract cases rejected with 46 diagnostics).
- Six-row live installer matrix: [run 39916543989](https://github.com/dknauss/Keel/actions/runs/39916543989),
  all six rows passed on runtime/test commit `8bab3b7`. Includes PHP 7.4 / WordPress 6.4,
  French locale, persistent-cache multisite, operable minor/major policy variants
  and backward/forward installation flows. These installations occur only in the
  matrix's disposable fixtures.
- Independent review of runtime/test commit `8bab3b7`: no actionable findings.
  Final PR CI is checked before marking the PR ready for review.

No production site was changed. Temporary local fixtures are removed after the pass;
the user-facing Playground remains available for review.

## PR #313 review: compact presentation

The demo-only, all-capitals headings and repeated fixture warning are replaced
with one sentence-case warning, scenario links and a collapsed explanation.
Synthetic evidence remains explicitly labelled; HTTP/download/install guards remain.
Production Keel now groups the core timeline and extension tables in one native
`details` disclosure, **Update diagnostics**, closed by default. This changes
presentation only: existing server-side collection and authorization are unchanged.
The policy notice keeps its policy, settings link and any observed-settings mismatch
visible; provenance and delivery caveats sit under **Policy details**. There is no
new saved setting; the schema remains 44 individually configurable settings.
Security patch offers and install results remain outside the diagnostic disclosure.

The Help tab's **Restore Plugin or Theme** is WordPress core copy in
`wp-admin/update-core.php`. It describes temporary plugin/theme backups and automatic
recovery on upgrade failure, not a user-operated Keel restore or rollback feature.
Keel's same-line core installer separately requests core's `attempt_rollback` option;
it does not add a general backup/restore interface. Core help is preserved.

Chromium 156 and Firefox 157 pass closed/expanded presentation and Enter/Space
keyboard activation at 1440, 782, 390 and 320 CSS pixels, with no page overflow or
JavaScript errors. Collapsed diagnostics occupy about 18 CSS pixels versus about
592 expanded on desktop and 982 on a 390-pixel mobile viewport. Scoped axe checks
on expanded diagnostics and policy details report zero WCAG A/AA violations.
The three real WordPress integration probes pass again on the single-site lab.
Official Blueprint schema and disposable Playground fixture checks pass; schema
requests now identify the validator to avoid an observed HTTP 403 for Python's
default user agent. The earlier six-row installer matrix applies to the unchanged
installer behavior; it was not rerun for this presentation refinement.

- [Compact desktop](images/updates-screen-compact-desktop-2026-10-23.png)
- [Compact mobile](images/updates-screen-compact-mobile-2026-10-23.png)
