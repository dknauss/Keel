# Manual test script

For surfaces a human has to look at. The unit suite checks what the code returns; this
checks what a person reads, and it exists because every defect found in this feature by
someone other than the tests was found by looking at a screen.

Run it before a release that touches the patch-status panel, the ladder, the Updates
screen offer, or the installer.

## The site

**A disposable copy of the `keel-6.9` lab, never the lab itself.** This script installs
a patch, downloads 7.1 (which can upgrade the database), rewrites Keel's settings, adds
`wp-config.php` constants and clears update transients. Undoing all of that by hand on a
shared lab is too easy to get wrong, and a lab left on 6.9.7 or 7.1 no longer reproduces
anything for the next person. So every step below runs on a copy, and the copy is deleted
at the end.

The source is the `keel-6.9` lab in `~/Developer/wp-labs`: WordPress 6.9.6, a release
WordPress.org flags, with `wp-content/plugins/keel` symlinked to the repository. The copy
keeps that symlink, so it runs whatever is checked out. It is a symlink to the same
checkout, so don't switch branches while a run is in progress.

**A copy is only disposable if its database is its own, and setup checks that before
anything writes.** The labs run WordPress on SQLite: the database is the file
`wp-content/database/.ht.sqlite` inside the site directory, and `DB_NAME` and `DB_HOST` in
`wp-config.php` are placeholders nothing reads. Copying the directory therefore copies the
database. That is a property of these labs, not of WordPress. A lab on MySQL would share
one database with every copy of its files, and this script would then rewrite and upgrade
the source. So setup checks the copy's files before WordPress ever loads — the SQLite
drop-in is present, nothing redirects the database with `DB_DIR`, `DB_FILE` or
`WP_CONTENT_DIR`, and the database file is inside the copy — and stops otherwise. It checks
the files rather than asking WordPress, because loading WordPress opens the database, and
opening a shared database already changes it. The copied `db.php` still loads the SQLite
driver's code from the source lab's plugin folder; that is code, not data, and nothing here
writes to it.

Setup is one script so that a failed check stops it with a non-zero exit before the next
step runs, rather than printing a warning and carrying on. It ends by serving the copy;
Ctrl-C stops the server.

```bash
export SRC="$HOME/Developer/wp-labs/keel-6.9"
export D="$HOME/Developer/wp-labs/keel-manual-test"
export PORT=9379

bash -euo pipefail <<'SETUP'
fail() { echo "setup stopped: $*" >&2; exit 1; }

# 1. The source is still on a release WordPress.org flags; the panel is empty otherwise.
#    Checked, never changed.
[ "$(wp --path="$SRC" core version)" = 6.9.6 ] || fail "$SRC is not on 6.9.6"

# 2. A fresh copy. An existing $D is a previous run nobody threw away: cp would nest the
#    new copy inside it, and the cleanup below would delete whatever is there.
[ ! -e "$D" ] || fail "$D exists: throw the previous copy away first (see below)"
cp -RP "$SRC" "$D"                     # -P: copy the plugin symlink as a symlink
echo "keel -> $(readlink "$D/wp-content/plugins/keel")"   # expect the repository checkout

# 3. The copy's database must be inside the copy. Checked from the files first: loading
#    WordPress opens the database, and opening a shared one is already touching it.
grep -q "SQLite integration (Drop-in)" "$D/wp-content/db.php" 2>/dev/null \
  || fail "the copy is not on SQLite, so it shares the source's database; give it its own first"
for c in DB_DIR DB_FILE WP_CONTENT_DIR; do
  if wp --path="$D" config has "$c" 2>/dev/null; then
    fail "the copy defines $c, which can point its database outside the copy; give it its own first"
  fi
done
[ -f "$D/wp-content/database/.ht.sqlite" ] || fail "no database file inside the copy"
#    Only now, with the file proven to be the copy's, ask WordPress to confirm it.
fqdb=$(wp --path="$D" eval 'echo defined( "FQDB" ) ? FQDB : "";')
case "$fqdb" in
  "$D"/*) echo "database: $fqdb" ;;
  *) fail "WordPress resolved the copy's database to '$fqdb', not inside $D" ;;
esac

# 4. Give the copy its own address, updaters back on, cron off.
#    - WP_HOME/WP_SITEURL: the copied database still says port 9369, the source's.
#    - The source ships AUTOMATIC_UPDATER_DISABLED and WP_AUTO_UPDATE_CORE false, which
#      put every panel below in the blocked state; removed here, in the copy only.
#    - DISABLE_WP_CRON: without it, serving pages fires wp-cron and the copy patches
#      itself out of the state mid-test.
perl -0pi -e "s/^define\( 'AUTOMATIC_UPDATER_DISABLED', true \);\n//m; s/^define\( 'WP_AUTO_UPDATE_CORE', false \);\n//m; s|(/\* That's all, stop editing!)|define( 'WP_HOME', 'http://127.0.0.1:$PORT' );\ndefine( 'WP_SITEURL', 'http://127.0.0.1:$PORT' );\ndefine( 'DISABLE_WP_CRON', true );\n\$1|" "$D/wp-config.php"
grep -q "define( 'WP_HOME', 'http://127.0.0.1:$PORT' );" "$D/wp-config.php" || fail "WP_HOME was not added"
grep -q "define( 'DISABLE_WP_CRON', true );" "$D/wp-config.php" || fail "DISABLE_WP_CRON was not added"
if grep -qE "AUTOMATIC_UPDATER_DISABLED|WP_AUTO_UPDATE_CORE" "$D/wp-config.php"; then
  fail "an updater constant is still set in the copy"
fi

# 5. Fresh offers and a fresh security status. Keel caches WordPress.org's stable-check
#    answer for a day (KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT, keel_stable_check) and a
#    failed fetch for five minutes (KEEL_DEFAULTS_STABLE_CHECK_FAILED,
#    keel_stable_check_failed). While the failure is cached Keel does not try again, so
#    either transient would let the panels read old data. The constants name them, so
#    this cannot drift from the code. The map is then fetched, and setup stops unless it
#    arrives and flags this version, because an unflagged version leaves the panels empty.
wp --path="$D" eval '
  delete_site_transient( "update_core" );
  delete_site_transient( KEEL_DEFAULTS_STABLE_CHECK_TRANSIENT );
  delete_site_transient( KEEL_DEFAULTS_STABLE_CHECK_FAILED );
  wp_version_check( array(), true );
  $map     = keel_defaults_stable_check();
  $version = get_bloginfo( "version" );
  if ( empty( $map ) ) {
    WP_CLI::error( "WordPress.org stable-check returned no usable answer; the panels would have nothing to show." );
  }
  if ( "insecure" !== ( $map[ $version ] ?? "" ) ) {
    WP_CLI::error( "stable-check does not flag $version as insecure, so the patch panels will be empty." );
  }
  echo "stable-check: fresh, ", count( $map ), " versions; $version is insecure\n";
'

# 6. Serve the copy. Several workers: Site Health's REST and loopback checks call back
#    into the site while the page request is still open, and a single worker times out.
echo "serving http://127.0.0.1:$PORT (Ctrl-C to stop)"
export PHP_CLI_SERVER_WORKERS=4
exec php -S "127.0.0.1:$PORT" -t "$D"
SETUP
```

