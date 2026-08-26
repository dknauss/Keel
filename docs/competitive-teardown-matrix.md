# How other plugins do the teardowns Keel does

A measured comparison of the most-installed wordpress.org plugins that overlap with
Keel's disable-style defaults, focused on the two surfaces that are easy to get
wrong: **the REST API** and **the comment teardown**.

Nothing here is taken from readmes or marketing copy. Every cell in the matrix is a
live HTTP or PHP probe against a real install with that plugin active and configured
the way its own settings screen would configure it.

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

Raw per-probe output is in the appendix.

## The field

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
| **Keel** 0.5.7 | — | live |
| [Classic Editor](https://wordpress.org/plugins/classic-editor/) 1.7.0 | 9,000,000+ | live |
| [Disable Gutenberg](https://wordpress.org/plugins/disable-gutenberg/) 3.3.2 | 500,000+ | live |
| [Clearfy](https://wordpress.org/plugins/clearfy/) 2.4.3 | 50,000+ | live |
| [WP Master Toolkit](https://wordpress.org/plugins/wpmastertoolkit/) 2.22.0 | 5,000+ | live |

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

---

## Appendix: raw probe matrix

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
