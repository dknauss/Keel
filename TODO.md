# Keel — TODO

Working checklist — what's in flight right now. Milestones and direction live in
[ROADMAP.md](ROADMAP.md).

The original scope document is `~/Code/pixel-lite-scope.md`, outside this repository and
partly stale (it records a GPL-3 decision; the plugin shipped GPL-2.0-or-later). Treat
the repo as authoritative and retire that file once anything still live in it has moved
into ROADMAP.md or here.

## Content / docs

- [x] **Reference doc coverage** — done 2026-08-04. All 37 schema keys now have an
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

- [ ] **readme.txt / README** — expand once the feature set is final; keep the HIBP
      external-services disclosure current.

## Feature ports (from pixel-experience) — see scope §13

- [x] `limit_unfiltered_html_to_admins` — first port; `user_has_cap` filter, default on,
      recursion-safe (is_super_admin guarded by is_multisite). Test: tests/unfiltered-html.php
- [~] reserved usernames — ported, then **removed** (`500c561`). The 73-name list is
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
- [ ] Trademark glance on "Keel" (USPTO + CIPO, classes 9/42) before public
