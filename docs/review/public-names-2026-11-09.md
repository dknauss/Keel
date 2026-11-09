# Lab record: Public Names, the Enforced tier for #355, 2026-11-09

> **Summary.** 23 of 24 tester probes PASS on MariaDB 10.11 with WordPress 7.1.3
> and PHP 8.3, single site and multisite. P3 PASSES on single site and is NOT RUN
> on multisite, because core's multisite signup refuses email-shaped usernames
> before Keel is involved. P21 and P22 were marked **spec gap** by the tester; they
> PASS against the rules Dan confirmed on 2026-11-09. The server-side probes also PASS on SQLite. The CI floor rows run the
> server-side probes only. See the coverage matrix.

Codex (OpenAI GPT-6) wrote the 24 probes in
[probes/355-email-exposure-enforced.md](probes/355-email-exposure-enforced.md)
from the issue, the roadmap rules, the readme and core source, and committed them
in `d7deb83` before any code existed. Claude (Anthropic) built the setting and the
cleanup and ran the probes. No tester probe was edited or disputed; the `Lab
probes` CI check confirms the file is unchanged since `d7deb83`.

## The change

`includes/public-names.php` adds an opt-in setting, **Public Names** ("Keep logins
and email addresses out of public names"), off by default, and a one-time cleanup
under Users → Public Names.

- **Rules** (case-insensitive). A display name or nickname is unsafe if it equals
  the login, the contact email, or the local part of either, or holds an
  email-address token. A slug is unsafe if it equals core's slug form of any of
  those. A literal at-sign that is not part of an address is fine.
- **On write.** `wp_pre_insert_user_data` and `insert_user_meta` (priority 20)
  replace only the unsafe fields, on every path that goes through
  `wp_insert_user()`: registration, Add New, profile saves, REST, WP-CLI and
  plugin provisioning. The display name falls back to the nickname, then core's
  own "first last" format, then first, then last, then the previously stored safe
  name, then a neutral handle, "Member" plus six hex characters. The slug follows
  the safe byline, truncated to 50 characters and made unique with core's `-N`
  loop, or becomes a 10-character hex token. A safe slug already stored is kept
  when none is submitted. Logins, email addresses and passwords never change.
- **Existing accounts** change only when an administrator runs the cleanup. It
  works in batches of 500 members with a 4-second budget per request, keeps its
  progress in one option (a site option on multisite), and writes a pending
  marker before each user write so a crash is resolved on the next request with
  exact counts. Past comments are rewritten only when the administrator ticks the
  box (unticked by default), and only for that account's comments whose stored
  name is exactly the old name, compared in PHP because the database collation
  may fold case. The comment cache is cleaned afterwards.
- **No redirects.** An old author slug answers like any unknown author. A
  redirect from old to new would confirm which address belongs to which byline.
- **Access.** The cleanup needs a valid `keel_public_names_run` nonce plus
  `edit_users` and `manage_options` on a single site, or `manage_network_users`
  on multisite, where it lives in Network Admin and site administrators cannot
  run it. Refusals are a bare 403 (400 for anonymous ajax), with nothing written
  or queued.
- **Multisite.** User records are shared, so one rule decides for every site:
  network policy if set, otherwise the main site's stored value. Other sites show
  a lock note on the setting. The cleanup targets one chosen site's members and
  that site's comments; the chosen site is shown before submission.
- **Output.** Counts only. No screen, response or log names an account or an
  address.

## Environment

| | |
| --- | --- |
| Source | working tree on `claude/project-thread-d39d95` over `d7deb83`, symlinked into each lab |
| WordPress | 7.1.3 |
| PHP | 8.3.6 CLI, built-in server with a router script |
| Databases | MariaDB 10.11.14 (`mysqli`); SQLite via sqlite-database-integration 3.1.3 |
| Topologies | single site; subdirectory multisite, Keel network-activated, a second site `/b/` |
| Object cache | none in the sandbox; Redis only in the CI multisite row |
| Browser | Playwright Chromium, real `wp-login.php` sign-ins |

Lab fixtures, all disposable:

- The mu-plugin from the #360 pass, extended so a signed-in user can fetch its own
  `keel_public_names_run` nonce. P16 used each denied role's own valid nonce.
- Fixture users with generated passwords in a 0600 file outside the web root,
  deleted at teardown. Addresses are synthetic, under `.test`.
- A 100,000-user table loaded by SQL for P23 (manifest: every ID where
  `ID % 10 == 3`, plus the last 60 rows, is exposed: 10,053 eligible).

## Results

Server-side probes ran through `tests/integration/public-names.php` (`wp
eval-file`), which creates real users through core's own APIs, reads every value
back in a fresh query and deletes its fixtures before the next case. HTTP and
browser probes ran from a lab script against the running sites.

| ID | Author | Result | Evidence |
| --- | --- | --- | --- |
| P1 | tester | PASS | Default is `no` in the schema and the effective policy. Server: with the setting off, direct insert, update and registration store exactly what core stores. HTTP: REST create and update, wp-admin Add New, an admin edit choosing the email login as display name and the address as nickname, and WP-CLI create and update were each run twice, once with Keel inactive and once active with the setting off. All six rows match after normalising the run token (REST update matched apart from the token's case). |
| P2 | tester | PASS | Exposed accounts and comments seeded before activation; snapshots identical after activation (real activation hook via `KEEL_DEFAULTS_FILE`), after enabling and saving the setting, after rendering the cleanup screen and after running due cron. No run state stored. |
| P3 | tester | PASS (single) / NOT RUN (multisite) | Single: `register_new_user()` and the real `wp-login.php?action=register` form with an email-shaped username succeed; login and email stored as submitted; name and nickname are a "Member" handle, slug a hex token. Multisite: core's `wpmu_validate_user_signup()` rejects email-shaped usernames, so the path does not exist there. |
| P4 | tester | PASS | Email-login account created with safe first and last names and no slug: the full byline is kept, slug and nickname are safe and carry no address. The same path through the real wp-admin Add New form ran in the P1 off/on comparison. |
| P5 | tester | PASS | Own profile and another user's admin edit in separate sessions, submitting the address as nickname and the login as display name: each save succeeds, both fields safe, the safe slug identical. The author archive title shows the safe byline. |
| P6 | tester | PASS | `wp_insert_user()`, its existing-ID path and a partial `wp_update_user()` changing only the first name: all succeed; the stale unsafe nickname is fixed though not submitted; the first name persists; the stored safe slug survives. |
| P7 | tester | PASS | REST create 201 and update 200 with a case-varied address inside a name, an address nickname and a contact-derived slug: response `name`, `slug` and `nickname` equal the stored safe values; edit-context `email` keeps core behaviour; anonymous read of the user carries no address. |
| P8 | tester | PASS | `wp user create` with an email login, then `wp user update` with an unsafe name and slug `chosen-cli-…`: both exit 0 on the same ID; the name is replaced; the chosen slug is stored exactly. |
| P9 | tester | PASS | WooCommerce-style handle from the local part, mixed-case name, slug with a real `-2` collision from a reserver: login and email unchanged; name, nickname and the collided slug replaced. |
| P10 | tester | PASS | Pen name, chosen nickname and hex slug survive an unrelated edit. In three mixed fixtures only the one unsafe field changes. "Studio @ Night" is kept. |
| P11 | tester | PASS | Five fixtures: a safe nickname wins over the full name; with no safe nickname the full name wins; the last name alone when the first name holds an address token; the first name alone when there is no last name; a nickname that matches the login except for case never wins. |
| P12 | tester | PASS | Exhausted candidates give "Member" plus six hex characters, not derived from login, email or ID; three accounts get three different handles. A byline whose slug equals the login's gives a 10-character lowercase hex slug, unchanged after a later unrelated save. |
| P13 | tester | PASS | 59-character email login with core's truncated slug, and a core-shortened `-N` variant: both repaired; a reserved byline slug forces `-2`; a 60-character byline slug is cut to 50; all slugs unique, at most 50, safe; reservers and the safe numeric slug `field-notes-…-2` unchanged. |
| P14 | tester | PASS | Archives open: old, unknown and new slugs return 200 with no redirect; the old slug's page matches the unknown one; the new one serves the archive with the post. Archive protection on: all three 301 to the home page. |
| P15 | tester | PASS | HTTP run from the screen's own script: strong, local-part-only and nickname-only exposures fixed; safe account, IDs, roles and post authors identical; the #360 Site Health test then reports `good`. |
| P16 | tester | PASS | Editor, Author, Contributor, Subscriber (and on multisite a site Administrator), each with its own valid nonce, comments variant included: admin-post 403, ajax 403, screen 403. Anonymous: 400 / login redirect. No run state, no cron event, user and comment digests identical. |
| P17 | tester | PASS | Administrator (single) and Super Admin (multisite) with a missing, wrong and other-action nonce: 403 each; loading the screen stores nothing; digests identical; no pending work. |
| P18 | tester | PASS | The checkbox renders unchecked; a run without it fixes the accounts and leaves every comment's stored name unchanged. |
| P19 | tester | PASS | With the box ticked: only the same account's comment with the exact old name changes; the relabelled, case-only, guest and other-account comments and every other column stay identical; with the cache warmed, a fresh read shows the new name. |
| P20 | tester | PASS | Canary addresses in login, email, name, nickname and a comment: none in about 590 responses (settings, screen, start, continue, done, no-op rerun, every denial, resume) in plain, decoded, entity, JSON-escaped or URL-encoded form, nor in headers, redirect targets or the PHP log. Core's own `users/me?context=edit` request was excluded as not feature output. |
| P21 | tester | PASS (rule confirmed by Dan) | Spec gap. Built so the main site (or network policy) decides for every site. Main site on, B off: a save on B is repaired. Main off, B's own value on: a save on B follows core. B shows the lock note explaining the shared-account effect. Toggling any site's setting writes no user rows. Dan confirmed this rule on 2026-11-09. |
| P22 | tester | PASS (rule confirmed by Dan) | Spec gap. Super Admin targets site A, comments ticked: the shared member is repaired and shows the new name on both sites; the B-only exposed member is untouched; no comment on B changes. The site selector and the "running on" note name A before submission. Dan confirmed this rule on 2026-11-09. |
| P23 | tester | PASS (MariaDB) | 100,000 users, 10,053 eligible including the last 60. 200 requests, longest 1,282 ms, median ~729 ms, peak 61.3 MB; 500 rows fetched per batch. Killed after 20 writes: the next request resolved the pending write; final counts exactly 10,053 changed of 100,001 scanned. Census: 0 unsafe names, 0 unsafe nicknames, 0 duplicate slugs, 0 safe rows changed. |
| P24 | tester | PASS | Rerun with the same choice: 0 identity and 0 comment changes. Login, email and password hash identical; an email login and a handle login with an email contact both sign in with their old password. |
| I1 | implementer | PASS | `tests/public-names.php`: 33 pure checks of the rules, fallbacks and slug truncation. |
| I2 | implementer | PASS | Every server-side case above also passed on SQLite (single site). |
| I3 | implementer | PASS | Mutation checks: without the nickname filter P3 fails; matching comments case-blind fails P19; letting site administrators run it on multisite fails P16. |

**Tester probes: 23 PASS (P21 and P22 on the rules Dan confirmed), 0 FAIL, 0
DISPUTED, 1 NOT RUN (P3 on multisite, path absent in core)** on the lab's cells.

Three findings came out of the probes and were fixed before this record:

1. **Stale nickname (P5).** `insert_user_meta` receives a `WP_User` that can
   predate the display-name change, so the nickname got its own, different handle.
   The two hooks now share the choice made for that request.
2. **New handle on every update (P7).** An update with an unsafe name minted a
   fresh random handle each time. The previously stored safe name is now tried
   before a new handle.
3. **Undercount after a crash (P23).** A kill between the user write and the
   progress save lost that write from the count. A pending marker written before
   each change now lets the next request settle it.

## Coverage matrix

| Cell | Result | Evidence or reason |
| --- | --- | --- |
| MariaDB 10.11, WP 7.1.3, PHP 8.3, single | PASS | all 24 probes, this pass |
| MariaDB 10.11, WP 7.1.3, PHP 8.3, multisite (network-activated) | PASS | 23 probes; P3 NOT RUN (core refuses the path) |
| SQLite 3.1.3 driver, WP 7.1.3, PHP 8.3, single | PASS (server side) | `public-names.php` 13 cases; HTTP and browser probes NOT RUN |
| MySQL 8, WP 6.4 branch, PHP 7.4, single (floor) | pending | the backport matrix runs `public-names.php`; first run after merge |
| MySQL 8, WP 6.9 branch, PHP 8.2, multisite + Redis | pending | as above, includes P21 and P22 server side |
| MySQL 8, WP 6.8 (fr_FR) / 6.9 / 7.0 branches, PHP 8.2, single | pending | as above |
| 100k users | PASS on MariaDB only | not run on SQLite or MySQL 8 |
| SQLite, multisite | NOT RUN | not attempted |

## Decisions

- **P21. Decided by Dan, 2026-11-09:** on multisite, network policy wins, otherwise the
  main site's setting decides for every site. The tester's text allowed a per-site
  rule ("a save in the enabled site's context may repair the shared identity").
  Per-site means one shared account can be repaired from one site and then
  re-exposed by a save on another, which is why it was not built that way.
- **P22. Decided by Dan, 2026-11-09:** a site-targeted run repairs that site's
  members only and rewrites that site's comments only.

Both rules are now decided; the code was built on them, so nothing changed.

## Not checked

- HTTP and browser probes on the WordPress 6.4 floor and on SQLite.
- The rendered byline for P5 was read from the author archive title, not from a
  theme's post byline.
- Real email: nothing in this feature sends mail.

## Cleanup

Each integration case deleted its own users, posts and comments; the multisite run
kept only site `/b/`, which the lab created. The browser runs deleted their users
and the 0600 credentials files are removed with the sandbox. The 100k table, the
lab sites and their databases live only in the sandbox scratch directory.
