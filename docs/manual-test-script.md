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

```bash
SRC=~/Developer/wp-labs/keel-6.9
D=~/Developer/wp-labs/keel-manual-test
PORT=9379

# 1. The source is still on a release WordPress.org flags; the panel is empty otherwise.
#    Checked, never changed.
wp --path=$SRC core version   # expect 6.9.6

# 2. A fresh copy. If $D already exists, a previous run was not thrown away: do that
#    first (see the end of this section), or cp nests the new copy inside the old one.
[ -e "$D" ] && echo "$D exists: throw the previous copy away first"
cp -RP "$SRC" "$D"                     # -P: copy the plugin symlink as a symlink
readlink "$D/wp-content/plugins/keel"  # expect the repository checkout

# 3. Give the copy its own address, updaters back on, cron off.
#    - WP_HOME/WP_SITEURL: the copied database still says port 9369, the source's.
#    - The source ships AUTOMATIC_UPDATER_DISABLED and WP_AUTO_UPDATE_CORE false, which
#      put every panel below in the blocked state; removed here, in the copy only.
#    - DISABLE_WP_CRON: without it, serving pages fires wp-cron and the copy patches
#      itself out of the state mid-test.
perl -0pi -e "s/^define\( 'AUTOMATIC_UPDATER_DISABLED', true \);\n//m; s/^define\( 'WP_AUTO_UPDATE_CORE', false \);\n//m; s|(/\* That's all, stop editing!)|define( 'WP_HOME', 'http://127.0.0.1:$PORT' );\ndefine( 'WP_SITEURL', 'http://127.0.0.1:$PORT' );\ndefine( 'DISABLE_WP_CRON', true );\n\$1|" "$D/wp-config.php"
grep -nE "AUTOMATIC_UPDATER_DISABLED|WP_AUTO_UPDATE_CORE|WP_HOME|WP_SITEURL|DISABLE_WP_CRON" "$D/wp-config.php"
# expect WP_HOME, WP_SITEURL and DISABLE_WP_CRON, and neither updater constant

# 4. Fresh offers.
wp --path=$D eval 'delete_site_transient("update_core"); delete_site_transient("keel_defaults_stable_check"); wp_version_check( array(), true );'

# 5. Serve the copy. Several workers: Site Health's REST and loopback checks call back
#    into the site while the page request is still open, and a single worker times out.
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:$PORT -t $D
```

Log in at http://127.0.0.1:9379/wp-login.php as the copy's administrator
(`wp --path=$D user list --role=administrator`; the copy has the source's users).
Every command below uses `$D`, the copy.

### Throwing the copy away

Run this when you finish, pass or fail. Stop the server (Ctrl-C in its terminal), then:

```bash
rm -rf "$D"
wp --path=$SRC core version   # still 6.9.6: the source was never touched
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
