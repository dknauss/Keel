# Sanitized posture export — 2026-10-21

Development work after merged, unreleased #295. No version/data-version bump,
tag, release or deployment is included. Existing settings and defaults are unchanged.

## Download contract

Site Defaults offers a separate POST form for an explicit `keel-posture.json`
download. The handler requires `manage_options` and `view_site_health_checks`, plus
`manage_network_options` on multisite. A nonce is bound to the current blog ID;
there is no target-site request parameter and no anonymous download hook.

The exact admin-post action dispatches at init priority 0, after WordPress has
established the user but before normal init scheduling/cron spawning and admin_init
update-cache refreshes. Both the early dispatcher and ordinary fallback use the same
method, capability and nonce checks before building data. Unrelated requests return
immediately. Third-party WordPress bootstrap behavior remains outside this feature.

Success is JSON with a fixed attachment filename, `nosniff` and private/no-store
headers. Invalid encoding fails rather than partially serializing data. Reports are
constructed for the response and are not stored on the server or sent elsewhere.

## Closed export schema

- Schema version, generation time and scope categories; no blog/network/user IDs,
  domains, site URLs, filesystem paths or full plugin/theme inventory.
- Configured effective policy values validated against the static schema. Only
  closed enum values and valid bounded integers are emitted. Free-form roles and
  malformed values become null. Lock sources are fixed categories, never the human
  lock note. Shared extension policies resolve network/main-site policy as in #295.
  Configured policies are not asserted to defeat arbitrary runtime filters.
- Running numeric core version and its cached stable-check verdict only. That cache
  has no trustworthy observation timestamp, so freshness remains unknown. Offer
  last_checked and retained failure presence/time/critical flag are projected without
  offers, URLs, error codes/messages/data. Future times remain unknown. Operability
  reports known global configuration restrictions only; full operability and success
  history remain unknown because export does not run a selector or probe.
- Validated per-site autoload and cron snapshots become numeric counters and explicit
  coverage/observation timestamps. Names, arguments, ownership labels, cache payloads
  and extra fields are omitted. Pending/error/unavailable data has no collected-at
  claim; stale, future and foreign-site snapshots are rejected. Collection limits and
  nontransactional semantics are stated. Counts describe collection, not current load.
- Cached conflict observations require the existing active-plugin fingerprint and
  bounded boolean entries. Only their count is exported, without hook or component
  names. Age and current relevance remain unverified. Missing/invalid provenance is
  unknown, never a zero-conflict verdict. Structural overlaps are unknown because
  reflection/attribution is not run. Expectation/item filters are not evaluated.

The export invokes no collectors, cron scans, remote status fetches, update checks,
installer probes or item/expectation callbacks. Core's ordinary expired-transient
cleanup and installed plugins' generic option/cache filters remain WordPress behavior.

## Verification

- Unit tests cover closed policy validation, injected secret omission, unknown states,
  lock categories, exact early dispatch, denied GET/POST, site-bound nonce verification,
  multisite authority and absence of an anonymous download hook.
- Real WordPress 7.1.3 / PHP 8.4 / SQLite multisite probe injects option names/values,
  user/role strings, hook/schedule names, callback identities, package URLs and failure
  payloads. None reaches JSON; numeric counters and partial coverage remain useful.
  HTTP, cron-read and policy-expectation callbacks throw if invoked; export passes.
- Cache regressions cover invalid fingerprints, future observation times, pending
  collection, expired/malformed snapshots and another site's snapshots. The pending
  timestamp test first failed, then passed after request time stopped being exported
  as observation time. A main-site nonce fails on the disposable second site.
- Browser transport: valid POST returns 200 JSON attachment, fixed filename and
  private/no-store; malformed nonce returns 403, GET 405 and anonymous POST 403.
  The settings-save and download forms are separate. Download is about 8 KB with
  the current 44-setting schema, without a site URL.
- Full unit suite, WPCS, PHP syntax/compatibility, existing scoped PHPStan and static
  contracts pass. Independent review's conflict provenance/callback findings were
  fixed; final review found no remaining issues.
- The real WordPress export probe is wired into every live installer-matrix row.

## PR #296 final review

All six [live matrix jobs](https://github.com/dknauss/Keel/actions/runs/39700808655)
passed on `d89569d`, including the new export contracts, PHP 7.4 / WordPress 6.4
floor, French locale, persistent-cache multisite and real backward/forward installer
flows. PR CI passed across PHP 7.4–8.5. Final review found no remaining issues or
unresolved GitHub review threads. This follow-up only records evidence; runtime
and test sources are unchanged.
