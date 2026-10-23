# Bounded HIBP lookup budget design

Status: **implemented and verified in open PR #316**, unreleased. See the
[verification record](../../review/bounded-hibp-budget-2026-10-23.md).
Written 2026-10-23 against Keel `1d754a9` (merged #314). The user approved this
bounded policy; numerical defaults remain operational starting points, not measured capacity.
This change preserves PHP 7.4 compatibility and the 44-setting schema.

## Approved policy

Use one shared token bucket per standalone site or WordPress network, ahead of
**uncached outbound** HIBP requests, with a fixed-size shared range cache. Do not
key throttling by password, prefix, IP address, cookie, user ID or submitted account.
Do not add a settings-screen control. Preserve the current fail-open password-change
behavior, but return and report **unknown**, never clean, when screening cannot run.

| Policy | Default |
| --- | --- |
| Burst allowance | 10 uncached requests |
| Refill | One request credit per 60 seconds, capped at 10 |
| Active admission leases | Two per scope; one per cache slot |
| Lease lifetime | 30 seconds; no renewal and no waiting for another worker |
| Retained range bodies | 64 fixed slots, each at most 128 KiB |
| Cache freshness | 12 hours from receipt of the validated live response; no sliding expiry |
| Budget/lease mutation retries | At most three compare-and-swap attempts per operation |
| Unavailability diagnostic writes | At most one per scope per five minutes, across reason changes |
| Scope | Site on single-site; network on multisite, including when Keel is site-active |

A bucket is an allowance, not an hourly window: it permits the initial burst and
then approximately 60 new requests/hour at steady state, not a strict 60-per-hour
ceiling. Cached checks do not consume credits. Scope is each network, not an entire
multi-network installation; aggregate capacity scales with the number of networks.

Ten credits is a conservative starting point for ordinary administrative password
changes, not a claim that it fits large signup/import workloads. Offer one code-level
policy filter for trusted operators: integer capacity 1–100 and refill interval
1–3600 seconds, validated as a complete configuration. Invalid values use the
defaults. No disable/unlimited value; the existing deliberate HIBP opt-out remains.
Changing a valid policy must preserve credit conservatively: clamp existing credit
to the new capacity, discard fractional credit on interval changes, and reset the
refill reference to now without granting a fresh burst. Policy changes cannot erase
active leases, reset diagnostic throttles or create new storage keys. When the interval changes, convert credit-seconds with
`new_credit = min(new_capacity, floor(old_credit / old_interval)) * new_interval`;
set the refill reference to `max(now, old_refill_reference)`. The cache and
lease caps remain fixed in v1. Document the resulting request bound when tuning.

## Baseline before implementation

`includes/password-policy.php` rejects empty and over-4096-byte input before the
lookup, but role exemptions are deliberately checked afterwards. Other local rules
remain role-scoped. Keep that ordering: this work must not silently exempt subscriber
accounts from breach screening or expand their length/blocklist requirements.

At `1d754a9`, `includes/password-hibp.php` cached each five-character SHA-1 prefix in
both an object-cache group and a 12-hour transient. It limits each default response
to 128 KiB and validates completion, format and zero-count padding, but has no
aggregate request or cache-cardinality budget. Repeated random prefixes can therefore
cause fresh HTTP and retained entries. Repeated requests for the same cold prefix
can also race. Existing generation namespacing and uninstall cleanup are necessary
but do not limit runtime cardinality.

This is local resource protection. The Pwned Passwords range API itself has no
service rate limit; do not confuse it with HIBP's other APIs. Keep five-character
prefix queries, local suffix comparison and `Add-Padding: true` as documented by
[HIBP](https://haveibeenpwned.com/API/v3#PwnedPasswords). Do not send incremental
password input, complete hashes or plaintext. These design-baseline observations concern that code and API, not a measured
production workload. Current implementation evidence is recorded below.

## Options and consequences

| Option | Benefit | Why it is not the recommended primary policy |
| --- | --- | --- |
| Per IP | Familiar request throttling | NAT/proxies group legitimate people; untrusted forwarded headers and distributed clients complicate correctness; adds identifying keys. |
| Per user/account | Separates known users | Not all flows have an authenticated actor; attacker-selected account keys can punish a victim or grow storage. |
| Per prefix | Coalesces duplicate work | Random prefixes bypass it and grow keys; it cannot bound total work. |
| Shared bucket plus bounded cache | Finite transport work and storage independent of client identity | One attacker can consume the shared allowance and reduce fresh breach screening for other users. |

The shared-allowance tradeoff is real. This design bounds amplification; it does not
make screening available to every legitimate user during an attack. A small reserve
for administrators would introduce actor classification, abuse and privacy rules;
leave that out of v1. Per-IP WAF rules can complement the design outside Keel.
Do not add queues, retries, cron jobs, CAPTCHAs or a new public endpoint.

## Lookup contract and ordering

1. Preserve existing HIBP opt-outs, local verdict filter and early empty/oversize
   validation. Disabled screening never opens the budget/cache store.
2. Hash only within the request. Read the one applicable cache slot and validate
   its schema, exact matching prefix, size, completion, body format and unexpired
   timestamp. A valid hit answers immediately before reading/mutating the bucket.
   Cached positive matches still reject a breached password while allowance is empty.
3. On a miss, inspect the small authoritative control row. Compute refill in memory,
   validate state and remove expired admission leases from the candidate state.
   If the requested slot already has a live lease, both leases are occupied, or no
   whole credit remains, return unknown immediately. No sleep, polling or queue.
4. Reserve one credit and a unique random ownership token using atomic
   compare-and-swap (CAS). Only the winning worker may attempt HTTP. A failed CAS
   rereads current state, checks cache again and retries within the fixed bound.
   Exhausted contention/storage failure returns unknown without HTTP.
5. Claim the fixed cache slot with that ownership token. Failure to claim consumes
   the reservation but starts no HTTP. Recheck lease ownership/expiry before starting
   HTTP; abandoned/expired workers must stop at that check.
6. Make one HTTP call using the existing configured transport timeout, response cap
   and padding. No refund for HTTP errors, invalid bodies, cancellation or worker
   crashes; refunds would let failures bypass the budget. No automatic retry.
7. Validate before answering or caching. Cache at most 128 KiB per slot even if the
   existing trusted `keel_hibp_max_response_bytes` filter permits a larger transport
   body. A larger, otherwise valid complete response can supply this request's verdict
   but is not retained. This preserves that filter without weakening the storage cap;
   the request budget limits call count, not bytes under operator-raised transport caps.
8. Before publishing or releasing, recheck ownership. Slot writes must be fenced by
   the ownership token: an older worker cannot overwrite a newer owner's slot or
   release its lease. If ownership is lost or expiry observed, discard cache publication
   and return unknown rather than presenting an obsolete completion as fresh evidence.
   A storage/cache-write failure after a validated HTTP response may still return
   that response's verdict, provided ownership is still valid; do not fabricate a
   successful cache write or a clean result from a storage error.
9. Preserve the public `bool|null` lookup contract and the current local verdict filter.
   For unknown, the existing password adapter remains fail-open; length, local
   blocklist, personal-context rules and their existing exemptions still apply.

Two leases bound *valid admissions*. A suspended worker or a transport/drop-in that
ignores timeouts can outlive its lease, so this is not a promise of at most two physical
sockets. A recovered worker must recheck before HTTP and before publication, with
fencing against replacement owners. Expiry never replenishes spent credits. An
operator can raise the existing timeout beyond the lease; document that such lookups
may be discarded after consuming allowance. Do not silently change that legacy filter
in this feature. Reliable cross-worker database state and a reasonable server clock
are assumptions; a malicious plugin or DB administrator is outside the threat model.

## Storage and concurrency

Prefer 65 fixed, non-autoloaded rows in the scope's canonical options table: one
small control row and 64 range slots. Single-site uses its options table; multisite
uses the current network's main-site options table with the network ID included in
fixed names. Resolve this from trusted WordPress network/site APIs, never request
parameters. A missing/deleted main site or unsupported storage adapter returns unknown;
do not fall back to a subsite-local bucket. Switching blogs does not change the
network's bucket. Different networks do not share it. No custom table in v1.

Use fixed keys such as `keel_hibp_v2_<network-id>_state` and
`keel_hibp_v2_<network-id>_slot_00` through `_slot_63` (single-site scope ID 0).
The control row contains schema/revision, credit/refill state, at most two leases
(slot index, random owner, expiry), and bounded diagnostic fields. No identifying
actor, prefix, suffix, password or request URL goes in it. Each slot contains its
current owner and at most one validated prefix/body/expiry tuple. Prefix/range data
is necessary cache material; it must never be exposed as diagnostic evidence.

Map the prefix deterministically to one slot: the first byte modulo 64. On a
collision, an unexpired exact-prefix hit is still usable before lease checks. A
new validated response may replace the old tuple; while a new owner is fetching,
retain the old valid tuple for hits rather than removing it at claim time. Slot
collisions can reduce cache effectiveness or suppress simultaneous cold lookups;
that is an intentional bounded-space tradeoff, not an unknown-prefix clean verdict.

Use database-authoritative reads and atomic conditional updates, not
`get_transient()` followed by `set_transient()`, and not an evictable cache lock.
WordPress explicitly allows transients to disappear before their nominal expiry
([Transient API](https://developer.wordpress.org/apis/transients/)). An evicted
counter must not grant another burst. `wp_options` has a unique option-name key
([core schema](https://developer.wordpress.org/reference/functions/wp_get_db_schema/));
network metadata lacks the same uniqueness guarantee, so do not base initialization
on a racing `add_site_option()` call.

Initialize the control row once using the unique name; only the insert winner
creates the full initial bucket. Existing corrupt/unsupported state must not be
reset automatically to a full bucket. Missing cache slots may be inserted lazily;
invalid slots are cache misses and may be replaced only by a properly admitted owner.
Require byte-exact CAS and revision/ownership checks for state and slots, compatible
with MySQL/MariaDB and the Studio SQLite adapter. Resolve table identifiers from
WordPress, prepare values, and keep each SQL operation narrowly keyed. No unbounded
retry or database lock held across HTTP. If an adapter cannot implement these
semantics, report unknown and make no outbound request; do not pretend that ordinary
Options API read/modify/write is atomic. Explicitly test backend comparison/collation
semantics when implementing the small storage adapter.

Do not mirror this new store into persistent object-cache groups in v1; eviction
must not alter authority, and blog-local groups must not multiply a network cache.
Read only the applicable range slot plus small control state, never all eight
MiB. Bounded CAS retries may reread that slot; keep at most one payload resident. Request-local memoization must also be bounded: at most one range payload and
bounded metadata, cleared on scope changes and subject to the same expiry/schema
checks. Unknown denial results must not be memoized indefinitely in long CLI runs.
Request-local cache hits need not reread the DB until their captured expiry.

Each control row is capped at 4 KiB. Each slot caps raw body at 128 KiB and its
complete encoded stored value at 129 KiB, including metadata and encoding overhead.
If encoding would exceed that ceiling, skip retention without truncating a valid
live answer. Thus new retained row count is at most 65/scope and raw range bodies at
most 8 MiB/scope, plus at most 68 KiB metadata. Those are logical retained-data
bounds, not process-memory, DB file-size, serialization-allocation or legacy-cache
bounds. Use a bounded text encoding without base64 expansion, validate lengths
before accepting stored state, and never autoload payload rows. Replaced/expired
slots reuse their fixed keys; expiry does not depend on cron deleting rows.

Refill uses integer arithmetic (e.g. credit measured in seconds, request cost 60
under the default policy). Cap credit before addition to avoid overflow after long
idle periods. Clock rollback grants nothing and preserves the previous refill
high-water mark; implausible/corrupt future state is unknown rather than silently
reset. Forward time replenishes no more than capacity; this is not a rate guarantee
against an operator repeatedly changing the machine clock. Revision/token rollover
must fail conservatively, not restore a burst.

## Diagnostics, lifecycle and privacy

Keep denial kinds distinct: `budget_exhausted`, `lookup_busy`, `budget_unavailable`,
and existing transport/body-validation failures. Return unknown for all; report
that a check was skipped or could not complete rather than saying HIBP is down.

Use the fixed control row for small shared last-live-success and sampled-unavailability
evidence. A denial should not rewrite state on every request: at most one diagnostic
sample every five minutes across *all* reason changes, using CAS. Label samples as
samples; do not count them as total denied requests. Transport admission/release
writes remain bounded by allowance/lease turnover; cheap cache hits do not write.
Successful live evidence can update during an already-required ownership/release
operation. Cache hits do not prove live service recovery or clear outage evidence.
Diagnostic-only failures must not stop a valid cached verdict. If the main control
row is corrupt/unavailable, report that read-time state without trying to repair it
on each denial. Site Health reads never refill persistently, call HTTP or write.

On multisite label this **network-shared screening transport evidence**, not proof
that a particular site's last password was checked. Restrict network-wide detail to
network managers; a site administrator can receive a generic unknown/degraded status
without another site's timestamps or activity. Keep cached/live/unknown distinct.
Do not export identities, prefixes, suffixes, request payloads or raw range bodies.
Do not add fields to the posture export in this task. Preserve existing supported
local verdict/opt-out filters; no extra filter invocation merely for diagnostics.

On upgrade, stop creating and reading legacy per-prefix cache entries. This is a
cold-cache transition; do not scan or import arbitrary old prefixes during password
validation. Existing expired transient DB rows may remain until core cleanup or
explicit maintenance runs; object-cache keys may remain until eviction/TTL. The new
65-row/8-MiB bound does not retroactively describe those leftovers. Plan bounded,
resumable cleanup separately if needed; do not run wildcard deletion or global
cache flush from an ordinary password request. Existing uninstall cleanup remains
necessary for legacy caches.

Deactivation retains the bucket so toggling the plugin cannot mint a fresh burst.
Expiry is checked on read; no cleanup cron is added. Uninstall removes the 65 known
keys for each owning scope, alongside legacy cleanup, with paged network traversal.
Reinstall after deliberate uninstall starts empty cache/full allowance. Complete
removal of the scope's main-site table/rows also resets authority; administrative
storage deletion cannot be protected by the budget itself.

HIBP transport/password validation is a shared Keel/Agency Experience path. The
implementation must review and port the agreed transport, unknown-status and cleanup
contracts to AX, or explicitly track the counterpart before merge. No AX runtime
changes or parity claims are made by this design-only work.

## Acceptance tests before implementation can merge

1. Freeze a controllable clock. Verify initial 10 admissions, the 11th denied, one
   credit at 60 seconds, preserved fractional refill, idle-cap, time rollback,
   future/corrupt state, integer bounds, policy changes and no refund on every failure.
2. Cache hits precede all budget mutation, including known-breached cached results
   under exhaustion; reject mismatched, malformed, oversized, future or expired cached
   data. Fixed slots stay <=64 over thousands of distinct adversarial prefixes and
   collisions, with body/metadata bounds and non-autoload flags enforced.
3. Barrier-synchronize real independent PHP workers against MySQL/MariaDB and SQLite:
   initial-row race, last credit, two-admission cap, same-slot coalescing, ownership
   replacement and stale release/publication. A sequential stub test alone cannot
   prove atomicity. An unavailable adapter admits nothing and creates no fallback keys.
4. Kill/suspend workers after reservation, slot claim and HTTP. Leases recover without
   refunds, old workers cannot change newer ownership, and three failed CAS attempts
   end as unknown without HTTP. Explicitly record the physical-socket limitation.
5. Repeat with no persistent cache, a persistent cache, eviction/flush, and scope
   switches. All child sites share one network allowance/cache even under site-only
   activation; networks stay independent. Verify no DB/object-cache namespace growth.
6. Preserve empty/oversize early rejection, universal breach screening for exempt
   roles, existing later role-scoped checks, all opt-outs, verdict filters, padding,
   transport cap/timeout overrides, truncated/malformed/HTTP-error handling and null
   semantics. No unknown branch may be labelled verified clean.
7. Drive repeated and alternating denials at high rate: zero HTTP, no per-attempt
   diagnostic writes or new keys, <=one diagnostic write/5min/scope, truthful samples,
   no cache-hit recovery claims, no secrets in logs/Site Health/export. Site Health
   authorization and read-only behavior must be covered on single-site/multisite.
8. Test cold upgrade, legacy leftovers, deactivation/reactivation, main-site failure,
   reinstall and paged uninstall. Read-only routes without password screening incur
   zero added queries, writes or HTTP. Benchmark hit/miss/exhausted paths separately;
   quantify the deliberate shared-DB hit-path cost before accepting this design's
   storage tradeoff. Run the existing standalone/real-WP gates and counterpart checks.

Implementation order: review this policy; build and prove the bounded atomic store;
wire cache-hit/admission/transport paths; reconcile diagnostics/lifecycle and AX;
then run the adversarial/parallel labs and document measured costs. If atomic storage
or hit-path costs prove unacceptable, revisit the design rather than weakening the
bounds invisibly. The user subsequently authorized implementation and a PR; no version bump or
release is authorized.

## Design review and validation

At the design-only stage, the builder check reported no consequential unanswered
questions. That review did not itself accept the numerical policy or prove
concurrency/storage behavior. The user subsequently approved implementation; the
linked verification record now supplies the backend acceptance evidence. Documentation/count guards
(`tests/default-count.php`, `tests/docs-consistency.php`, `tests/readme-spec.php`)
and `git diff --check` pass. No new runtime or lab tests were claimed for this
design-only change. TODO and ROADMAP mark #314 (`1d754a9`) merged and unreleased,
and separate this design review from future implementation work.

## Implementation decisions

The store uses the canonical options table directly; no transient or object-cache
copy is authoritative. Both slot claim and completion use a short transaction and
a control-row CAS to fence reservations while touching a slot. No DB lock spans
HTTP. Only InnoDB and the WordPress SQLite adapter with observable transaction
state are supported; unknown adapters fail as unknown. A caller-owned transaction
or disabled MySQL autocommit denies new work before mutations or HTTP, because
MySQL-compatible BEGIN can implicitly commit an existing transaction. Cached hits
remain readable. SQLite's public `inTransaction()` checks its connection. MySQL checks autocommit
and creates/releases a random private savepoint: outside a transaction, SAVEPOINT
has no effect and RELEASE reports 1305; inside one, both succeed and fresh work is
denied. The probe never rolls back or commits caller data and removes its own savepoint.

Success retains the failure sample until its one-hour display expiry. It cannot
reset the five-minute sampling cadence; Site Health shows the two historical
evidence points separately. Lost ownership also samples unknown without touching
a newer lease. Capacity-only policy changes retain fractional credits; interval
changes use the conservative whole-credit conversion above.

Fenced MySQL transactions pin their original mysqli handle and bypass wpdb's
connection-retry/replay path until commit/rollback. A connection change, SQL failure
or deadlock latches failure and permits only rollback; an old worker cannot reconnect
and replay a stale publication after losing its row lock. An independent worker
kills that database connection, admits a replacement owner, then resumes the old
worker to verify slots, leases, credits and success evidence remain intact.
