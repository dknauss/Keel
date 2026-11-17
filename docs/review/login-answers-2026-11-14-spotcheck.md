# Spot-check: Login Answers lab (#378), 2026-11-14

> **Summary.** Every probe re-run here reproduces as recorded: P28, P5, P19,
> plus P26, P27, P29 and P30 (multisite) and the rest of the single-site phase
> on 7.1.3 came out PASS from the record's own scripts, and P28, P5 and P19 also
> PASS under separate raw `curl` checks that don't use the runner's comparison
> code. One new finding: the P28 docs fix (`8e0c0fe`) is still incomplete. A
> **domain-mapped site on a subdirectory network** links "Lost your password?"
> to the main site's form, not its own, so the docs sentence "on a subdirectory
> network each site serves its own" is wrong for that site. The behaviour itself
> matches the decided rule (the site that serves the form decides). Two small
> lab-hygiene notes follow.

This is the Tier 3 separate spot-check from the
[QA and AI-testing process](https://github.com/dknauss/agent-skills/blob/trunk/docs/qa-process.md).
It covers PR [#380](https://github.com/dknauss/Keel/pull/380) at `04276f6`.

## Who did what

| Role | Who |
| --- | --- |
| Spot-checker | A fresh Claude session started by the project coordinator. It wrote neither the probes (`47e2d3a`) nor the code (`fc6080e`, `8e0c0fe`), and it did not read the implementer's process log. Same model family as both, so shared blind spots are possible. |
| Inputs read | `docs/review/probes/378-uniform-login.md`, `docs/review/login-answers-2026-11-14.md`, `tests/integration/login-answers-lab/` (README, `setup.sh`, `env.sh`, `run.sh`, `probes.py`), the `8e0c0fe` diff, core 7.1.3 `wp_lostpassword_url()` |

## Lab

The README commands were followed as written, with these differences:

- Only two sites were built: `s71` (7.1.3 single site) and `ms` (7.1.3 subdirectory multisite), with `setup.sh "s71 7.1.3 mysql 0" "ms 7.1.3 mysql 1"`. The probes picked here don't need `s64`, `sq` or WooCommerce.
- `KEEL_LA_WP_GIT` pointed to a shallow clone holding only tag `7.1.3` (`git clone --depth 1 --branch 7.1.3`). `setup.sh`'s `git archive 7.1.3` works with that.
- WP-CLI came from `composer require wp-cli/wp-cli-bundle`. MariaDB was 10.11.14 and PHP was 8.3.6.

Every README command ran without edits and gave the results below. **Nothing failed to reproduce.**

## Results

| ID | Why picked | Record | Re-run with the record's scripts | Separate check | Result |
| --- | --- | --- | --- | --- | --- |
| P28 | The only probe that failed and led to a fix | PASS (premise corrected) | PASS: the link is `http://ms.test/site2/wp-login.php?action=lostpassword`; with main on and `site2` off, `site2`'s login and lost-password forms both answer as core does, and the main site's form is uniform | Both directions, following the real link: see below | **PASS** for a path-based subsite. **Docs finding** for mapped domains |
| P5 | Core login uniformity | PASS | PASS (`s71`) | Raw `curl`: all four inputs give byte-identical headers (except `Date`) and bodies once the typed value is masked | **PASS** |
| P19 | Reset not allowed must not confirm the account | PASS | PASS (`s71`) | Raw `curl`, setting on and off: see below | **PASS** |
| P26, P27, P29, P30 (ms) | Multisite | PASS | PASS, all four | — | **PASS** (runner only) |
| P3, P6–P23, P32 (s71) | Rest of the single-site phase | PASS | PASS, every line | — | **PASS** (runner only) |

### P28 in detail

- **Core source.** In 7.1.3, `wp_lostpassword_url()` builds the link from `network_site_url( get_site()->path . 'wp-login.php' )` (`wp-includes/general-template.php` lines 901–908). So the link goes to *the network's domain plus the site's path*.
- **Main on, `site2` off, no network policy.** I POSTed all four inputs to the link `site2` actually renders. Unknown inputs got 200 with "There is no account…" and no mail; real inputs got 302 to `/site2/wp-login.php?checkemail=confirm` with one mail. `site2`'s login also gave core's two messages. So the subsite's own setting governs both of its forms.
- **Main off, `site2` on (the reverse, not run by the record).** All four inputs got 302 to `/site2/wp-login.php?checkemail=confirm`, with mail counts 0, 0, 1, 1. `site2`'s login gave the uniform message for both `nobody` and `riley`. This confirms the result isn't an accident of the main site's choice.
- **Domain-mapped site (new).** I added `site3`, then set its `wp_blogs` row to `domain=mapped.test, path=/` and its `home`/`siteurl` to `http://mapped.test`. Its login page links "Lost your password?" to **`http://ms.test/wp-login.php?action=lostpassword`**, the main site's form. With main off and `site3` on, `mapped.test`'s login is uniform, but the linked lost-password form answers `nobody` with 200 "There is no account with that username or email address." and `riley` with 302 and one mail. The docs fix says "on a subdirectory network each site serves its own", and that is wrong for this site.

**Verdict.** P28's Expected is "the site that serves the lost-password form decides", and the code meets it in all three cases. The help text, readme FAQ and `docs/wordpress-default-settings.md` should say that any site whose address has no path of its own goes to the main site's form. That covers every site on a subdomain network and a site with its own mapped domain on any network. Suggested wording for the detail string:

> On multisite, a site's "Lost your password?" link leads to the main site's form when the site's address has no path of its own (every site on a subdomain network, and any site with its own domain), so the main site's setting, or network policy, decides it. A site at a path such as `/blog/` serves its own form.

Network policy gives one rule everywhere, as the docs already say, so a site that wants certainty has a fix that works today.

### P5 in detail (`s71`, setting on)

| Input | Header hash (no `Date`, cookie values dropped) | Body hash (typed value masked) | Typed value appears | `#user_login` value | Focus |
| --- | --- | --- | --- | --- | --- |
| `nobody` | `81d68c6f` | `ada35f51` | once (the field) | `nobody` | `user_pass` |
| `nobody@example.test` | `81d68c6f` | `ada35f51` | once | `nobody@example.test` | `user_pass` |
| `riley` | `81d68c6f` | `ada35f51` | once | `riley` | `user_pass` |
| `riley@example.test` | `81d68c6f` | `ada35f51` | once | `riley@example.test` | `user_pass` |

The message was "Error: The username, email address or password is incorrect. Lost your password?". As a control, with the setting off `nobody` and `riley` got core's two different messages, so the comparison isn't vacuous. The full header set was the same for every input: status 200, `Expires`, `Cache-Control`, `Content-Type`, `Set-Cookie: wordpress_test_cookie`, `X-Frame-Options`, `Content-Security-Policy` and `Referrer-Policy`.

### P19 in detail (`s71`)

| Setting | `noreset` | `nobody` | `riley` | `noreset` vs `nobody` |
| --- | --- | --- | --- | --- |
| on | 302 → `/wp-login.php?checkemail=confirm`, 0 mails | 302, same `Location`, 0 mails | 302, same `Location`, 1 mail | headers and body identical |
| off (control) | 200, "Password reset is not allowed for this user" | 200, "There is no account with that username or email address." | 302, 1 mail | bodies differ (the leak the setting closes) |

No reset key was written for `noreset` (its `user_activation_key` is empty), while `riley`'s is set.

### Extra observation (outside the probes)

With the setting on, the `site2` lost-password form answered `nobody`, `netonly` (a network user who isn't a member of `site2`), `spammy` (spammed) and `pendingone` (pending signup) the same way: 302 to `/site2/wp-login.php?checkemail=confirm`. Mail went to `netonly` (1) and `riley` (1), and to nobody else. That is core sending a reset to any network account, and it doesn't show on screen, so it's no finding. It belongs with the P24 timing caveat, since a mail costs time.

## Lab hygiene notes

1. **A Python bytecode file is committed.** `tests/integration/login-answers-lab/__pycache__/probes.cpython-313.pyc` arrived in `04276f6`. It should be removed, and `__pycache__/` added to `.gitignore`. It's excluded from the release by `/tests` in `.distignore`.
2. **The runner's P28 check is looser than the probe.** It records `served_by_main_site` but doesn't assert it. It POSTs to a hard-coded `/site2/` path rather than the link it read, it runs only one direction (main on, `site2` off), and it doesn't check the docs half of the Expected column. The record's P28 PASS still holds, because the separate checks above cover those gaps, but a later re-run of the script alone would not catch a regression there.

## Reproduce

The separate checks used `curl` against the lab's `php -S` servers, with the lab's own `set-setting.php` for settings and the lab's mail log for counts. The lab's `env.sh` started the database and web servers inside each command. The scripts lived in the spot-checker's scratch space; the commands that matter are:

```sh
# Login, one input (repeat for each input; compare headers without Date, and bodies with the typed value masked)
curl -s -D h -o b -H 'Host: s71.test' -H 'X-Lab-Rid: <id>' \
  --data-urlencode 'log=riley' -d 'pwd=x-wrong&wp-submit=Log+In' http://127.0.0.1:8871/wp-login.php
# Lost password at the link a subsite actually renders
link=$(curl -s -H 'Host: ms.test' http://127.0.0.1:8872/site2/wp-login.php | grep -o 'href="[^"]*action=lostpassword[^"]*"')
# Mapped-domain site
wp site create --slug=site3 ...; wp db query "update wp_blogs set domain='mapped.test', path='/' where blog_id=3"
wp option update home http://mapped.test --url=http://mapped.test   # and siteurl
curl -s -H 'Host: mapped.test' http://127.0.0.1:8872/wp-login.php | grep -o 'href="[^"]*action=lostpassword[^"]*"'
```

Tested commit: `04276f6` (product code as of `8e0c0fe`).
