=== Keel Defaults ===
Contributors: dknauss
Tags: security, defaults, hardening, privacy, performance
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0-dev
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sane, individually-toggleable defaults for every new WordPress site — security, updates, privacy, UX, and performance.

== Description ==

Keel flips a menu of sensible defaults onto any WordPress install, each one a switch under **Settings → Keel**. Nothing is hidden and nothing is all-or-nothing — you can see exactly what the plugin does to your site and turn any piece off.

Every default is one entry in a single schema array that drives both the settings screen and the code that wires it to WordPress. A default is an opinionated filter behind a toggle.

**Status:** early development. This build imports the base and identity; ported hardening/admin defaults, a rebuilt Site Health surface, and multisite-aware seeding are in progress.

== External services ==

When the **Require strong passwords** default is enabled, Keel screens new passwords against the **Have I Been Pwned** Pwned Passwords range API (`https://api.pwnedpasswords.com`) to reject passwords found in known breaches. This uses k-anonymity: only the first five characters of the password's SHA-1 hash are ever sent — never the password, and never the full hash. No personal data is transmitted. The check runs only when a password is being set or changed and the default is on. It can be disabled with `define( 'KEEL_DISABLE_HIBP', true );` in `wp-config.php`, with the `keel_disable_hibp` filter, or by turning off the strong-password default. If the API is unreachable, or answers with a truncated or malformed response, the check is skipped and the password is allowed — a breach-data outage never blocks a password change. Have I Been Pwned is operated by Troy Hunt; see https://haveibeenpwned.com/Privacy and https://haveibeenpwned.com/API/v3 for its terms and privacy policy.

== Recommended wp-config.php hardening ==

A few defenses live best in `wp-config.php`, outside any plugin: they apply before plugins load and cannot be switched off from the dashboard. These are optional and independent of Keel — add the ones that fit your site.

`define( 'DISALLOW_FILE_EDIT', true );` — removes the built-in plugin and theme code editors, so a compromised admin account cannot edit PHP from the dashboard.

`define( 'WP_POST_REVISIONS', 10 );` — caps stored post revisions so the database does not grow without bound.

`define( 'AUTOSAVE_INTERVAL', 120 );` — lengthens the editor autosave interval. This is independent of Keel's Heartbeat throttle: both influence how often the editor saves in the background, but neither replaces or overrides the other.

== Changelog ==

= 0.1.0-dev =
* Initial scaffold: base imported from Better by Default (WPYEG, GPL-3.0-or-later) and re-identified as Keel. Work in progress.
* Licence is now GPL-2.0-or-later, matching WordPress core and the upstream 10up Experience code some defaults descend from. Relicensed by the sole author of the carried-over work; nothing is withdrawn, since "or later" still permits GPL-3 terms.
* Breach screening can be switched off with the KEEL_DISABLE_HIBP constant or the keel_disable_hibp filter, and a truncated or malformed range response is now rejected instead of parsed and cached.

== Credits ==

Keel is a de-branded evolution of Better by Default, the WordPress defaults plugin by WPYEG (the Edmonton WordPress meetup): https://github.com/WPYEG/Better-by-Default

Better by Default is published under the GPL-3.0-or-later; its sole author, who also wrote Keel, additionally licenses the portions carried over here under the GPL-2.0-or-later. Keel keeps Better by Default's core architecture — a single schema array that drives both the settings screen and the bootstrap, where each default is one array entry plus one hook — and adds further hardening and admin defaults adapted from the Pixel Managed Platform plugin (GPL-2.0-or-later).

Pixel Managed Platform is itself a hard fork of the 10up Experience plugin by 10up (GPL-2.0-or-later): https://github.com/10up/10up-experience — so several of Keel's adapted defaults ultimately descend from code first written for 10up Experience. Copyright in that work is retained by 10up and its contributors, and 10up retains its marks; Keel is not affiliated with or endorsed by 10up. See LICENSE for the full GPL-2.0 text.
