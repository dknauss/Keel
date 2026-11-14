# Lab record: probe gate from the base branch, P12 flake (2026-11-14)

Change: Keel #376. Tier 1 (guardrail tooling and a test fix).
Independent review: Codex, against ten probes written before it read the
implementation, plus one added afterwards (R1 to R11), on head 9ecaa69.
This record covers the fixes made after that review.

## What changed after review

| Finding | Fix |
| --- | --- |
| R1: on `pull_request` the pull request's own workflow decided whether `main`'s checker ran | `lab-probes.yml` runs on `pull_request_target`; it checks out the base commit, fetches the pull request's commits as data, and runs the base checker. Nothing from the head is executed. |
| R2: the flag missed new probe files, renames and the case where grading failed first | Separate required job "Guardrail changes reviewed"; `--no-renames` so a rename shows both paths; every probe file counts, new ones included; fails until a maintainer adds `guardrail-reviewed`. |
| R10: "ID not a whole word" accepted `Member7` and `Member 000007` | P12 now requires exactly `Member` plus six hex characters and rejects `Member`, optional `#`, zero padding and the ID. |
| R11: nothing tied Tier 3 to probes | A `tier-3` label makes the probe job fail when the pull request adds no probe file. Whether results were resolved stays a review judgment. |

Not changed: ordinary tests outside the probe machinery are reviewed at merge,
not label-gated, because almost every pull request touches them.

## Evidence

| Check | Result |
| --- | --- |
| Both jobs' shell steps, run from a base-commit worktree against this branch | probe job exit 0 (no probe file); guardrail job exit 1 without the label, exit 0 with it |
| P12 predicate on sample names | `Member 3f7a2c` (ID 7) and `Member a12b3c` (ID 12) accepted; `Member7`, `Member 000007`, `Member #7`, `Member 12` rejected |
| `tests/integration/public-names.php`, WP 7.1.4, MariaDB, single site, fresh database with small user IDs | 5 of 5 runs: 17 cases passed |

## Coverage

| Row | Status |
| --- | --- |
| MariaDB, WP 7.1.4, PHP 8.4, single site | PASS (integration, 5 runs) |
| MySQL 8, multisite, WP 6.4 floor | NOT RUN here (CI backport matrix) |
| `pull_request_target` check attaching to the pull request as the required "Tester probes came first" | NOT RUN: shows on the first pull request opened after this merges. If the required check doesn't attach, the ruleset's check names need updating. |
