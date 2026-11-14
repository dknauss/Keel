# Lab record: Login Answers (#378), 2026-11-14

> **Summary.** 31 of 33 tester probes PASS on every cell they were run in:
> WordPress 7.1.3 and 6.4.7 on MariaDB 10.11, 7.1.3 on SQLite, and a 7.1.3
> subdirectory multisite on MariaDB, all on PHP 8.3. P4 is **DISPUTED** (it
> assumes a `wp-config.php` constant route this setting doesn't have). P23 and
> P22 PASS with parts **NOT RUN** (REST Basic auth, profile screens, expired
> reset key). P24 measured a large timing gap on the login form (about 18 ms for
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
| Multisite, subdomain | — | NOT RUN: `php -S` lab can't serve wildcard hosts. P28's subdomain behaviour is from core source (`wp_lostpassword_url()`), not observed |
| MySQL 8 | — | NOT RUN here; CI matrix rows run unit tests only for this change |
| PHP 7.4 floor | — | NOT RUN: only PHP 8.3 in the sandbox. `phpcompat.xml` passes |
| Persistent object cache | — | NOT RUN; the change reads one autoloaded option through Keel's normal path |
| Third-party login form | WooCommerce 11.3.0 My Account on `s71` | PASS (P25: unchanged, as decided) |
| Upgrade path | Keel 0.9.2 (`v0.9.2` worktree) → this branch on `s64`, no save | PASS (P2) |
| Browser / screen reader | — | NOT RUN: P32 checked markup and focus target only |

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
| P4 | **DISPUTED** | — | Keel has no `wp-config.php` constant for this setting. Its locks are core constants that supersede a setting (`DISALLOW_UNFILTERED_HTML`, `WP_AUTO_UPDATE_CORE`…), and no core constant governs login errors. Adding one only to satisfy the probe would be new scope. The forcing route that exists, network policy, is P26 (PASS). **Dan to decide.** |
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
| P18 | PASS | s71, s64, sq | `wp_mail()` failing: one send attempted, same 302, no "could not be sent" |
| P19 | PASS | s71, s64, sq | `allow_password_reset` false for `noreset`: same 302, 0 mails |
| P20 | PASS | s71, s64, sq | `redirect_to=/thanks/` → `/thanks/` for all four; `https://evil.example/` → `http://<site>/wp-admin/` for all four |
| P21 | PASS | s71, s64, sq | Empty field: core's "Please enter a username or email address." |
| P22 | PASS, part NOT RUN | s71, s64, sq | `action=rp` with a bad key for a real and an unknown login: both 302 to `/wp-login.php?action=rp`, identical to baseline. The follow-up GET with the rp cookie and the expired-key case were **NOT RUN**; Keel adds no hook on `action=rp` |
| P23 | PASS, part NOT RUN | s71, s64, sq | Baseline: Keel on with this setting off (Keel's own XML-RPC defaults would otherwise differ). Registration "already registered" and XML-RPC `wp.getUsersBlogs` ("Incorrect username or password." for both) unchanged. **REST Basic auth NOT RUN**: core ignored the Basic header under `php -S` even with Keel inactive, so the comparison was vacuous. **Profile/user-edit NOT RUN**; no hook there |
| P24 | PASS (measured) | s71, sq | 200 requests each, median ms (stdev): login unknown 17.5 (3.4) vs real wrong 89.3 (6.5) on MariaDB; 20.0 (3.0) vs 89.0 (4.7) on SQLite. Lost password unknown 13.4 (2.2) vs real 22.6 (3.6) on MariaDB; 16.1 (2.7) vs 23.2 (2.6) on SQLite. Readme, settings detail and Site Health say response time is not covered |
| P25 | PASS | s71 | WooCommerce My Account, Keel inactive vs on: identical notices, still core's three messages; readme and detail name WooCommerce as not covered |
| P26 | PASS | ms | Network policy `yes` with `site2`'s own setting `no`: `site2` login and lost-password uniform; control `aria-disabled="true" data-keel-locked="1"` with the lock note |
| P27 | PASS | ms | No network policy, main `yes`, `site2` `no`: main uniform, `site2` core's messages |
| P28 | PASS (premise corrected) | ms | On a subdirectory network `site2`'s link is `http://ms.test/site2/wp-login.php?action=lostpassword`, its own form, so `site2`'s setting governs both its login and lost-password answers. The probe's "core usually links to the main site" holds only on subdomain networks; docs corrected in `8e0c0fe` |
| P29 | PASS | ms | On `site2`: network-only user, spammed user, pending signup with wrong password identical to unknown |
| P30 | PASS | s71, ms | Editor POST to `options.php`: 403, option unchanged. `site2` admin POST to network policy: 403, network option unchanged |
| P31 | PASS | s71 | Site Health `good` on, `recommended` off; both, the Help "Settings in detail" text and readme FAQ name all eight not-covered items |
| P32 | PASS (markup only) | s71, s64, sq | Message is text in `#login_error` (`notice-error`); focus target `user_pass` for every input. No screen reader run |
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
   `8e0c0fe`.
3. **The lab's own bugs.** Four red results were runner mistakes, each
   diffed before being fixed: a P14 text comparison missing core's `<p>`, a P23
   baseline taken with Keel inactive (Keel's XML-RPC and REST user defaults
   made it differ), a P9 masking order that changed with Python's hash seed
   (failed about one run in three on 6.4, reproduced on a fresh site), and the
   network helper passing managed keys as a list instead of a map (P26).

## Reproduce

Scripts: [`tests/integration/login-answers-lab/`](../../tests/integration/login-answers-lab/README.md).
The final run rebuilt all four sites from scratch with those scripts and ran
every phase; all results above come from that run, except P24, measured on the
first lab at the same commit.

Tested commit: `8e0c0fe` (product code unchanged since `fc6080e`).
