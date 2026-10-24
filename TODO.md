# Keel — TODO

Working checklist — what's in flight right now. Milestones and direction live in
[ROADMAP.md](ROADMAP.md).

The original scope document, `pixel-lite-scope.md`, was kept outside this repository and
is retired: it was no longer on the maintainer's machine when looked for on 2026-10-13.
This file and ROADMAP.md are the only record of scope.

## Now — observe 0.8.0 in the field

Keel 0.8.0 is published on GitHub and WordPress.org (verified 2026-10-21).
The protected WordPress.org dry run passed before publication. SVN revision is
3759061; both public packages, the default download and all listing assets match
the tagged sources. See the
[verification record](docs/review/release-verification-0.8.0-2026-10-21.md).

- [x] **Prepare the 0.6.9 candidate** — version metadata, cache-busting constant,
      changelog and upgrade notice synchronized; no new defaults or PHP floor change.
- [x] **Release 0.6.9** — #274 merged after green CI/live matrix and clear
      counterpart sweep; v0.6.9 tagged, protected deployment approved, SVN revision
      3749515 and published packages/assets verified.
- [x] **Verify bounded HIBP Site Health presentation** — completed 2026-10-23;
  unreleased. Chromium, Firefox and Playwright WebKit pass nine synthetic states
  across standalone, network-manager and delegated site-admin views: 81 panels,
  nine access denials, 324 width checks and nine settings-link checks. Keyboard,
  status grouping, privacy and scoped axe checks pass; no runtime defect found.
  Viewing caused no HIBP requests or authority writes. See the
  [browser verification record](docs/review/hibp-site-health-browser-2026-10-23.md)
  for evidence, cleanup and manual-screen-reader/native-Safari limits. #317 merged
  as `c2c967c`; #318 merged as `0a619bd`, including the lab cleanup correction.
  Both are unreleased; no new setting, version bump or release.

- **Current state (2026-10-24):** the focused Updates-screen verification pass is
  complete; see the [verification record](docs/review/updates-screen-verification-2026-10-23.md).
  Admin List Tables and the accepted update/support work are merged and unreleased;
  verification fixes are merged in #313 (`fb121e0`), accessibility fixes in #314
  (`1d754a9`), the bounded HIBP budget in #316 (`e6de5ca`), and the policy/diagnostic
  refinement in #322 (`4afe74a`); all unreleased.
  #322 places the compact policy notice above **Current version** while preserving
  WordPress's text, and moves update-forensics tables to Site Health. The full
  three-engine focused accessibility matrix re-verified that placement on 2026-10-24:
  42 lab combinations, 168 width checks, no scoped axe violations or page errors.
  Assurance gates merged in #324 (`facde24`); unreleased. The focused PHP boundary
  pass found and fixed legacy network-action authority and malformed-setting
  conversion gaps, with adversarial regression tests; #325 merged as `1d858a0`,
  unreleased. See the [review record](docs/review/php-boundaries-2026-10-24.md).
  The subsequent local HTTP authorization pass and its scope are recorded in
  [the lab evidence](docs/review/http-authorization-2026-10-24.md).
  The AX budget port is merged as commit `f628cb8`; #315 is closed. The development schema has 44
  individually configurable settings. Extension policies and Admin List Tables default to Leave unchanged;
  Tidy includes date and time without a separate control. Saved Screen Options and
  per-item update choices are preserved. The maintainer's WordPress.com settings-save
  confirmation remains open. Continue dated field observation; no
  version bump or release is authorized. Routine PHPStan expansion stays paused.

- [x] **Release 0.6.0, 0.6.1, 0.6.2, 0.6.3** — tagged on commits carrying their own
      green CI and live matrix; deployed to wordpress.org through the reviewed SVN path.
- [x] **Validate the published artifacts** — GitHub ZIP, SVN tag, directory version,
      screenshots, upgrade notice and Playground links, per release.
- [x] **Decide where the screenshot check belongs** — a **major-release step**, looked
      at by a person who then records or skips it. Not CI. Making it a merge gate would
      turn "somebody looked at three pictures and judged them still true" into a chore
      discharged by running the recording command, and being routinely satisfied without
      looking is the one failure this check cannot survive. Recorded in
      `bin/verify-screenshots.sh`.
