# Keel — TODO

Working checklist — what's in flight right now. Milestones and direction live in
[ROADMAP.md](ROADMAP.md).

The original scope document is `~/Code/pixel-lite-scope.md`, outside this repository and
partly stale (it records a GPL-3 decision; the plugin shipped GPL-2.0-or-later). Treat
the repo as authoritative and retire that file once anything still live in it has moved
into ROADMAP.md or here.

## Now — 0.6.0 release

- [ ] **Release 0.6.0**
  - Merge the final documentation and regression-test work.
  - Require the ordinary CI suite on the exact merge commit.
  - Tag that exact commit `v0.6.0`; do not add feature work to the candidate.
- [ ] **Validate the published 0.6.0 artifacts**
  - Confirm the GitHub ZIP contains the tagged runtime tree.
  - Approve and verify the WordPress.org deployment, SVN tag and directory version.
  - Inspect the public upgrade notice, four screenshots and both Playground links.
- [ ] **Observe the first post-release matrix**
  - Review the next scheduled live rollback/forward run.
  - Triage early field reports before opening the next release cycle.

## Next — 0.7.0 privacy and content integrity

- [ ] **Keep password-protected posts out of site search**
  - Hide protected titles and excerpts from logged-in users who cannot read the post.
  - Preserve results for authors, editors and capabilities granted by other plugins.
  - Cover logged-out, Subscriber, author/editor and custom-capability cases.
- [ ] **Hide broken-shortcode residue and report it in Site Health**
  - Hide orphaned shortcode markup from public output without deleting stored content.
  - Report enough evidence in Site Health for an administrator to identify the gap.
  - Do not ship the hiding half without the reporting half.
- [ ] **Leave typographic punctuation as typed**
  - Decide and document the exact `wptexturize` surfaces before naming the toggle.
  - Cover front-end content, excerpts and feeds; verify editor behaviour separately.

- [ ] **Trim the security-review credit to a permanent line**
  - The 0.6.0 credit recaps the whole review because the review was current. Once the
    release is no longer new, shorten it to a standing acknowledgement.
  - Keep Austin Ginder and Anchor Hosting named in both `readme.txt` and `README.md`;
    drop the finding-by-finding detail, which the commit history and changelog hold.
  - One or two sentences with no version number, so it does not need revisiting at
    each release.

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

This release keeps the schema at 39 defaults and adds the security-patch status
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

- [ ] **Review the logo graphics and banner tagline** — candidate: *balanced defaults*.
      The current line is *Sensible defaults for steady sites.*, and `branding/README.md`
      records that the waterline in the mark is drawn as the "even keel" cue behind it.
      *Balanced defaults* says the same thing in two words and lands the keel metaphor
      without explaining it, which is what a banner has room for.

      **There are already three taglines in circulation, and the shipped banner is the
      odd one out.** Resolve that first — a new line should replace one tagline, not
      become a fourth. Current state:

      | Surface | Line |
      | --- | --- |
      | `branding/README.md` | *Sensible defaults for steady sites.* |
      | `includes/settings-page.php` | *Sensible defaults for steady sites.* |
      | newlocalmedia.github.io preview | *Sensible defaults for steady sites.* |
      | **`.wordpress-org/` banners, live on wordpress.org** | *Keeps your site upright with sensible defaults.* |

      Keel's own brand documentation therefore disagrees with the asset Keel ships, and
      the one people actually see on the plugin directory is the one nothing else says.

      Scope, because a tagline change is not only a tagline:
      - `.wordpress-org/banner-1544x500.png` and `banner-772x250.png` — the listing
        banners, where the line actually appears.
      - `branding/README.md` — states the tagline and the reason the waterline echoes it.
      - `branding/keel-logo-horizontal.svg`, `keel-logo-stacked.svg`, `keel-mark.svg` —
        review the marks at banner and 128px icon sizes while the banners are open.
      - `includes/settings-page.php` — the settings-screen header carries it as a
        **translatable string**, so a change is a new msgid and the old one is dropped
        from every existing catalogue. Regenerate the `.pot` in the same commit.
      - **`newlocalmedia.github.io/assets/keel-preview.png` and `.webp`** — a separate
        composition drawn by `scripts/generate-project-previews.py`, not a crop of the
        plugin banner. It is a different treatment entirely: dark navy rather than
        white, mixed-case *Keel* rather than letter-spaced *KEEL*, no blue keel fin,
        and no rule under the wordmark. Decide whether it should match the banner or
        stay a house style for that site. Its alt text in `scripts/site-config.mjs`
        describes the current image accurately and stops being true the moment the
        image changes.
      - Check whether the wordmark still reads at the 772×250 banner size; the outlined
        Avenir Next was set for the larger one.

      Not release-blocking, and deliberately not in 0.6.0: the listing assets are served
      from SVN `assets/` and can be updated without a plugin release. The settings-screen
      string cannot — that one ships with the plugin, so it either waits for a release or
      the surfaces diverge again in the meantime.

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

## Feature ports (from pixel-experience) — see scope §13

- [x] `limit_unfiltered_html_to_admins` — first port; `user_has_cap` filter, default on,
      recursion-safe (is_super_admin guarded by is_multisite). Test: tests/unfiltered-html.php
- [x] **Reserved usernames removed** (`500c561`). The 73-name list is
      too opinionated for a general-purpose defaults plugin: it includes names an
      ordinary site legitimately uses (`manager`, `marketing`, `sales`, `office`,
      `client`), and a plugin that silently refuses to create a user called
      marketing has decided something on the owner's behalf without saying so.
      Core's `illegal_user_logins` does it in one call with a list the site chose;
      readme.txt carries the snippet. Kept in Pixel Managed Platform, where a
      fleet-wide house policy is the point — and there the login-blocking half is
      opt-in with the list and the affected accounts shown before you switch it on.
- [x] force classic editor — new "Editor" group; 4 filters (use_block_editor_for_post
      [+_post_type], gutenberg_can_edit_post, use_widgets_block_editor). Test: tests/force-classic-editor.php
- [x] admin menu width — PX-style RANGE SLIDER (index-based save, live label); fixed 2 bugs:
      (1) numeric-key strict-compare made saves revert; (2) missing !important made CSS a no-op on WP7 · lowercase upload filenames · media sizes panel ·
      hide admin bar for non-admins · helper list columns
- [x] environment indicator — admin-bar env label (prod/staging/dev/local), default OFF (opt-in),
      per-value CSS color sanitiser + accessible label-clip. tests/environment-indicator.php
- [x] disable post-password protection — CSS-hide editor UI (opt-in, non-destructive; keeps field on already-protected posts). tests/post-passwords.php
- [x] mail-failure notice — new Email group; risky From-address warning + zero password-reset catch (de-branded, no SupportMonitor). tests/mail-failure.php
- [x] PX comment teardown (default-closed/feeds/widget) · PX header logic (strictness/case-insensitive)
      · password role-scoping (keel_weak_roles, subscriber exempt). tests: headers, password-scoping

## Rebuild / infra

- [x] Site Health surface — read-only posture (informational; escalates to 'recommended'
      only for strong-passwords + rest-discovery). tests/site-health.php

- [x] Multisite-aware seeding — done 2026-08-04 (keel#29). Network activation seeds
      each site; `lifecycle.php` is network-aware throughout.
