# Cron and update-delay attribution implementation plan

Goal: Complete the 0.8.0 observability scope using the same report-only Site Health
pattern as autoload attribution. Existing roadmap and the maintainer's instruction
supply the scope; numeric limits below are implementation choices.

- [ ] Add failing standalone tests for bounded cron metadata extraction, overdue
  (>15 minutes) and frequent (<5 minutes) observations, ambiguous owners, update
  offer age, next core checks, failure-code privacy and external scheduling.
- [ ] Add an authenticated async Site Health report and background collector.
  Read at most 1 MiB of serialized cron data in one size-guarded SQL statement;
  deserialize without classes and discard all event arguments. Process at most
  2,000 event instances / 6,000 traversal steps. Keep five overdue and five
  frequent observations plus the two core-update hooks. State partial coverage
  explicitly. SQL execution time and core's own cron-option loading are not hard
  bounded by this report. No source scans, job execution or update HTTP calls.
- [ ] Cache per site for an hour, failures for five minutes; pending jobs expire
  honestly after ten minutes. Reuse the existing insert-only diagnostic lease and
  authoritative ownership checks; force fresh report reads after acquiring it.
  Report missing/future offer timestamps and undated failures as unknown.
  Site cron is per-site; core offers/failures are network-wide on multisite.
- [ ] Add deactivation/uninstall cleanup of only private report/job state. Keep
  the 42-setting schema and version metadata unchanged. Add docs and POT strings.
- [ ] Run real WordPress fixtures, browser nonce/capability/a11y checks, unit/lint/
  compatibility checks and the live MySQL/Redis matrix. Independently review the
  security, cache and parsing paths, fix findings, then open a PR.