Log in at http://127.0.0.1:9379/wp-login.php as the copy's administrator
(`wp --path=$D user list --role=administrator`; the copy has the source's users).
The server holds the first terminal, so run everything below in a second one, after
setting `D` there too: `D="$HOME/Developer/wp-labs/keel-manual-test"`. Every command
below uses `$D`, the copy.

### Throwing the copy away

Run this when you finish, pass or fail. Stop the server (Ctrl-C in its terminal), then:

```bash
D="$HOME/Developer/wp-labs/keel-manual-test"
SRC="$HOME/Developer/wp-labs/keel-6.9"
rm -rf "$D"
wp --path="$SRC" core version   # still 6.9.6: the source was never touched
```

There is nothing else to restore. The core version, the database, Keel's settings, the
transients, `wp-config.php` and the server all belonged to the copy.

## Switching between the two states that matter

Keel's own `core_update_policy` decides which one you get, which is worth understanding
before testing: the site option `auto_update_core_major` can say `enabled` and Keel will
still hold core to minor releases. That is the plugin working, and it means the "skipped"
state needs Keel's policy changed rather than WordPress's.

```bash
# State A — Keel holding the line. Core takes the patch.
wp --path=$D eval '$o = get_option( KEEL_DEFAULTS_OPTION, array() ); $o["core_update_policy"] = "minor"; update_option( KEEL_DEFAULTS_OPTION, $o );'

# State B — majors allowed. Core steps over the patch. This is the case the feature argues about.
wp --path=$D eval '$o = get_option( KEEL_DEFAULTS_OPTION, array() ); $o["core_update_policy"] = "all"; update_option( KEEL_DEFAULTS_OPTION, $o );'
```

Flush the offer cache after either: `wp --path=$D eval 'delete_site_transient("update_core"); wp_version_check( array(), true );'`

## What to look at

### 1. Site Health → Status, "Security patch status"

| | State A | State B |
| --- | --- | --- |
| Verdict | says the patch will arrive on a scheduled check | says WordPress would install the newer release instead, and that this patch will not arrive on its own |
| Ladder, patch rung | `← security fix · WordPress installs this` | `← security fix` |
| Ladder, newest rung | no marker | `← WordPress installs this` |
| Arrows per rung | exactly one, whatever labels it carries | same |
| Install button | present | present |

The verdict and the ladder must agree. A panel that promises a scheduled install above a
ladder marking a different release is the defect this release fixed; it is worth
re-reading both every time.

### 2. Dashboard → Updates

The offer renders under the automatic-update settings and above WordPress's own update
block. Check:

- It names the patch, and names what the screen is offering instead.
- In state B it says the patch is passed over. In state A it does not.
- It does **not** repeat the panel's explanation that the Updates screen will not offer
  the release. That sentence is true in Site Health and absurd here.
- The button submits. Pressing it is the test — see below.

### 3. The install, end to end

Press **Install WordPress x.y.z now** from either screen. Expect a redirect back, a
success notice naming the release, and the offer gone. Then confirm the site really moved:

```bash
wp --path=$D core version
```

A completed install reported as a failure is a defect that shipped once already. So is a
success notice on a site that did not move.

Reload after the install: the result shows once and clears, so a second reload must not
repeat it.

### 4. The states with nothing to offer

| Set up | Expect |
| --- | --- |
| `wp --path=$D core download --version=7.1 --force --skip-content` | no panel finding, no Updates-screen offer |
| A release flagged with no patched release on its line | the panel says moving to a maintained line is the only remedy, and offers no install |
| `define( 'DISALLOW_FILE_MODS', true )` in `wp-config.php` | the blocker is named, no install button, and nothing claims the release cannot be installed at all — Keel refuses, a deployment workflow may not |
| `define( 'AUTOMATIC_UPDATER_DISABLED', true )` in `wp-config.php` | the blocker is named, the install button is present, and the panel says the patch can still be installed deliberately — never "Keel will not offer a deliberate install" above a working button |

## Recording it

Add a dated section to this file: what was run, which states, what was seen, and anything
that looked wrong even if it passed. "Looked right" is a result worth writing down, because
the alternative is re-deriving next time whether anyone actually opened the screen.
