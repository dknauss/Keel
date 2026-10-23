# Admin List Tables consolidation — 2026-10-22

PR #299 reconciled on latest head `9fb0f9c`, after merged #300, #301, #302
and #303. No version/data-version bump, release, tag or deployment.

## Interface and behavior

`admin_list_tables` is a select: unchanged (default), tidy, tidy_helpers.
`date_columns_show_time` is the only dependent checkbox, on by default and hidden/
inactive while unchanged. Four former toggles are removed from the current schema,
reducing 48 settings to 45 (19 on toggles, 13 opt-in toggles, 13 other controls).

Unchanged adds no helpers or formatting. Both Tidy modes reuse the current
screen-filter label fix, full accessible labels, initial visibility, shared date
formatting and auto-sized desktop tables. Only tidy_helpers registers post ID,
Image, sortable Modified, Media file size and Users registration/last-login helpers.
Scheduled/missed-schedule labels remain visible; drafts retain an accessible dash.

The latest auto-layout CSS is preserved, including 50% Title and wrapping in other
cells, above core's 783px breakpoint. Core's mobile layout remains in place.

## Preservation and compatibility

Saved Screen Options, including an empty hidden list, always win. The preference
backfill function, screen hook and tracking metadata/uninstall changes are removed.
Reading or visiting a table creates no preference or migration marker.

A legacy helper=yes resolves to tidy_helpers; any other enabled legacy tidying
choice resolves to tidy. Explicit new modes win, and malformed modes resolve to
unchanged. Legacy network choices are normalized in memory and remain authoritative
in getters, lock checks and the network form. Reads persist nothing. A normal site
settings save under a network lock retains the dormant site's resolved legacy mode,
so lifting the policy restores it. Independent review identified that save-boundary
gap; the fix has failing-first regression coverage.

## Verification

- Full standalone suite, WPCS, PHP 7.4 compatibility scan, scoped PHPStan, static
  contract checks and syntax checks run for this branch.
- Real WordPress 7.1.3/PHP 8.4 disposable Studio lab: 86 integration assertions,
  including saved empty/nonempty preferences, no user-meta changes on visits,
  real published/draft/future dates, Show Times and complete fixture cleanup.
- Browser checks at 1440px: unchanged uses fixed layout and no helper columns;
  Tidy uses auto layout without helpers; Tidy + helpers adds sortable Modified.
  Title measured about 95px unchanged, 601px Tidy, and 564px with helper defaults
  in the representative local fixture; these are fixture measurements, not promises.
- At 390px, core's fixed responsive layout remains and no horizontal overflow was
  observed. Site Defaults displays exactly three choices and no retired controls;
  Show Times hides under unchanged and appears checked for Tidy.
- Temporary browser fixture removed; no actual plugin inventory changes or stored
  option overrides were used for screenshots. Captures retained locally under
  `.playwright-mcp/three-mode-*`. Listing screenshots are unchanged.
- The existing integration probe runs on all live installer matrix rows.
