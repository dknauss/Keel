# Data inventory

Everything Keel stores, every request it sends off the site, and what happens to
each when the plugin is deactivated or deleted. Use it for a privacy review, a
migration, or to check a database after uninstalling.

Keel stores no passwords or password hashes, no IP addresses and no email
content. It sets no cookies and loads nothing from a third-party host on
the front end.

## Options

| Name | Holds | Autoloaded | Scope |
| --- | --- | --- | --- |
| `keel_settings` | Every setting's value, as one array. | yes | site |
| `keel_settings_data_version` | Which data migration the site has run. | yes | site |
| `keel_environment_override_host` | The host the **Environment** setting was chosen on, so a copied database does not inherit it. | no | site |
| `keel_network_settings` | The settings a Super Admin decided under Network Policy. | network option | network |
| `keel_autoload_scan_lock` | A short lock while the autoload report is being collected. | no | site |
| `keel_hibp_v2_<network>_state` | The shared breach-lookup budget, plus the time and kind of the last sampled failure and the last good reply. No password data. | no | main site of each network (`0` on single site) |
| `keel_hibp_v2_<network>_slot_00` to `_slot_63` | 64 fixed cache slots for breach-lookup replies, kept 12 hours. Each holds the public reply for one five-character hash prefix, and that prefix. Hundreds of thousands of possible passwords share any one prefix, so it does not identify a password. | no | main site of each network |
| `keel_defaults` | The settings option's name before the rename. Nothing writes it now; older installs may still have it. | yes | site |

## Transients

| Name | Holds | Lifetime |
| --- | --- | --- |
| `keel_policy_divergence` | Which settings were last seen not producing the value they ask for, with a fingerprint of the active plugins. | 1 hour |
| `keel_shortcode_residue` | Up to 20 recent post IDs and 10 missing shortcode tag names per post. | 1 hour |
| `keel_autoload_report` | The largest autoloaded option names and their sizes. Never their values. | 1 hour, 5 minutes after an error |
| `keel_cron_report` | Overdue and frequent cron hook names, schedules and lateness. No arguments or callback paths. | 1 hour, 5 minutes after an error |
| `keel_backport_install_result_<user ID>` | The result of a security patch install, shown once after the redirect back to Site Health. | 5 minutes |
| `keel_stable_check` (site transient) | WordPress.org's map of which core versions are current, outdated or insecure. | 1 day |
| `keel_stable_check_failed` (site transient) | Marks that the stable check could not be reached, so it is not retried at once. | 5 minutes |

## User meta

The user table is shared by the whole network on multisite, so these are
network-wide.

| Key | Holds | Written when |
| --- | --- | --- |
| `keel_last_login` | Time of the last login. | Every login, while **Admin List Tables** is set to Tidy + helper columns, for the **Last login** column. |
| `keel_password_screened` | When a login breach re-check last got a definite answer. | At login, at most once per re-check interval, while **Password Strength** is on. |
| `keel_password_breached` | When the password was found in a known breach. Cleared when the password changes. | Same as above. |
| `keel_conflicts_dismissed` | A fingerprint of the plugin overlap the person dismissed, so the notice comes back if it changes. | When the person dismisses the notice. |
| `keel_backport_dismissed` | The security release version the person dismissed. | When the person dismisses it. |

All values are timestamps, versions or fingerprints. None hold the password or
anything derived from it.

## Scheduled events

| Hook | Runs |
| --- | --- |
| `keel_defaults_cron_collect` | Once, five seconds after Site Health asks for the cron report. Not recurring. |
| `keel_defaults_autoload_collect` | Once per batch while the autoload report is being collected. Not recurring. |

## Requests to other services

| Destination | Sent | When |
| --- | --- | --- |
| `https://api.pwnedpasswords.com/range/<first five hex characters of the SHA-1>` | The first five characters of the password's SHA-1 hash, with padding requested so the reply size reveals nothing. The password and the rest of its hash never leave the site. | When a password is set or changed, and at login at most once per re-check interval, while **Password Strength** is on. Rate-limited by the shared budget. Turn it off with `KEEL_DISABLE_HIBP` or the `keel_disable_hibp` filter. |
| `https://api.wordpress.org/core/stable-check/1.0/` | Nothing beyond a normal WordPress HTTP request, whose user agent names the WordPress version and the site's address. | At most once a day, when Site Health or the Updates screen needs to know whether the installed version is flagged insecure. |

WordPress's own update checks are not Keel's. Site Health → Info → Keel
describes what those send, without sending anything.

## Deactivating and deleting

**Deactivating** removes nothing. Every setting is still there when the plugin
comes back.

**Deleting** the plugin from the Plugins screen runs `uninstall.php`, which
removes every option, transient, user meta key and scheduled event above. On
multisite it does so for every site, then removes the network option and the
shared breach-lookup rows for each network. It also clears breach-lookup cache
rows left by older versions, and the old `keel_defaults` option, but only when
that option still looks like Keel's own settings array.

If you delete the plugin files by hand instead, nothing is removed. Delete
through WordPress, or remove the names above with WP-CLI.
