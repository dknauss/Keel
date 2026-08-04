# Keel — roadmap

Where Keel is going, and what has to be true before each step. Milestone-level; the
task-level checklist is [TODO.md](TODO.md).

**Current version: `0.1.0-dev`.** Requires WordPress 6.4+, PHP 7.4+, tested to 7.0.
GPL-2.0-or-later.

> **Provenance note.** The original planning document is `~/Code/pixel-lite-scope.md`,
> outside this repository — so it is invisible to anyone who clones Keel, and it has
> already drifted (it records a GPL-3 decision; the plugin shipped GPL-2.0-or-later in
> `7822926`). This roadmap is the in-repo source of truth for direction. Where the two
> disagree, the repository wins. Fold anything still live out of the scope doc and into
> here or TODO.md, then retire it.

---

## v0.2 — feature-complete for review

Everything a wordpress.org submission needs, before polish.

- [ ] **Multisite-aware seeding.** v1 target is multisite *compatible*, not governed:
      network-activatable without breakage, each subsite storing its own settings. Not
      free — the Better by Default base has essentially no multisite awareness (a single
      `register_activation_hook`, no per-site or new-subsite seeding). Port the
      network-aware lifecycle: seeding keyed on an `$is_network` flag plus a
      `wp_initialize_site` hook so new subsites get seeded.
- [ ] **Document the shared-user-table caveat.** Password and reserved-username policies
      act on the network-wide user table, so on multisite they are effectively
      network-wide even when configured per-site. Documented in v1, not governed.
- [ ] **Reference doc coverage.** `docs/wordpress-default-settings.md` still describes
      Better by Default's feature set. Add an entry per ported default.
- [ ] **Schema-key reconcile.** Some reference-doc keys use Better by Default naming that
      may not match Keel's shipped keys (`disable_rest` vs `require_auth_rest`,
      `disable_application_passwords` vs `prohibit_app_passwords`). Align the doc to the
      schema, which is the source of truth.
- [ ] **Trim `uninstall.php`** to the option set Keel actually ships.

## v1.0 — wordpress.org submission

Plugin Review requirements, not niceties.

- [ ] **`readme.txt` to spec** — Stable tag, Tested up to, Contributors, and an explicit
      external-services disclosure for HIBP (`api.pwnedpasswords.com`, k-anonymity: only
      a SHA-1 prefix leaves the site, with an opt-out filter). Review blocks without it.
- [ ] **Expand `readme.txt` / `README.md`** once the feature set is frozen.
- [ ] **Trademark glance on "Keel"** — USPTO and CIPO, Nice classes 9/42 — before the name
      is in public print. The w.org slug is free and `keel.sh` is unrelated devops
      tooling, but neither is a clearance.
- [ ] **Re-point the test spine**: regression suite, metrics guard, doc-coverage and badge
      sync all still reference the pre-rename tree.

## v1.x — verification and evidence

Keel's pitch is that it tells you exactly what it does to your site. That claim has to
be re-measurable, not asserted once.

- [ ] **Probe Clearfy (50k installs) and WP Master Toolkit (5k).** Both were code-reviewed
      only in [docs/competitive-teardown-matrix.md](docs/competitive-teardown-matrix.md);
      neither has been measured. They are the closest architectural peers to Keel — a
      menu of independently toggleable defaults rather than a single-purpose switch — so
      they are the most informative comparison and the most likely to surface a technique
      worth adopting. Review suggested Clearfy has the field's other correct pingback
      teardown (`xmlrpc_methods` plus an `xmlrpc_call` guard) and no REST teardown at all
      in the free tier; WP Master Toolkit pairs `xmlrpc_enabled` with a
      `wp_xmlrpc_server_class` swap. Confirm or correct both by measurement.
- [ ] **Probe Classic Editor (9M) and Disable Gutenberg (500k)** on the editor surface,
      which the current matrix covers by code review only.
- [ ] **Re-run the matrix against a classic theme.** Every measurement to date is from a
      single block-theme install. Several comment-teardown rows — anything touching
      rendered markup or the comment template — are theme-dependent by construction.
- [ ] **Re-run against an older supported WordPress.** Keel claims 6.4+; the matrix was
      measured on 7.0.2 only.
- [ ] **Make the probe harness part of the repo.** It currently lives in a scratch
      directory: a throwaway WordPress install plus `probe.sh`. Until it is committed,
      none of the published numbers can be reproduced by anyone else — which is the whole
      basis of the comparison. This is the highest-value item in this section.

## v2 — deferred by decision

- [ ] **Multisite governance.** Network-admin settings screen, network-scoped policy
      pushed to subsites, Super-Admin-only controls. Explicitly deferred from v1.

---

## Non-goals

Recording these so they stop being re-litigated:

- **Plugin/theme governance screens.** Dropped deliberately — blocking admin screens is a
  wordpress.org review problem, and it is not what a defaults plugin is for.
- **Growing past Better by Default scale.** The budget is roughly 2.5–3.5k LOC against
  the 1,329-LOC base. Pixel Managed Platform is ~24k. The smallness is the point: every
  default is one schema entry plus one bootstrap `if`-block.
- **Punching holes in security toggles for convenience.** Blocking anonymous REST takes
  oEmbed down with it. That consequence gets named in the help text; it does not get
  quietly worked around.
