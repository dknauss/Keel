# Integration harnesses

Two scripts, asking different questions.

**`verify-behaviors.sh`** — does each setting register the filters it should, and
do they answer correctly? Runs against a real WordPress load, so the
schema-driven bootstrap re-wires with each value, but it stops at the filter.
It also runs `filter-semantics.php` through the real WordPress plugin API, pinning
that every callback executes on `pre_wp_mail` and `comments_pre_query`; standalone
hook stubs are not allowed to redefine that execution model. Keel's overlap
detector does not replay either hook—or any foreign callback—to perform diagnosis.

**`probe-teardown.sh`** — with the plugin active, what does the site still
*serve*? Thirty probes over HTTP plus a direct database check.

The gap between them is the point. A comment-feed fix once passed every
assertion in `verify-behaviors.sh` while serving a `301` redirect loop to a URL
that never existed: `set_404()` cleared the query flags, `redirect_canonical()`
did not bail on a 404, and it guessed `/post/feed/` → `/post/feed/feed/`. No
filter-level test can see that. The probe caught it on the first run.

`probe-teardown.sh` is deliberately plugin-agnostic — it reports what the site
does, not what any plugin claims. That is what makes it a comparison tool. The
numbers in [`docs/competitive-teardown-matrix.md`](../../docs/competitive-teardown-matrix.md)
came from running it against ten plugins on one install.

**`verify-network.sh`** — does the multisite behaviour hold on a real network?
Everything Keel does on multisite was proven only against stubs the plugin's own
tests define: `tests/multisite-seeding.php` declares its own `switch_to_blog()`,
`get_sites()` and `restore_current_blog()`, and `tests/network-policy.php`
declares its own `get_site_option()`. Those tests pin the logic, but a stub that
is wrong about WordPress lets every one of them pass while the plugin does the
wrong thing on a real network.

```bash
PROBE_PATH=/path/to/multisite-wp bash tests/integration/verify-network.sh
```

It needs a real network (`wp core multisite-convert`), creates one throwaway
subsite, removes it again, and restores whatever network policy it found — so it
is safe to point at a network somebody is using. It clears policy explicitly
before its baseline assertion rather than assuming the network starts clean; the
first draft did assume that and reported a failure that was ambient state.

---

## Running the probe

```bash
PROBE_URL=http://127.0.0.1:9314 \
PROBE_PATH=/path/to/throwaway-wp \
  bash tests/integration/probe-teardown.sh "keel — comments off, REST closed"
```

Both variables are required and have no defaults. A default here would mean
probing whatever happened to be running, and the harness writes comments and
deletes them again.

Output is one `key value` per line, diffable between runs:

```
rest.comments.pretty      401 (n=err)
feed.site_comments        404
xmlrpc.direct_pingback    fault=-32601
write.comment_landed_indb 0
php.get_comments=0 typed=0 wp_count_comments=0 comments_open=0 …
auth.posts_edit           200
```

Capture a baseline with no plugins active first. A cell only means something
against what stock WordPress does on the same install.

---

## Comparing plugins

`probe-teardown.sh` measures whatever the site currently does. `probe-plugin.sh`
adds the other half of a comparison — getting a plugin into the state its own
settings screen would produce, then measuring, then deactivating:

```bash
PROBE_URL=http://127.0.0.1:9314 PROBE_PATH=/tmp/probe-wp \
  bash tests/integration/probe-plugin.sh disable-comments "disable-comments 2.8.0 (1M)"
```

Configuration lives in `probe-configs/<slug>.php` and is optional; plugins with
nothing to configure simply have no file. Each one says what it sets and why,
including where it deliberately leaves something alone — Admin and Site
Enhancements keeps its feed-disabling off so a 404 on a comment feed can be
attributed, and Keel keeps its XML-RPC endpoint block off so the per-method rows
mean something.

**A plugin measured with default settings is a plugin measured switched off**,
and the numbers then read as a damning finding rather than an untouched
checkbox. That is the single easiest way to publish a wrong comparison.

### Why configuration runs before activation, with `--skip-plugins`

Because a teardown plugin changes the state its own configuration is derived
from.

Disable Comments RB's "everywhere" option does not store a flag it reads later —
it stores a snapshot of every post type that supported comments when Save was
pressed. If the plugin is already active from a previous run, it has *removed*
that support before the config file can look. The config then stores an empty
list, the plugin does nothing, and the probe reports it closing nothing.

