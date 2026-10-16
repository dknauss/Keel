# Keel — TODO

Working checklist — what's in flight right now. Milestones and direction live in
[ROADMAP.md](ROADMAP.md).

The original scope document, `pixel-lite-scope.md`, was kept outside this repository and
is retired: it was no longer on the maintainer's machine when looked for on 2026-10-13.
This file and ROADMAP.md are the only record of scope.

## Now — observe the 0.6 line

Each 0.6 release is published only after the release checklist runs in full.
GitHub and WordPress.org 0.6.9 are published (verified 2026-10-15).
The release/deploy workflow passed; both published packages and the default
WordPress.org download match all 41 tagged runtime files. See the
[verification record](docs/review/release-verification-2026-10-15.md).

- [x] **Prepare the 0.6.9 candidate** — version metadata, cache-busting constant,
      changelog and upgrade notice synchronized; no new defaults or PHP floor change.
- [x] **Release 0.6.9** — #274 merged after green CI/live matrix and clear
      counterpart sweep; v0.6.9 tagged, protected deployment approved, SVN revision
      3749515 and published packages/assets verified.
- **Next focused implementation:** hide orphaned shortcode output together with
  Site Health reporting, as scoped below for 0.7.0. Field observation and the maintainer's
  WordPress.com settings-save confirmation remain open; routine PHPStan expansion
  stays paused unless a concrete change warrants it.

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
  - Not yet seen on a live WordPress.com site: it needs 0.6.8 installed on one. Of the
    nine checked on 2026-10-13, seven leave the width unchanged, which is the state
    the Jetpack notice is for, and two set their own.
