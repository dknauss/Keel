# Production observation — 2026-10-23

Focused read-only observation of the nine production sites in the maintainer's
shared access inventory. Final samples were taken at 22:01:46–22:01:59 UTC.
Public evidence uses Site A–I labels; domains, aliases, plugin inventories,
callback paths, raw option payloads and credentials are withheld.

## CI and merged development state

- #318 merged as `0a619bdfb1cedc8c6303b967cb099b00b5f0dfd8`.
- [Post-merge CI](https://github.com/dknauss/Keel/actions/runs/40027700735)
  passed, including lint, unit tests, compatibility/static checks, PHP 7.4–8.5
  and the existing rolling-build job. No version tag or WordPress.org deployment
  was requested or run for this observation.
- The [latest scheduled live core matrix](https://github.com/dknauss/Keel/actions/runs/39916543989)
  passed at its October 23 run on `8bab3b7`. It predates #318; it is not a new
  matrix run on the observation branch.
- #318's browser evidence and cleanup correction are merged and unreleased.
  The development posture export, registration audit and bounded HIBP budget
  functions are absent on the sampled released installations, as expected.

## Findings

[Sanitized per-site evidence](evidence/production-observation-2026-10-23.json)
retains only the projected fields used below.

| Observation | Result |
| --- | --- |
| Deployed Keel | 0.8.0 active on all nine sites |
| WordPress | 7.1.3; all standalone installations |
| PHP | Seven on 8.3.36; two on 8.4.27 |
| Jetpack | 16.4-a.3 active on all nine; connection/signature delivery not exercised |
| Environment | WordPress and Keel both report production on all nine |
| Cron configuration | `DISABLE_WP_CRON` true on all nine; external scheduler execution not established |
| Structural policy overlap | None in the WP-CLI request context |
| Informational overlap | Host must-use code identified on `wp_revisions_to_keep` and `user_has_cap` on every site; additional participants on `user_has_cap` remain informational |
| Retained diagnostic reports | CLI object-cache misses for autoload, cron and policy divergence on all nine |
| Core update evidence | Cached `update_core` present on all nine, with past attempt timestamps; no retained `auto_core_update_failed` record |
| XML-RPC settings | Endpoint block off on all nine; multicall off on eight and on on one |
| Password screening evidence | Strong-password setting enabled on all nine; no recorded legacy HIBP success timestamp or retained unavailable sample |

The host attribution is a current observation of the hooks previously described
as unattributed in the October 13 notes. Sharing a hook is not proof of a
conflict. No current structural overlap was found, but this CLI context does not
exercise admin-only hooks or prove every setting's effective result.

Missing diagnostic caches leave autoload size, cron pressure and retained
divergence findings **unknown**. No Site Health health/render callback, AJAX
collector or collection scheduler was invoked to fill those caches. A CLI cache
miss does not prove the browser has never collected evidence or that a previous
report never existed. No full Site Health browser pass was performed.

The core timestamp is an attempted-check marker, not proof of a successful
WordPress.org response, current offers or delivery. Absence of the retained
failure record is not a complete update history. Likewise, neither absence of a
legacy HIBP failure nor the enabled setting establishes that screening ran or
that the provider is currently available. No password check or HIBP request was
initiated. The unreleased bounded-budget behavior cannot be field-verified on
these 0.8.0 installations.

## Method and production boundary

Used the existing remote WP-CLI aliases after reading the user-level safety rules
and shared access inventory. The probe was supplied through `wp @ALIAS eval`;
no probe file was uploaded. Plugin header/activation reads, environment reads,
function-presence checks and the deployed passive overlap reflection supplied
inventory and attribution. Existing report caches were read with `wp_cache_get`
from the transient group. A bounded SELECT fallback for installations without an
external cache was included but not used on these sites. It disables object
instantiation when decoding and does not delete expired records. Only counts and
allowlisted scalar metadata leave the probe; no option payloads, cron arguments,
error messages or callback source are retained.

The follow-up reads project the existing core offer-cache timestamp, presence of
the core failure record, three configured booleans and a validated historical
legacy HIBP success timestamp. There were no settings submissions, update checks,
cron executions, cache flushes, collector calls, installations or production
mutation commands. This pass does not instrument all writes or network activity
inside normal WordPress/host/plugin bootstrap; it establishes the probe's
read-only scope, not a transactional zero-write audit of the entire request.

## Follow-up and validation

Field observation remains ongoing. The useful next evidence is an already
collected autoload/cron report from normal authorized administration. Intentionally
opening cold Site Health to schedule production collection is outside this
read-only pass. The separate WordPress.com settings-save confirmation remains
open; no save was attempted. No production defect requiring a code fix was
established by these observations.

Validated the nine JSON reports, version/count summaries, missing-cache results
and evidence links against the recorded data. TODO and roadmap now distinguish
this current snapshot from October 13 history and mark #318 merged/unreleased.
Runtime code, settings count and version metadata are unchanged.
