# Integration harnesses

Two scripts, asking different questions.

**`verify-behaviors.sh`** — does each setting register the filters it should, and
do they answer correctly? Runs against a real WordPress load, so the
schema-driven bootstrap re-wires with each value, but it stops at the filter.

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