- [ ] **Observe the field**
  - Checked 2026-10-13, read-only, on nine WordPress.com sites the maintainer runs.
    All nine are on 0.6.7 with WordPress 7.1.3 and a connected Jetpack. None declares
    a non-production environment, so the 0.6.8 warning for a live site that says it
    is local would show on none of them.
  - The scheduled live matrix last ran green on 2026-10-12. No issues are open.
  - Looked for conflicts with WordPress.com on the same nine, 2026-10-13. Found none
    that breaks anything: Jetpack has no stored connection errors on any site, no
    setting is recorded as failing to take effect, no plugin shares an authoritative
    hook, and one site's PHP error log for the week has no entry from Keel.
    WordPress.com's plugin screen lists the plugins with multicall refused.
  - Found: every one of the nine shows the notice that two settings are "affected by
    code that cannot be traced to any plugin". The code is the host's own must-use
    plugins, on `wp_revisions_to_keep` (priority 5, so Keel's limit still decides)
    and `user_has_cap`. Fixed in 0.6.8: the report names must-use plugins and
    themes (dknauss/Keel#244). Not yet seen on one of the nine.
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

## Priority fix — multicall refusal breaks Jetpack

Found 2026-10-05 on a live WordPress.com Atomic site (Jetpack 16.3-a.7, Keel with the
shipped default `xmlrpc_allow_multicall = no`). Takes precedence over the 0.7.0 work.

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

- [ ] **Compact list-table columns** — [#265](https://github.com/dknauss/Keel/issues/265),
      optional compact labels/default-hidden columns. This enhancement predates 0.6.9;
      the helper-column code is unchanged from 0.6.8. Keep separate from search privacy.
      No open issue reports a newly introduced 0.6.9 defect at this review; that is
      not evidence that unreported regressions are impossible.

## Next — 0.7.0 privacy and content integrity

- [x] **Keep password-protected posts out of site search**
  - Exclude protected search results for logged-in users without per-post editing
    or unlocked-password access; excerpt display otherwise depends on the consumer.
  - Preserve results for authors, editors and capabilities granted by other plugins.
  - Verified logged-out, Subscriber, author/editor, custom per-post/CPT capabilities,
    password access, counts/pagination and unchanged admin/non-search queries.
  - Implemented as `protect_search` (default on), independently of the editor UI
    switch; 41 defaults in the development tree. See the
    [search verification record](docs/review/protected-search-privacy.md).
- [ ] **Hide broken-shortcode residue and report it in Site Health**
  - Hide orphaned shortcode markup from public output without deleting stored content.
  - Report enough evidence in Site Health for an administrator to identify the gap.
  - Do not ship the hiding half without the reporting half.
- [ ] **Leave typographic punctuation as typed**
  - Decide and document the exact `wptexturize` surfaces before naming the toggle.
  - Cover front-end content, excerpts and feeds; verify editor behaviour separately.
- [ ] **Mark locked settings in the row, not only in the note** — considered while
      fixing the lock note's visibility and deliberately left out of that change. The
      note only helps once you are already reading the row it belongs to; on a
      thirty-nine setting screen the question a host-managed site actually has is
      "which of these are not mine?", and answering it currently means reading every
      row. A lock glyph beside the label would make that scannable. Bigger than a CSS
      rule: it touches label rendering for every field type, so it wants its own pass.
- [ ] **Summarise locks at the top of the settings screen** — "3 settings are locked by
      `wp-config.php`", each linking to its anchor. `keel_defaults_setting_anchor()`
      already generates the targets, so the linking is free; the work is deciding
      whether it belongs on the settings screen, in Site Health, or both, and whether
      network policy locks count in the same sentence as constant locks. They are
      different authorities and probably should not be summed into one number without
      saying so.
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

- [ ] **Run a UI/UX/accessibility sweep across the settings screens** — the
      right-to-left check above looked at one locale and three surfaces; this is the
      whole screen, at both levels.
  - Visual hierarchy, label and help-text clarity, responsive layout.
  - Keyboard use, focus visibility, screen-reader names and descriptions, contrast.
  - Single-site versus network-level controls, which differ in more than wording.
  - Capture representative site and network screens before and after.
  - [x] The network settings screen's intro was confined to half the width — done in
    #217. The inline `max-width:46em` is gone, so the intro follows the admin
    column like the settings below it, and `tests/network-screen-prose.php` keeps
    a cap from coming back inline or in a stylesheet the screen loads.

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

## Then — 0.8.0 performance observability

- [ ] **Attribute oversized autoloaded options**
  - Add size and probable-owner detail to core's aggregate autoload diagnosis.
  - Never expose values, auto-delete rows or state inferred ownership as fact.
  - Make collection asynchronous, cached, bounded and multisite-aware.
- [ ] **Attribute cron pressure and update-delivery delays**
  - Report overdue and unusually frequent hooks, schedules and probable owners.
  - Add offer freshness, next scheduled core check and recent update failures.
  - Distinguish an unknown external scheduler from a broken WP-Cron configuration.

## Then — 0.9.0 update operations and support

- [ ] **Show the core-update delivery timeline**
  - Combine selected release, branch tip, offer freshness, next check and recent result.
  - Reuse core's decisions and stable blocker codes; never promise an execution time.
- [ ] **Toggle plugin and theme auto-updates**
  - Three states — `enabled`, `disabled`, `unset` — through the `auto_update_plugin`
    and `auto_update_theme` filters. `unset` stays the default: the per-item
    checkboxes on the Plugins screen are a deliberate choice to leave alone.
  - No "minor updates only". That channel is core-only —
    `Core_Upgrader::should_update_to_version()` runs for `'core'` and nothing else —
    because wordpress.org neither requires nor verifies semantic versioning. The label
    would promise a property the data cannot support, and would fail selectively on
    exactly the authors who do not use semver.
  - Report instead: how many items have auto-updates on, whether the option or a
    filter decides, and which items a filter is overriding.
- [ ] **Export a sanitized Keel posture report**
  - Include effective settings, conflicts, update operability and diagnostic evidence.
  - Exclude secrets, option values, user data and unnecessary site identifiers.
  - Read VerCheck API and Updawa for payload shape; depart from both by reporting
    known-vulnerable, and treat a full plugin inventory as reconnaissance material —
    authenticated and opt-in, or not at all.

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
- [ ] **Extend verification when risk warrants it** — add JS/CSS linting separately;
      assess broader accessibility, browser coverage and faithful shared test stubs.
      These remain maintenance work, not failures of the completed release pass.
- [ ] **Keep comments and tooling contracts current** — shorten historical narratives
      and audit source-reading tests, packaging and screenshot guards after moves.

## Rebuild / infra

- [x] Site Health surface — read-only posture (informational; escalates to 'recommended'
      only for strong-passwords + rest-discovery). tests/site-health.php

- [x] Multisite-aware seeding — done 2026-08-04 (keel#29). Network activation seeds
      each site; `lifecycle.php` is network-aware throughout.
