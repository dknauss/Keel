# Keel — TODO

Working checklist. Full plan lives in `~/Code/pixel-lite-scope.md`.

## Content / docs

- [ ] **Reference doc coverage** — `docs/wordpress-default-settings.md` documents Better
      by Default's feature set. As PX ports land, add entries for each new default:
      reserved usernames, `limit_unfiltered_html_to_admins`, `helper_list_columns`,
      force-classic-editor, environment indicator, post-password disable, mail-failure
      notice, admin-menu-width, lowercase-upload-filenames, media-sizes-panel,
      hide-admin-bar-for-non-admins, title-only-search, hide-welcome-panel.
- [ ] **Schema-key reconcile** — some doc keys use BBD naming that may not match Keel's
      final keys (e.g. `disable_rest` vs `require_auth_rest`, `disable_application_passwords`
      vs `prohibit_app_passwords`). Align the reference doc to the shipped schema keys.
- [ ] **readme.txt / README** — expand once the feature set is final; keep the HIBP
      external-services disclosure current.

## Feature ports (from pixel-experience) — see scope §13

- [x] `limit_unfiltered_html_to_admins` — first port; `user_has_cap` filter, default on,
      recursion-safe (is_super_admin guarded by is_multisite). Test: tests/unfiltered-html.php
- [x] reserved usernames — `illegal_user_logins` (block creation, NOT PX's authenticate/
      login block, which risks locking out existing legit accounts). Test: tests/reserved-usernames.php
- [x] force classic editor — new "Editor" group; 4 filters (use_block_editor_for_post
      [+_post_type], gutenberg_can_edit_post, use_widgets_block_editor). Test: tests/force-classic-editor.php
- [x] admin menu width — select (200/240/280/300), scoped admin_head CSS · lowercase upload filenames · media sizes panel ·
      hide admin bar for non-admins · helper list columns
- [ ] environment indicator
- [x] disable post-password protection — CSS-hide editor UI (opt-in, non-destructive; keeps field on already-protected posts). tests/post-passwords.php
- [x] mail-failure notice — new Email group; risky From-address warning + zero password-reset catch (de-branded, no SupportMonitor). tests/mail-failure.php
- [ ] PX comment teardown (fuller disable) · PX header logic · password role-scoping

## Rebuild / infra

- [ ] Site Health surface (neutral severity, scoped to shipped features)
- [ ] Multisite-aware seeding (port PX network-aware lifecycle)
- [ ] Trademark glance on "Keel" (USPTO + CIPO, classes 9/42) before public
