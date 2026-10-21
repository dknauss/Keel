# Autoload attribution — 2026-10-21

Unreleased 0.8.0 work; version metadata remains 0.7.0. No deployment or tag.

## Behavior and safeguards

The authenticated Site Health async test requests a per-site background scan.
The first request schedules work; rendering and frontend requests never scan the
options table. Only callers with `view_site_health_checks` receive metadata; AJAX
also requires core's Site Health nonce.

SQL returns option IDs, names, autoload flags and `OCTET_LENGTH(option_value)`,
never values. Primary-key pages process at most 250 rows plus one lookahead, with
10,000 rows and ten minutes per scan. These bound rows/work, not SQL wall-clock
execution or the byte size of a single database row. Ten largest observed names
and byte counts are retained. Modern autoload flags use core's helper; WP 6.4
falls back to `yes`. Partial results are explicitly labelled, including when an
external scheduler has not run before the time budget expires. Size alone never
changes the result to a performance failure or implies an option is dispensable.

Reports are per-site transients cached up to one hour (query errors five minutes).
Transient caches may live in a persistent object cache. Schema/site/shape checks
reject incompatible cache contents. Collection is nontransactional; concurrent
option writes can change the ranking. No whole-database ranking is promised when
the limit is reached. A fresh report after expiry starts from the beginning.

A non-autoloaded private lock uses an insert-only database claim, a 30-second
lease, authoritative database ownership checks and compare-and-delete release.
It does not rely on `add_option()` being insert-only or on a cached ownership
read. Deactivation and uninstall remove Keel's own report, lock and scheduled job.
Discovered options are never deleted, updated or assigned different autoload flags.

Known core names and verified installed theme-mods names get high confidence.
Installed plugin slug prefixes get low confidence with explicit evidence; ambiguous
or unrecognized names stay unknown. Only the first 200 installed-plugin entries
are considered for prefix matching. This does not identify the actual writer.
Names and plugin/theme labels are escaped. No source-file or option-value search.

## Verification

- Standalone contracts cover cold/cached reads, pagination, a later-page large
  entry, modern flags, scan cap, query errors, permissions/nonce, site isolation,
  escaped labels, ambiguous owners, malformed caches, delayed external cron and
  expired/replacement leases, stale request-local cursors and forced backend reads.
- Real Studio WordPress 7.1.3 / PHP 8.4 / SQLite: UTF-8 fixture values measured as
  1,200,000 and 1,000,000 bytes; a 1,500,000-byte non-autoloaded fixture excluded;
  Unicode option name preserved; cached snapshot caused no SQL; values absent
  from cache; unauthenticated direct call returned no metadata. Fixtures removed.
- Real browser: core registered the async test; authenticated valid-nonce AJAX
  returned 200 with a Site Health result; invalid nonce returned 403. Background
  cron completed and the report returned observed options and sizes.
- Browser panel accessibility: axe reported zero violations in the new report.
  Caption, column headers and wrapped option names were visually inspected.
  [Panel capture](autoload-attribution-2026-10-21.png).
- Full standalone suite, coding standards, PHP compatibility, syntax, existing
  PHPStan scope and its negative contracts passed locally. PHPStan's existing
  selected-path scope has not been expanded to this module.
- Package build includes the module and excludes test/probe sources.
- Live MySQL matrix now runs the same integration probe on every row; the
  multisite row also enables Redis persistent object caching. Run evidence is
  recorded below after CI completes.

The separate [Playground investigation](../../playground/hosted-investigation-2026-10-21.md)
records seven successful fresh-context boots and the reproduction limits. It does
not establish whether the reported intermittent failure belongs to Keel or Playground.

## Final candidate evidence

Implementation commit: `e96ec591067742ab97f76b19d91db2400a959891`.

- [CI run 39653866227](https://github.com/dknauss/Keel/actions/runs/39653866227):
  WPCS/unit/static checks and PHP 7.4, 8.0–8.5 all passed.
- [Live matrix run 39653859859](https://github.com/dknauss/Keel/actions/runs/39653859859):
  all six rows passed, including the WP 6.4/PHP 7.4 floor, French install and
  multisite. Every row logged `autoload real-database contracts: OK` before the
  existing backward/forward installer checks. Multisite confirmed separate site
  reports with connected Redis 7.4.11 / PhpRedis 6.3.0.
- Independent review identified and verified fixes for `add_option()`'s race,
  stale request-local progress and negative option caches; no important findings
  remained. The second-site fixture restores the installation flag left enabled
  by core's `wpmu_create_blog()` helper before checking ordinary runtime behavior.
- All five existing listing screenshots were inspected and remain representative;
  the new report is captured separately above. The screenshot guard now watches
  the new renderer and its review stamp was refreshed.

These checks do not establish transactional rankings, exact writer attribution or
reproduction of the intermittent hosted Playground failure.

Direct Plugin Check 2.1.0 on the final built package returned zero errors and the
same two reviewed warnings as 0.7.0: update-hook detection in `backports.php` and
the protected-search query in `content.php`. No new autoload-module finding.
The temporary Plugin Check installation was deactivated after the check.
