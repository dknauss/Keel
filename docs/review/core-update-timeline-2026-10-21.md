# Core-update delivery timeline — 2026-10-21

Development work following #293; no version bump, tag or publication.

The Updates screen combines its existing core-selected automatic release and
available same-line patch with updater operability, stable blocker codes, offer
`last_checked` age, cached site-local core-check events and the retained core failure
record. It reuses core's selector, including the existing suppression of rejected
offer notification emails. It adds no setting, result collector, remote request,
cron scan, scheduled event or update action. Existing screen status resolution can
still refresh its stable-check cache as before.

Missing selector evidence differs from no selection. Neither no selection nor no
failure record establishes that core is current or an update succeeded. WordPress's
retained failure may be historical and is not tied to the current selected release.
Durable success history is explicitly unknown rather than inferred or collected.
Only the sanitized failure code/time enter the view; error messages/data do not.

Offer markers older than one day are labelled stale, but `last_checked` never
claims a successful response or release-availability time. Missing/future markers
stay unknown. Schedule evidence uses the existing validated one-hour, per-site
snapshot; pending, failed, missing and expired collection remain unavailable.
Partial coverage and unobserved hooks are explicit. An event is called overdue only
at collection time. A timestamp passed since collection says current execution is
unknown: an event may already have run. Neither scheduled hook is an installation
appointment. External cron is unverified, not declared broken.

Offers/failures are network-wide on multisite; events remain local to the displayed
site. Rendering requires `update_core`, plus `manage_network_options` on multisite,
before any timeline reads. The view is an ordinary heading and nested list, with
escaped text and no interactive controls or color-dependent status.

## Verification

- Unit boundaries: missing selector and schedule evidence, no selection, stale
  offers, partial coverage, absent hooks, external cron, global blockers overriding
  a selected release, escaping, private failure data exclusion and permission gates.
- A regression first failed for a cached event due after collection; it now stays
  execution-unknown instead of becoming falsely overdue at page load.
- Real WordPress 7.1.3 / PHP 8.4 / SQLite multisite lab: validated snapshot rendering,
  foreign-site, expired and future snapshot rejection, sanitized/future failure
  evidence, real blocker precedence and core-selected credential-probe exception.
  The probe uses temporary read filters and restores them; no update is installed.
- Full unit suite, WPCS, PHP compatibility, scoped PHPStan and type contracts pass.
- Playwright: authenticated Network Updates screen renders the timeline once;
  ordinary desktop and 390px layouts have no horizontal overflow. Pending evidence
  is readable without initiating collection.
- The real WordPress probe is included in every live installer-matrix row.

All six [live matrix jobs](https://github.com/dknauss/Keel/actions/runs/39697678143)
passed on `977669d`, including the new timeline probe, persistent-cache multisite,
French locale, PHP 7.4 / WordPress 6.4 floor and backward/forward installer flows.
PR CI also passed across PHP 7.4–8.5. Independent review found no remaining issues
after the cached timing regression was fixed. Subsequent changes only clarify
historical TODO wording and record this evidence.
