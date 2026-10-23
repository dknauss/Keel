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

- [ ] 1. Add failing `tests/hibp-budget.php` for pure policy/refill/schema rules; run red. Create `includes/password-hibp-budget.php` with scope, fixed-row read/write, state validation/refill, cache read, admission, ownership, completion and sampled evidence. Run green and real adapter smoke tests before integration.
- [ ] 2. Replace legacy prefix-cache lookup path in `includes/password-hibp.php` with `keel_hibp_budget_admit($scope,$prefix)` returning `body`, `ticket`, or `error`; ticket `{scope,slot,owner,until}`. `keel_hibp_budget_finish($ticket,$body=null,$kind='')` validates/fences completion; `keel_hibp_budget_evidence()` returns `{available,failure,success}` without initialization/writes. Adapt legacy HIBP unit stubs/expectations and prove hit/exhaustion/unknown paths.
- [ ] 3. Update Site Health and uninstall for the shared evidence/fixed store. Add new denial wording, network authorization, non-writing readers and paged fixed-key cleanup tests; regenerate translations.
- [ ] 4. Add independent-PHP-worker WordPress integration harness and run SQLite plus MySQL/MariaDB, concurrency, storage, scope, failure/kill, persistent cache and performance cases. Use Studio for local site lifecycle/CLI and an isolated CI database lab where Studio cannot supply MySQL.
- [ ] 5. Update accepted design status, TODO/roadmap, docs and verification record. Run existing lint/compatibility/static/type/unit gates, deep review, fix findings, record counterpart honestly and open PR. No merge/tag/release.

## Interfaces

All storage functions live in `includes/password-hibp-budget.php` (loaded by password-hibp.php).
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
