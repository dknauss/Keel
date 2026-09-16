# How other plugins do the teardowns Keel does

A measured comparison of the most-installed wordpress.org plugins that overlap with
Keel's defaults, across three surfaces that are easy to get wrong: **the REST
API**, **the comment teardown**, and **the core update policy**.

Nothing here is taken from readmes or marketing copy. Every cell in the matrix is a
live HTTP or PHP probe against a real install with that plugin active and configured
the way its own settings screen would configure it.

The first two surfaces are a comparison of teardowns: what does the site still
serve once the plugin has switched something off. The third is a different
question asked of a different field — given the plugin's own configuration, which
core release would this site actually install, and does any screen the plugin owns
say anything true about the release it is running? A plugin can get the first half
exactly right and still leave an administrator with no way to learn that the
version underneath it has publicly known vulnerabilities. Those are separate
features and the matrix scores them separately.
---

## Method

- **Lab:** a throwaway WordPress 7.0.2 install (SQLite, PHP 8.5, `php -S`), block
  theme, one seeded comment on post 1, pretty permalinks, `ping_status=open`.
  Keel was additionally re-measured on a **classic** theme (Twenty Twenty-One) —
  see [The classic-theme run](#the-classic-theme-run) — and on **WordPress 6.4**,
  the floor it claims — see [The older-WordPress run](#the-older-wordpress-run).
  The other plugins have not been; their two rendered-markup rows are block-theme
  figures measured on 7.0.2.
  **Re-checked on 2026-08-23**, on WordPress 7.1: the server-side comment-read
  row below (§3) was re-run against Disable Comments 2.8.0 in its "everywhere"
  mode, with a comment written straight to the table by `$wpdb` so no plugin
  could intercept the write. Keel returned nothing from `get_comments()`; Disable
  Comments returned the comment while reporting a count of zero.

  Nothing else in this document has been re-measured since. The Keel row is
  relabelled to the current version rather than re-run, and this note is what
  that relabelling rests on: 0.4.0, 0.4.1, 0.5.0, 0.5.1 and 0.5.2 changed conflict reporting,
  the text domain and the AI-connectors gate, and 0.5.3 changed how Keel's own CSS
  and JavaScript reach the page and what ships in the zip. None of those is a
  teardown tabulated here — every row is a server-side probe, and none of them
  reads a stylesheet. A release that changes one has to re-run the probes rather
  than edit the label.

  **0.6.1 is such a release, and its row is relabelled anyway — read the Keel
  comment and REST cells as stale.** It fixed two behaviours this document
  probes: `GET /wp/v2/comments/123` answered on a site with comments disabled,
  because the filter covering every comment listing does not cover the
  single-item route; and `/?author=N` still disclosed the author nicename while
  attachment pages still rendered, both having lost a priority race against
  core's own `redirect_canonical`. The label moved because the version guard in
  `tests/docs-consistency.php` requires it and a stale label is its own defect,
  but the probes have not been re-run. Both changes can only move Keel's cells
  in its own favour, so nothing here overstates Keel today — it understates it.
  Re-run before the row is cited for anything.

  **0.6.2 is not such a release, and the relabel is routine.** It changes the
  patch-status panel's wording, the admin notice, and one branch of the installer's
  return handling. Nothing it touches is probed here: every row is a server-side
  probe of comments, REST, XML-RPC or redirects, and none of them reads the Site
  Health copy. The 0.6.1 caveat above still stands and is not cleared by this.

  **0.6.3 is not such a release either.** It adds the same-line patch offer to
  `update-core.php`, fixes where an install returns and what it reports there, and
  rewords the ladder. All of it is wp-admin rendering for a logged-in administrator;
  every row here is an unauthenticated server-side probe. The 0.6.1 caveat still
  stands.

  **Nor is 0.6.4.** It adds a 160px stop to the admin menu width slider, relabels
  the stop that stands down, and changes the conflict notice's wording and the
  Site Health status that notice links to. Menu width is admin CSS and the rest is
  wp-admin copy read by a logged-in administrator; no row here probes any of it.
  The 0.6.1 caveat still stands.

  **Nor is 0.6.5.** It stops Keel's own saved menu-width rule from overriding
  Keel's own live preview on the settings screen, and adds an FAQ paragraph. Both
  are admin-side: one is CSS precedence on a wp-admin screen a logged-in
  administrator is looking at, the other is prose. No row here probes either. The
  0.6.1 caveat still stands.

  Deliberately *not* the Studio site — an always-on managed plugin there was
  filtering `pings_open`, stripping XML-RPC methods and answering comment queries
  empty, which silently contaminated the first run.
- **One plugin active at a time**, activated, configured, probed, deactivated.
  Configuration was replicated from each plugin's own save handler, not guessed —
  for Disable Comments RB that mattered: its "everywhere" radio also writes a
  snapshot of every comment-supporting post type, and probing without it produced a
  false "does nothing" result.
- **~30 probes per plugin** across five categories: anonymous REST reads (pretty
  route, `?rest_route=`, `_embed`), REST collateral (index, users, posts, oEmbed,
  discovery link), comment feeds, `X-Pingback`, XML-RPC (`system.listMethods` plus
  *direct* calls to `pingback.ping` and `wp.getUsersBlogs`, which bypass the method
  list), comment write paths (`wp-comments-post.php` and `POST /wp/v2/comments`,
  with a direct `$wpdb` check afterwards so no filter can hide whether a row
  actually landed), server-side reads (`get_comments`, `wp_count_comments`,
  `comments_open`, post-type support, `get_default_comment_status`), rendered
  front-end HTML, and **cookie+nonce authenticated admin requests** — the last one
  is what catches plugins that break the block editor.

### The update-policy lab

A second lab, because the first one cannot ask this question. Every row above is
about what a site serves to an anonymous request; every row below is about which
core release a site would install and what it says about the one it is running,
and neither has an answer on an install that is already on the newest release.

Measured **2026-09-16**.

- **Lab:** a throwaway WordPress **6.9.5** install (SQLite, PHP 8.5, `php -S`),
  separate from the 7.0.2 one above and built for this. 6.9.5 was chosen because
  it makes all five questions answerable at once. On the day of the run
  WordPress.org's stable-check map classified it **`insecure`**; the highest
  release on its own 6.9 line that is not so classified is **6.9.7**; and the
  release WordPress.org calls latest is **7.1**. So the site is running a version
  with publicly known vulnerabilities, there is a same-line patch for it, and that
  patch is not the newest release — which is the only arrangement in which
  "the nearest fix" and "what WordPress will install" can be told apart.
- **What core offers it.** `wp_version_check()` returns four offers: `upgrade 7.1`,
  `autoupdate 7.1`, `autoupdate 7.0.4`, and `autoupdate 6.9.7` (with
  `partial_version 6.9.5`). `get_core_updates()` discards every offer whose
  response is `autoupdate`, so `update-core.php` lists exactly one release — 7.1.
  **6.9.7, the patch for the line this site is on, is not on that screen at all.**
  That is core's behaviour, not any plugin's, and it is the thing the reporting
  rows below are about.
- **One plugin active at a time**, configured, probed, deactivated, with the
  state core keeps outside the plugin reset between every run. That reset is not
  hygiene theatre: WP Auto Updater writes `auto_update_core_major = 'disable'` as
  a **site option** on activation and never puts it back, so without the reset it
  re-points the "which release would install" row for every plugin measured after
  it. The update-check transient is refetched each time too, and every cached
  transient is dropped, because a plugin that asks api.wordpress.org once a day
  asks nothing at all on the second run of the morning and would score as never
  asking.
- **Configuration is each plugin's own nearest equivalent of Keel's
  `core_update_policy = minor`** — install maintenance and security releases
  automatically, do not install a major. The files are in
  `tests/integration/probe-configs-updates/`, one per plugin, each saying where it
  got its keys. Comparing a plugin configured to "disable everything" against one
  configured to "minor only" measures the configuration rather than the plugin;
  where a plugin's shipped default is something else, the default is a row in the
  matrix rather than something quietly configured away.
- **Probes** are run by `tests/integration/probe-updates.sh` and come in three
  kinds:
  - **Ground truth**, asked of core rather than of the plugin: which release would
    actually install, which the Updates screen lists, and what the four policy
    filters answer. `find_core_auto_update()` is not the answer to the first —
    it returns the newest auto-update offer without applying the policy at all,
    and says 7.1 on this lab whatever any plugin has decided. The decision is
    `WP_Automatic_Updater::should_update()`, evaluated per offer, and the release
    that lands is the **highest** offer that passes rather than the nearest.
  - **Authenticated renders** of every admin screen the plugin adds — discovered
    by diffing the admin menu against a no-plugins baseline, not by being told
    where to look — plus `update-core.php`, both Site Health tabs and
    `options-general.php`. Counts from a plugin's own screens and counts from the
    shared core screens are reported separately and never added together.
  - **Outbound requests**, logged by an mu-plugin on `http_api_debug`. Whether a
    plugin asks `api.wordpress.org/core/stable-check/1.0/` is the only objective
    way to separate "reports that this version has known vulnerabilities" from
    "reports that an update exists": that endpoint is the only public source for
    the first, and rendered text can use the word "insecure" about anything.

**Every number below reproduces.** The full pass was re-run from the committed
harness and the committed configs after the write-up was finished, and the output
is byte-identical to the table in the appendix. That is a lower bar than it
sounds — it says the harness is deterministic and the configs do not depend on
state left by a previous run, which is exactly what went wrong the last time this
document's numbers were re-verified — but it is a bar the first draft of this
harness did not clear.

Four things about this lab are worth knowing before reading a number off it.

**`DISABLE_WP_CRON` is defined in its `wp-config.php`.** A throwaway install
happily updates itself overnight and does not ask — the 6.4 lab built for the
older-WordPress run above was 7.0.3 by the next morning. The usual guard,
`AUTOMATIC_UPDATER_DISABLED`, could not be used here: it is one of the two
constants these rows are about, and defining it would have made the
constant-override question unaskable. Disabling cron stops the background updater
without touching update policy. It is visible to plugins, and one of them reports
it: Easy Updates Manager's constants notice names `DISABLE_WP_CRON` on every run.

**Easy Updates Manager's settings screen renders in JavaScript.** 9.0.22 ships
`<div class="eum-dashboard-app"></div>` and builds the rest client-side, so an
HTTP probe reads an empty container. Its reporting rows below were confirmed
separately in a real browser, logged in, against the same install — the rendered
screen offers "Manually update / Disable core updates / Auto update all minor
versions / Auto update all releases", and names no WordPress version anywhere. It
is the only plugin in this field that needs that; the other eight render
server-side and their dumps show it.

**SQLite is not MySQL for one plugin here.** Companion Auto Update keeps its
settings in a table of its own rather than in options, and its deactivation hook
runs `DROP TABLE` on it — so its settings and its entire recorded update history
are destroyed by a deactivation, and there is nothing for a pre-activation config
file to write to. It is configured after activation instead
(`companion-auto-update.post.php`). That is a real property of the plugin, not a
lab artefact; what *was* a lab artefact was the first attempt to detect the
table, which asked `information_schema` (MySQL's, and the SQLite dropin answers 0
for every name) and then `sqlite_master` with a double-quoted literal (SQLite's,
and it parses that as an identifier). Both report a table that exists as missing.

**Two of these numbers were wrong before they were right, in ways the control
could not catch.** Counting words across a plugin's screens scored three of
the nine for naming "the release WordPress would install", on screens that say
nothing of the kind: WordPress's own footer reads "Get Version 7.1" on every
admin page and the update nag names it again inside the content area. And the
outbound-request log was originally cleared after the plugin was activated, which
reported Core Rollback — which fetches `core/version-check` in a constructor that
runs on activation — as making no network request at all. Neither shows up in the
stock column, because stock has no plugin screens and activates no plugin. The
harness now scopes each screen to `#wpbody-content` minus `.update-nag`, and
clears the log before activation.

Raw per-probe output is in the appendix.

## The field

### The teardown field

Probed on the 7.0.2 lab.

| Plugin | Active installs | Probed |
|---|---|---|
| [Disable Comments](https://wordpress.org/plugins/disable-comments/) 2.8.0 | 1,000,000+ | live |
| [Admin and Site Enhancements](https://wordpress.org/plugins/admin-site-enhancements/) 8.9.2 | 200,000+ | live |
| [Disable XML-RPC](https://wordpress.org/plugins/disable-xml-rpc/) 1.0.1 | 200,000+ | live |
| [Disable XML-RPC API](https://wordpress.org/plugins/disable-xml-rpc-api/) 2.1.7 | 100,000+ | live |
| [Disable Comments RB](https://wordpress.org/plugins/disable-comments-rb/) 1.0.27 | 100,000+ | live |
| [Disable Everything](https://wordpress.org/plugins/disable-everything/) 0.4.1 | 30,000+ | live |
| [Disable WP REST API](https://wordpress.org/plugins/disable-wp-rest-api/) 2.6.8 | 30,000+ | live |
| [Disable Blog](https://wordpress.org/plugins/disable-blog/) 0.5.5 | 20,000+ | live |
| [Simply Disable Comments](https://wordpress.org/plugins/simply-disable-comments/) 0.3.1 | 6,000+ | live |
| **Keel** 0.6.6 | — | live |
| [Classic Editor](https://wordpress.org/plugins/classic-editor/) 1.7.0 | 9,000,000+ | live |
| [Disable Gutenberg](https://wordpress.org/plugins/disable-gutenberg/) 3.3.2 | 500,000+ | live |
| [Clearfy](https://wordpress.org/plugins/clearfy/) 2.4.3 | 50,000+ | live |
| [WP Master Toolkit](https://wordpress.org/plugins/wpmastertoolkit/) 2.22.0 | 5,000+ | live |

### The update-policy field

Probed on the 6.9.5 lab, 2026-09-16. Install counts from the wordpress.org API on
the day of the run.

| Plugin | Active installs | Probed |
|---|---|---|
| [Easy Updates Manager](https://wordpress.org/plugins/stops-core-theme-and-plugin-updates/) 9.0.22 | 300,000+ | live |
| [Companion Auto Update](https://wordpress.org/plugins/companion-auto-update/) 3.9.4 | 40,000+ | live |
| [Core Rollback](https://wordpress.org/plugins/core-rollback/) 1.4.3 | 20,000+ | live |
| [Disable All WordPress Updates](https://wordpress.org/plugins/disable-wordpress-updates/) 2.0.2 | 10,000+ | live |
| [Disable Updates – Updates Manager](https://wordpress.org/plugins/webcraftic-updates-manager/) 1.3.3 (Webcraftic) | 10,000+ | live |
| [Disable Updates](https://wordpress.org/plugins/disable-updates/) 1.4.3 | 10,000+ | live |
| [Disable WordPress Update Notifications](https://wordpress.org/plugins/disable-update-notifications/) 2.4.3 | 10,000+ | live |
| [WP Auto Updater](https://wordpress.org/plugins/wp-auto-updater/) 1.7.4 | 7,000+ | live |
| [Update Control](https://wordpress.org/plugins/update-control/) 1.5.1 | 4,000+ | live |
| **Keel** 0.6.6 | — | live |

**[WP Rollback](https://wordpress.org/plugins/wp-rollback/) (300,000+) is not in
this field**, and it is the first name anyone will look for. It rolls back
plugins and themes and does not touch core; Core Rollback, by the same author, is
the core half and is the one probed. Webcraftic's Updates Manager is from the
vendor behind Clearfy, which the teardown field above already covers, but it is a
separate plugin with a separate install base rather than a Clearfy module.

Three plugins in the earlier field also carry update-related toggles — Admin and
Site Enhancements, WP Master Toolkit and Clearfy — and are not re-probed here.
None of them competes on core update *policy*: their controls suppress update
notices rather than choose which releases install, which is the surface below.

---

## Headline findings

### 1. "Disable XML-RPC" (200k installs) does not disable the pingback vector

The plugin is one line: `add_filter( 'xmlrpc_enabled', '__return_false' )`. Measured
against stock WordPress, the only thing that changes is the fault code on an
*authenticated* method (`403` → `405`). Everything else is identical to having no
plugin at all:

- `xmlrpc.php` still answers `200`
- `system.listMethods` still returns all **80** methods
- `pingback.ping` is still listed **and still executes**
- `system.multicall` still available
- `X-Pingback` still advertised in the response headers

`xmlrpc_enabled` gates methods that call `login()`. Pingback is unauthenticated by
design, so it sails straight through — and pingback is the method behind XML-RPC
reflection/DDoS amplification and SSRF probing, i.e. the actual reason most people
install one of these plugins. Disable Everything's XML-RPC toggle and WP Master
Toolkit's `xmlrpc_enabled` line have the same shape (WPMT redeems itself by also
swapping `wp_xmlrpc_server_class`).

**Doing it right** looks like Keel's, Clearfy's, or Disable XML-RPC API's approach:
unset `pingback.ping` and `pingback.extensions.getPingbacks` from `xmlrpc_methods`,
strip the `X-Pingback` header, and — if you want the endpoint gone — replace
`wp_xmlrpc_server_class` or 403 the file. Clearfy additionally hooks `xmlrpc_call`
and `wp_die`s on `pingback.ping`, which is belt-and-braces but correct.

Measuring Clearfy afterwards corrected that description: it does all of the above
*and* 403s `xmlrpc.php` outright, so none of the method-level work is reachable
anyway. See [the Clearfy and WP Master Toolkit run](#the-clearfy-and-wp-master-toolkit-run).

### 2. Disable Everything's REST toggle 403s logged-in administrators

Its `rest_authentication_errors` callback never checks `is_user_logged_in()`:

```php
add_filter( 'rest_authentication_errors', function ( $result ) {
    if ( empty( $result ) && ! is_admin() ) {
        return new WP_Error( 'rest_authentication_error', 'Forbidden', array( 'status' => 403 ) );
    }
    return $result;
}, 20 );
```

`is_admin()` is false during a REST request, so the guard never fires. Probed with a
valid admin cookie and `X-WP-Nonce`, **every** endpoint returns 403 —
`wp/v2/posts?context=edit`, `wp/v2/settings`, `wp/v2/types`, `wp/v2/block-types`.
That is the block editor and most REST-backed admin UI, dead. Every other
REST-disabling plugin in the field gets this right.

Two smaller defects in the same plugin: unguarded `$_SERVER['QUERY_STRING']` in the
user-enumeration branch (warning + "headers already sent" on servers that don't
always populate it), and its feed teardown `wp_die`s with **HTTP 500** rather than
404/410 — monitoring and crawlers read that as an outage.

### 3. Every comment plugin except Keel leaves server-side comment reads wide open

`comments_open`, `comments_array` and the REST layer only cover the theme's comment
template and the API. `get_comments()`, `wp_count_comments()`, a Recent Comments
widget shipped by another plugin, a custom `WP_Comment_Query` — all go straight to
the database and answer normally.

Measured `get_comments( array( 'status' => 'approve' ) )` with comments "disabled":

| | Disable Comments | …RB | Simply DC | ASE | Keel |
|---|---|---|---|---|---|
| `get_comments()` | 1 | 1 | 1 | 1 | **0** |
| `wp_count_comments()->approved` | 1 | 1 | 1 | 1 | **0** |

Keel is the only one that short-circuits `comments_pre_query`. That is the design
call documented in `includes/content.php`, and the probe confirms it is the only
implementation in the field where "comments are off" is true below the presentation
layer.

### 4. Disable Comments' own XML-RPC toggle misses pingbacks

Its "remove XML-RPC comments" setting unsets exactly one method:

```php
public function disable_xmlrc_comments( $methods ) {
    unset( $methods['wp.newComment'] );
    return $methods;
}
```

Method count drops 80 → 79. `pingback.ping` stays listed and reachable — and a
pingback *is* a comment row. So with the toggle on, the one XML-RPC path that can
still create comments on the site is the one left open.

### 5. Disable Comments blocks WP 6.9 Notes for administrators out of the box

2.8.0 added an allowlist so `type=note` (editorial Notes, stored as comments) keeps
working. In practice the allowlist defaults to empty:

```php
private function get_allowed_comment_types() {
    if ( ! isset( $this->options['allowed_comment_types'] ) || ! is_array( ... ) ) {
        return array(); // Default: all special comment types disabled
    }
```

Probed with an admin cookie: `wp/v2/comments`, `?type=note` and `?type=comment` all
return **403**. The Notes carve-out only exists if the user finds and ticks "Enable
Certain Comment Types". Worth noting that core helps here — WP 7.0 rejects the
`type` parameter for anyone who can't moderate (`rest_forbidden_param`, 401), so an
allowlist keyed on `type` can't be abused anonymously; the risk is only that it's
off by default.

### 6. Disable Comments RB (100k) has no REST or XML-RPC teardown at all

It is a fork of Disable Comments 1.x and stops at the presentation layer. With
"everywhere" saved: comment submission is correctly blocked (`wp-comments-post.php`
→ 403, nothing lands in the DB) and feeds 403 — but `GET /wp/v2/comments` returns
every comment, `_embed=replies` returns them, all 80 XML-RPC methods including
`wp.newComment` remain, and on a block theme the Comments block still renders.

There's also a structural issue it shares with Disable Comments' per-type mode: the
disabled post-type list is a **snapshot taken when you press Save**. Any post type
registered later by a new plugin or theme isn't covered until you re-save the
settings.

### 7. Everyone who closes REST also closes oEmbed and the REST index

Disable WP REST API, Admin and Site Enhancements and Disable Everything all return
401/403 for `/wp-json/`, `/wp-json/oembed/1.0/embed` and every route. That breaks
other sites embedding your posts — silently, and on *their* sites, with nothing on
the affected site to show it happened.

**Keel was the fourth. It no longer is** (keel#32, 2026-08-04): `oembed/1.0` stays
reachable past the gate, so the REST API is closed and embeds still work. Re-probed
with the gate on — `/wp-json/` 401, `/wp/v2/posts` 401, `/wp/v2/users` 401,
`oembed/1.0/embed` **200**.

The carve-out only became safe once oEmbed stopped disclosing the author (keel#31,
keel#34). Left alone it returns `author_name` and an `author_url` carrying the
account nicename — to exactly the anonymous caller the gate has just refused
`/wp/v2/users`. Opening the route without that fix would have reopened the
enumeration the gate exists to close.

None of the other three allowlist `oembed/1.0`.

### 8. Nothing in the update-policy field asks whether the release you are running is insecure

Nine plugins besides Keel, one lab, one running version that WordPress.org
classifies `insecure` on the day of the run. Requests to
`api.wordpress.org/core/stable-check/1.0/` during a full pass over every admin
screen each plugin owns, plus `update-core.php`, both Site Health tabs and
Settings → General:

| | EUM | Companion | Core Rollback | Disable All | Webcraftic | Update Control | Disable Updates | Disable Notices | WP Auto Updater | **Keel** |
|---|---|---|---|---|---|---|---|---|---|---|
| `net.stable_check` | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | **1** |

Zero is not a near miss. That endpoint is the only public source for the question
"does the version this site is running have publicly known vulnerabilities", and a
plugin that never asks it cannot answer, whatever its screens say. Confirmed
against the source as well as the wire: `grep` for `api.wordpress.org` across the
nine finds `plugins/info`, `themes/info`, `plugins/update-check`,
`themes/update-check` and `core/version-check`, and nothing else.

The whole field therefore reports the same thing WordPress already reports: that a
newer release exists. None of them distinguishes that from "this one is
vulnerable" — and the two come apart in both directions. A site on 6.9.7 has an
update available and no known vulnerabilities; this lab's 6.9.5 has both, and
nothing in the field says which.

**The near miss belongs to Core Rollback, and it is worth reading exactly.** Its
readme is the only document in the field that knows the endpoint exists:

> Refer to https://api.wordpress.org/core/stable-check/1.0/

That is an instruction to the reader to go and look it up in a browser. The
plugin's own screen offers "any outdated, secure release version of WordPress
Core" and lists 7.1, 7.0.4, 6.9.7, 6.8.8, 6.7.7 and down — which are in fact the
secure tips of each line, but only because that is what `core/version-check/1.7/`
happens to return per branch. Nothing on the screen says the running version is
one of the insecure ones, or which entry in that list is the fix for it.

### 9. Disable All WordPress Updates installs the major release its Security Mode exists to refuse

Its Security Mode is the one setting in that plugin that takes this field's
position — minor core updates install themselves, nothing else does. It registers
eight filters, and two of them contradict each other:

```php
add_filter( 'allow_minor_auto_core_updates', '__return_true', 20 );
add_filter( 'allow_major_auto_core_updates', '__return_false', 20 );
add_filter( 'allow_dev_auto_core_updates', '__return_false', 20 );
add_filter( 'auto_update_core', '__return_true', 20 );
```

`WP_Automatic_Updater::should_update()` asks the branch filters first, through
`Core_Upgrader::should_update_to_version()`, and then passes the answer through
`auto_update_core`. `__return_true` ignores the value handed to it. So the third
line of that block discards the second, and measured on the lab:

```
allow_major_auto_core_updates( true )        -> false
Core_Upgrader::should_update_to_version(7.1) -> false
auto_update_core( false )                    -> true
callbacks on auto_update_core: [10] __return_false  [20] __return_true
```

`truth.would_install` for this plugin with Security Mode on is **7.1** — a major
release, on a site configured for security releases only, from the plugin whose
name is Disable All WordPress Updates.

**Its own screen states the opposite of what it then does.** With
`WP_AUTO_UPDATE_CORE` defined, the plugin renders a block that reads, in part:

> The `WP_AUTO_UPDATE_CORE` constant is defined outside of this plugin … This
> constant always overrides this plugin's filters

Measured with `WP_AUTO_UPDATE_CORE = false`, which in core returns from
`should_update_to_version()` before any branch filter runs: stock WordPress
installs nothing (`would_install none`), and this plugin **still installs 7.1**.
The constant does not override its filters; its filters override the constant, in
the one direction that matters.

This is the only defect in either field where a plugin's configured-for-security
state is measurably less safe than its configured-for-nothing state, so it is
worth being precise about the blast radius: the release it installs is a current,
signed WordPress release, not a downgrade. The failure is that an operator who
chose "security releases only" gets a major version upgrade, unattended, on
whatever schedule cron runs.

### 10. Easy Updates Manager (300k) switches core automatic updates off on activation

`MPSUM_Admin_Core::get_defaults()` ships `core_updates => 'on'`, and
`MPSUM_Disable_Updates` reads `'on'` as *manually update*:

```php
if ( ! isset( $core_options['core_updates'] ) || 'on' == $core_options['core_updates'] ) {
    $this->is_core_updating_allowed = false; // on means manually update
}
```

which is then hooked onto `auto_update_core` at `PHP_INT_MAX - 10`, above anything
else on the site. Install the most popular plugin in this category, change
nothing, and automatic core updates — including security releases — stop. The
screen's own wording for the setting that restores them is "Auto update all minor
versions"; the default is the entry above it, "Manually update".

Configured to `automatic_minor`, it is correct: `would_install 6.9.7`, majors and
dev refused. The matrix rows below are the configured state, and this paragraph
is the default.

**A second thing in the same file makes its filters order-dependent.**
`core_should_update_to_new_version()` answers all three branch filters by writing
one instance property and returning it, and `is_core_updating_allowed()` — the
`auto_update_core` callback — returns whatever that property was last set to. The
answer to "would this plugin allow a core update" therefore depends on which
branch filter ran most recently. Core's own sequence makes it come out right; a
harness that probes the filters in a convenient order and then reads
`auto_update_core` gets a plugin blocking an update it in fact installs. This
harness measures `would_install` through core's own code path first for exactly
that reason, and the ordering is pinned in `probe-updates.sh` with the reason
written next to it.

### 11. Disable Updates (10k) leaves the site insecure, silent, and unable to notice

Four lines of a 90-line plugin, and together they close every route by which a
site learns it needs patching:

```php
add_filter( 'pre_site_transient_update_core', 'du_last_checked' );   // fakes an empty, freshly-checked result
add_filter( 'automatic_updater_disabled', '__return_true' );
add_action( 'admin_menu', 'du_remove_menus', 102 );                  // removes the Updates menu item
add_filter( 'site_status_tests', function ( $tests ) {
    unset( $tests['async']['background_updates'] );                  // removes the Site Health test
    unset( $tests['direct']['plugin_theme_auto_updates'] );
    return $tests;
} );
```

Measured, it is the only column in the field where the ground-truth rows go blank:
`same_line_patch none`, `would_install none`, `updates_screen none`,
`updater_disabled 1`. The faked transient reports `last_checked = time()` and no
updates, so nothing anywhere — not `update-core.php`, not the menu bubble, not
Site Health — indicates that this site is running a release with publicly known
vulnerabilities and will never install another one.

That is a defensible thing to want on a site whose updates are managed by
something outside WordPress. It is worth stating plainly anyway, because the
plugin has no settings screen and no way to say so: there is no partial mode, and
nothing tells a later administrator why the Updates menu is missing.

### 12. Disable WordPress Update Notifications makes the site re-check the API on every admin page

Its core setting nulls the update transient:

```php
add_filter( 'pre_option_update_core', '__return_null' );
add_filter( 'pre_site_transient_update_core', '__return_null' );
```

Unlike Disable Updates above, which substitutes a freshly-stamped empty object,
this returns nothing at all — so core cannot see a `last_checked` and re-checks.
Measured over one pass of four admin screens:

| | stock | Disable Notices | every other plugin |
|---|---|---|---|
| `net.version_check` | 0 | **8** | 0 or 1 |

Eight outbound requests to `api.wordpress.org/core/version-check/1.7/` where stock
makes none, on a site whose owner installed the plugin to stop thinking about
updates. The notice is hidden; the polling it was hiding got louder.

The same callback has its capability check the way round that only works by
accident:

```php
if ( ! current_user_can( 'update_core' ) ) {
    return;
}
```

The suppression is applied **only** to users who can update core, and skipped for
everyone else. That is the intended effect — the nag is what it is hiding, and
core only shows that to users with `update_core` — but written this way the
plugin also hides the update data itself from precisely the people who would act
on it, and leaves it in place for people who cannot.

### 13. Four plugins install the right release and none of them says so

Configured to minor-only, four of the nine put the site on the same-line patch:

| | EUM | Companion | Update Control | WP Auto Updater | Keel | stock |
|---|---|---|---|---|---|---|
| `truth.would_install` | 6.9.7 | 6.9.7 | 6.9.7 | 6.9.7 | **6.9.7** | 7.1 |
| names 6.9.7 anywhere | ❌ | ❌ | ❌ | ❌ | ✅ | — |

That is the policy half of this category working. The reporting half is the
column of crosses: none of the four names the release it is about to install, or
the one the site is on, or the fact that the Updates screen is offering a
different release from the one the plugin has decided on.

The gap is not cosmetic. On this lab, `update-core.php` offers 7.1 and nothing
else, because `get_core_updates()` discards every offer whose response is
`autoupdate` and 6.9.7 is one of those. An administrator running any of these four
sees one release on the Updates screen, has a plugin configured to install a
different one, and has nothing anywhere that reconciles the two.

### 14. Only Keel stands down for `WP_AUTO_UPDATE_CORE`, and only two plugins mention it at all

Re-run with the constant defined in `wp-config.php` and every plugin still
configured to minor-only, so the constant and the plugin disagree on purpose:

| `WP_AUTO_UPDATE_CORE = true` | stock | WP Auto Updater | EUM | Companion | Disable All | Webcraftic | Update Control | **Keel** |
|---|---|---|---|---|---|---|---|---|
| `truth.would_install` | 7.1 | 7.1 | 6.9.7 | 6.9.7 | 7.1 | 7.1 | 6.9.7 | 7.1 |
| `policy.allow_major` | 10 | 10 | 00 | 00 | 00 | 10 | 00 | **10** |
| names the constant on screen | 0 | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ✅ |

| `WP_AUTO_UPDATE_CORE = false` | stock | EUM | Disable All | **Keel** |
|---|---|---|---|---|
| `truth.would_install` | none | **6.9.7** | **7.1** | none |
| names the constant on screen | 0 | ❌ | ✅ | ✅ |

Core treats the constant as a *default*: it sets `$upgrade_minor` / `$upgrade_major`
and then runs the branch filters over them, so any plugin using `__return_false`
or `__return_true` silently wins. Every plugin here that registers those filters
does win, and only one of them tells you.

Keel takes the other position. Its bootstrap does not register those filters at
all when the constant is defined (`includes/bootstrap.php`):

```php
if ( ! defined( 'WP_AUTO_UPDATE_CORE' ) && 'inherit' !== keel_defaults_get( 'core_update_policy' ) ) {
```

so the constant decides, and the settings screen says which setting is no longer
in charge:

> Locked by `WP_AUTO_UPDATE_CORE` in `wp-config.php`. Remove that constant to
> manage core releases here.

Whether standing down is the right call is a judgement rather than a defect —
Easy Updates Manager overriding a `false` constant is arguably what a plugin whose
whole job is update policy should do. What is not a judgement is saying nothing
about it: on a site where a host set that constant deliberately, EUM installs a
core release and neither its screen nor the constant's owner is told.

Disable All WordPress Updates is the only other plugin that reports the constant,
and it reports it in detail — value, likely origin, consequence. Finding 9 above
is that the consequence it states is the reverse of the one measured.

### 15. Keel is the only plugin that surfaces the patch `get_core_updates()` throws away

The same-line patch is invisible on `update-core.php` by core's own design. What
Keel renders there, measured on this lab:

> **A security release exists for your version line.** This site is running a
> release with publicly known vulnerabilities. 6.9.7 fixes them and stays on the
> same release line. The update offered below is 7.1. 6.9.7 is the minor update
> that closes the known vulnerabilities. **[Install WordPress 6.9.7 now]**

and, on a site whose policy permits majors:

> WordPress would install 7.1 and skip 6.9.7. It takes the highest release your
> settings allow rather than the nearest, so the fix for the line you are on is
> passed over.

Those are the two claims the probe was built to check, and they are the two
nobody else makes. `core.says_same_line_patch` is 4 for Keel and 0 for every
other plugin and for stock; `core.says_insecure` is the same. The install button
is a same-line install: it targets 6.9.7 specifically rather than sending the
administrator to a screen that would install 7.1.

**Core Rollback is the only other plugin that can put 6.9.7 on the site**, from a
dropdown of core releases on its own screen. It is framed as a rollback — the
screen's first line is a warning about downgrading — it does not mark which entry
is the security fix for the running version, and it gets there by re-writing
core's version-check request rather than through the offer core already has.

**One limit on Keel's side of this, since it is the whole feature.** Every row
above depends on `api.wordpress.org/core/stable-check/1.0/` being reachable. When
it is not, `keel_defaults_version_status()` returns `unknown` and the panel says
so rather than guessing; the probe's `net.stable_check 1` is a measurement that
Keel asks, not that the answer is always available.

---

## The matrix

Legend: ✅ correct · ⚠️ partial / caveat · ❌ open or broken · — not in scope

### REST API surface

| | Disable Comments | …RB | Simply DC | Disable WP REST API | Disable XML-RPC | Disable XML-RPC API | Disable Everything | Disable Blog | ASE | **Keel** |
|---|---|---|---|---|---|---|---|---|---|---|
| Anonymous `GET /wp/v2/comments` blocked | ✅ 403 | ❌ 200, all comments | ❌ 200, all comments | ✅ 401 | ❌ | ❌ | ✅ 403 | ⚠️ 200 but empty | ✅ 401 | ✅ 401 |
| `?rest_route=` variant blocked too | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ✅ | ⚠️ | ✅ | ✅ |
| `_embed=replies` leak closed | ✅ | ❌ | ❌ | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| Anonymous user enumeration closed | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ✅ | ❌ | ✅ | ✅ |
| **Block editor still works (authed)** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ **403 everywhere** | ⚠️ post type gone | ✅ | ✅ |
| REST discovery link removed | ❌ | ❌ | ❌ | ✅ | ❌ | ❌ | ✅ | ❌ | ✅ | ✅ *(fixed)* |
| oEmbed provider kept working | ✅ | ✅ | ✅ | ❌ 401 | ✅ | ✅ | ❌ 403 | ❌ 404 | ❌ 401 | ✅ 200 *(fixed)* |
| Comments route reachable by admins | ❌ 403 | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ 403 | ✅ | ⚠️ 404 (route unregistered) | ✅ |

### Comment teardown

| | Disable Comments | …RB | Simply DC | Disable Blog | ASE | **Keel** |
|---|---|---|---|---|---|---|
| `comments_open()` false | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `pings_open()` false | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `wp-comments-post.php` rejects | ✅ 403 | ✅ 403 | ✅ 403 | ✅ 403 | ✅ 403 | ✅ 403 |
| No comment row lands in DB | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `get_comments()` answers empty | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| `wp_count_comments()` answers 0 | ❌ | ❌ | ❌ | ✅ | ❌ | ✅ |
| `get_comments_number()` reads 0 | ✅ | ❌ 1 | ❌ 1 | ❌ 1 | ✅ | ✅ *(fixed)* |
| Post-type support removed | ❌ | ✅ | ❌ | ❌ | ❌ | ✅ |
| `get_default_comment_status()` closed | ❌ open | ✅ closed | ❌ open | ❌ open | ❌ open | ✅ closed |
| Comment feeds blocked | ✅ 403 | ✅ 403 | ❌ 200 | ❌ 200 | ❌ 200 | ✅ 404 *(fixed)* |
| `X-Pingback` header stripped | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Block-theme comment markup gone | ✅ | ❌ | ⚠️ | ❌ | ✅ | ✅ *(fixed)* |
| Classic-theme comment form gone | — | — | — | — | — | ✅ *(measured)* |
| Comment blocks pulled from inserter | ⚠️ JS, latest-comments only | ❌ | ❌ | ❌ | ⚠️ | ✅ PHP, 15 blocks |


#### The Clearfy and WP Master Toolkit run

These two were code-reviewed but never measured, and they are the closest
architectural peers to Keel — a menu of independently toggleable defaults rather
than a single-purpose switch. Measured 2026-08-09 on the same 7.0.2 install.

Both were configured through their own storage, which took two attempts each and
is the whole reason this section exists:

- **WP Master Toolkit writes no option at all on activation.** Its modules live in
  `wpmastertoolkit_settings` as a class-name → `'1'` map, and
  `admin/class-handle-options.php` instantiates only what is marked. A fresh
  install therefore has every module **off**. Probing it unconfigured would have
  measured the plugin doing nothing.
- **Clearfy's bundled comments component declares one option prefix and reads
  another.** `comments-plus.php` says `'prefix' => 'wbcr_comments_plus_'`, which is
  what reading the source gives you; loaded inside Clearfy the component's plugin
  object carries `wbcr_clearfy_`, so `wbcr_clearfy_disable_comments` is the key it
  actually reads. Setting the declared name left it inert while every surface said
  otherwise — class loaded, helper defined, option present with the right value.
  Caught by asking the plugin (`WCM_Plugin::app()->getOptionName( … )`) after
  noticing `comments_open` still carried nothing but core's own callback.

| Probe | stock | Clearfy | WPMT | **Keel** |
|---|---|---|---|---|
| `rest.index` | 200 | 200 | 401 | 401 |
| `rest.users` | 200 | 200 | 401 | 401 |
| `rest.oembed` | 200 | 200 | **401** | **200** |
| `rest.head_link` | 1 | 1 | 0 | 0 |
| `feed.site_comments` | 200 | 403 | 200 | 404 |
| `header.xpingback` | 1 | 0 | 1 | 0 |
| `xmlrpc.http` | 200 | **403** | 403 | 200 |
| `xmlrpc.methods` | 80 | 0 | 0 | 3 (`system.*`) |
| `write.comment_landed_indb` | 1 | 0 | **1** | 0 |
| `html.comment_form` | 6 | 1 | 6 | 0 |
| `get_comments()` | 2 | **2** | **2** | **0** |
| `wp_count_comments()` | 2 | **2** | **2** | **0** |
| `auth.posts_edit` | 200 | 200 | 200 | 200 |

**Clearfy has no REST teardown.** Confirmed rather than corrected — every REST row
is identical to stock, and the discovery link is still in `<head>`.

**Clearfy 403s `xmlrpc.php` outright, which the code review missed.**
`xmlRpcSetDisabledHeader()` checks `basename( $_SERVER['SCRIPT_FILENAME'] )` at
plugin load and, on `xmlrpc.php`, sends a 403 and `die()`s. Reproduced directly: a
`system.listMethods` POST returns 403 with a 22-byte body. So Clearfy's careful
per-method work — unsetting pingback methods, the `xmlrpc_call` guard — sits behind
a door that is already shut. It is not granular in practice; the matrix rows above
have been corrected from ⚠️ to ❌.

**WP Master Toolkit's REST teardown takes oEmbed with it.** `rest.oembed` returns
401, where Keel's returns 200. That is the trade Keel's non-goals argue about from
the other side: closing the route costs other sites' embeds of your posts, and
Keel keeps it reachable while stripping the author fields instead.

**WP Master Toolkit has no comment teardown in its free tier.** `Disable Comments`
is a pro module, so every comment row reads exactly like stock — the feed answers
200, `X-Pingback` is advertised, `comments_open` is 1, and a posted comment
**lands in the database**. That is "not offered", not "does nothing", and it is
why the comment rows above are not a criticism of the implementation.

**Neither closes server-side comment reads.** Clearfy shuts the presentation layer
properly — `comments_open` 0, post-type support removed, `default_status` closed,
`html.comment_form` down from 6 to 1 — and `get_comments()` still answers 2. That
is headline finding 3 holding for two more plugins, and it now covers every
comment-capable plugin in the field.

Neither breaks the block editor: all four authenticated admin probes return 200.

#### The classic-theme run

Every other measurement here is from a block-theme install, which left the two
rendered-markup rows open to a fair objection: a teardown that removes
`core/comments` from a block template proves nothing about a classic theme, where
the form comes from `comments_template()` and the theme's own `comments.php`.

Measured 2026-08-09 on one install — WordPress 7.0.2, SQLite, the probe harness in
`tests/integration/` — by switching only the theme between runs.

**Stock WordPress, no plugins, block theme → classic theme.** Two rows move and
nothing else does, which is the check that the theme swap did what was intended:

| Probe | Twenty Twenty-Five | Twenty Twenty-One |
|---|---|---|
| `html.comment_form` | 6 | 4 |
| `html.comments_block` | 4 | **0** |

`wp-block-comments` is a block-theme marker and disappears with the theme, so on a
classic theme that row can never have measured a teardown — it reads 0 whether a
plugin is active or not. Anyone comparing plugins on a classic install would score
every one of them as passing it.

**With Keel configured, block theme → classic theme: zero differences.** Not in
the two markup rows, not anywhere in the other thirty-odd probes. Both themes
report `html.comment_form 0` and `html.comments_block 0`.

The reason is that the teardown does not run at the theme layer at all. Keel
closes comments in the data: `comments_open()` false, post-type support removed,
`get_default_comment_status()` closed. A classic theme calling
`comments_template()` then renders nothing because `comment_form()` is gated on
`comments_open()`, and a block theme renders nothing because the same state
reaches `render_block`. One mechanism, two themes, and no theme-specific code.

So the block-theme-only measurement was not hiding anything for Keel. It is worth
saying that it *could* have been: the row is the kind that passes for the wrong
reason, and the only way to know was to switch the theme and look.

#### The older-WordPress run

Keel's header claims `Requires at least: 6.4`, and every measurement here was taken
on 7.0.2 — six releases above the floor. A support claim nobody has stood on is a
claim, not a fact.

Measured 2026-08-09 on a second throwaway install: WordPress **6.4** exactly, the
earliest release the header admits, same SQLite setup, same PHP 8.5, same harness,
same `probe-configs/keel.php`.

**Stock 6.4 versus stock 7.0.2, no plugins: byte-identical across all 38 probes.**
That is the control, and it is what makes the rest of this mean anything — without
it, "no differences with Keel" could as easily have been a harness that stopped
measuring.

**Keel configured, 6.4 versus 7.0.2: zero differences.** All 38 probes agree. For
contrast, Keel moves 26 of those rows against stock on 6.4, so the comparison is
plainly capable of showing a difference.

The HTTP probe cannot see the admin, which is where a missing API would actually
surface, so two further checks:

| Check | 6.4 | 7.0.2 |
|---|---|---|
| Settings screen bytes / row headings / fieldsets | 39000 / 34 / 28 | 39000 / 34 / 28 |
| Site Health Info groups | 10 | 10 |
| Site Health posture status | good | good |
| Site Health tests registered | 2 | 2 |
| PHP diagnostics raised from `plugins/keel/` | none | none |

The rendered settings markup is identical between the two versions apart from the
nonce, which is per-session rather than per-version.

Every WordPress function Keel calls was also checked against both loads. The only
names undefined on 6.4 are undefined on 7.0.2 too — `switch_to_blog()`,
`restore_current_blog()` and `get_sites()`, which exist only on multisite and which
Keel calls behind `is_multisite()`. Nothing Keel uses arrived after 6.4.

The diagnostics check was itself verified by planting an undefined variable in
`keel_defaults_site_health_info_styles()` and confirming it was reported
(`site-health.php:441 Undefined variable`), because a null result from a listener
that cannot fire is worth nothing.

**So the 6.4 floor is now measured rather than asserted.**

The multisite half was closed on 2026-08-10: a second 6.4 lab installed directly
as a network runs `tests/integration/verify-network.sh` clean — all ten checks,
including the three functions the single-site run could not reach
(`switch_to_blog`, `restore_current_blog`, `get_sites`) and the whole network
policy layer. Seeding a subsite created after activation works on 6.4 exactly as
it does on 7.0.2.

What is still not covered is **6.4 served on PHP 7.4**. CI runs the unit suite on
7.4, so the language floor is tested; what is untested is the oldest supported
WordPress running on the oldest supported PHP as a live site, which needs a 7.4
runtime this lab does not have.

One caution learned the hard way, now in the integration README: the first 6.4
lab had become 7.0.3 by the following morning, through a background core update
it never announced. The original measurement stands — the version was verified in
the same session it was taken — but a lab pinned to an old release does not stay
pinned unless the updater is switched off, and the version is worth re-checking
immediately before every run rather than only at build time.

### XML-RPC

| | Disable XML-RPC | Disable XML-RPC API | Disable Everything | Disable Comments | ASE | Clearfy | WPMT | **Keel** |
|---|---|---|---|---|---|---|---|---|
| Technique | `xmlrpc_enabled` | 403 the endpoint | `xmlrpc_enabled` | unset `wp.newComment` | 403 the endpoint | **403 the endpoint** (plus unset methods, `xmlrpc_call` die) | `xmlrpc_enabled` + server class 403 | unset methods, per-capability |
| Methods left listed | ❌ 80 | ✅ 0 | ❌ 80 | ⚠️ 79 | ✅ 0 | ✅ | ✅ | ✅ 3 (`system.*`) |
| `pingback.ping` unreachable | ❌ | ✅ | ❌ | ❌ | ✅ | ✅ | ✅ | ✅ |
| Remote publishing blocked | ⚠️ 405 | ✅ | ⚠️ 405 | ❌ | ✅ | ✅ | ✅ | ✅ |
| `system.multicall` removable | ❌ | ✅ (all-or-nothing) | ❌ | ❌ | ✅ (all-or-nothing) | ✅ (all-or-nothing) | ✅ (all-or-nothing) | ✅ **individually** |
| Granular (keep app publishing, drop pingback) | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |

### Classic editor

Measured 2026-08-09 with `tests/integration/probe-editor.sh`, which renders the
post-edit screen as a logged-in administrator and counts what came back. The
capability rows below stay as code review — they describe options a probe of one
configuration cannot see — but the top three rows are now measurement.

| | stock | Classic Editor (9M) | Disable Gutenberg (500k) | **Keel** |
|---|---|---|---|---|
| `block-editor-page` on the edit screen | 1 | **0** | **0** | **0** |
| TinyMCE references | 3 | 8 | 8 | 8 |
| Classic editor on `post-new.php` too | ❌ | ✅ | ✅ | ✅ |
| Adds per-row editor links to the posts list | — | 0 | **2** | 0 |
| `use_block_editor_for_post_type` | — | ✅ | ✅ | ✅ |
| Per-post `use_block_editor_for_post` | — | ✅ | ❌ | ❌ |
| Per-user opt-in / both-editors mode | — | ✅ | ⚠️ role/type rules | ❌ single switch |
| Unhooks Gutenberg-plugin REST routes | — | ✅ | ✅ | ❌ |

**All three actually replace the editor.** That is the part code review could not
settle, and it is settled: the block editor container is gone from both the edit
and the new-post screens in all three, and TinyMCE loads instead.

**Classic Editor needs no configuration.** It writes no option on activation and
its filters already answer `classic` — the only plugin in this whole matrix that
is correctly configured out of the box. **Disable Gutenberg** writes no option
either but ships `disable-all => 1` in its defaults, so it is on out of the box as
well; it is the only one of the two that adds editor links to the posts list.

Classic Editor remains the reference implementation; nothing in this field improves
on it. Keel's `force_classic_editor` is a blunt site-wide switch by comparison —
appropriate for a defaults plugin, but it should say so in its help text rather than
imply parity.

**One difference in the numbers is not an editor difference.** Keel's edit screen
carries one `wp-editor-area` where the other two carry two. The second is
`replycontent`, the comment-reply box in the comments metabox, and it is absent
because the probe configuration has Keel's comment teardown on. Turning comments
back on brings it back. Worth recording because the raw count reads like a missing
piece of the editor and is nothing of the kind.

**A note on what the probe cannot see from CLI.** `probe-editor.sh` also reports
what `use_block_editor_for_post_type()` answers in a WP-CLI context, and for
Disable Gutenberg that reads `block` — for a plugin whose rendered screen is
demonstrably the classic editor. It registers from an admin-only hook that never
fires under CLI. That divergence is the reason this column could not be settled by
reading code: a filter can be present and invisible, or absent and irrelevant, and
only the rendered screen tells you which.

### Core update policy

Measured on the 6.9.5 lab, every plugin configured to its own nearest equivalent
of "install maintenance and security releases, not majors". `would install` is
core's own decision — `WP_Automatic_Updater::should_update()` evaluated over every
offer — not the plugin's stored setting.

| | EUM | Companion | Core Rollback | Disable All *(Security Mode)* | Webcraftic | Update Control | Disable Updates | Disable Notices | WP Auto Updater | **Keel** |
|---|---|---|---|---|---|---|---|---|---|---|
| Has a core update policy control | ✅ | ✅ | ❌ | ⚠️ one checkbox | ✅ | ✅ | ❌ | ❌ | ✅ | ✅ |
| Configured policy is actually applied | ✅ 6.9.7 | ✅ 6.9.7 | — | ❌ **installs 7.1** | ❌ 7.1 | ✅ 6.9.7 | — | — | ✅ 6.9.7 | ✅ 6.9.7 |
| Refuses majors | ✅ | ✅ | — | ⚠️ filter set, overridden | ❌ inherits | ✅ | — | — | ✅ *(site option)* | ✅ |
| Refuses development builds | ✅ | ❌ inherits | — | ✅ | ❌ inherits | ✅ | — | — | ❌ inherits | ✅ |
| Policy holds during an ordinary request | ✅ | ✅ | — | ✅ | ✅ | ✅ | — | — | ⚠️ majors only | ✅ |
| Correct out of the box | ❌ **auto-updates off** | ✅ minor-only | — | ❌ all updates off | ⚠️ minors on, majors untouched | ✅ minor-only | ❌ all off | ❌ | ✅ minor-only | ⚠️ `inherit` by default |
| Leaves `auto_update_core` alone | ❌ forces | ✅ | ✅ | ❌ **forces true** | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Site can still learn an update exists | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ❌ **transient faked** | ⚠️ re-checks ×8 | ✅ | ✅ |
| Can install a specific same-line release | ❌ | ❌ | ⚠️ as a rollback | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |

### What the screens say

The five questions this field was probed for. A ✅ means the plugin's own screens,
or a screen it adds content to, state it; core's own output does not count, and
the stock column is 0 on every marker below.

| | EUM | Companion | Core Rollback | Disable All | Webcraftic | Update Control | Disable Updates | Disable Notices | WP Auto Updater | **Keel** |
|---|---|---|---|---|---|---|---|---|---|---|
| Reports the stable-check status of the installed version | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Distinguishes "an update exists" from "this version is vulnerable" | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Surfaces the same-line patch `get_core_updates()` discards | ❌ | ❌ | ⚠️ listed, unmarked | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Names the release WordPress would actually install | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Says when a `wp-config.php` constant overrides the setting | ❌ | ❌ | — | ⚠️ says it, backwards | ❌ | ❌ | — | — | ❌ | ✅ |
| Asks `api.wordpress.org/core/stable-check/1.0/` | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |
| Flags blocking `wp-config.php` constants at all | ⚠️ `DISABLE_WP_CRON` only | ❌ | ❌ | ✅ | ❌ | ❌ | ❌ | ❌ | ❌ | ✅ |

Two rows deserve their caveats spelled out rather than left in a symbol.

**Easy Updates Manager has the mechanism and checks one constant.**
`MPSUM_CONSTANT_CHECKS::get_prohibited_active_constants()` is a five-line method
whose entire body tests `DISABLE_WP_CRON`. The notice it drives is well written
and appears on every screen; it simply does not look at `WP_AUTO_UPDATE_CORE` or
`AUTOMATIC_UPDATER_DISABLED`, which are the two constants that decide the policy
the rest of that screen is configuring.

**Core Rollback lists 6.9.7 without saying what it is.** Its dropdown is the
secure tip of each release line, which on this lab includes the patch for the
running version, and its copy calls them "outdated, secure" releases. Nothing
marks the running version as insecure or that entry as its fix, and the screen
frames the whole operation as a downgrade.

#### One number that is not what it looks like

`own.says_insecure` reads 1 for Keel in the appendix, and it is not the patch
panel. It is the word "vulnerability" in the help text on Keel's own settings
screen. Keel's reporting is not on its settings screen at all: it is on Site
Health, on `update-core.php`, and in an admin notice — all shared screens, which
is why it is counted in the `core.says_*` rows, where stock reads 0 and
attribution is therefore clean.

Two cells in the first table are code review rather than measurement, because the
lab offers no development build to measure against: the "refuses development
builds" row reads the `allow_dev_auto_core_updates` pair and the
`auto_update_core_dev` site option, not an install.

**Webcraftic's "minor" setting does not stop a major.** Its `wp_update_core`
branch for that position adds `allow_minor_auto_core_updates => __return_true`
and touches nothing else, so majors are left to whatever core's own
`auto_update_core_major` site option says. On a site installed at
5.6 or later that option is `enabled` — `populate_options()` seeds
`auto_update_core_major => 'enabled'` for new installs — and this lab is one,
which is why `would_install` is 7.1 for a plugin configured to minor-only. A site
upgraded from before 5.6 has `'unset'` instead, written by the upgrade routine
specifically to override that seed, and the same configuration then behaves
correctly. So this plugin's answer depends on how old the site is — which is the
thing the comment above Keel's own filter registration says those filters exist
to remove.

**WP Auto Updater's two rows need reading together.** Its
`allow_*_auto_core_updates` filters are registered *inside its own cron callback*,
between `wp_auto_updater/before_auto_update/wordpress_core` and the matching
after-action, so a probe of those filters during an ordinary admin request
correctly reads all three as untouched. What holds outside that callback is a site
option: it writes `auto_update_core_major = 'disable'` on activation, which is why
`would_install` is 6.9.7 rather than 7.1. The consequence is that its four other
core scenarios — Minor Only, Previous Generation, Manual — apply only on its own
schedule, and a core auto-update triggered by anything else on the site is
governed by that one site option alone. It also never puts the option back on
deactivation.

---

## What this means for Keel

Keel measures best-in-field on the two surfaces it was designed around: it is the
only plugin that closes server-side comment reads, and its XML-RPC teardown is the
only granular one (drop pingbacks, keep remote publishing, or any combination). Its
REST gate is on the correct side of the authenticated/anonymous line.

The probes also found five gaps in Keel itself. All five are fixed. The fifth was
recorded here as an inherent trade-off before it was closed; the entry below now
says what actually shipped.

1. **`get_comments_number()` returned 1** while `wp_count_comments()` returned 0 — a
   theme printing "1 Comment" above a thread that no longer exists. **Fixed:**
   `get_comments_number` filtered to zero.
2. **The REST discovery link was still emitted** with `disable_rest` on, advertising
   `rel="https://api.w.org/"` for an endpoint that answers 401. **Fixed:** all three
   discovery outputs unhooked — `rest_output_link_wp_head`, `rest_output_link_header`
   and `rest_output_rsd`.
3. **Block-theme comment markup still rendered** — the Comments block wrapper, the
   "Comments" heading and the block's CSS shipped on every post. The inserter filter
   only governs what an editor can add next; it does not touch blocks already saved
   in a theme's templates. **Fixed:** `render_block` returns an empty string for the
   comment blocks, which leaves the blocks registered and the template markup intact
   so the default stays reversible.
4. **Comment feeds returned 200** — empty, thanks to `comments_pre_query`, but live
   and crawlable. This was the clearest of the four, because the `disable_comments`
   help text already *claimed* comment feeds were removed; only the `<link>` markup
   was. **Fixed:** comment feed requests 404.
5. **oEmbed went down with the REST API** — shared with every other plugin that
   blocks anonymous REST, and recorded here at first as an acceptable cost of the
   toggle. It was not: the site paying it is the one doing the embedding, so the
   breakage lands somewhere the operator never sees. **Fixed** (keel#32):
   `oembed/1.0` is allowlisted past the gate, and the `disable_rest` help text says
   so. See finding 7 above for the re-probe. The carve-out is only safe because
   `keel_defaults_strip_oembed_author()` is registered by the gate itself, so the
   route cannot hand an anonymous caller the nicenames the gate just refused.

Fix 4 needed a second pass. Calling `set_404()` alone produced a *worse* result than
the bug: `redirect_canonical()` does not bail on a 404 — it calls
`redirect_guess_404_permalink()`, and against the query `set_404()` had just emptied
it answered `/hello-world/feed/` with a 301 to `/hello-world/feed/feed/`. The
canonical redirect has to be removed for that request too. Only the HTTP probe
caught this; every filter-level assertion still passed.

**None of the four were Keel-specific.** All three of the plugins in this lineage —
Keel, Better by Default and the Pixel Managed Platform — share them, because they
share the code they came from. A three-way matrix between siblings
(`~/Code/keel-px-feature-matrix.md`) had already given the comment teardown a
full read-verdict and found nothing, which is what a sibling comparison does: it
sees divergence, never common inheritance. These surfaced only against unrelated
plugins, where Disable Comments and Admin and Site Enhancements turned out to be
measurably ahead of all three of ours on rendered markup and comment counts. The
back-ports are filed there as B-8/B-9 (Better by Default) and P11/P12 (Pixel);
both **landed the same day and were re-probed** — all three plugins now return
identical results across all 30 probes.

Settling it also turned up a reporting bug one layer out. Pixel's Site Health
posture counted `comment_status = 'open'` rows straight from the database, so on a
site where the teardown was fully on and nothing could post a comment through any
route, the panel still flagged "Open comments" as a live public-input surface. The
stored status is a candidate, not the answer — every core write path gates on
`comments_open()`, which is a filter.

That one was fixed upstream, independently and first: Pixel's `#218` landed while
this comparison was being written, and short-circuits the count on
`Comments::instance()->comments_are_disabled()`. Worth recording that it closes the
case narrowly. It reports the effective state for *Pixel's own* toggle, so a site
running Pixel alongside a third-party comment plugin is still flagged for open
comments it cannot receive — and it reaches from `PluginContext` back into the
Comments module, which is the coupling that object's own docblock says it exists to
avoid. Asking `comments_open` directly would cover any teardown and need no such
dependency; that is a preference, not a defect, and the shipped fix has the tests.

Coverage for all four landed in `tests/integration/verify-behaviors.sh` (48 checks,
all passing). That harness also had a bug of its own: it routed any site with a
`wp-content/db.php` dropin through `studio wp`, which made the documented
`KEEL_SITE` override unusable on exactly the kind of throwaway SQLite install this
comparison needs. It now keys off the path instead.

### What the update-policy field adds

Keel measures best-in-field on the surface it leads with, and the margin is
wider than on the other two — but it is a margin on *reporting*, not on policy,
and the two should not be run together.

**On policy, Keel is one of five plugins that get it right.** Configured to
minor-only it installs 6.9.7 and refuses 7.1, and so do Easy Updates Manager,
Companion Auto Update, Update Control and (by a different mechanism) WP Auto
Updater. Keel's policy implementation has no advantage over theirs; Update
Control in particular does the same three filters in about the same number of
lines. Two of the nine get it wrong — Disable All WordPress Updates installs the
major its Security Mode refuses, Webcraftic never refuses majors at all — and
three more have no core policy at all.

**On reporting, nothing else in the field is trying.** The five questions this
probe was built around are answered by one plugin out of ten, and the reason is
structural rather than a matter of effort: none of the other nine asks
`api.wordpress.org/core/stable-check/1.0/`, so none of them *can* say whether the
running release is insecure, and everything downstream of that — which patch
closes it, whether the Updates screen is offering that patch, which release would
actually install instead — follows from the same missing fact.

That asymmetry is the honest summary of this category. The policy filters are
well-trodden; what is unoccupied is the question of whether the site is actually
patched, which WordPress core does not ask and which every plugin in this field
has inherited core's silence about.

**Two things the probes found in Keel, neither of them fixed here.**

1. **Keel's reporting is not on Keel's screen.** `own.says_same_line_patch` and
   `own.says_would_install` are both 0: the patch panel lives on Site Health and
   `update-core.php`, and the settings screen where an administrator chooses the
   core update policy says nothing about the release the site is on. That is
   defensible — the panel belongs where the diagnosis is — but the setting and
   the diagnosis are the same decision, and someone reading only the settings
   screen sees the policy and not the reason to care about it.
2. **The default is `inherit`.** Every other plugin in this field that ships a
   working policy ships it switched on; Keel's `core_update_policy` defaults to
   leaving WordPress's own decision alone. That is the correct default for a
   defaults plugin and it is what the "correct out of the box" row's ⚠️ means —
   Keel does not decide until asked. Worth stating because the same row marks
   Easy Updates Manager's default as a defect, and the difference is that EUM's
   default silently *changes* the behaviour (core auto-updates stop) while Keel's
   silently preserves it.

**And one thing the field found that Keel should not copy.** Companion Auto
Update's deactivation hook drops its tables, destroying its settings and its
update history; Disable Updates removes the Site Health test that would have
reported the problem it creates; WP Auto Updater leaves `auto_update_core_major`
set to `'disable'` behind it when deactivated. Keel's uninstall coverage is
tested (`tests/uninstall-coverage.php`) and its deactivation leaves core's own
options alone — the probes confirmed that by resetting those options between
runs and finding Keel's column unchanged either way — but the three failures
above are all "what the plugin leaves behind", and that is a surface worth a test
rather than an assumption.

---

## Appendix: raw teardown matrix

Every value is a live measurement. HTTP status codes unless noted; `n=` is the
number of comments returned in the JSON body; `fault=` is the XML-RPC fault code
from a direct method call (`-32601` = method not found, `none` = no response body,
`405` = "XML-RPC services are disabled").

| probe | stock WP | disable-comments | …-rb | simply-dc | disable-wp-rest-api | disable-xml-rpc | disable-xml-rpc-api | disable-everything | disable-blog | ASE | Keel (fixed) |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `rest.comments.pretty` | 200 (n=1) | 403 (n=err) | 200 (n=1) | 200 (n=1) | 401 (n=err) | 200 (n=1) | 200 (n=1) | 403 (n=err) | 200 (n=0) | 401 (n=err) | 401 (n=err) |
| `rest.comments.querystring` | 200 (n=1) | 403 (n=err) | 200 (n=1) | 200 (n=1) | 401 (n=err) | 200 (n=1) | 200 (n=1) | 403 (n=err) | 200 (n=0) | 401 (n=err) | 401 (n=err) |
| `rest.comments.embed` | n=1 | n=0 | n=1 | n=1 | n=0 | n=1 | n=1 | n=0 | n=0 | n=0 | n=0 |
| `rest.index` | 200 | 200 | 200 | 200 | 401 | 200 | 200 | 403 | 200 | 401 | 401 |
| `rest.users` | 200 | 200 | 200 | 200 | 401 | 200 | 200 | 403 | 200 | 401 | 401 |
| `rest.posts` | 200 | 200 | 200 | 200 | 401 | 200 | 200 | 403 | 404 | 401 | 401 |
| `rest.oembed` | 200 | 200 | 200 | 200 | 401 | 200 | 200 | 403 | 404 | 401 | 200 |
| `rest.head_link` | 1 | 1 | 1 | 1 | 0 | 1 | 1 | 0 | 1 | 0 | 0 |
| `feed.site_comments` | 200 | 403 | 403 | 200 | 200 | 200 | 200 | 500 | 200 | 200 | 404 |
| `feed.post_comments` | 200 | 403 | 403 | 200 | 200 | 200 | 200 | 500 | 200 | 200 | 404 |
| `header.xpingback` | 1 | 0 | 0 | 0 | 1 | 1 | 0 | 0 | 0 | 0 | 0 |
| `xmlrpc.http` | 200 | 200 | 200 | 200 | 200 | 200 | 403 | 200 | 200 | 403 | 200 |
| `xmlrpc.methods` | 80 | 79 | 80 | 79 | 80 | 80 | 0 | 80 | 50 | 0 | 3 |
| `xmlrpc.has_pingback` | 1 | 1 | 1 | 1 | 1 | 1 | 0 | 1 | 0 | 0 | 0 |
| `xmlrpc.has_multicall` | 1 | 1 | 1 | 1 | 1 | 1 | 0 | 1 | 1 | 0 | 1 |
| `xmlrpc.has_newPost` | 1 | 1 | 1 | 1 | 1 | 1 | 0 | 1 | 0 | 0 | 0 |
| `xmlrpc.has_newComment` | 1 | 0 | 1 | 0 | 1 | 1 | 0 | 1 | 1 | 0 | 0 |
| `xmlrpc.direct_pingback` | fault=0 | fault=0 | fault=0 | fault=0 | fault=0 | fault=0 | fault=none | fault=0 | fault=-32601 | fault=none | fault=-32601 |
| `xmlrpc.direct_login` | fault=403 | fault=403 | fault=403 | fault=403 | fault=403 | fault=405 | fault=none | fault=405 | fault=-32601 | fault=none | fault=-32601 |
| `write.wp-comments-post` | 302 | 403 | 403 | 403 | 302 | 302 | 302 | 302 | 403 | 403 | 403 |
| `write.rest_post` | 401 | 403 | 401 | 401 | 401 | 401 | 401 | 403 | 401 | 401 | 401 |
| `write.comment_landed_indb` | 1 | 0 | 0 | 0 | 1 | 1 | 1 | 1 | 0 | 0 | 0 |
| `html.comment_form` | 6 | 0 | 1 | 0 | 6 | 6 | 6 | 6 | 1 | 0 | 0 |
| `html.comments_block` | 4 | 0 | 4 | 1 | 4 | 4 | 4 | 4 | 4 | 0 | 0 |
| `auth.posts_edit` | 200 | 200 | 200 | 200 | 200 | 200 | 200 | 403 | 404 | 200 | 200 |
| `auth.settings` | 200 | 200 | 200 | 200 | 200 | 200 | 200 | 403 | 200 | 200 | 200 |
| `auth.types` | 200 | 200 | 200 | 200 | 200 | 200 | 200 | 403 | 403 | 200 | 200 |
| `auth.block_types` | 200 | 200 | 200 | 200 | 200 | 200 | 200 | 403 | 200 | 200 | 200 |
| `auth.comments` | 200 | 403 | 200 | 200 | 200 | 200 | 200 | 403 | 200 | 404 | 200 |
| `php.get_comments` | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 0 |
| `php.typed` | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 0 |
| `php.wp_count_comments` | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 0 | 1 | 0 |
| `php.comments_open` | 1 | 0 | 0 | 0 | 1 | 1 | 1 | 1 | 0 | 0 | 0 |
| `php.pings_open` | 1 | 0 | 0 | 0 | 1 | 1 | 0 | 0 | 0 | 0 | 0 |
| `php.number` | 1 | 0 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 0 | 0 |
| `php.supports` | 1 | 1 | 0 | 1 | 1 | 1 | 1 | 1 | 1 | 1 | 0 |
| `php.default_status` | open | open | closed | open | open | open | open | open | open | open | closed |

---

## Appendix: raw update-policy matrix

Every value is a live measurement on the 6.9.5 lab, 2026-09-16, one plugin active
at a time, each configured by the file of its name in
`tests/integration/probe-configs-updates/`. Produced by
`tests/integration/probe-updates.sh`.

How to read the rows:

- `truth.*` is asked of WordPress, not of the plugin. `would_install` is the
  highest offer that passes `WP_Automatic_Updater::should_update()`;
  `updates_screen` is what `get_core_updates()` returns, which is what
  `update-core.php` renders.
- `policy.*` filter rows are a **pair**: the filter is applied to `true` and then
  to `false`, and the two answers are printed together. `10` is a filter nothing
  has touched, `11` is forced on, `00` is forced off. Asking once with an invented
  default would measure the default.
- `own.says_*` counts occurrences on the plugin's own admin screens only;
  `core.says_*` counts them on `update-core.php`, both Site Health tabs and
  Settings → General. Each screen is reduced to `#wpbody-content` with
  `.update-nag` removed, so WordPress's own "WordPress 7.1 is available!" and
  "Get Version 7.1" do not score for anybody. Stock reads 0 on every `says_` row.
- `net.*` counts outbound requests to those api.wordpress.org endpoints across the
  whole run, including plugin activation.

| probe | stock | wp-auto-updater | EUM | companion | core-rollback | disable-all | webcraftic | update-control | disable-updates | disable-notices | Keel |
|---|---|---|---|---|---|---|---|---|---|---|---|
| `truth.running_version` | 6.9.5 | 6.9.5 | 6.9.5 | 6.9.5 | 6.9.5 | 6.9.5 | 6.9.5 | 6.9.5 | 6.9.5 | 6.9.5 | 6.9.5 |
| `truth.same_line_patch` | 6.9.7 | 6.9.7 | 6.9.7 | 6.9.7 | 6.9.7 | 6.9.7 | 6.9.7 | 6.9.7 | none | 6.9.7 | 6.9.7 |
| `truth.would_install` | 7.1 | 6.9.7 | 6.9.7 | 6.9.7 | 7.1 | 7.1 | 7.1 | 6.9.7 | none | 7.1 | 6.9.7 |
| `truth.updates_screen` | 7.1 | 7.1 | 7.1 | 7.1 | 7.1 | 7.1 | 7.1 | 7.1 | none | 7.1 | 7.1 |
| `policy.allow_minor` | 10 | 10 | 11 | 11 | 10 | 11 | 11 | 11 | 10 | 10 | 11 |
| `policy.allow_major` | 10 | 10 | 00 | 00 | 10 | 00 | 10 | 00 | 10 | 10 | 00 |
| `policy.allow_dev` | 10 | 10 | 00 | 10 | 10 | 00 | 10 | 00 | 10 | 10 | 00 |
| `policy.auto_update_core` | 10 | 10 | 11 | 10 | 10 | 11 | 10 | 10 | 10 | 10 | 10 |
| `policy.updater_disabled` | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 1 | 0 | 0 |
| `ui.own_screens` | 0 | 2 | 8 | 1 | 1 | 2 | 4 | 0 | 0 | 1 | 1 |
| `own.says_running_version` | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| `own.says_same_line_patch` | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 |
| `own.says_would_install` | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 0 |
| `own.says_insecure` | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 1 |
| `own.says_constant` | 0 | 0 | 0 | 0 | 0 | 2 | 0 | 0 | 0 | 0 | 0 |
| `core.says_same_line_patch` | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 4 |
| `core.says_insecure` | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 4 |
| `core.says_constant` | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| `net.stable_check` | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 1 |
| `net.version_check` | 0 | 0 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 8 | 0 |

### With `WP_AUTO_UPDATE_CORE` defined

The same lab and the same configurations, re-run twice with the constant set in
`wp-config.php`. Only plugins with a core policy control are included; the other
three have no setting for a constant to override.


**`WP_AUTO_UPDATE_CORE = true`**

| probe | stock | wp-auto-updater | EUM | companion | disable-all | webcraftic | update-control | Keel |
|---|---|---|---|---|---|---|---|---|
| `truth.would_install` | 7.1 | 7.1 | 6.9.7 | 6.9.7 | 7.1 | 7.1 | 6.9.7 | 7.1 |
| `policy.allow_minor` | 10 | 10 | 11 | 11 | 11 | 11 | 11 | 10 |
| `policy.allow_major` | 10 | 10 | 00 | 00 | 00 | 10 | 00 | 10 |
| `policy.auto_update_core` | 10 | 10 | 11 | 10 | 11 | 10 | 10 | 10 |
| `own.says_constant` | 0 | 0 | 0 | 0 | 6 | 0 | 0 | 1 |

**`WP_AUTO_UPDATE_CORE = false`**

| probe | stock | disable-all | EUM | Keel |
|---|---|---|---|---|
| `truth.would_install` | none | 7.1 | 6.9.7 | none |
| `policy.allow_minor` | 10 | 11 | 11 | 10 |
| `policy.allow_major` | 10 | 00 | 00 | 10 |
| `policy.auto_update_core` | 10 | 11 | 11 | 10 |
| `own.says_constant` | 0 | 6 | 0 | 1 |
