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
| Codex PR review P1: the label outlived later pushes | On `synchronize`, if the push changed any guardrail file (or the old head can't be compared, as after a force push), the job removes `guardrail-reviewed` and fails. The job gets `pull-requests: write` for that and still runs nothing from the pull request. |
| Codex PR review P2: Tier 3 accepted any added file, e.g. `placeholder.txt` or a nested README | The tier-3 step now runs `bin/check-lab-probes --require-probe`, so "probe file" means exactly what the checker validates. |

Not changed: ordinary tests outside the probe machinery are reviewed at merge,
not label-gated, because almost every pull request touches them.

## Evidence

| Check | Result |
| --- | --- |
| Both jobs' shell steps, run from a base-commit worktree against this branch | probe job exit 0 (no probe file); guardrail job exit 1 without the label, exit 0 with it |
| P12 predicate on sample names | `Member 3f7a2c` (ID 7) and `Member a12b3c` (ID 12) accepted; `Member7`, `Member 000007`, `Member #7`, `Member 12` rejected |
| Guardrail step with a stub `gh`, against a scratch repository | unlabelled exit 1; labelled exit 0; code-only push after the label exit 0, label kept; guardrail push after the label exit 1, label removed; unknown old head exit 1, label removed |
| `tests/lab-probes-check.php` | 17 cases pass, including tier-3 with valid probes (pass), no probe, `placeholder.txt` only, nested README only (all fail) |
| `tests/integration/public-names.php`, WP 7.1.4, MariaDB, single site, fresh database with small user IDs | 5 of 5 runs: 17 cases passed |

## Coverage

| Row | Status |
| --- | --- |
| MariaDB, WP 7.1.4, PHP 8.4, single site | PASS (integration, 5 runs) |
| MySQL 8, multisite, WP 6.4 floor | NOT RUN here (CI backport matrix) |
| `pull_request_target` check attaching to the pull request as the required "Tester probes came first" | NOT RUN: shows on the first pull request opened after this merges. If the required check doesn't attach, the ruleset's check names need updating. |

## After merge (2026-11-14)

The first `qa-bootstrap.sh --apply` on Keel created both rulesets correctly. A
second run, after the classic branch-protection rule was removed, read GitHub's
"Branch not protected" 404 body as the list of required checks: the ruleset
then required three checks that never run and no longer required "Lint and unit
tests" or "Counterpart recorded". The independent re-test caught it. Dan reset
the list by hand to the four real checks (confirmed through the API), and the
script now aborts on any API error other than a 404 and is covered by a test in
agent-skills.

R13, whether the two `pull_request_target` jobs attach to a pull request and
satisfy the ruleset, is checked by the pull request that adds this section: it
touches no guardrail file and no probe file, so both jobs should pass and merge
should be allowed once the other two checks pass.
