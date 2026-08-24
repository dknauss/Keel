=== Keel Defaults ===
Contributors: dpknauss
Donate link: https://github.com/sponsors/dknauss
Tags: security, defaults, hardening, privacy, performance
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

More than 30 sane WordPress defaults, each one a switch you can see and turn off — security, updates, privacy, UX, and performance.

== Description ==

Keel flips a menu of sensible defaults onto any WordPress install, each one a switch under **Settings → Keel**. Nothing is hidden and nothing is all-or-nothing — you can see exactly what the plugin does to your site and turn any piece off.

All 39 defaults are declared in a single schema array that drives both the settings screen and the code that wires them to WordPress. A default is an opinionated filter behind a control.

**Disabling something means it is actually disabled.** Measured against nine of the most-installed plugins in this space — every result a live request against a real install, not a readme claim — Keel is the only one where "comments are off" is true below the presentation layer. The others stop at the theme template and the REST route: ask the database directly, with `get_comments()`, and the comments are still there. The same care runs through the rest — closing the REST API also removes the link advertising it, and disabling comments also stops the comment feed answering.

**Site Health shows you the whole posture**, read-only: every default and its current state on one screen, so you can see what the site is actually doing without clicking through tabs. It also reports when another plugin is controlling the same settings, which otherwise fails silently.

**Outgoing email stops at the edge of production.** A database copied down from production carries real customer addresses and whatever mail service production was using, so a cron run or a bulk action can email real people from a staging site or a laptop. Keel suppresses outgoing mail on any environment that is not production — on by default, does nothing on production, and says so in an admin notice so nobody is left wondering why a password reset never arrived.

**It works the same on a network.** Activated across multisite, Keel seeds every existing site and every site created afterwards, so a later change to a default cannot move some sites and not others. A Super Admin can decide any setting for the whole network under Network Admin → Settings → Keel Defaults; sites see those settings as locked, with their own saved values untouched underneath, so lifting a policy returns each site to exactly what it had.

== External services ==

When the **Require strong passwords** default is enabled, Keel screens new passwords against the **Have I Been Pwned** Pwned Passwords range API (`https://api.pwnedpasswords.com`) to reject passwords found in known breaches. This uses k-anonymity: only the first five characters of the password's SHA-1 hash are ever sent — never the password, and never the full hash. No personal data is transmitted. The check runs only when a password is being set or changed and the default is on. It can be disabled with `define( 'KEEL_DISABLE_HIBP', true );` in `wp-config.php`, with the `keel_disable_hibp` filter, or by turning off the strong-password default. If the API is unreachable, or answers with a truncated or malformed response, the check is skipped and the password is allowed — a breach-data outage never blocks a password change. Have I Been Pwned is operated by Troy Hunt; see https://haveibeenpwned.com/Privacy and https://haveibeenpwned.com/API/v3 for its terms and privacy policy.

== Recommended wp-config.php hardening ==

A few defences live best in `wp-config.php`, outside any plugin: they apply before plugins load and cannot be switched off from the dashboard. These are optional and independent of Keel — add the ones that fit your site.

`define( 'DISALLOW_FILE_EDIT', true );` — removes the built-in plugin and theme code editors, so a compromised admin account cannot edit PHP from the dashboard.

`define( 'WP_POST_REVISIONS', 10 );` — caps stored post revisions so the database does not grow without bound. Keel's **Post Revision Retention** control can govern the same policy after plugins load; a numeric or false constant remains the higher-level operator choice and locks that control.

`define( 'AUTOSAVE_INTERVAL', 120 );` — lengthens the editor autosave interval. This is independent of Keel's Heartbeat throttle: both influence how often the editor saves in the background, but neither replaces or overrides the other.

== Installation ==

1. Copy the plugin folder into `wp-content/plugins/`, or upload the built zip through **Plugins → Add New → Upload Plugin**.
2. Activate it. The documented defaults are seeded on activation; nothing is applied before that.
3. Visit **Settings → Keel** and turn off anything you do not want.

