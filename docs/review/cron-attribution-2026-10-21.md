# Cron pressure and update delivery attribution — 2026-10-21

Unreleased 0.8.0 work after #287, merged as
`9f83f314d0a8bf62368799aa33f993b629949c87`. The 42 settings and 0.7.0 version
metadata are unchanged. This is a reporting feature, with no release or deployment.

## Evidence and limits

Authenticated Site Health requests background collection; the AJAX endpoint checks
core's Site Health nonce and `view_site_health_checks`. No collector runs at plugin
load, frontend rendering or activation. Per-site reports cache for an hour; query,
decode and size failures cache for five minutes. Pending work becomes explicitly
unavailable after ten minutes, without declaring externally managed cron broken.

The collector's single keyed SQL read returns cron's byte length and its serialized
payload only when it is at most 1 MiB. The SQL CASE guards size and payload in the
same statement. Cron arguments are read internally because they are part of core's
serialized format, but never enter the report, cached metadata or HTML. Decode
disables classes and limits nesting to 32. At most 2,000 event instances / 6,000
traversal steps are examined, retaining five most-overdue and five most-frequent
observations. Malformed entries and reached caps are flagged as partial coverage;
oversized/undecodable data is unavailable, never a reassuring empty schedule.

These limits bound the collector's input and work, not database statement duration,
PHP's expanded-memory size, or WordPress's own cron APIs. Core's scheduling APIs
still read/write the cron option normally when scheduling Keel's own job. The
report does not inspect runtime filters, externally stored queues, CPU time or job
durations, and cannot prove which component caused an update delay. Snapshots are
nontransactional and clock changes/concurrent scheduling can alter observations.

The review thresholds (>15 minutes late, recurring intervals <5 minutes, and offer
markers older than 24 hours) are observational heuristics, not failures or proof
of unnecessary work. Hook attribution uses known core/Keel names or low-confidence
installed-plugin slug prefixes; ambiguous names remain unknown. Core option-name
and theme-mods rules are deliberately excluded from hook attribution. No callback
source scans or paths are exposed. An interval of zero means no positive stored
recurrence interval was observed, not a promise about actual execution frequency.

The core subset records observed `wp_version_check` and `wp_maybe_auto_update`
timestamps, without claiming missing hooks are absent from an incomplete snapshot.
Core offer age comes from existing `update_core.last_checked`, which core can stamp
before a successful response. Future/missing timestamps remain unknown. The
existing `auto_core_update_failed` record supplies only a safe code, timestamp and
critical flag; no error messages/data or update URLs are retained. This record may
be historical and is not a log of all transient failures. Offers/failures are
network-wide on multisite; events and report caches are per-site. Keel initiates
no offer fetch, update installation or observed job execution for this report.

Collection shares the existing insert-only diagnostic lease: authoritative token
checks and fresh post-lease cache reads prevent stale workers sharing ownership.
Deactivation/uninstall clear only Keel's report and pending collector. No observed
jobs, autoload flags, update offers or update policy are changed.

## Validation

- Standalone tests exercise timing/interval thresholds, event cap, core timestamps,
  unknown attribution, offer-age/failure privacy, permissions and nonce, async cold
  reads, cached reads, site isolation, oversized/query/decode errors, malformed
  report caches, delayed scheduling and classless decoding (no object wakeup).
- Real Studio WordPress 7.1.3/PHP 8.4/SQLite: hostile hook name escaped; overdue and
  30-second events identified; 90,000-second offer age and safe failure code shown;
  no argument/error-data secret in cached state; cached snapshot caused no SQL;
  oversized payload was rejected by SQL CASE. Second-site isolation passed.
  All fixture cron/offers/failure state was restored.
- Full standalone suite, WPCS, PHP compatibility, existing PHPStan scope and its
  negative contracts pass locally. PHPStan's selected-path scope remains unchanged.
- Browser, package, Plugin Check and live matrix evidence follows after completion.
