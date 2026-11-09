# Lab record: email addresses in public names (#355), 2026-11-09

> **Summary.** All 20 tester probes PASS on MariaDB 10.11 with WordPress 7.1.3
> and PHP 8.3, single site and multisite. The server-side probes also PASS on
> SQLite (WordPress Playground's driver). The WordPress 6.4 / PHP 7.4 floor,
> MySQL 8 and the Redis multisite rows pass the server-side probes in the live
> backport matrix (six rows, WordPress 6.4 to 7.0 branches); the browser probes
> (P1, P13 rendered, P14) did **not** run on those rows. See the coverage matrix.

This is the first Keel change built with a [separate tester](probes/README.md).
Codex (OpenAI GPT-6) wrote the 20 probes in
[probes/355-email-exposure-site-health.md](probes/355-email-exposure-site-health.md)
from the issue, the roadmap rules, the readme and core source, and committed them
in `4fd6cc4` before any code existed. Claude (Anthropic) built the check in
`96c9166` and ran the probes. No tester probe was edited or disputed; the
`Lab probes` CI check confirms the file is unchanged since `4fd6cc4`.

## The change

`includes/identity-exposure.php` adds an async Site Health test, "Email
addresses in public names", with the Security badge.

- **Detection** (case-insensitive). Strong: the display name is an email login
  (rule 1), is the contact email or holds an email-address token (rule 2), or the
  slug is core's derivation of an email login or contact email, including core's
  `-N` collision suffix on the exact 50-character truncation (rule 3). Weak: the
  display name or slug is the email's local part (rule 4), reported separately and
  never enough on its own to turn the result `recommended`.
- **Output.** Counts by role, strong and weak separately. Up to 20 administrators
  and editors with a strong match and a published post in a public type or an
  approved comment, listed by a safe display name or "User #ID" with a profile
  link. A count of any further named accounts. Copy that says which surfaces
  Keel's archive and REST settings cover, which they don't, and that closing a
  route is not a fix.
- **Cost.** One candidate query per site, joined on this site's capabilities key,
  administrators and editors first, capped at 20,000 candidates with a "Partial
  count" note beyond that. Two grouped queries check public activity for the
  exposed administrators and editors. No query runs per user.
- **Cache.** One transient per site, valid while a network-wide generation marker
  is unchanged. `user_register`, `profile_update`, role changes, site membership
  changes and deletions bump the marker, so every site's next request rescans.
- **Access.** The admin-ajax endpoint checks the Site Health nonce and
  `view_site_health_checks`; anyone else gets a bare 403. Core's scheduled check
  gets the status and nothing else.
- **Writes.** None, apart from the transient and the marker.

## Environment

| | |
| --- | --- |
| Source | `96c9166` on `claude/project-thread-d39d95`, symlinked into each lab |
| WordPress | 7.1.3 (git tag) |
| PHP | 8.3.6 CLI, built-in server with a router script |
| Databases | MariaDB 10.11.14 (`mysqli`); SQLite via sqlite-database-integration 3.1.3 (`WP_SQLite_DB`, "8.0.38-mysql-on-sqlite-3.1.3") |
| Topologies | single site; subdirectory multisite, Keel network-activated, a second site created per run |
| Object cache | none in the sandbox; Redis only in the CI multisite row |
| Browser | Playwright Chromium, real `wp-login.php` sign-ins |
| Pre-existing state | all labs created for this pass; the lab admin's own name and slug were set to "Lab Admin" / `lab-admin` so the baseline starts clean |

Lab fixtures, all disposable and named in this record:

- An mu-plugin, active only when `wp-content/keel-lab-on` exists, that prints the
  signed-in user's own `health-check-site-status` nonce, so P14 could use a real,
  valid nonce for each denied role. The same file drops core's network-bound
  async Site Health tests, because the sandbox blocks outbound HTTP and they
  stall the queue for minutes. Keel's tests are untouched.
- For P17, an mu-plugin that logs which request ran the candidate query.
- Fixture users with generated passwords held in a 0600 file outside the web
  root, deleted at teardown. Addresses are synthetic, under `.test`.

## Results

Every tester probe, with who wrote it. Server-side probes ran through
`tests/integration/identity-exposure.php` (`wp eval-file`), which creates real
users through `wp_insert_user()` so core derives names, slugs and collision
suffixes itself, compares each case with the site's own baseline, and deletes its
fixtures before the next.

| ID | Author | Result | Evidence |
| --- | --- | --- | --- |
| P1 | tester | PASS | Browser, single and multisite, clean fixtures: the Site Health page HTML carries no result for this test; one separate admin-ajax request returns it; Security badge, `good`, no findings or named accounts, cold and warm. Server: an email login with a chosen byline and slug plus the default nickname, and a handle login, add nothing. |
| P2 | tester | PASS | `Studio @ Night` and `Crew ops@local` add zero strong and zero weak. |
| P3 | tester | PASS | Login `Case.Login…@fixture.test`, lowercase display name, different contact email, safe slug: one strong, counted under Author, `recommended`. |
| P4 | tester | PASS | Handle login, name = contact email: one strong, `recommended`, address absent from the JSON result. |
| P5 | tester | PASS | `Desk outside…@fixture.test Team`: one strong; neither the address nor the byline appears in the result. |
| P6 | tester | PASS | Core slug of a mixed-case email login, and a handle login whose slug core built from its contact email: two strong. |
| P7 | tester | PASS | A reserver took the would-be slug; core assigned `…-2` to the exposed user (asserted): one strong. `field-notes-…-2` and the reserver add nothing. |
| P8 | tester | PASS | `long.`×8 + `writer@fixture.test` (59 characters, accepted by core): truncated base slug strong. With nine reserved slugs core emitted the 47-character stem + `-10` (asserted): strong; the nine reservers clean. |
| P9 | tester | PASS | Name = local part in other case; slug = sanitised local part; WooCommerce-style handle from the local part on a different domain (role `customer`): each one weak, zero strong, `good`. |
| P10 | tester | PASS | Exposed Administrator and Editor, a weak-only Author, and an Author matching both a strong and a weak rule: three strong (one per role), one weak, `recommended`. |
| P11 | tester | PASS | Named: the Administrator with a published post and the Editor with an approved comment, with a `user-edit.php?user_id=` link to the right ID. Not named: a draft-only Administrator, a pending-comment-only Editor, an Author with a public post. |
| P12 | tester | PASS | Archive and REST settings forced on, then off: `recommended` both times; copy names bylines, stored comment names and comment classes as still visible; "on" says closed routes don't make the fields safe; "off" says the routes are open. |
| P13 | tester | PASS | Browser, single and multisite, cold and warm: canary addresses in login, contact email, a full-address byline and an embedded-address byline never appear in the raw ajax body, headers, rendered HTML or text, in plain, HTML-entity, JSON-escaped or URL-encoded form. The canary Administrator is listed as "User #ID". Denied roles' responses checked too. Server: nothing in the stored transient. 100k run: no address-shaped string in the result. |
| P14 | tester | PASS | Editor, Author, Contributor, Subscriber (and on multisite a site Administrator, who lacks the capability there), each signed in with real cookies and its own valid nonce: Site Health screen 403, ajax 403 `{"success":false,"data":[]}`, with the cache empty and populated. Anonymous: 400 `0`. |
| P15 | tester | PASS | Multisite: strong Administrator with a post on A only, weak member on B only, a shared safe member, a member of neither. A: +1 strong, B's weak not counted. B: +1 weak over its own baseline, no strong, no named accounts. A served warm (same timestamp) after B. Each cache carries its own site ID. |
| P16 | tester | PASS | 100,000 users, 9,099 strong (50 Administrators, 129 Editors, 8,920 Subscribers), 900 weak, 60 named-eligible: report shows exactly those counts, 20 named rows and "40 more". With the old 5,000-candidate cap the count was marked "Partial count"; the cap is now 20,000. |
| P17 | tester | PASS | 100k users: Site Health page load ran no scan (logged); the async request ran the candidate query once. Cold: 3 exposure queries (candidate + posts + comments), 0.75 to 0.90 s over four runs, peak memory 62 MB in WP-CLI. Warm: 0 queries, ~1 ms, same report. Plan: range on `usermeta.meta_key` (~50k rows estimated), `eq_ref` on `users.PRIMARY`, filesort for the role ordering. |
| P18 | tester | PASS | Warm a report, register an exposed user: the generation changed and the next request reported +1 strong before the transient expired. |
| P19 | tester | PASS | Multisite: a member of A and B changed to an exposed name through `wp_update_user()`: both sites +1 on their next request; restored: both back to baseline. |
| P20 | tester | PASS | Snapshot of the user row, nickname, post, comments (including a stored `comment_author` address) and Keel settings identical before and after a cold and a warm check. |
| I1 | implementer | PASS | `tests/identity-exposure.php`: 33 pure detection checks (rules 1-4, mixed case, collision suffix on the right stem only, `-1` never core output, truncation, plus sign, safe labels, role priority). |
| I2 | implementer | PASS | Every server-side probe above also passed on SQLite (single site). |
| I3 | implementer | PASS | Mutation checks: with the invalidation hooks removed, P3 fails on a stale cache; with the contact-email slug rule removed, P6 and the unit test fail. |

**Tester probes: 20 PASS, 0 FAIL, 0 DISPUTED, 0 NOT RUN** on the lab's cells. On
the CI floor rows the browser probes are NOT RUN.

One finding came out of the probes: at 100,000 users the first version's
5,000-candidate cap undercounted (5,000 of 9,099 strong) and read candidates in
ID order, so on a larger table it could have missed every administrator. It now
reads administrators and editors first and caps at 20,000.

## Coverage matrix

Rows come from the declared support surface: `Requires at least: 6.4`, `Requires
PHP: 7.4`, tested to 7.1, single site and multisite, MySQL/MariaDB and SQLite
(Playground and Studio).

| Cell | Result | Evidence or reason |
| --- | --- | --- |
| MariaDB 10.11, WP 7.1.3, PHP 8.3, single | PASS | all 20 probes, this pass |
| MariaDB 10.11, WP 7.1.3, PHP 8.3, multisite (network-activated) | PASS | all 20 probes, this pass |
| SQLite (sqlite-database-integration 3.1.3), WP 7.1.3, PHP 8.3, single | PASS | server-side probes; browser probes NOT RUN on SQLite |
| MySQL 8, WP 6.4 branch, PHP 7.4, single (floor) | PASS (server side) | [run 42570619640](https://github.com/dknauss/Keel/actions/runs/42570619640), row `6.4-floor`; browser probes NOT RUN |
| MySQL 8, WP 6.9 branch, PHP 8.2, multisite + Redis | PASS (server side, incl. P15, P19) | [run 42570619640](https://github.com/dknauss/Keel/actions/runs/42570619640), row `6.9-multisite` |
| MySQL 8, WP 6.8 (fr_FR) / 6.9 / 7.0 branches, PHP 8.2, single | PASS (server side) | [run 42570619640](https://github.com/dknauss/Keel/actions/runs/42570619640), rows `6.8-fr`, `6.9-operable-minor`, `6.9-operable-majors`, `7.0-line` |
| 100k users | PASS on MariaDB only | not run on SQLite or MySQL 8 |
| SQLite, multisite | NOT RUN | not attempted in this pass |

## Not checked

- Browser probes on the WordPress 6.4 floor and on SQLite.
- Real email delivery is irrelevant here; nothing sends mail.
- Theme bylines and comment output were not changed or checked: this tier
  reports and does not filter them. Only the Site Health copy describes them.
- The P17 Site Health page itself took about 12 s to load on the 100k site in
  the single-threaded PHP server, with this test's request blocked. The scan log
  shows the check did not run during that load; the time is core's and the
  server's, and was not investigated further.

## Cleanup

Each probe deleted its own users, posts and comments, and the multisite run
deleted the site it created. The browser runs deleted their users, canaries and
post, the 0600 credentials file and the transient. The three lab sites, their
databases and the mu-plugins live only in the sandbox scratch directory and go
with it.