Every default is a switch, and the switches are the whole interface. Defaults that can change behaviour or break an integration — requiring authentication for all REST requests, blocking the XML-RPC endpoint, the Classic editor — are off out of the box and opt-in.

There is one exception: Keel sends an `X-Frame-Options` header of `SAMEORIGIN`, so other sites cannot embed yours in an iframe. If something else is meant to display this site inside a frame — an intranet dashboard, a screenshot or visual-review service, a kiosk or signage screen — set **Frame options** to "Leave unchanged" under Security and Attack Surface. A blocked frame usually fails silently, as a blank box.

Deactivating stops every default at once; stored settings are kept so reactivating restores the same configuration. Uninstalling removes them.

== Frequently Asked Questions ==

= Will this break my site? =

The defaults that are on out of the box are low-risk, with one exception worth naming: `X-Frame-Options: SAMEORIGIN` is sent by default, and it stops other sites embedding yours in an iframe. Set **Frame options** to "Leave unchanged" if the site is meant to be embedded, because a blocked frame fails silently as a blank box.

Everything else that can break something is off and opt-in, and each says on the settings screen what it will cost you — for example that blocking the XML-RPC endpoint also stops apps and services that publish through it. Requiring authentication for REST is the one place Keel spends a little of that strictness back: `oembed/1.0` stays reachable, so other sites can still embed your posts when every other route is closed.

= Does it send anything off my site? =

One thing, and only when the strong-password default is on: the first five characters of a password's SHA-1 hash, to check it against known breaches. Never the password, never the full hash, no personal data. See **External services** above for the full description and how to switch it off.

= Why has email stopped working on my staging site? =

Because Keel switched it off, deliberately, and there is an admin notice on the site saying so. The **Non-Production Email** default suppresses outgoing mail on any environment that is not production, so a database copied down from production cannot email real customers from a staging site or a laptop.

It does nothing on production, so it cannot be left on by mistake. To send from a non-production site anyway, turn the default off under **Settings → Keel**, define `KEEL_ALLOW_NONPRODUCTION_MAIL` in `wp-config.php`, or use the `keel_suppress_nonproduction_mail` filter. A mail catcher can still record what would have been sent by hooking `keel_outgoing_mail_suppressed`.

The environment is read the same way the admin-bar environment indicator reads it: `WP_ENVIRONMENT_TYPE`, whether set as a constant or an environment variable, and a host-name fallback for local development tools when neither is set.

= Does it delete anything? =

No. Disabling comments hides them and closes the forms; nothing is removed from the database, and turning the default off brings every comment back. The same holds for the other content defaults.

= Can I set these in code instead? =

Yes. Every default reads its value through the plugin's own option, and the behaviours are filterable — `keel_weak_roles`, `keel_disable_hibp`, `keel_comment_blocks`, `keel_allowed_comment_types` and others. A `wp-config.php` constant always wins over the settings screen where one applies; the screen says so when it is being overridden.

= I run multisite. Does the password policy apply per site? =

The setting is stored per site; the effect is not. WordPress keeps one user table for the whole network, so a password is checked against whichever site it is being set on — and once set, it is that person's password everywhere. Exempting a role on one subsite decides what happens when a password is changed *there*; it does not exempt those accounts from another site's policy. In practice the strictest site on the network sets the floor for anyone who changes their password on it.

Keel can now govern it as well as document it. Under **Network Admin → Settings → Keel Defaults**, a Super Admin can decide any setting for the whole network; sites see it as locked and cannot change it. Tick the password rules there and the network has one policy instead of a floor set by whichever site is strictest.

Nothing is written into your sites. A network value is applied when a setting is read, so a site's own saved settings are untouched — untick a setting later and every site returns to exactly the value it had. Settings left unticked stay each site's own business.

= I already have another defaults or security plugin. Can I run both? =

You can, but you probably should not, and Keel will tell you when it matters.

Some settings are applied through WordPress filters that transform a value in priority order — session length is the clearest example. Another callback on the same filter does not prove a conflict: two plugins may reach the same outcome or govern different parts of a structured result.

