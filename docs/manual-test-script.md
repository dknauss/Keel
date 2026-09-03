# Manual test script

For surfaces a human has to look at. The unit suite checks what the code returns; this
checks what a person reads, and it exists because every defect found in this feature by
someone other than the tests was found by looking at a screen.

Run it before a release that touches the patch-status panel, the ladder, the Updates
screen offer, or the installer.

## The site

`keel-test` at http://localhost:8883, admin / `keel-demo-2026`. The plugin is symlinked
to the repository, so the site runs whatever is checked out.

Two things have to be true or nothing below reproduces:

```bash
D=~/Studio/keel-test

# 1. On a release WordPress.org flags. The panel is empty otherwise.
wp --path=$D core download --version=6.9.6 --force --skip-content
wp --path=$D option update db_version "$(grep -m1 'wp_db_version =' $D/wp-includes/version.php | grep -o '[0-9]\+')"

# 2. Cron off, or the site patches itself out of the state mid-test.
#    (wp-config.php should already define DISABLE_WP_CRON for this site.)

wp --path=$D eval 'delete_site_transient("update_core"); delete_site_transient("keel_defaults_stable_check"); wp_version_check( array(), true );'
```

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

## Recording it

Add a dated section to this file: what was run, which states, what was seen, and anything
that looked wrong even if it passed. "Looked right" is a result worth writing down, because
the alternative is re-deriving next time whether anyone actually opened the screen.
