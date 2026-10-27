# Constants and locked settings

Some Keel behavior is decided in `wp-config.php` rather than on the settings
screen. This page lists the constants Keel defines for you to set, and the
WordPress constants that lock a Keel setting so its control cannot be changed.
Filters are in the [hooks reference](hooks.md).

## Constants Keel reads

Define these in `wp-config.php`, above the line that says to stop editing.

| Constant | Effect | Wins over |
| --- | --- | --- |
| `KEEL_DISABLE_HIBP` | `true` skips the Have I Been Pwned lookup when a password is set and at login. Length, the blocklist and the other password rules still apply. Site Health reports screening as switched off in code, not as a fault. | The **Password Strength** setting's breach check. The `keel_disable_hibp` filter can also switch it off, but cannot switch it back on. |
| `KEEL_ALLOW_NONPRODUCTION_MAIL` | `true` lets a non-production site send email even while **Non-Production Email** is on. | The setting. The `keel_suppress_nonproduction_mail` filter still runs afterwards and has the last word. |

```php
define( 'KEEL_DISABLE_HIBP', true );
define( 'KEEL_ALLOW_NONPRODUCTION_MAIL', true );
```

Keel also defines constants of its own in `keel.php`, such as
`KEEL_DEFAULTS_OPTION` and `KEEL_DEFAULTS_VERSION`. Those are internal names, not
settings, and are not meant to be set from `wp-config.php`.

## WordPress constants that lock a setting

When a WordPress constant already decides something Keel offers, Keel disables
that control, shows the value the constant enforces, and says which constant to
remove. The settings screen counts these under **Locked settings**. The stored
Keel value is kept underneath, so removing the constant brings it back.

| Constant | Setting locked | What the control shows |
| --- | --- | --- |
| `AUTOMATIC_UPDATER_DISABLED` = `true` | Core Auto-Updates, Plugin Auto-Updates, Theme Auto-Updates, Translations | No automatic updates. WordPress installs no background updates at all. |
| `DISALLOW_FILE_MODS` = `true` | The same four | The same, because WordPress cannot write its own files. |
| `WP_AUTO_UPDATE_CORE` | Core Auto-Updates | `false` reads as no automatic core updates, `'minor'` as maintenance and security releases only, and `true` or a pre-release value (`'beta'`, `'rc'`, `'development'`, `'branch-development'`) as all releases. Anything else leaves WordPress on its own default. |
| `WP_POST_REVISIONS` set to anything but `true` | Post Revision Retention | The number the constant sets. `false` shows 0. WordPress itself defines `true` when `wp-config.php` says nothing, so that value does not lock the control. |
| `DISALLOW_UNFILTERED_HTML` = `true` | Unfiltered HTML | A note that the constant already removes unfiltered HTML from every role, so Keel's narrower restriction adds nothing. |

The first two are checked before `WP_AUTO_UPDATE_CORE`. With background updates
off, the core policy constant is not what decides, so the note names the
constant that does. The rules are in `keel_defaults_config_lock()` and
`keel_defaults_config_locked_value()` in `includes/settings-policy.php`.

## Locked by network policy

On multisite, a Super Admin can decide any setting for every site under
**Network Admin → Settings → Network Policy**. Those settings are locked on each
site with a note pointing there, and are counted separately under **Locked
settings**. Each site's own saved value is left untouched, so unticking the
setting at network level gives every site back exactly what it had.
A `wp-config.php` lock still wins over network policy: the network screen shows
the same lock, and any policy saved underneath it is kept.

Plugin and theme auto-updates are always locked on subsites, because plugin and
theme files are shared by the whole network. Network policy decides them if set,
otherwise the main site's setting does. See `keel_defaults_network_lock()` in
`includes/network.php`.

## Environment

`WP_ENVIRONMENT_TYPE`, as a constant or a server environment variable, tells
Keel and every other plugin which environment the site is. It does not lock
Keel's **Environment** setting. That setting changes only what Keel uses, and
Site Health says when it is covering for a wrong WordPress label. The order of
precedence, the local host fallback and the copied-database guard are in
[Environment detection](environment-detection.md).