Keel reports a structural overlap only when it is registered on an authoritative policy hook and a callback attributable to another active plugin is registered there too. It never executes the other plugin's callback to diagnose the overlap. The notice appears on the Plugins screen, on **Settings → Keel**, and on the dashboard, where it can be dismissed until the overlap changes. The full detail is under **Tools → Site Health**.

That evidence confirms shared ownership of a hook, not that the plugins' configured outcomes disagree. Keel asks you to compare their settings and never recommends deactivation from callback presence alone. Mail, authentication, comment-query, capability, and unattributable overlaps stay unconfirmed and informational.

There is a limit worth knowing. WordPress ships tiny helper callbacks such as `__return_false`; the callback belongs to WordPress, not the plugin that registered it. Keel labels that limitation unconfirmed instead of guessing from source code or naming a plugin without evidence.

Keel also stays out of the fight where it has nothing to say: when a setting is still at the value WordPress itself uses, Keel does not register the filter at all, so it cannot override a deliberate choice another plugin has made — and it will not report a conflict on a setting it is not itself setting.

= Why is there no password strength meter? =

WordPress ships one, but it is JavaScript: it advises the person typing and cannot refuse anything, so a password set over the REST API, WP-CLI, or a form with scripts disabled never meets it. Keel enforces length, breach screening, a blocklist and a personal-context check server-side instead, where they cannot be bypassed. See the Help tab on the settings screen.

== Screenshots ==

1. Settings → Keel. Every default is one switch with the reason it exists written beside it, so nothing the plugin does is hidden behind a name you have to guess at.
2. The Passwords help tab. Length and breach screening in place of composition rules, with what the breach check actually sends spelled out — five characters of a hash, never the password.
3. Site Health → Info. Every default and its current state on one read-only screen, so you can answer "what is this plugin doing to my site?" without opening the settings and reading checkboxes.

== Credits ==

Keel is a de-branded evolution of Better by Default, the WordPress defaults plugin by WPYEG (the Edmonton WordPress meetup): https://github.com/WPYEG/Better-by-Default

Better by Default is published under the GPL-3.0-or-later; its sole author, who also wrote Keel, additionally licenses the portions carried over here under the GPL-2.0-or-later. Keel keeps Better by Default's core architecture — a single schema array that drives both the settings screen and the bootstrap, where each default is one array entry plus one hook — and adds further hardening and admin defaults adapted from the Pixel Managed Platform plugin (GPL-2.0-or-later).

Pixel Managed Platform is itself a hard fork of the 10up Experience plugin by 10up (GPL-2.0-or-later): https://github.com/10up/10up-experience — so several of Keel's adapted defaults ultimately descend from code first written for 10up Experience. Copyright in that work is retained by 10up and its contributors, and 10up retains its marks; Keel is not affiliated with or endorsed by 10up. See LICENSE for the full GPL-2.0 text.

== Support This Plugin ==

Keel is free and stays free. If it saves you an afternoon of hardening a new site, or keeps a staging server from emailing your client's customers, you can support its maintenance through [GitHub Sponsors](https://github.com/sponsors/dknauss).

