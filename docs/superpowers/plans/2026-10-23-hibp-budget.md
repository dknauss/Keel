# Bounded HIBP Budget Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans task-by-task; bounded independent verification/lifecycle work may be delegated.

**Goal:** Implement the approved bounded shared HIBP transport/cache policy and prove it with independent real-WordPress workers.
**Architecture:** Fixed canonical options rows, byte-exact database CAS, two fenced admission leases and a 64-slot range cache. Lookup hits precede the bucket. Diagnostics remain passive and unknown stays distinct from clean.
**Tech Stack:** PHP 7.4+, WordPress 6.4+, MySQL/MariaDB and Studio SQLite, existing PHPUnit-free suites and disposable WP labs.
**Spec:** ../specs/2026-10-23-hibp-budget-design.md

## Global Constraints

- Burst 10; one credit/60 seconds; two 30-second leases; 64 slots; 128-KiB raw/129-KiB encoded slot; 4-KiB state; 12-hour freshness; three CAS retries.
- Shared current-network main-site options table, single-site scope 0; no persistent-cache authority, actor/prefix-keyed counters or new settings.
- No version bump, release or production writes. Preserve opt-outs, universal breach lookup, local policy and bool/null contract.
- Diagnostics sampled at most once/5min across reason changes; no raw credential/prefix evidence or posture export additions.

## Review Focus

- Concurrent initialization and last credit cannot mint extra allowance: independent-worker barrier tests.
- Lost/expired owner cannot publish/release newer ownership: stale-worker barriers and byte-exact CAS.
- Persistent-cache eviction and blog switches cannot split authority: real shared-scope tests.
- Denial/corruption cannot become a clean verdict or per-attempt writes: unit and instrumented WP checks.
- Fixed cache keys, encoded lengths, policy changes, clock skew and uninstall traversal remain bounded: storage/property tests.

## Tasks

- [x] 1. Add failing `tests/hibp-budget.php` for pure policy/refill/schema rules; run red. Create `includes/password-hibp-budget.php` with scope, fixed-row read/write, state validation/refill, cache read, admission, ownership, completion and sampled evidence. Run green and real adapter smoke tests before integration.
- [x] 2. Replace legacy prefix-cache lookup path in `includes/password-hibp.php` with `keel_hibp_budget_admit($scope,$prefix)` returning `body`, `ticket`, or `error`; ticket `{scope,slot,owner,until,prefix}`. `keel_hibp_budget_finish($ticket,$body=null,$kind='')` validates/fences completion; `keel_hibp_budget_evidence()` returns `{available,failure,success}` without initialization/writes. Adapt legacy HIBP unit stubs/expectations and prove hit/exhaustion/unknown paths.
- [x] 3. Update Site Health and uninstall for the shared evidence/fixed store. Add new denial wording, network authorization, non-writing readers and paged fixed-key cleanup tests; regenerate translations.
- [x] 4. Add independent-PHP-worker WordPress integration harness and run SQLite plus MySQL/MariaDB, concurrency, storage, scope, failure/kill, persistent cache and performance cases. Use Studio for local site lifecycle/CLI and an isolated CI database lab where Studio cannot supply MySQL.
- [x] 5. Update accepted design status, TODO/roadmap, docs and verification record. Run existing lint/compatibility/static/type/unit gates, deep review, fix findings, record counterpart honestly and open PR. No merge/tag/release.

## Interfaces

Storage primitives live in `includes/password-hibp-store.php`; admission, cache and evidence live in `includes/password-hibp-budget.php`, loaded by password-hibp.php.
- `keel_hibp_budget_scope(): array|false` => `{table:string,id:int}`.
- `keel_hibp_budget_policy(): array` => `{capacity:int,interval:int}`.
- `keel_hibp_budget_initial(array $policy,int $now): array` and `keel_hibp_budget_refill(array $state,array $policy,int $now): array|false` are pure.
- `keel_hibp_budget_read(array $scope,string $key,int $cap=4096): string|null|false`; null missing, false error/oversized.
- `keel_hibp_budget_write(array $scope,string $key,?string $old,array $new,int $cap=4096): bool`; insert-if-absent or byte-exact CAS; encoded bound and autoload=no.
- `keel_hibp_budget_state(?string $raw,int $now): array|false` validates existing state (never resets corrupt rows).
- `keel_hibp_budget_cache(array $scope,string $prefix,int $now): string|false` reads one matching valid body.
- `keel_hibp_budget_admit(array $scope,string $prefix): array` => `['body'=>string]`, `['ticket'=>array]`, or `['error'=>kind]`.
- `keel_hibp_budget_owned(array $ticket): array|false` => `{raw:string,state:array}` only for current unexpired owner.
- `keel_hibp_budget_finish(array $ticket,?string $body=null,string $kind=''): bool`; release only owned lease, optional fenced cache publish; false means unknown.
- `keel_hibp_budget_note(string $kind): void` samples failures without creating authority; `keel_hibp_budget_evidence(): array` passive `{available:bool,failure:?array,success:?int}`.
- Fixed key prefix `keel_hibp_v2_<scope-id>_`; state field names `v,rev,policy,credit,last,leases,sample,success`; lease fields `slot,owner,until`; cached slot fields `v,owner,data`; data `prefix,body,received,expires`. All fields validated at boundaries.

## Execution ledger

- User explicitly approved design and implementation; execute without an extra plan-approval pause.
- Existing thread limit requires reusing available collaborators. Runtime store/lookup are owned locally; lab harness and Site Health/uninstall have separate ownership.

- Initial pure-policy test failed before the module existed, then passed. Transport and state regressions cover malformed/truncated/oversized bodies, cache hits under exhaustion, policy changes, CAS retry bounds and caller rollback.
- Review fixed lost-lease diagnostic sampling and capacity-only fractional-credit loss. Stale-worker fences require short control-row transactions for both claim and completion; no lock spans HTTP.
- SQLite's MySQL translator can implicitly commit on BEGIN too. Use its public transaction-state API. The first MySQL lab exposed an unavailable mysqli property; replace it with an autocommit/private-savepoint check, preserving caller data and pruning only the expected suppressed probe error.
- MySQL reports zero affected rows on an unchanged CAS value; uninstall fixture setup now skips identical writes. The production CAS paths always advance revisions/owners.
- Standalone SQLite passed all 15 independent-worker cases. Two-network SQLite passed adapter/scope/cache paths. Four MySQL/MariaDB × floor/current cells passed the expanded suite; all 17 cases plus two child site-only cases pass in each final cell. No performance or backend success is inferred from sequential stubs.
- Counterpart port is explicitly pending in Keel #315; PR #316 remains unreleased. No version bump, tagging or publishing.

- KILL CONNECTION reproduced wpdb reconnect/replay after losing the row lock (bad: finished/cached true). Pinning the original native connection plus rollback-only failure latching changed the reproduction to false/false; independent replacement-owner tests pass on all four final database cells. Read-only denials avoid unnecessary transaction probes.
- Final evidence: `docs/review/bounded-hibp-budget-2026-10-23.md`; 81 standalone scripts, WPCS/compatibility/syntax, scoped PHPStan and static contracts pass. PR #316 open; AX #315 pending; no release actions.
