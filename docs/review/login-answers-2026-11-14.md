# Lab record: Login Answers (#378), 2026-11-14

> **Summary.** 33 of 33 tester probes PASS on every cell they were run in:
> WordPress 7.1.3 and 6.4.7 on MariaDB 10.11, 7.1.3 on SQLite, and a 7.1.3
> subdirectory multisite on MariaDB, all on PHP 8.3. Two of those passes rest on
> a decision rather than a run: P4 by Dan's decision (network policy is the
> configuration override; P26), and P24, which measures response time and passes
> because the docs say it is not covered (Keel #383). P22, P23 and P32 were
> completed after the Codex re-review; one limit remains, listed under *Scope
> decisions* for Dan: P32 reads the browser's accessibility tree, not a screen
> reader. P24 measured a large timing gap on the login form (about 18 ms for
> an unknown account against 90 ms for a real one); it is documented as not
> covered, as decided, but it is the biggest limit on what this setting
> achieves. See *Findings*.

The change is PR [#380](https://github.com/dknauss/Keel/pull/380), Tier 3
(login). It follows the shared
[QA and AI-testing process](https://github.com/dknauss/agent-skills/blob/trunk/docs/qa-process.md).

## Who did what

| Role | Who | Evidence |
| --- | --- | --- |
| Decided rule | Dan, in issue [#378](https://github.com/dknauss/Keel/issues/378) (scope list drafted by the implementer, confirmed by Dan) | Issue body |
| Tester | A separate Claude session, briefed by Dan, which saw the issue, ROADMAP, readme, probes README and core source, never this branch's code. Dan delegated six spec-gap answers (P7, P19, P25, P27, P28, P31) to it. Same model family as the implementer; see the file's `Drafting:` line | [probes/378-uniform-login.md](probes/378-uniform-login.md), commit `47e2d3a`, first on the branch, `Lab-Role: tester` |
| Implementer | Claude (Anthropic), this thread | `fc6080e` (build), `8e0c0fe` (docs fix found by the lab) |
| Independent review | Codex on Dan's Mac | Pending; brief in the PR thread |

No tester probe was edited. The `Tester probes came first` check confirms it.

## The change

A **Login Answers** toggle, `uniform_login_answers`, Security group, default
`yes` (so on for sites that update without saving), in
`includes/login-answers.php`:

- `wp_login_errors` (applied only by `wp-login.php`, just before rendering):
  `invalid_username`, `invalid_email` and `incorrect_password` become one error
  under core's `incorrect_password` code, "The username, email address or
  password is incorrect." plus core's lost-password link. Reusing that code makes
  core refill the username field, focus the password field and shake the form
  the same way for every input. Other plugins' errors keep code, messages, data
  and order.
- `lost_password` (fired only by `wp-login.php`, after `retrieve_password()` has
  run once and failed): on POST, `invalidcombo`, `invalid_email`,
  `no_password_reset` and `retrieve_password_email_failure` are removed; with
  nothing else left the visitor is redirected exactly as core's success branch
  does. Another plugin's error, if any, is shown alone.
- A **Login answers** Site Health test: `good` on, `recommended` off, both
  naming what is and isn't covered.

## Coverage matrix

| Row | Cell | Result |
| --- | --- | --- |
| WordPress newest | 7.1.3 single site, MariaDB 10.11 (`s71`) | PASS (P1, P3, P5–P23, P25, P30–P33) |
| WordPress oldest supported | 6.4.7 single site, MariaDB 10.11 (`s64`) | PASS (P2, P3, P5–P23, P32) |
| SQLite | 7.1.3 single site, sqlite-database-integration trunk (`sq`) | PASS (P3, P5–P24, P32) |
| Multisite, subdirectory | 7.1.3, MariaDB, network-activated, `site2` (`ms`) | PASS (P26–P30, P33 network uninstall) |
| Multisite, subdomain | — | NOT RUN: `php -S` lab can't serve wildcard hosts. A mapped-domain site (`site3`, no path of its own) is run instead and takes the same `wp_lostpassword_url()` branch a subdomain site does (the main site's form); a real subdomain network is still not observed |
| MySQL 8 | — | NOT RUN here; CI matrix rows run unit tests only for this change |
| PHP 7.4 floor | — | NOT RUN: only PHP 8.3 in the sandbox. `phpcompat.xml` passes |
| Persistent object cache | — | NOT RUN; the change reads one autoloaded option through Keel's normal path |
| Third-party login form | WooCommerce 11.3.0 My Account on `s71` | PASS (P25: unchanged, as decided) |
| Upgrade path | Keel 0.9.2 (`v0.9.2` worktree) → this branch on `s64`, no save | PASS (P2) |
| Browser / screen reader | Chromium via Playwright 1.63, on `s71`, `s64`, `sq` | PASS (P32: accessibility tree, focus, Enter). Screen reader NOT RUN; see *Scope decisions* |

## Probe results

Cells: `s71`, `s64`, `sq`, `ms` as above. "Typed value masked" means the
response bodies were compared byte for byte after replacing the submitted value
(which P7 requires core to echo back) with a placeholder; nothing else was
masked.

| ID | Result | Cells | Evidence |
| --- | --- | --- | --- |
| P1 | PASS | s71 | Fresh activation (option deleted first): stored `"yes"`, resolved `yes`; control rendered `checked` |
| P2 | PASS | s64 | Stored option under 0.9.2 has no key; after swapping in this branch the row is byte-identical, resolves `yes`, and P5/P16 answers are uniform |
| P3 | PASS | s71, s64, sq | Setting off vs Keel inactive: full response bodies byte-identical for all four login inputs; lost-password status, `Location` and errors identical; core's three different messages present |
| P4 | PASS (decided) | ms | Was DISPUTED: Keel has no `wp-config.php` constant for this setting, and no core constant governs login errors. **Decided by Dan, 2026-11-14:** network policy is the accepted configuration override for this setting. That route is P26 (PASS): network policy on overrides a site's own "no", and the site's checkbox is shown locked. No constant will be added |
| P5 | PASS | s71, s64, sq | Unknown username, unknown email, wrong password: identical status (200), headers, cookie names and body (typed value masked) |
| P6 | PASS | s71, s64, sq | `riley@example.test` + wrong password identical to unknown email |
| P7 | PASS | s71, s64, sq | `#user_login` value is the escaped typed value for all four inputs; focus goes to `user_pass` for all |
| P8 | PASS | s71, s64, sq | `<strong>Error:</strong> The username, email address or password is incorrect.` + one `action=lostpassword` link; no submitted value |
| P9 | PASS | s71, s64, sq | `CASEY` (real `Casey`) and ` riley ` with wrong password identical to unknown username on MariaDB and SQLite |
| P10 | PASS | s71, s64, sq | `<script>…"'`, 1,000 characters, invalid UTF-8: status 200, same message, no raw markup echoed; invalid UTF-8 refills as empty (`esc_attr()`) |
| P11 | PASS | s71, s64, sq | Empty fields keep core's messages; `riley`/`nobody` with empty password identical |
| P12 | PASS | s71, s64, sq | `interim-login=1` and a `wp_login_form()`-shaped post (`rememberme`, front-end `redirect_to`): identical within each route |
| P13 | PASS | s71, s64, sq | `wp_login_failed` once per failure, with the submitted username, for all four inputs |
| P14 | PASS | s71, s64, sq | A test plugin returning `too_many_retries` from `authenticate`: "Locked out for 20 minutes." shown unchanged |
| P15 | PASS | s71, s64, sq | Correct password by username and email: 302 to `/wp-admin/`, `wordpress_logged_in_*` set, same `Location` as Keel inactive; breach re-check still hooked on `wp_login` |
| P16 | PASS | s71, s64, sq | All four lost-password inputs: 302 to `/wp-login.php?checkemail=confirm` |
| P17 | PASS | s71, s64, sq | Mail recipients: unknown username 0, unknown email 0, real username 1, real email 1 |
| P18 | PASS (tightened after Codex re-review) | s71, s64, sq | `wp_mail()` failing: the verdict now requires the mail log for that very request to hold one attempt, to one recipient, that the hook failed; then the same 302 as P16 and no "could not be sent". Before, a request that never tried to send mail could also pass |
| P19 | PASS | s71, s64, sq | `allow_password_reset` false for `noreset`: same 302, 0 mails |
| P20 | PASS | s71, s64, sq | `redirect_to=/thanks/` → `/thanks/` for all four; `https://evil.example/` → `http://<site>/wp-admin/` for all four |
| P21 | PASS | s71, s64, sq | Empty field: core's "Please enter a username or email address." |
| P22 | PASS (completed after Codex re-review) | s71, s64, sq | The whole reset-link flow, setting off and on, compared: a bad key for a real and an unknown login (302 to `action=rp` with the rp cookie, then the follow-up GET with that cookie: both 302 to `lostpassword&error=invalidkey`), an expired key for a real login (`error=expiredkey`) and a valid key (the reset form renders). Off and on identical in every field; the first redirect also matches Keel inactive. Core already answers a bad key the same for real and unknown logins; the expired-key answer exists only for a real login, but it needs a key that was mailed to that account, so it reveals nothing to someone without one |
| P23 | PASS (completed after Codex re-review) | s71, s64, sq | Baseline: Keel on with this setting off. Registration "already registered" and XML-RPC `wp.getUsersBlogs` ("Incorrect username or password." for both) unchanged. **REST Basic auth:** a fresh application password for `riley` is the positive control (`/wp/v2/users/me` 200 as user 2, off and on); a wrong one for `riley` and one for `nobody` answer the same off and on (404 `rest_no_route`: Keel hides the users routes from anonymous requests, and in this lab core never surfaces the application-password error to REST because the current user is resolved before `REST_REQUEST` is set, on and off alike). **Add New** (duplicate username and email) and **user-edit** (an administrator giving `eddy` riley's address) show core's "already registered" errors, off and on identical. **Profile** (riley asking for eddy's address): core shows no error there, a 302 to `profile.php?updated=1` off and on |
| P24 | PASS (measured) | s71, sq | 200 requests each, median ms (stdev): login unknown 17.5 (3.4) vs real wrong 89.3 (6.5) on MariaDB; 20.0 (3.0) vs 89.0 (4.7) on SQLite. Lost password unknown 13.4 (2.2) vs real 22.6 (3.6) on MariaDB; 16.1 (2.7) vs 23.2 (2.6) on SQLite. Readme, settings detail and Site Health say response time is not covered |
| P25 | PASS | s71 | WooCommerce My Account, Keel inactive vs on: identical notices, still core's three messages; readme and detail name WooCommerce as not covered |
| P26 | PASS | ms | Network policy `yes` with `site2`'s own setting `no`: `site2` login and lost-password uniform; control `aria-disabled="true" data-keel-locked="1"` with the lock note |
| P27 | PASS | ms | No network policy, main `yes`, `site2` `no`: main uniform, `site2` core's messages |
| P28 | PASS (premise corrected, re-run after spot-check) | ms | The site that serves the lost-password form decides it. A site with a path of its own serves its own form: `site2`'s link is `http://ms.test/site2/wp-login.php?action=lostpassword`, and its setting governs both answers in both directions (main on/site2 off: neither uniform; main off/site2 on: both uniform). A site with no path of its own links to the main site's form: `site3`, mapped to `mapped.test`, links to `http://ms.test/wp-login.php?action=lostpassword`, its login follows its own setting, and its lost-password answer follows the main site's (not uniform with main off, uniform with main on). The runner POSTs to the link each login page actually renders. The first pass hard-coded `/site2/` and checked one direction; the spot-check ([login-answers-2026-11-14-spotcheck.md](login-answers-2026-11-14-spotcheck.md)) showed `8e0c0fe`'s wording missed mapped domains, so the docs now say "no path of its own" (subdomain sites and any mapped domain), and the probe checks all three docs say so |
| P29 | PASS | ms | On `site2`: network-only user, spammed user, pending signup with wrong password identical to unknown |
| P30 | PASS (re-run after Codex review) | s71, ms | Each denied request carries a nonce minted for that user, so only a capability check can refuse it, and the runner names which check did. s71: the settings form as it posts with Login Answers unticked; an administrator's save turns it off (302), the editor's gets 403 from core's `manage_options` check and the setting stays on. ms: the network form as it posts with Login Answers managed and unticked; a Super Admin's save sets that policy (302), `site2`'s administrator gets 403 from core's Network Admin gate and the network option is unchanged. Keel's own capability check in the save handler sits behind that gate and is not reached here. The first pass sent a different setting (s71), a list where the form posts a map (ms) and an invalid nonce on both, so a 403 could have come from the nonce alone |
| P31 | PASS | s71 | Site Health `good` on, `recommended` off; both, the Help "Settings in detail" text and readme FAQ name all eight not-covered items |
| P32 | PASS (browser run added after Codex re-review) | s71, s64, sq | In Chromium (Playwright, `a11y.js`), setting off and on, Enter pressed in the password field: the accessibility tree of `#login_error` is the same text paragraph for every input, the form's tree is the same once the typed value is masked, focus lands on `user_pass` for every input, the error's role and live-region attributes are what core gives it with the setting off, and every Enter submits once. This is the tree a screen reader is handed, not a screen reader run; see *Scope decisions* |
| P33 | PASS | s71, ms | After `uninstall.php`: no `keel%` rows in `wp_options`, `wp_2_options` or `wp_sitemeta`, nothing containing `uniform_login_answers` |

## Implementer probes

| ID | Behaviour | Result |
| --- | --- | --- |
| I1 | A CAPTCHA-style error beside core's account error: real and unknown accounts end with the same errors, on both forms | PASS (unit, `tests/login-answers.php`) |
| I2 | `retrieve_password()` runs once per lost-password POST (no second `lostpassword_post`, so a one-time CAPTCHA token isn't spent twice) | PASS by construction: the callback runs on `lost_password`, after core's only call. Not instrumented |

## Findings

1. **Timing still separates real accounts on the login form (P24).** A real
   account costs a password-hash check (~70 ms here); an unknown one returns
   before it. The gap is about five times the response and ten standard
   deviations, so a script can tell real logins apart in a few requests. Decided
   as not covered, and documented so. Closing it is cheap: check the submitted
   password against a fixed dummy hash when no account matches. **Suggested
   follow-up for Dan**, not built here.
2. **Docs were wrong about multisite lost-password links (P28).** Fixed in
   `8e0c0fe`, which still left out mapped domains. The Tier 3 spot-check
   caught that; the wording now keys on whether a site's address has a path of
   its own, and the lab adds a mapped-domain site to prove it.
3. **The lab's own bugs.** Four red results were runner mistakes, each
   diffed before being fixed: a P14 text comparison missing core's `<p>`, a P23
   baseline taken with Keel inactive (Keel's XML-RPC and REST user defaults
   made it differ), a P9 masking order that changed with Python's hash seed
   (failed about one run in three on 6.4, reproduced on a fresh site), and the
   network helper passing managed keys as a list instead of a map (P26).
4. **Codex review of the runner (2026-11-14, at `6d98065`).** Four findings,
   all fixed: `probes.py` now exits 1 when any probe records FAIL (it exited 0
   before, so a red run looked green to a script); `run.sh` stops only the
   `php -S` servers it started, not every one on ports 88xx; P30 was rebuilt as
   described in its row; and a nested f-string that needs Python 3.12 is gone
   (the runner now parses on 3.9, and the README says 3.9 or later).

5. **Lab server cache (found during the AX port, 2026-11-14).** PHP's built-in
   server runs opcache and re-reads a changed PHP file only every few seconds.
   The AX lab, which swaps plugin builds and edits `wp-config.php` between
   requests, served stale code because of it. This lab is not affected: it
   edits no PHP file between requests, and its one build swap (P2, 0.9.2 to
   this branch) passed only because the new code ran, since 0.9.2 has no
   Login Answers. `env.sh` now turns opcache off anyway.

## Scope decisions

Both limits below were **accepted by Dan on 2026-11-14**, as recommended.

| Probe | What is not run | Decision |
| --- | --- | --- |
| P32 | A real screen reader (VoiceOver, NVDA). The lab reads Chromium's accessibility tree instead, which is what a screen reader is given | Accepted (Dan, 2026-11-14). The setting changes only the text inside core's own `#login_error` element; its role, live-region attributes and place in the tree are unchanged (checked off vs on). A screen reader can only differ if the words do, and the words are checked |
| P23, REST | Core's own application-password error over REST. In this lab core resolves the current user before `REST_REQUEST` is set, so a wrong application password reaches REST as an anonymous request, on and off alike | Accepted (Dan, 2026-11-14). Login Answers adds no hook on REST or `authenticate`; the positive control shows Basic auth is processed, and off and on match |

## Reproduce

Scripts: [`tests/integration/login-answers-lab/`](../../tests/integration/login-answers-lab/README.md).
The final run rebuilt all four sites from scratch with those scripts and ran
every phase; all results above come from that run, except P24, measured on the
first lab at the same commit.

Tested commit: `8e0c0fe` (product code unchanged since `fc6080e`). P28 re-run on the spot-check fix commit, with a mapped-domain site added to `setup.sh`. P26 to P30 (multisite) and P30 (s71) re-run on the Codex-fix commit, both exiting 0.