- [x] **Refresh the stable-check cache on release day** — done 2026-09-13, released in
      0.6.6. The status map was cached for a day and never invalidated, so a
      site running the version a new security release had just made insecure read as
      latest for up to 24 hours: the window the check exists for. Keel now fetches it
      again when core stores update offers naming any release the map does not list —
      any, not only a newer one, because a security release can ship on an older line
      alone. Development offers prove nothing and are skipped; with nothing cached the
      fetch stays lazy; a failed refresh keeps the old map for five minutes, not a day.
      Found reading the wp-env thread on the same endpoint
      ([WordPress/gutenberg#81553](https://github.com/WordPress/gutenberg/issues/81553)),
      where stable-check and the release it names are shown drifting apart by hours.
      Pinned in `tests/backport-status.php`; staged live on every row of the backport
      matrix by `tests/integration/stable-check-refresh-probe.php`.
- [x] **Environment override and mislabelled-site warning** — shipped in tagged 0.6.8. The schema
      gained `environment_override` (Automatic, or a fixed environment
      Keel uses instead of `WP_ENVIRONMENT_TYPE`). Found on WordPress.com sites whose
      live `wp-config.php` had been replaced by a WordPress Studio copy declaring
      `local`: Keel held back their email and Jetpack went offline, with nothing on
      screen saying why. Keel now warns administrators, without a toggle, when a site
      declares local or development but is served from a public host or runs on
      WordPress.com (`IS_ATOMIC`). Pinned in `tests/environment-indicator.php`.
- [x] **Keep warning while the Environment override covers for a wrong label** — shipped in tagged 0.6.8.
      Found 2026-10-13 reviewing the override in use: choosing Production cleared the
      mislabelled-site warning, but WordPress, Jetpack and WordPress.com went on reading
      `local`, so the stopgap hid the fault. A second warning now replaces the first until
      `wp-config.php` agrees, then asks for Automatic; Keel's own screen points out an
      override that has become redundant. The advice names the source (constant or
      environment variable), the `wp-config.php` Keel found (beside the content directory
      on WordPress.com) and `wp config set WP_ENVIRONMENT_TYPE production --type=constant`.
      Site Health gains an Environment type check, and the Network Admin shows the label
      warning. No new settings. Pinned in `tests/environment-indicator.php`.
- [x] **Say when Jetpack, not Keel, is setting the admin menu width** — shipped in tagged 0.6.8. Found
      2026-10-12 on a WordPress.com Atomic site (Jetpack 16.3): the menu was 272px with
      Admin Menu Width at "Leave unchanged". Jetpack's masterbar package enqueues
      `jetpack-admin-menu`, which sets 272px, and Keel emits nothing at that setting.
      Keel now detects the enqueued handle (`keel_defaults_admin_menu_width_source()`),
      shows a note on the field only in that state, and marks the width row in Site
      Health. It does not change the setting; choosing 160px takes the width back.
      Nothing is inferred from `IS_ATOMIC`. Pinned in `tests/admin-menu-width-source.php`
      and `tests/settings-render.php`; documented under Admin Menu Width in
      `docs/wordpress-default-settings.md`.
  - Not yet seen on a real site: the note itself.
- [x] **The block editor follows the admin menu width** — shipped in tagged 0.6.8. Checked 2026-10-12
      on a disposable local site (Jetpack 16.3, its masterbar package started by hand).
      Outside fullscreen mode the editor's frame is `position: fixed` with its own
      `left`, 160px from core and 272px from Jetpack, and Keel never set it. At 240px
      without Jetpack the menu covered 80px of the editor; at 160px with Jetpack's
      stylesheet a 112px blank strip sat between menu and editor, and flyout submenus
      stayed 272px wide. `keel_defaults_admin_menu_width_css()` now sets the frame's
      offset and the flyouts' `min-width` to the chosen width, and stands down in
      fullscreen mode and for a folded menu. Pinned in `tests/admin-ux-media.php` and by
      the function's `@emits`/`@omits` tags; documented under Admin Menu Width in
      `docs/wordpress-default-settings.md`.
  - Left alone: the widgets screen's snackbar list, which Jetpack places for a 272px
    menu. Core offsets it before 7.0 and centres it after, under one class.
  - The October 13 snapshot predated installation of 0.6.8. All nine now run
    0.8.0 (checked 2026-10-23), but the live editor layout and Jetpack notice
    were not browser-verified in that read-only pass. The seven unchanged widths
    and two custom widths below describe October 13, not current configuration.
- [ ] **Get an en_CA language pack built**
  - 2026-10-23: translate.wordpress.org en_CA Stable showed 0 translated, 364 waiting
    and 140 untranslated, so no pack is built and en_CA sites read "behavior".
  - The maintainer requested the en_CA Project Translation Editor role on Polyglots
    the same day. Once granted, import `keel-defaults-en_CA.po` with every string
    filled into Stable and Development as approved. Not bundling the catalog; see
    ROADMAP.md.
- [ ] **Observe the field** — ongoing; the focused read-only pass completed
  2026-10-23 on all nine configured production sites. All run active Keel 0.8.0
  and WordPress 7.1.3; WordPress and Keel agree on production. The CLI snapshots
  identify host must-use overlap without structural overlap, but have no retained
  autoload, cron or policy-divergence reports. Missing evidence is unknown, not a
  clean bill of health. See the [current observation record](docs/review/production-observation-2026-10-23.md).
  #319 merged as `0cf70bc`; #320’s pending en_CA language-pack task is retained.
  The following October 13 notes remain historical; they are not current versions.
  - Checked 2026-10-13, read-only, on nine WordPress.com sites the maintainer runs.
    All nine are on 0.6.7 with WordPress 7.1.3 and a connected Jetpack. None declares
    a non-production environment, so the 0.6.8 warning for a live site that says it
    is local would show on none of them.
  - At the 2026-10-13 field snapshot, the scheduled live matrix had last run
    green on 2026-10-12 and no issues were open. This is historical context, not
    the current CI or issue status.
  - Looked for conflicts with WordPress.com on the same nine, 2026-10-13. Found none
    that breaks anything: Jetpack has no stored connection errors on any site, no
    setting is recorded as failing to take effect, no plugin shares an authoritative
    hook, and one site's PHP error log for the week has no entry from Keel.
    WordPress.com's plugin screen lists the plugins with multicall refused.
  - Found: every one of the nine shows the notice that two settings are "affected by
    code that cannot be traced to any plugin". The code is the host's own must-use
    plugins, on `wp_revisions_to_keep` (priority 5, so Keel's limit still decides)
    and `user_has_cap`. Fixed in 0.6.8: the report names must-use plugins and
    themes (dknauss/Keel#244). The October 23 CLI pass now identifies host must-use
    participation on both hooks on all nine; it does not verify the browser notice.
  - Found: the 272px menu belongs to one of the nine. Looked at in a browser on
    eight: one loads Jetpack's `jetpack-admin-menu` stylesheet, and it is the one
    with no admin interface style stored. The other seven are set to the classic
    interface, load `wpcom-admin-menu` instead and have core's 160px menu, so the
    0.6.8 note will not show on them. The one site has its width set in Keel, so
    the note will show there only at "Leave unchanged". On another, a different
    plugin sets the width, which Keel does not report.
  - Found: AI Connectors, a default, switches off Jetpack's AI features too. Run
    with Keel loaded and with it skipped, on two sites: Jetpack's AI Assistant block,
    its `jetpack-ai` routes and every AI extension are gone with the setting on.
    In 0.6.8 the setting says so where Jetpack is active (dknauss/Keel#246). The
    default stays on: connectors are for features on the site, and nothing an
    outside tool or agent uses to work on a site passes through that gate. On the
    site with WordPress.com's agent plugin active, the agent still answered.
  - Keel sends `X-Frame-Options: SAMEORIGIN` on the front end, which blocks a
    WordPress.com screen from framing the site. The 0.6.8 help text and reference
    doc say so (dknauss/Keel#245). The
    hosting overview and the plugins screen do not frame the site.
  - Triage early reports before opening another release cycle.
  - The installer has run on real `en_US` and `fr_FR` sites, but only by people who
    already knew how it worked. First outside use is still the untested case.

## Multicall repair — shipped; field confirmation remains

Found 2026-10-05 on a live WordPress.com Atomic site (Jetpack 16.3-a.7, Keel with the
then-shipped default `xmlrpc_allow_multicall = no`). The repair shipped in 0.6.7;
only the maintainer’s WordPress.com settings-save confirmation below remains open.

- [x] **Stop the multicall refusal from cutting a Jetpack site off from WordPress.com**
  - Evidence. WordPress.com's calls into the site failed with "transport error - HTTP
    status code was not 200 (405)": the plugin list and the newsletter settings write
    both failed. A `system.multicall` POST to `xmlrpc.php?for=jetpack` returned HTTP 405
    with the fault "system.multicall is disabled on this site." Jetpack's server-to-site
    requests use multicall, so the connection looked healthy from the site while
    WordPress.com could no longer act on it.
  - Decided: let Jetpack's own requests through. A multicall runs only when Jetpack's
    `Manager::verify_xml_rpc_signature()` verifies the request as signed by
    WordPress.com; `for=jetpack` decides nothing. It fails closed if that check is
    missing, errors or throws.
  - Decided: a refusal is an ordinary fault on HTTP 200. The fault code is `-32601`,
    not 405, because with remote publishing off core sends the fault code as the HTTP
    status. Pinned in `tests/xmlrpc-multicall.php` and, against a running site, in
    `tests/integration/assert-multicall-refusal.sh`.
  - Site Health has a "Jetpack and XML-RPC" check, shown only on a connected Jetpack
    site: critical when the endpoint is blocked, recommended when this Jetpack cannot
    verify requests.
  - Corrected: the setting's help text and `docs/wordpress-default-settings.md`, which
    both said refusing multicall was harmless.
- [ ] **Confirm on the site that found it**
  - Current read-only check, 2026-10-23: all nine run 0.8.0; eight still have
    multicall off and one on. No signed request or settings save was exercised.
  - Checked 2026-10-13 on nine connected sites running 0.6.7. Eight refuse multicall,
    the shipped default, and on each Keel finds Jetpack's signature check available.
    The ninth allows multicall. For two of the eight, the plugin list loaded live on
    WordPress.com. That is the first half; what cannot be seen from outside is
    whether it travelled as a multicall.
  - Still open: a settings change saved from WordPress.com with multicall off. It is
    a write to a live site, so it waits for the maintainer.
  - Not observed: the refusal as a fault on HTTP 200. The host answered an unsigned
    multicall with 429 before the request reached WordPress.
  - Only WordPress.com can sign a request, so the half that matters cannot be probed
    from outside: with the fix installed and multicall still off, the plugin list
    loads and a settings change saves from WordPress.com.
  - Not yet known: whether multicall was the only thing in the way. Remote publishing
    off also removes the `wp.*` methods and keeps `xmlrpc_enabled` false; if any
    WordPress.com call needs those, it still fails.

## Issue triage — 2026-10-15

- [x] **Compact list-table columns** — merged in #300 (`3a19720`); #265 closed; unreleased.
  - Original #300 implementation used two opt-in controls; merged #299 consolidated them. Preserve accessible labels, keys,
    sorting and saved Screen Options. Only site-admin post-type lists are in scope.
  - Verification: [disclosure and columns record](docs/review/update-disclosure-and-columns-2026-10-22.md).

## 0.7.0 privacy and content integrity — released

- [x] **Prepare the 0.7.0 candidate** — version metadata and asset cache-buster,
  changelog and upgrade notice synchronized. Both new default-on settings are
  called out for existing sites; the notice is under the directory's 300-character
  limit. Both remaining lock UX items are complete and #284 is merged.
- [x] **Release 0.7.0** — #285 merged after green CI and six-row live matrix;
  v0.7.0 tagged on the merge. GitHub release with ZIP/checksum and WordPress.org
  publication completed only after the new-version dry run passed. SVN revision
  3758838; package contents, metadata and all 11 listing assets verified. See the
  [publication record](docs/review/release-verification-2026-10-20.md).

- [x] **Keep password-protected posts out of site search**
  - Exclude protected search results for logged-in users without per-post editing
    or unlocked-password access; excerpt display otherwise depends on the consumer.
  - Preserve results for authors, editors and capabilities granted by other plugins.
  - Verified logged-out, Subscriber, author/editor, custom per-post/CPT capabilities,
    password access, counts/pagination and unchanged admin/non-search queries.
  - Implemented as `protect_search` (default on), independently of the editor UI
    switch; 42 settings in that historical development build. See the
    [search verification record](docs/review/protected-search-privacy.md).
- [x] **Hide broken-shortcode residue and report it in Site Health**
  - Merged together in #279 as `hide_shortcode_residue` (default on), released in 0.7.0.
  - Conservative strong-syntax cleanup of front-end post content; enclosed text,
    editors' evidence, registered handlers, examples and stored content are preserved.
  - Site Health reads capped, per-site, one-hour observations; no post scan or raw content.
  - Bare bracketed words, REST and excessive parser workloads stay unchanged. See the
    [verification and scope record](docs/review/shortcode-residue-2026-10-16.md).
- [x] **Validate typography compatibility across core versions and locales** —
  completed 2026-10-17: nine core versions, six real catalogs, single-site/multisite,
  10,260 comparisons plus Keel/shortcode and REST/feed probes. The narrow bridge
  has locale, boundary and surface gaps; the original core cache and performance
  concerns were addressed in the follow-up below. See the
  [validation record](docs/review/typography-compatibility-2026-10-17.md).
- [x] **Develop the standalone typography feature plugin** — implemented 2026-10-19
  in [feature-plugin #2](https://github.com/dknauss/wp-texturize-inline-quote-fix/pull/2); core #12249 now refreshes locale tables automatically
  and reduces added ~40 KB formatting cost by about 66% (HTML) / 55% (quote-heavy).
  All 10,260 real-locale/version/site-mode comparisons match the patched reference;
  Keel runtime stays unchanged. Feature-plugin review is complete and #2 is merged;
  a release remains a separate step.
- [x] **Typography is out of Keel's scope** — decided 2026-10-19. The texturize
  fixes and any "leave punctuation as typed" control belong to the separate feature
  plugin ([wp-texturize-inline-quote-fix](https://github.com/dknauss/wp-texturize-inline-quote-fix))
  and its core tickets. Keel registers nothing on `wptexturize`, `run_wptexturize`
  or the `no_texturize_*` filters, and adds no typography setting. The review
  records above stay as evidence; the [draft plan](docs/plans/typography-policy-review.md)
  is superseded for Keel. Coexistence with the feature plugin was lab-verified on
  2026-10-17 (1,140 rows matching the no-Keel results, with Keel's shortcode
  cleanup toggled).
- [x] **Mark locked settings in the row, not only in the note** — implemented
  2026-10-20. A lock icon and visible “Locked” text accompany each locked row
  heading, or each affected statement in grouped controls, on both screens.
- [x] **Summarise locks at the top of the settings screen** — implemented
  2026-10-20. Separate configuration and network-policy counts link to each
  setting. Configuration wins when both apply. Network Policy lists only
  configuration locks, since its own policy remains editable there. Summary
  links reveal hidden dependent settings and focus their locked controls.
  See the [verification record](docs/review/settings-lock-ux-2026-10-20.md).
- [x] **Check the settings screens in a right-to-left admin** — done 2026-09-14, on
      disposable `he_IL` copies of the `keel-latest` and `keel-network` labs, measured with
      Playwright at 1280px and 390px. Keel ships no `-rtl` stylesheet, so every directional
      rule is mirrored by hand.
  - The highlight bar on a linked setting: on the right edge (`inset -4px`), label clearance
    `padding-right: 16px`, grouped items `8px 16px 8px 12px`, and on mobile the stacked
    field cell clears it too. Correct.
  - The lock note: rule on the right, lock glyph gap on its left, on the site screen and for
    a network-locked setting. The locked control stays keyboard-focusable. Correct.
  - The admin menu width saved at 240px: menu flush right, content and footer
    `margin-right: 240px`, flyout submenus open beside the menu without overlap, and a
    folded menu is core's 36px. Correct.
  - **The live preview was wrong, and is fixed.** Dragging the slider to 300px on a site saved
    at 240px gave the content `margin-left: 300px` and kept `margin-right: 240px`, so the
    widened menu covered the start of every label. The left-to-right preview rule matched
    the `.rtl` body with `!important`, and the right-to-left rule had none. Both of its
    margins are now `!important`, and `tests/settings-render.php` asserts it.

- [x] **Run a UI/UX/accessibility sweep across the settings screens** — done
      2026-10-19 on a disposable WordPress 7.1.3 / PHP 8.4 lab, single site and
      network. See the [audit record](docs/review/settings-a11y-2026-10-19.md).
  - axe (WCAG 2.0/2.1 A and AA plus best practice), a keyboard walk of every control,
    heading/fieldset/label structure, and 1280px, 200%-zoom and 390px layouts.
  - **Fixed:** nine Network Policy value controls (selects, numbers and the menu
    width) had no accessible name, because the fieldset legend also covers the
    "Decide this for the whole network" checkbox. They now carry `aria-label` on
    both screens; `tests/settings-render.php` asserts it for every such field.
  - [x] The network settings screen's intro was confined to half the width — done in
    #217. The inline `max-width:46em` is gone, so the intro follows the admin
    column like the settings below it, and `tests/network-screen-prose.php` keeps
    a cap from coming back inline or in a stylesheet the screen loads. Re-measured
    2026-10-19: intro and table share the 1078px column at 1280px.

- [x] **Trim the security-review credit to a permanent line** — done in 0.6.1, earlier
      than queued. `tests/docs-consistency.php` bans a released version outside the
      changelog, so the credit naming 0.6.0 failed the moment 0.6.1 was the current
      version. That is exactly the revisiting-every-release cost the item predicted;
      the guard just found it first. `readme.txt` now carries the short standing
      form with no version number. `README.md` keeps the longer account, which is
      the right split: the directory listing wants the acknowledgement, the
      repository can afford the detail.

- [x] **Offer the patch on the Updates screen** — shipped in 0.6.3
  - Rendered on `after_core_auto_updates_settings`, not `core_upgrade_preamble` as
    planned: core documents the latter as firing *after* the update tables, which would
    have put the offer at the bottom of the page. The former fires immediately before
    core's update block, directly below the settings that decide which release the site
    would take.
  - The premise was confirmed live rather than only read in core — WordPress.org offers
    `6.9.7 autoupdate` to a 6.9.6 site and `get_core_updates()` returns only 7.1.
  - Carried a defect of its own: an install started there returned to Site Health and
    reported nothing, because a successful install removes the panel that would have
    shown the result. Fixed in the same release.

## 0.8.0 performance observability — released

- [x] **Prepare 0.8.0** — version metadata, changelog and upgrade notice finalized.
- [x] **Review release PR, tag and publish** — #289 and #290 merged; v0.8.0 tagged,
  dry run passed, GitHub release published and SVN revision 3759061 verified in the
  [verification record](docs/review/release-verification-0.8.0-2026-10-21.md).

- [x] **Attribute oversized autoloaded options**
  - Add size and probable-owner detail to core's aggregate autoload diagnosis.
  - Never expose values, auto-delete rows or state inferred ownership as fact.
  - Make collection asynchronous, cached, bounded and multisite-aware.
   Implementation and limits: [verification record](docs/review/autoload-attribution-2026-10-21.md).

- [x] **Attribute cron pressure and update-delivery delays**
  - Report overdue and unusually frequent hooks, schedules and probable owners.
  - Add offer freshness, next scheduled core check and recent update failures.
  - Distinguish an unknown external scheduler from a broken WP-Cron configuration.

## Then — 0.9.0 update operations and support

- [x] **Show the core-update delivery timeline** — merged in #294 and unreleased; no version bump or release.
  - Combine selected release, branch tip, attempted-check age, cached next check and retained failure evidence; #309 now renders this in tables.
  - Reuse core's decisions and stable blocker codes; never promise an execution time.
  - Missing success history stays unknown; no new collector or update controls.
  - Preserve site-local cached cron versus network-wide offer/failure scope.
  - Verification: [timeline record](docs/review/core-update-timeline-2026-10-21.md).
- [x] **Toggle plugin and theme auto-updates** — merged in #295 and unreleased.
  - Three states — `enabled`, `disabled`, `unset` — through the `auto_update_plugin`
    and `auto_update_theme` filters. `unset` stays the default: the per-item
    checkboxes on the Plugins screen are a deliberate choice to leave alone.
  - No "minor updates only". That channel is core-only —
    `Core_Upgrader::should_update_to_version()` runs for `'core'` and nothing else —
    because wordpress.org neither requires nor verifies semantic versioning. The label
    would promise a property the data cannot support, and would fail selectively on
    exactly the authors who do not use semver.
  - Report enabled decisions, unknown metadata and filter changes, with bounded
    installed-item coverage and no installation guarantee.
  - Verification: [extension policy record](docs/review/extension-auto-updates-2026-10-21.md).
- [x] **Export a sanitized Keel posture report** — merged in #296 (`ab299e8`); unreleased.
  - Include effective settings, conflicts, update operability and diagnostic evidence.
  - Exclude secrets, option values, user data and unnecessary site identifiers.
  - Explicit administrator download; multisite requires network-management authority.
  - Closed schema, validated policies and cached numeric summaries only; no inventory,
    site identifiers, raw option records, callback paths or failure payloads.
  - No refreshes, scans, update selectors or installation probes; unavailable evidence
    and unmeasured freshness stay unknown.
  - Verification: [export record](docs/review/posture-export-2026-10-21.md).

- [x] **Audit runtime registrations** — merged in #297 (`b4eb1e9`); unreleased.
  - Inspect four redirect callbacks and the session clamp against their bootstrap baseline.
  - Report missing registrations, lost precedence, inactive policies and unknown coverage
    in Site Health. No callback execution, probes, repairs or persistent verdicts.
  - Verification: [registration audit record](docs/review/runtime-registration-audit-2026-10-22.md).

- [x] **Clarify core update policy on the Updates screen** — merged in #298 (`67767de`); unreleased.
  - Preserve core’s text and add a persistent Keel notice with branch scope,
    policy provenance and a direct link to the relevant setting. No delivery guarantee.
  - Verification: [notice record](docs/review/update-policy-notice-2026-10-22.md).

- [x] **Report what this site normally sends to WordPress.org** — merged in #300 (`3a19720`); unreleased.
  - Static Site Health Info documentation, with no actual site values, request
    capture, inventory reads, extra HTTP, storage or blocking controls.
  - Verification: [disclosure and columns record](docs/review/update-disclosure-and-columns-2026-10-22.md).

- [x] **Inventory the Abilities authorization filters** — merged in #301 (`68a877b`); unreleased. Read-only registration inventory; no permission policy or observer. #312 adds the passive attribution guard; its AX port is complete (#311).

- [x] **Consolidate Admin List Tables** — #299 merged as `87cbfe8`; unreleased.
  One three-mode control replaces five controls; Tidy includes times without a
  separate setting. Image starts visible in helper mode; ID and Modified start
  hidden only where no saved Screen Options exist. Saved lists, including empty
  arrays, are never backfilled or marked. Both Tidy modes retain content-sized
  desktop columns and accessible full labels.
  #302's dates and sorting, #305's adjacent sort arrows and #310's wrapping/help
  refinements are merged. The combined #305/#310 check passed 77 standalone suites,
  86 real-WordPress list-table checks and Chromium checks at 375–1440px, including
  saved Screen Options across mode changes. This is focused coverage, not a full
  browser-engine or live installer matrix.
  Earlier design record: [three-mode record](docs/review/admin-list-table-modes-2026-10-22.md).

- [x] **Reconcile the October follow-ups** — #303 Playground badges, #304 overview
  wording, #306 narrow-screen environment indicator, #307 compact policy notice,
  #308 Canadian catalogs and #309 update-evidence tables are merged; unreleased.
  #309 distinguishes attempted checks from successful responses, leaves absent
  hooks unknown under partial coverage, and describes changed decisions against
  WordPress's baseline with a ten-per-type detail cap. The catalogs are reconciled
  against the combined source; MO header/bounds and continued PO strings are tested.

- [x] **Keep callback attribution passive** — #312 merged as `bb1d8ea`; unreleased.
  Unloaded class callbacks remain untraceable without triggering autoloaders;
  loaded classes and traits retain attribution. AX commit `668d326` ports the fix;
  #311 is closed and #312 records the counterpart as ported. AX local gates, PR CI
  and post-merge single-site/multisite WordPress canaries passed.

- [x] **Verify the consolidated Updates screen** — merged in #313 (`fb121e0`); unreleased. Verified 2026-10-23;
  PR #313 preserved visible policy/action warnings and shortened the synthetic demo
  notice. #322 subsequently moves the update-forensics tables from this screen to
  Site Health, where they remain behind WordPress's native disclosure; no new setting
  or release.
  [results and limits](docs/review/updates-screen-verification-2026-10-23.md).
  - Fixed 320px page overflow, supplied visible focus for keyboard scroll regions,
    distinguished failed collection from unread evidence, and stopped cold timeline
    rendering from resolving the branch tip again and issuing HTTP.
  - Chromium and Firefox passed seven widths on single-site and Network Updates,
    keyboard scrolling/link targets and 200% layout checks. English, Canadian and
    French locale rendering passed on single-site; French Keel strings fall back
    to English. Scoped axe scans found no violations; computed color pairs passed
    contrast checks. Manual screen-reader speech, WebKit and RTL were not tested.
  - All three strengthened real-WordPress probes pass on single-site and multisite;
    all six live installer-matrix rows pass on `8bab3b7`, including version-floor,
    French, persistent-cache multisite and backward/forward installation coverage.
  - A hosted Playground demo is available in the record: synthetic evidence is
    labelled and real WordPress downloads/installations are blocked. No production
    writes, new collectors, settings, version bump or release.

- [x] **Run the focused accessibility regression pass** — merged in #314 (`1d754a9`); unreleased. Verified 2026-10-23.
  Fix locked-checkbox page navigation and WebKit table arrow scrolling. Browser/RTL
  evidence and the remaining manual VoiceOver/native Safari limits are recorded in
  [the focused accessibility record](docs/review/focused-accessibility-2026-10-23.md).
  No version bump, new setting or release.

- [x] **Review the bounded HIBP lookup budget design** — approved for implementation.
  The user approved burst 10, one credit/minute, two admission leases and 64 fixed
  cache slots, with cached verdicts ahead of throttling and explicit unknown on denial.
  See the [bounded HIBP design](docs/superpowers/specs/2026-10-23-hibp-budget-design.md).
- [x] **Implement and verify the bounded HIBP lookup budget** — implemented in
  [#316](https://github.com/dknauss/Keel/pull/316), merged as `e6de5ca`; unreleased.
  Fixed database authority, two fenced leases, 64 cache slots, sampled shared
  diagnostics and paged uninstall preserve the 44-setting schema. SQLite labs
  and all four MySQL/MariaDB × WordPress floor/current cells pass, including
  process/connection loss, Redis, two networks, site-only activation and cleanup.
  See the [verification record](docs/review/bounded-hibp-budget-2026-10-23.md).
  AX counterpart is merged as commit `f628cb8d668c581beb43cffa26620783f384657a`;
  [#315](https://github.com/dknauss/Keel/issues/315) is closed.
  #313 and #314 remain merged and unreleased. No version bump or release.

## Completed in v0.6.0

This release added no new defaults; it adds the security-patch status
and deliberate same-line installer. The completed record stays here for provenance;
active work belongs in **Now** or **Next**, above.

- [x] **Security patch status** ([#134](https://github.com/dknauss/Keel/pull/134)) — done 2026-08-31, over four PRs.
      #134 the Site Health test, #135 asking core instead of re-deriving, #136 the
      offer ladder, #137 removing the link to a screen that cannot deliver the patch.
      A Site Health test over `api.wordpress.org/core/stable-check/1.0/`, which core
      never queries, so nothing in wp-admin distinguishes "an update is available"
      from "this version has known vulnerabilities". Reports three states — current,
      not currently flagged, known vulnerable — and where a patch exists names the
      release on the site's own line rather than the newest one.

      This reporting-and-routing phase deliberately did not install anything. Its
      remediation re-enabled minor auto-updates only when the stored option genuinely
      decided. The deliberate same-line installer was then added separately in #141,
      below, after structured blocker codes made that boundary testable.

      Stepping through intermediate releases one line at a time is a separate,
      undecided piece of work. The API already offers the whole ladder — a 5.9 site
      is offered twelve intermediate versions — but `find_core_auto_update()` always
      takes the highest permitted offer, so honouring a chosen rung means Keel
      running the core upgrader itself.

- [x] **Structured blocker codes** ([#138](https://github.com/dknauss/Keel/issues/138), step 0) — done 2026-08-31.
      `keel_defaults_minor_update_state()` returned blockers as translated strings, so
      nothing could tell a filesystem blocker from a policy one without matching text —
      which breaks in every locale and is what CONTRIBUTING.md forbids. Each is now
      `array( 'code', 'text' )` with a stable code, landed before the install on its own.
      The codes also let core's per-offer credential result outrank Keel's separate
      branch-tip probe without letting it override genuinely global blockers.

- [x] **Install the same-line patch** ([#138](https://github.com/dknauss/Keel/issues/138)) — done 2026-09-01 in #141.
      The install half of the above, scoped to one release rather than the ladder:
      a button that hands the site's own branch tip to `Core_Upgrader::upgrade()`.
      Cheap because the upgrader has no auto-update policy gate — those live in
      `WP_Automatic_Updater::should_update()` — so an `autoupdate` offer passes
      through untouched, and the offer is already in the `update_core` transient
      Keel reads. The target is recomputed server-side and can only ever be the
      branch tip, so it moves a site forward within its own line and cannot cross
      one or go back. First write to the filesystem outside a settings save;
      wants the deep review.

- [x] **Author-feed status** ([#101](https://github.com/dknauss/Keel/issues/101)) — done 2026-08-23. The feed was already caught by the
      archive's broad 301 because WordPress sets both query flags. It now returns
      an explicit 404 while the HTML archive keeps its existing 301. No new setting.
- [x] **Revisions control** ([#102](https://github.com/dknauss/Keel/issues/102)) — done 2026-08-23. New activations seed 10; existing
      installs migrate to unlimited instead of changing on update. `-1` means
      unlimited and `0` disabled. Numeric/false wp-config policy locks site and
      network controls; core's indistinguishable default `true` cannot be detected.

## Content / docs

- [x] **Finish the Agency Experience rename in the maintainer's agent instructions** —
      done 2026-10-13. The instruction files on the maintainer's main machine no longer
      name the old sync docs; the last references were in agents' saved notes, which
      now point at `docs/keel-sync.md` and Agency Experience's
      `docs/keel-feature-matrix.md`. A second machine keeps its own copies and was not
      checked.

- [x] **Review the logo graphics and banner tagline** — done 2026-10-13, in 0.6.8.
      Both lines the brand package names now say *balanced* where they said
      *sensible*: the banners read *Keeps your site upright with balanced defaults.*
      and the settings screen reads *Balanced defaults for steady sites.* The review
      found two lines in use, not three, and that the wordmark, tagline and icon hold
      at their small sizes. `branding/`, the mark the racing yacht replaced on
      2026-08-09, is removed from the repository; history keeps it.
  - The banners have no layered source. `.wordpress-org/brand/README.md` records how
    the line was changed on the existing PNGs.
  - The new banners reach wordpress.org when the listing assets are next deployed.
- [x] **Bring the newlocalmedia.github.io preview into line with the tagline** — done
      2026-10-13 (newlocalmedia/newlocalmedia.github.io#22). `assets/keel-preview.png`
      and `.webp` there now say *Balanced defaults for steady sites.*, and the alt
      text quotes it. The image is a static asset, not drawn by a script, so the
      line was covered and reset in the original's Avenir Next. It still shows the
      earlier two-sail mark rather than the racing yacht.

- [x] **Reference doc coverage** — done 2026-08-04. Every schema key then present received an
      entry in `docs/wordpress-default-settings.md`. Thirteen were missing outright;
      three more (`remove_version`, `security_headers`, `frame_options`) were described
      in prose in section 6 but never keyed, so a search for the key found nothing.

- [x] **Schema-key reconcile** — done 2026-08-04. Every entry named a standalone
      `keel_*` option that has never existed; settings live in the single `keel_settings`
      array, which the doc's own intro said. Fifteen entry headers, the whole
      quick-reference table and eight inline mentions corrected to the real schema key.
      Two table rows named keys with no counterpart at all (`keel_remember_me_policy`,
      `keel_session_regular_hours`) and are now `remember_me_days` / `session_regular_days`
      with their real defaults.

- [x] **readme.txt / README** — done 2026-08-04. Both expanded once the initial
      feature set had settled. The HIBP external-services disclosure is current and guarded by
      `tests/readme-spec.php`, which asserts it names the opt-out constant and filter,
      says how much of the hash is sent, says what happens when the API is unreachable,
      and links the operator's privacy policy.

## Feature ports (from Agency Experience)

- [x] `limit_unfiltered_html_to_admins` — first port; `user_has_cap` filter, default on,
      recursion-safe (is_super_admin guarded by is_multisite). Test: tests/unfiltered-html.php
- [x] **Reserved usernames removed** (`500c561`). The 73-name list is
      too opinionated for a general-purpose defaults plugin: it includes names an
      ordinary site legitimately uses (`manager`, `marketing`, `sales`, `office`,
      `client`), and a plugin that silently refuses to create a user called
      marketing has decided something on the owner's behalf without saying so.
      Core's `illegal_user_logins` does it in one call with a list the site chose;
      readme.txt carries the snippet. Kept in Agency Experience, where a
      fleet-wide house policy is the point — and there the login-blocking half is
      opt-in with the list and the affected accounts shown before you switch it on.
- [x] force classic editor — new "Editor" group; 4 filters (use_block_editor_for_post
      [+_post_type], gutenberg_can_edit_post, use_widgets_block_editor). Test: tests/force-classic-editor.php
- [x] admin menu width — AX-style RANGE SLIDER (index-based save, live label); fixed 2 bugs:
      (1) numeric-key strict-compare made saves revert; (2) missing !important made CSS a no-op on WP7 · lowercase upload filenames · media sizes panel ·
      hide admin bar for non-admins · helper list columns
- [x] environment indicator — admin-bar env label (prod/staging/dev/local), default OFF (opt-in),
      per-value CSS color sanitiser + accessible label-clip. tests/environment-indicator.php
- [x] disable post-password protection — CSS-hide editor UI (opt-in, non-destructive; keeps field on already-protected posts). tests/post-passwords.php
- [x] mail-failure notice — new Email group; risky From-address warning + zero password-reset catch (de-branded, no SupportMonitor). tests/mail-failure.php
- [x] AX comment teardown (default-closed/feeds/widget) · AX header logic (strictness/case-insensitive)
      · password role-scoping (keel_weak_roles, subscriber exempt). tests: headers, password-scoping

## Incremental architecture maintenance

Plan and acceptance criteria: [incremental refactoring](docs/review/incremental-refactoring.md).
These are maintenance increments alongside feature work, not new release promises.

- [x] **Share site/network control rendering** — extracted 2026-10-14; preserve
      screen-specific layouts, locks, input names and save behavior.
- [x] **Consolidate duplicate WP_Error test doubles** — reuse the shared stub and
      remove the duplicate-class lint warning; refresh stale lint/compat comments.
- [x] **Separate backport responsibilities** — extracted 2026-10-14 into status/cache,
      policy, presentation, and actions modules; public functions and hooks retained.
- [x] **Extract password and HIBP handling** — extracted 2026-10-14 into policy/adapters
      and HIBP transport/cache modules; entry points and privacy behavior preserved.
- [x] **Split conflict diagnostics internally** — extracted 2026-10-14 into attribution,
      overlap, divergence and notice modules; fixed self-reporting under symlink aliases.
- [x] **Establish performance baseline and budget** — 2026-10-14; paired real-WP
      cache modes and crowded diagnostics; eliminate repeated root resolution. See
      [performance record](docs/review/performance-budget.md).
- [x] **Port diagnostic root memoization to counterpart** — completed 2026-10-14;
      AX commit `d62da33`, resolving [#254](https://github.com/dknauss/Keel/issues/254).
- [x] **Extract settings policy resolution** — extracted 2026-10-14 into a shared,
      declaration-only module; constant precedence and stored preferences preserved.
- [ ] **Research a PHP 8.x minimum** — separately evaluate supported-version lifetimes,
      intended users and WordPress/hosting compatibility, paired runtime benchmarks,
      removable compatibility code, useful native types/enums, CI/tooling savings,
      and migration/release impact. Produce an evidence-based recommendation before
      changing the floor; the current minimum remains PHP 7.4.
- [x] **Start data-contract and PHPStan pilot** — 2026-10-14; shared array shapes,
      level-6 backport/configuration-policy checks targeting PHP 7.4, and positive/
      negative contract examples in CI. See [the pilot record](docs/review/data-contracts-phpstan.md).
- [x] **Analyze installer and presentation bodies** — 2026-10-14; level-6 checks,
      precise Site Health/result/return-map contracts, minimal consumer inputs,
      and seven static rejection cases; PHP 7.4 floor and behavior preserved.
- [x] **Analyze conflict diagnostics** — 2026-10-14; attribution, overlap and
      divergence pass level 6; precise public markers, tracked registration inputs
      and passive observer identity; ten static rejection cases; PHP 7.4 preserved.
- [x] **Analyze conflict notices** — 2026-10-14; eight modules pass level 6,
      eleven static rejection cases, extensible screen-map contract and missing-ID
      fallback verified; PHP 7.4, rendering and dismissal behavior preserved.
- [x] **Analyze status and transport** — 2026-10-14; backport status and HIBP
      transport bring the level-6 scope to ten modules; distinguish validated
      verdicts from raw cache records; fourteen rejection cases; runtime unchanged.
- [x] **Analyze backport action handlers** — 2026-10-14; eleven modules pass
      level 6; request-ending callback contracts and fifteen rejection cases;
      PHP 7.4, capabilities, nonces, writes and redirects unchanged.
- [x] **Analyze password-policy adapters** — 2026-10-14; twelve modules pass
      level 6; raw REST/endpoint boundaries, prepared-user identity and true/error
      verdicts; seventeen rejection cases; PHP 7.4 and runtime behavior unchanged.
- [x] **Analyze shared settings controls** — 2026-10-15; thirteen modules pass
      level 6; minimal field/copy projections and twenty rejection cases; ten page
      renders and 321 control renders match before/after; PHP 7.4 preserved.
- [x] **Analyze the Updates screen** — 2026-10-15; fourteen modules pass level 6;
      raw branch-tip contracts preserve defensive checks, capability gating and
      offer/result markup; PHP 7.4 and executable code unchanged.
- [x] **Analyze update-policy callbacks** — 2026-10-15; fifteen modules pass
      level 6; boolean input/output fixtures bring rejection coverage to 28 cases
      (31 diagnostics); PHP 7.4 and all runtime source unchanged.
- [x] **Analyze activation and migrations** — 2026-10-15; sixteen modules pass
      level 6; raw new-site hook input and evolving data-version analysis preserve
      defensive guards; 33 rejection cases / 36 diagnostics; PHP 7.4 preserved.
- [x] **Analyze email policies and notices** — 2026-10-15; seventeen modules
      pass level 6; open mail/filter contracts, 37 rejection cases / 40 diagnostics;
      notice markup, PHP 7.4 and executable code preserved.
- [x] **Analyze asset providers** — 2026-10-15; eighteen modules pass level 6;
      zero-argument callable contracts and guarded raw registry; 40 rejection cases /
      43 diagnostics; registration order, deduplication, CSS and PHP 7.4 preserved.
- [x] **Analyze network policy** — 2026-10-15; nineteen modules pass level 6;
      raw form/store boundaries and shared control projections; 43 rejection cases /
      46 diagnostics; guards, policy behavior, markup and PHP 7.4 preserved.
- [x] **Reassess static analysis by risk, not file count** — decided 2026-10-15; pause routine expansion
      after the network increment. Prioritize release/boundary verification; expand
      remaining modules or levels only for a concrete defect risk or planned change.
      Keep PHP 7.4 and validate raw inputs before narrowing.
- [x] **Complete the refactor release checks** — 2026-10-15; 48 real-WP probes,
      47 boundary assertions, browser flows on both network sites, six live installer
      rows and packaged-install smoke pass. See the [verification record](docs/review/release-verification-2026-10-15.md).
- [x] **Trim the historical upgrade notice** — 2026-10-15; shorten 0.6.4 copy
      below the directory limit while preserving both reporting fixes.
- [x] **Test Notifications feature-plugin compatibility** — 2026-10-16; released
      Keel and development runtime pass the exercised hub/REST/mail-policy checks;
      client-store, PHP deprecation and new-site schema evidence reported upstream.
      This prototype has no complete delivery path yet. See the
      [compatibility record](docs/review/notifications-compatibility-2026-10-16.md).
- [ ] **Recheck Notifications when delivery lands** — persistent delivery, client
      store hydration/dismissal, new-site migrations and cross-site recipient isolation.
- [x] **Add WordPress-aligned JS/CSS linting** — merged in #321 on 2026-10-23;
      unreleased. ESLint and Stylelint cover all browser assets, fail on
      warnings, and gate the rolling build. npm tools/configuration stay out of the
      plugin ZIP. See the [verification record](docs/review/asset-lint-2026-10-23.md).
- [ ] **Extend verification when risk warrants it** — assess broader accessibility,
      browser coverage and faithful shared test stubs.
      These remain maintenance work, not failures of the completed release pass.
- [ ] **Keep comments and tooling contracts current** — shorten historical narratives
      and audit source-reading tests, packaging and screenshot guards after moves.

## Rebuild / infra

- [x] Site Health surface — read-only posture (informational; escalates to 'recommended'
      only for strong-passwords + rest-discovery). tests/site-health.php

- [x] Multisite-aware seeding — done 2026-08-04 (keel#29). Network activation seeds
      each site; `lifecycle.php` is network-aware throughout.
