=== Keel Defaults ===
Contributors: dknauss
Tags: security, defaults, hardening, privacy, performance
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0-dev
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Sane, individually-toggleable defaults for every new WordPress site — security, updates, privacy, UX, and performance.

== Description ==

Keel flips a menu of sensible defaults onto any WordPress install, each one a switch under **Settings → Keel**. Nothing is hidden and nothing is all-or-nothing — you can see exactly what the plugin does to your site and turn any piece off.

Every default is one entry in a single schema array that drives both the settings screen and the code that wires it to WordPress. A default is an opinionated filter behind a toggle.

**Status:** early development. This build imports the base and identity; ported hardening/admin defaults, a rebuilt Site Health surface, and multisite-aware seeding are in progress.

== External services ==

When the **Require strong passwords** default is enabled, Keel screens new passwords against the **Have I Been Pwned** Pwned Passwords range API (`https://api.pwnedpasswords.com`) to reject passwords found in known breaches. This uses k-anonymity: only the first five characters of the password's SHA-1 hash are ever sent — never the password, and never the full hash. No personal data is transmitted. The check runs only when a password is being set or changed and the default is on. It can be disabled with the `keel_disable_hibp` filter (or by turning off the strong-password default). Have I Been Pwned is operated by Troy Hunt; see https://haveibeenpwned.com/Privacy and https://haveibeenpwned.com/API/v3 for its terms and privacy policy.

== Recommended wp-config.php hardening ==

A few defenses live best in `wp-config.php`, outside any plugin: they apply before plugins load and cannot be switched off from the dashboard. These are optional and independent of Keel — add the ones that fit your site.

`define( 'DISALLOW_FILE_EDIT', true );` — removes the built-in plugin and theme code editors, so a compromised admin account cannot edit PHP from the dashboard.

`define( 'WP_POST_REVISIONS', 10 );` — caps stored post revisions so the database does not grow without bound.

`define( 'AUTOSAVE_INTERVAL', 120 );` — lengthens the editor autosave interval. This is independent of Keel's Heartbeat throttle: both influence how often the editor saves in the background, but neither replaces or overrides the other.

== Changelog ==

= 0.1.0-dev =
* Initial scaffold: base imported from Better by Default (WPYEG, GPL-3.0-or-later) and re-identified as Keel. Work in progress.

== Credits ==

Keel is a de-branded evolution of Better by Default, the WordPress defaults plugin by WPYEG (the Edmonton WordPress meetup): https://github.com/WPYEG/Better-by-Default

Better by Default is licensed GPL-3.0-or-later, and Keel is a derivative work under the same licence; original copyright is retained by its authors. Keel keeps Better by Default's core architecture — a single schema array that drives both the settings screen and the bootstrap, where each default is one array entry plus one hook — and adds further hardening and admin defaults adapted from the Pixel Managed Platform plugin (GPL-2.0-or-later, which composes into GPL-3.0). See LICENSE for the full GPL-3.0 text.
