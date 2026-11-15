# Agent instructions

Read [CONTRIBUTING.md](CONTRIBUTING.md) first. It sets the checks to run, the
risk tiers, and the rules for tests. This file adds one thing: how to write a
pull request description.

## Pull request descriptions

Write the description for a busy human reviewer.

- Use `.github/pull_request_template.md`. Keep its headings, fill each section,
  and keep the `Counterpart:` line.
- Keep the whole description under 200 words.
- Describe behaviour, not files. Don't list changed files or restate the diff.
- No introduction, no closing summary, no praise for the change.
- Under "Verification", give the exact commands you ran and their results. If
  something wasn't tested, say so plainly. Never claim a check passed unless
  you ran it in this session.
- For a bug fix, show how the bug was reproduced before the fix.
- Put long logs, transcripts, or review notes in a pull request comment or a
  collapsed `<details>` block, not in the description.