Bug reports and feature requests are welcome on the issue tracker: [https://github.com/dknauss/keel/issues](https://github.com/dknauss/keel/issues). If you have found a security problem, please report it privately rather than in a public issue — SECURITY.md ships with the plugin and says how.

== Changelog ==

= 0.5.1 =
* Removed effect probes from policy-overlap detection. Diagnostics no longer execute another plugin's callbacks with synthetic or real user/post context, so reporting an overlap cannot send mail, write data, terminate the request, mutate hooks, or trigger other callback side effects.
* Restored structural detection on authoritative hooks: Keel must be registered on the hook and the other callback must be attributable to an active plugin. The report confirms shared ownership only, tells administrators to compare settings, and does not recommend deactivation from presence alone.
* Memoized the overlap report for each request and added adversarial coverage for mutating, throwing, and terminating callbacks, plus guards against hook-registry mutation and overstated UI copy.

= 0.5.0 =
* Added post-revision retention: new activations keep 10 revisions, existing sites preserve their previous unlimited behavior on upgrade, `-1` means unlimited, and `0` disables future revisions. Numeric or false `WP_POST_REVISIONS` policy locks both site and network controls.
* Author feeds now return an explicit 404 when author archives are disabled. They were already closed by the archive's broad 301 because WordPress sets both query flags; the corrected test now proves that routing fact against the real request.
* Rebuilt overlapping-policy detection around confirmed, compatible, and unconfirmed effects. Callback presence alone no longer generates deactivation advice, and the incorrect claim that mail/comment-query callbacks stop after the first non-null value is gone.

= 0.4.1 =
* Removed the unconfirmed half of the overlapping-settings check. It reported a plugin when something untraceable was registered on a setting Keel also sets and that plugin's source mentioned the same filter — but WordPress itself, and Keel itself, both register through the same untraceable helper functions, so the first of those two conditions was true on nearly every setting. That left one weak signal doing the work of two, and it named plugins that were not doing anything: Clearfy and WP Master Toolkit were both reported on five settings between them while registering nothing at all. Confirmed detection is unchanged and unaffected.
* The check now says what it cannot see, on the settings screen and in Site Health. A plugin that turns something off by handing one of WordPress's own helper functions to a filter cannot be traced back from that filter, so a clear result means nothing traceable was found rather than nothing competing.

= 0.4.0 =
* The plugin folder is now `keel-defaults` rather than `keel`, and the text domain moved with it. WordPress.org serves translations as `{slug}-{locale}.mo`, so a text domain that is not the slug means no translation ever loads — silently, with nothing to search for.
* Keel now tells you when another plugin is setting the same things it is. Session length, comment behaviour, the editor and a dozen other settings are applied through WordPress filters that return a single value: when more than one plugin uses the same filter, only one of them takes effect, there is no error, and the ones that lost go on showing the values they set. The check names the plugins and the settings — on the Plugins screen, on Keel's own screen, and in full under Site Health.
* What it cannot see is stated where it is reported. A plugin that turns a feature off by handing one of WordPress's own helper functions to a filter leaves nothing to trace back to it, so a clear result means nothing traceable was found rather than nothing competing.
* A conflict needs Keel to be on the hook too. Turning a default off takes Keel out of the contest and the report follows, instead of warning about a setting Keel has stopped touching.
* Capability conflicts are judged by the capability rather than by the filter. Nearly every plugin that adds a custom role uses the same filter Keel uses to take `unfiltered_html` away, and almost none of them touch that capability; only the ones that do are reported.
* AI Connectors no longer appears on WordPress versions that have no AI connectors. The setting is gated on the core function rather than a version number, and its stored value is left alone, so a site that upgrades to 7.0 finds the default already there.
* Tested against WordPress 7.1, behaviourally rather than by reading the release notes.

= 0.3.0 =
* Multisite: a Super Admin can decide any setting for the whole network, under Network Admin → Settings → Keel Defaults. Sites see those settings as locked. Policy applies when a value is read rather than being written into each site, so a site's own saved settings are untouched and lifting the policy returns every site to exactly what it had.
* A locked setting now stays locked when the form is saved, not only when it is drawn. A wp-config constant or a network policy was enforced in the rendered control and nowhere else, so a submission could still write the value it protected. It never took effect, but the stored setting drifted from what the screen showed.
* Locked controls can be reached by keyboard and screen reader, and say why they are locked. They were disabled, which removes them from the tab order — so the explanation attached to them was announced on a focus that never happened.
* Settings that hide when another choice makes them irrelevant now tell assistive technology which control governs them, and whether they are showing.
* The staging environment indicator failed WCAG AA contrast at 2.41:1 against the 4.5:1 minimum for text that size. Every environment colour is now checked by the test suite.
* The admin menu width slider announces its setting as a word rather than a position, and no longer repeats itself on every keypress.
* Site Health → Info groups the defaults by category instead of repeating the group name on every row, and the section is named "Keel Defaults" rather than "Keel".
* Number settings report their unit, so Site Health says "14 days" rather than "14".
* X-Frame-Options is left alone inside the Customizer preview, which sets that header itself so the preview can load.
* Translation catalogs rebuilt: 68 strings in the code were missing from the template, and the en_CA catalog translated nothing because every string it named had been reworded.
* An XML-RPC help tab covering the whole family — what it is, why four switches rather than one, the Jetpack constraint, and why system.multicall's reputation is out of date.
* A "try it live" Playground link that follows each stable release.

= 0.2.0 =
* First stable release. The initial feature set was frozen; what changed since the scaffold is listed below.
* Comment teardown now reaches past the rendered page: comment queries are answered empty, comment blocks stop rendering in block themes, comment feeds return a real 404 instead of a redirect loop, and the comment count reports zero.
* A closed REST API stops advertising itself — the `<link rel>`, the `Link:` header and the RSD entry all go — and oEmbed stays reachable through the gate so other sites embedding yours do not silently degrade to a bare link.
* Author identity no longer leaks past a hidden author archive. oEmbed responses drop `author_name` and `author_url`, and the users sitemap provider is removed.
* Uninstall leaves nothing behind: settings, the last-login user meta and the breach-screening transients are all removed, network-wide on multisite.
* Activation seeds every existing site on a network, and a subsite created afterwards is seeded too, so a later schema change cannot move some sites and not others.
* Site Health reports every default and its state under Info, flags only what warrants attention under Status, and names other active plugins setting the same defaults.
* Outgoing mail is suppressed outside production, and the settings screen says so on screen rather than only in a notice.
* The session-length filter stands down when it has nothing to say, so it does not overrule a host or another plugin that has already decided.
* Environment detection no longer overrides a site that declares `WP_ENVIRONMENT_TYPE` through an environment variable rather than the constant.

= 0.1.0-dev =
* Removed the reserved-usernames default. It refused to create accounts named `admin`, `support`, `info` and 70 others, which is a reasonable policy for a managed fleet and a presumptuous one for a general-purpose defaults plugin — the list is long, opinionated, and includes names an ordinary site legitimately uses (`manager`, `marketing`, `sales`, `office`, `client`). Existing accounts were never affected and still are not. A stored setting is ignored and drops out of the option on the next save; no migration is needed. To keep the behaviour, WordPress's own filter does it in one call: `add_filter( 'illegal_user_logins', function ( $logins ) { return array_merge( $logins, array( 'admin', 'administrator', 'root' ) ); } );`
* Initial scaffold: base imported from Better by Default (WPYEG, GPL-3.0-or-later) and re-identified as Keel. Work in progress.
* Licence is now GPL-2.0-or-later, matching WordPress core and the upstream 10up Experience code some defaults descend from. Relicensed by the sole author of the carried-over work; nothing is withdrawn, since "or later" still permits GPL-3 terms.
* Breach screening can be switched off with the KEEL_DISABLE_HIBP constant or the keel_disable_hibp filter, and a truncated or malformed range response is now rejected instead of parsed and cached.

== Upgrade Notice ==

= 0.5.1 =
Policy-overlap diagnostics no longer execute other plugins' callbacks. Re-check prior 0.5.0 conflict results; 0.5.1 reports structural overlap without claiming the configured outcomes disagree.

= 0.5.0 =
Existing sites keep unlimited revision history unless you choose a limit; new activations default to 10. Re-check overlapping-policy results because Keel now reports only confirmed incompatible effects as actionable.

= 0.4.1 =
Fixes the overlapping-settings check naming plugins that were not competing. If 0.4.0 told you another plugin was setting the same things and you have not acted on it yet, re-check under Site Health before deactivating anything. Confirmed results were always correct; the unconfirmed ones are gone.

= 0.4.0 =
From wordpress.org: nothing to do, and your settings are kept. From GitHub before this release: the folder changed from `keel` to `keel-defaults`, so WordPress sees a new plugin rather than an update. Deactivate and delete the old copy — your settings are stored separately and survive it.

= 0.3.0 =
Nothing to do on upgrade: no setting changes meaning and no stored value is rewritten. Multisite networks gain a Network Admin screen that can set any default for every site; it does nothing until a Super Admin uses it.
