# Tester probes

A lab pass is only as good as what it chooses to check. When the agent that
wrote a change also picks the probes, it drifts toward the paths it built and
away from the ones it forgot. For risky changes, Keel splits the work:

1. **A tester goes first.** Another agent (ideally on a different model, such
   as Codex testing Claude's work or the other way round) or a person writes
   the probes from the issue, the readme and the declared support surface. It
   doesn't see the diff or the implementer's plan. It commits one file here,
   `docs/review/probes/<slug>.md`, in a commit that touches nothing else and
   ends with a `Lab-Role: tester` trailer.
2. **The implementer builds and runs the lab.** Every tester probe gets a
   result in the evidence record: PASS, FAIL, DISPUTED or NOT RUN. The
   implementer can add its own probes (I1, I2, ...) in the record, but never
   edits the tester's file. A probe it thinks is wrong is marked DISPUTED with
   a reason, and Dan decides.

Use it for changes to authorization, login and passwords, secrets, personal
data, outbound mail or HTTP, destructive operations, and release candidates.
Skip it for copy, styling and docs.

The full method is in the wp-lab-pass skill's
[separate tester reference](https://github.com/dknauss/agent-skills/blob/trunk/skills/wp-lab-pass/references/separate-tester.md).

## File format

```markdown
# Probes: <the change, in plain words>

Tester: <agent and model, or a person>
Implementer: <agent and model>
Saw: <what the tester was given>

| ID | Behaviour to prove | Role | Artefact that proves it | Expected |
| --- | --- | --- | --- | --- |
| P1 | ... | editor | HTTP status, option unchanged | 403, no write |
```

## What CI checks

`.github/workflows/lab-probes.yml` runs `bin/check-lab-probes` on every pull
request. For each probe file the branch touches, it fails when:

- an earlier commit on the branch touches anything outside this folder (the
  build started before the probes landed);
- the tester's commit touches anything besides probe files, or has no
  `Lab-Role: tester` trailer;
- a later commit edits or deletes the file, or the branch edits a probe file
  that is already on `main`;
- the `Tester:` and `Implementer:` lines are missing, still placeholders, or
  name the same agent;
- there are no `P1`, `P2`, ... rows.

Merge commits are skipped, so merging `main` into the branch is fine. Pull
requests that touch no probe file pass.

The workflow runs on `pull_request_target`, so both it and the checker come
from the base branch: a pull request can't edit either and be graded by its own
version. The pull request's commits are fetched as data and read with git; none
of its code runs. A second required job, "Guardrail changes reviewed", fails any
pull request that touches `.github/`, the checker, its test and fixtures, or any
probe file, until a maintainer reads those diffs and adds the
`guardrail-reviewed` label. Add the `tier-3` label to make the probe job fail
when a pull request adds no probe file. The tier itself is a review judgment;
the gate checks ordering and presence, not whether results were resolved.

Git can prove the order and that nobody moved the probes. It can't prove the
tester never saw the implementation, or that the `Tester:` line is true. Those
are declarations; record them honestly.

Tests: `php tests/lab-probes-check.php`.
