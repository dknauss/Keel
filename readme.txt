=== Keel Defaults ===
Contributors: dknauss
Tags: security, defaults, hardening, privacy, performance
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0-dev
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

More than 30 sane WordPress defaults, each one a switch you can see and turn off — security, updates, privacy, UX, and performance.

== Description ==

Keel flips a menu of sensible defaults onto any WordPress install, each one a switch under **Settings → Keel**. Nothing is hidden and nothing is all-or-nothing — you can see exactly what the plugin does to your site and turn any piece off.

Every default is one entry in a single schema array that drives both the settings screen and the code that wires it to WordPress. A default is an opinionated filter behind a toggle.

**Disabling something means it is actually disabled.** Measured against nine of the most-installed plugins in this space — every result a live request against a real install, not a readme claim — Keel is the only one where "comments are off" is true below the presentation layer. The others stop at the theme template and the REST route: ask the database directly, with `get_comments()`, and the comments are still there. The same care runs through the rest — closing the REST API also removes the link advertising it, and disabling comments also stops the comment feed answering.

**Site Health shows you the whole posture**, read-only: every default and its current state on one screen, so you can see what the site is actually doing without clicking through tabs. It also reports when another plugin is setting the same defaults, which otherwise fails silently.

**Status:** pre-release. The feature set is frozen at 37 defaults — the ported hardening and admin defaults, the Site Health surface, and multisite-aware seeding are all in. What remains before a wordpress.org release is verification and packaging, not features.

== External services ==

When the **Require strong passwords** default is enabled, Keel screens new passwords against the **Have I Been Pwned** Pwned Passwords range API (`https://api.pwnedpasswords.com`) to reject passwords found in known breaches. This uses k-anonymity: only the first five characters of the password's SHA-1 hash are ever sent — never the password, and never the full hash. No personal data is transmitted. The check runs only when a password is being set or changed and the default is on. It can be disabled with `define( 'KEEL_DISABLE_HIBP', true );` in `wp-config.php`, with the `keel_disable_hibp` filter, or by turning off the strong-password default. If the API is unreachable, or answers with a truncated or malformed response, the check is skipped and the password is allowed — a breach-data outage never blocks a password change. Have I Been Pwned is operated by Troy Hunt; see https://haveibeenpwned.com/Privacy and https://haveibeenpwned.com/API/v3 for its terms and privacy policy.

== Recommended wp-config.php hardening ==

A few defences live best in `wp-config.php`, outside any plugin: they apply before plugins load and cannot be switched off from the dashboard. These are optional and independent of Keel — add the ones that fit your site.

`define( 'DISALLOW_FILE_EDIT', true );` — removes the built-in plugin and theme code editors, so a compromised admin account cannot edit PHP from the dashboard.

`define( 'WP_POST_REVISIONS', 10 );` — caps stored post revisions so the database does not grow without bound.

`define( 'AUTOSAVE_INTERVAL', 120 );` — lengthens the editor autosave interval. This is independent of Keel's Heartbeat throttle: both influence how often the editor saves in the background, but neither replaces or overrides the other.

== Installation ==

1. Copy the plugin folder into `wp-content/plugins/`, or upload the built zip through **Plugins → Add New → Upload Plugin**.
2. Activate it. The documented defaults are seeded on activation; nothing is applied before that.
3. Visit **Settings → Keel** and turn off anything you do not want.

Every default is a switch, and the switches are the whole interface. Defaults that can change behaviour or break an integration — cross-origin framing, requiring authentication for all REST requests, the Classic editor — are off out of the box and opt-in.

Deactivating stops every default at once; stored settings are kept so reactivating restores the same configuration. Uninstalling removes them.

== Frequently Asked Questions ==

= Will this break my site? =

The defaults that are on out of the box are low-risk. The ones that can break something are off and opt-in, and each says on the settings screen what it will cost you — for example that blocking cross-origin framing also blocks legitimate embeds. Requiring authentication for REST is the one place Keel spends a little of that strictness back: `oembed/1.0` stays reachable, so other sites can still embed your posts when every other route is closed.

= Does it send anything off my site? =

One thing, and only when the strong-password default is on: the first five characters of a password's SHA-1 hash, to check it against known breaches. Never the password, never the full hash, no personal data. See **External services** above for the full description and how to switch it off.

= Does it delete anything? =

No. Disabling comments hides them and closes the forms; nothing is removed from the database, and turning the default off brings every comment back. The same holds for the other content defaults.

