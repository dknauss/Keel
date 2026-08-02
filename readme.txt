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

== Changelog ==

= 0.1.0-dev =
* Initial scaffold: base imported from Better by Default (WPYEG, GPL-3.0-or-later) and re-identified as Keel. Work in progress.

== Credits ==

Keel is a de-branded evolution of Better by Default (WPYEG), with further defaults adapted from the Pixel Managed Platform plugin. See CREDITS.md.
