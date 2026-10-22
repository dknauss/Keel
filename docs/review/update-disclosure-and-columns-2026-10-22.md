# Update disclosure and optional columns — 2026-10-22

Development after #298 merged as `67767de`. No version/data-version bump,
tag, release or deployment. #298 remains unreleased. The two new column controls
are opt-in; the development schema has 46 settings.

## WordPress.org update-check disclosure

Site Health Info adds a capability-gated static category summary. It requires
`view_site_health_checks` and, on multisite, `manage_network_options`.
No site-specific values, installed inventories, payloads, logs or actual request
observations enter the report or its clipboard data. It reads only the running
version to decide whether its maintained baseline applies. It does not intercept,
perform, refresh, block, redirect or persist requests and registers no HTTP hooks.
It does not change the sanitized posture export.

The three endpoints are core/version-check/1.7, plugins/update-check/1.1 and
themes/update-check/1.1 on api.wordpress.org. Static categories describe normal
core behavior, not verified transmission or exhaustive schemas. WordPress/host/
plugin filters may change or prevent requests. Package downloads, third-party
update services and Keel’s separate stable-check call are outside this scope.
Network and requesting-site contexts are distinguished without enumerating sites.
Stable families 6.4–7.1 are covered; development, newer, older or malformed
versions withhold detailed categories and explicitly remain unverified.

### Source evidence

Reviewed `src/wp-includes/update.php` on each upstream WordPress development
branch 6.4–7.1, fetched read-only on 2026-10-22, plus local WordPress 7.1.3.
Reference functions: [wp_version_check](https://developer.wordpress.org/reference/functions/wp_version_check/),
[wp_update_plugins](https://developer.wordpress.org/reference/functions/wp_update_plugins/) and
[wp_update_themes](https://developer.wordpress.org/reference/functions/wp_update_themes/).
These are maintained category summaries, not dynamically read core files.

Core data includes site/installation identity, environment/platform/extension/
image-support categories, counts and translations; newer versions add storage-engine
metadata. Plugin requests include inactive plugin metadata and active identifiers.
Theme requests include names, author names and author/update URIs as well as versions
and identifiers. Review caught an initial incorrect theme compatibility-metadata
label; corrected with a failing-first regression before PR creation.

## Optional list-table presentation (#265)

Two independent UX toggles default off: Compact Column Labels and Fewer List
Columns. They affect site-admin post-type lists only. Known keys map Jetpack SEO
description to Meta, Hum shortlink to Link, and ActivityPub to Fedi; unknown keys
are untouched. `keel_defaults_column_labels` allows a site to extend the map.
Full original labels remain accessible and available on hover. Keys, cell data,
sorting and primary-column identity are preserved.

Default hiding targets present SEO description/schema/search, shortlink, Keel ID
and Modified columns. Saved Screen Options, including an explicitly empty hidden
list, take precedence; no user preferences are rewritten. Users and Media are out
of scope. A changed/absent upstream key is a no-op, not guessed attribution.

## Verification

Standalone boundaries cover category scope, unknown versions, capabilities and
absence of collection/storage/network helpers. Real WordPress probes cover those
boundaries plus post/page headers, original labels, sorting, default hiding and
saved Screen Options. Both probes run on every live installer matrix row.
Browser checks and final CI evidence are recorded with the PR.

### Final local evidence

- Full standalone suite, WPCS, PHP 7.4 compatibility scan, scoped PHPStan,
  static contract regression checks and syntax checks pass. Schema-count checks
  confirm 46 settings: 18 toggles on, 16 opt-in, 12 non-toggle.
- Real WordPress 7.1.3/PHP 8.4 SQLite multisite: disclosure boundaries pass;
  list-table probe passes 72 checks, including cleanup. Review found that
  wp_delete_user only removes membership on multisite; fixed with wpmu_delete_user
  and a failing-first assertion that the fixture account no longer exists.
- Browser: Posts and Pages show Meta/Link/Fedi, original hover and accessible
  labels, unchanged sort links and exactly the original Screen Options text.
  Default hiding removes only the six present optional columns; title and
  ActivityPub remain visible. No horizontal overflow at desktop or 390px mobile.
  Disclosure’s native Info section is readable at both sizes and contains
  only static category text. Local captures: `.playwright-mcp/update-disclosure-*`,
  `compact-columns-desktop.png` and `fewer-columns-desktop.png`.
- Temporary browser fixture removed, option overrides were in-memory only, and
  the two leftover lab-only test accounts were removed after the cleanup fix.
  Listing screenshots were not changed. Independent review findings were fixed
  before PR creation; neither feature changes update policy or core’s policy text.