It works the first time and silently unconfigures itself on every run after.
That is not hypothetical: re-verifying the published matrix from the committed
harness produced seven differences, all in that one column, all the harness's
fault rather than the plugin's. `probe-plugin.sh` enforces the ordering so
nobody has to remember it, and the config file exits non-zero rather than
storing an empty list if it is ever run the wrong way.

## Building a throwaway install

Do **not** point this at a site you care about, or at a Studio site with other
plugins on it. Two of those bit us: a managed plugin loaded as an mu-plugin
answered comment queries empty and stripped XML-RPC methods, silently poisoning
what looked like a clean baseline.

```bash
# 1. Core, without wp-cli's extractor (it exhausts memory on some setups)
mkdir -p /tmp/probe-wp && tar xzf ~/.wp-cli/cache/core/wordpress-*.tar.gz \
  -C /tmp/probe-wp --strip-components=1

# 2. SQLite, so there is no database server to arrange
cp -R /path/to/sqlite-database-integration /tmp/probe-wp/wp-content/plugins/
cp /tmp/probe-wp/wp-content/plugins/sqlite-database-integration/db.copy \
   /tmp/probe-wp/wp-content/db.php
#    then replace {SQLITE_IMPLEMENTATION_FOLDER_PATH} in db.php with that plugin path

# 3. Serve it. Pick a port nothing else holds — see the note below.
php -S 127.0.0.1:9314 -t /tmp/probe-wp /tmp/probe-wp/router.php

# 4. Install, and give the probe something to measure
wp core install --path=/tmp/probe-wp --url=http://127.0.0.1:9314 \
  --title="Probe" --admin_user=admin --admin_password=probe-pass --admin_email=a@b.invalid --skip-email
wp option update permalink_structure '/%postname%/' --path=/tmp/probe-wp
wp rewrite flush --path=/tmp/probe-wp
wp post update 1 --ping_status=open --path=/tmp/probe-wp
```

`router.php` alongside `index.php`:

```php
<?php
// Real web servers always define QUERY_STRING; php -S sets it only when non-empty,
// and a plugin reading it unguarded then emits warnings that break every response.
if ( ! isset( $_SERVER['QUERY_STRING'] ) ) { $_SERVER['QUERY_STRING'] = ''; }
$uri = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$f   = __DIR__ . $uri;
if ( '/' !== $uri && file_exists( $f ) && ! is_dir( $f ) && '.php' !== substr( $f, -4 ) ) { return false; }
if ( '/' !== $uri && file_exists( $f ) && ! is_dir( $f ) ) { require $f; return true; }
require __DIR__ . '/index.php';
```

---

## Things that will waste an hour if you do not know them

**A lab pinned to an old WordPress will not stay there.** A throwaway install
happily updates itself in the background, and it does not ask. The 6.4 lab built
for the older-WordPress run was WordPress 7.0.3 by the next morning — confirmed by
`auto_core_update_notified`, which records a successful automatic core update. The
measurement taken the night before was valid, because the version was checked in
the same session; a re-run the next day would have measured 7.0.3 while every note
around it said 6.4.

Build any version-pinned lab with the updater switched off, and check the version
again immediately before you trust a result:

```bash
wp config set AUTOMATIC_UPDATER_DISABLED true --raw --path=/tmp/probe-wp
wp config set WP_AUTO_UPDATE_CORE false --raw --path=/tmp/probe-wp
wp core version --path=/tmp/probe-wp    # before every run, not just the first
```

**Check the port is yours.** `php -S` prints `Address already in use` and exits,
but a stale server from another session answers happily on that port — so the
probe runs, returns plausible numbers, and measures somebody else's install.
Confirm with `lsof -nP -iTCP:9314 -sTCP:LISTEN` before trusting a single result.

**`ping_status` must be open on post 1** or `X-Pingback` never appears and the
header probe reads 0 for every plugin, including none.

**Build the probe against the actual account.** A probe asserting that
`admin-secret-2026` is refused proves nothing on an install whose administrator
is called something else — it will pass while the feature is broken. Read the
login from the site rather than assuming it.

**Deactivate everything for the baseline**, and check that "everything" includes
mu-plugins and dropins. `wp plugin list` does not show an mu-plugin loader.

**The authenticated probes need a real session token.** The harness mints one
per run and never writes it to disk; it is a live login cookie. If those probes
return `403`, the nonce and the cookie disagree — usually because the cookie was
generated without a session token, which `wp_validate_auth_cookie()` rejects.