= Can I set these in code instead? =

Yes. Every default reads its value through the plugin's own option, and the behaviours are filterable — `keel_weak_roles`, `keel_disable_hibp`, `keel_comment_blocks`, `keel_allowed_comment_types` and others. A `wp-config.php` constant always wins over the settings screen where one applies; the screen says so when it is being overridden.

= I run multisite. Does the password policy apply per site? =

The setting is stored per site; the effect is not. WordPress keeps one user table for the whole network, so a password is checked against whichever site it is being set on — and once set, it is that person's password everywhere. Exempting a role on one subsite decides what happens when a password is changed *there*; it does not exempt those accounts from another site's policy. In practice the strictest site on the network sets the floor for anyone who changes their password on it.

Keel documents this rather than governing it. Network-wide policy — one setting applied across every subsite from network admin — is deliberately out of scope for now.

= I already have another defaults or security plugin. Can I run both? =

You can, but you probably should not, and Keel will tell you when it matters.

Some settings are applied through WordPress filters that return a single value — session length is the clearest example. When two plugins set the same one, WordPress keeps whichever ran last. There is no error and nothing in a log; the plugin that lost simply goes on showing its own number on its own settings screen while the site uses the other one.

Keel checks for this and reports it under **Tools → Site Health**, naming which plugins are contesting which setting. It does not tell you which plugin to keep — that is a judgement about your site, and a plugin answering it would be arguing for its own retention.

Keel also stays out of the fight where it has nothing to say: when a setting is still at the value WordPress itself uses, Keel does not register the filter at all, so it cannot override a deliberate choice another plugin has made.

= Why is there no password strength meter? =

WordPress ships one, but it is JavaScript: it advises the person typing and cannot refuse anything, so a password set over the REST API, WP-CLI, or a form with scripts disabled never meets it. Keel enforces length, breach screening, a blocklist and a personal-context check server-side instead, where they cannot be bypassed. See the Help tab on the settings screen.

== Upgrade Notice ==

= 0.1.0-dev =
Early development. The reserved-usernames default has been removed; if you relied on it, the readme's changelog shows the one-line filter that replaces it.

== Changelog ==

= 0.1.0-dev =
* Removed the reserved-usernames default. It refused to create accounts named `admin`, `support`, `info` and 70 others, which is a reasonable policy for a managed fleet and a presumptuous one for a general-purpose defaults plugin — the list is long, opinionated, and includes names an ordinary site legitimately uses (`manager`, `marketing`, `sales`, `office`, `client`). Existing accounts were never affected and still are not. A stored setting is ignored and drops out of the option on the next save; no migration is needed. To keep the behaviour, WordPress's own filter does it in one call: `add_filter( 'illegal_user_logins', function ( $logins ) { return array_merge( $logins, array( 'admin', 'administrator', 'root' ) ); } );`
* Initial scaffold: base imported from Better by Default (WPYEG, GPL-3.0-or-later) and re-identified as Keel. Work in progress.
* Licence is now GPL-2.0-or-later, matching WordPress core and the upstream 10up Experience code some defaults descend from. Relicensed by the sole author of the carried-over work; nothing is withdrawn, since "or later" still permits GPL-3 terms.
* Breach screening can be switched off with the KEEL_DISABLE_HIBP constant or the keel_disable_hibp filter, and a truncated or malformed range response is now rejected instead of parsed and cached.

== Credits ==

Keel is a de-branded evolution of Better by Default, the WordPress defaults plugin by WPYEG (the Edmonton WordPress meetup): https://github.com/WPYEG/Better-by-Default

Better by Default is published under the GPL-3.0-or-later; its sole author, who also wrote Keel, additionally licenses the portions carried over here under the GPL-2.0-or-later. Keel keeps Better by Default's core architecture — a single schema array that drives both the settings screen and the bootstrap, where each default is one array entry plus one hook — and adds further hardening and admin defaults adapted from the Pixel Managed Platform plugin (GPL-2.0-or-later).

Pixel Managed Platform is itself a hard fork of the 10up Experience plugin by 10up (GPL-2.0-or-later): https://github.com/10up/10up-experience — so several of Keel's adapted defaults ultimately descend from code first written for 10up Experience. Copyright in that work is retained by 10up and its contributors, and 10up retains its marks; Keel is not affiliated with or endorsed by 10up. See LICENSE for the full GPL-2.0 text.
