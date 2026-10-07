=== Keel Defaults ===
Contributors: dpknauss
Donate link: https://github.com/sponsors/dknauss
Tags: security, updates, site health, defaults, hardening
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.6.7
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

39 sane WordPress defaults, a warning when your version is vulnerable, and a button to install the secure fix.

== Description ==

A new WordPress site leaves a lot of small decisions to you: how strong passwords must be, when updates happen, what the outside world can see, what a copied test site is allowed to do. Keel Defaults makes those decisions sensibly from the start and puts all 39 defaults on one screen, in plain language, where you can change any of them.

**[Try Keel live in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/Keel/main/playground/blueprint-stable.json)** — a temporary WordPress site opens in your browser with the current release of Keel already switched on. No hosting, installation, or account required.

= What Keel does for you =

* **Tells you when WordPress itself needs attention.** If WordPress.org flags your version of WordPress as insecure, Keel says so in Site Health. When a secure fix exists for your version, Keel names it and gives an administrator a button to install it. If no fix exists yet, it tells you that too.
* **Makes safer settings routine.** Better password protection, less exposed to the outside world, and practical choices for updates and revisions, without a collection of small single-purpose plugins.
* **Stops test sites emailing real people.** Staging and local copies of your site send no email by default, so a copied database cannot email your customers, members, or clients by surprise.
* **Shows you exactly what it is doing.** One Site Health page lists every setting and its current state, and points out likely overlaps with other plugins.
* **Keeps a multisite network consistent.** A Super Admin can set chosen defaults for every site from one Network Policy screen. Each site's own choices are kept underneath and come back when the policy is lifted.

= Who it is for =

Keel is especially useful if you build or look after many sites, as a freelancer, an agency, or the owner of a multisite network. It is just as useful if you have one site that matters too much to leave its basic safeguards to memory.

= Safe to try, easy to undo =

Every setting is one switch with the reason for it written beside it, under **Settings → Site Defaults**. Keel does not edit or delete your existing posts, pages, media, or comments. The one change it makes to files by default is giving new uploads lowercase filenames. Turn a setting off and that behavior returns to WordPress; uninstall Keel and its settings are removed.

= Good to know before you activate =

Out of the box, Keel turns off comments, trackbacks, and pingbacks, hides public author archives, and stops other sites displaying yours inside a frame. Nothing is deleted, and each of these is a switch you can turn back. The FAQ below explains each one.

== External services ==

Keel contacts two outside services. Neither is sent personal data.

= Have I Been Pwned: checking passwords against known breaches =

When the **Require strong passwords** default is enabled, Keel screens new passwords against the **Have I Been Pwned** Pwned Passwords range API (`https://api.pwnedpasswords.com`) and rejects passwords found in known breaches.

* **What is sent.** Only the first five characters of the password's SHA-1 hash, a method called k-anonymity. Never the password, never the full hash, and no personal data.
* **When.** Only when a password is being set or changed and the default is on.
* **How to turn it off.** Turn off the strong-password default, add `define( 'KEEL_DISABLE_HIBP', true );` to `wp-config.php`, or use the `keel_disable_hibp` filter.
* **If the service cannot be reached.** If the API is unreachable, or answers with a truncated or malformed response, the check is skipped and the password is allowed, so a breach-data outage never blocks a password change. It is not skipped silently: the failure is recorded and reported under Site Health. Only the kind of failure and when it happened are stored, never the password or the hash prefix.

Have I Been Pwned is operated by Troy Hunt; see https://haveibeenpwned.com/Privacy and https://haveibeenpwned.com/API/v3 for its terms and privacy policy.

= WordPress.org: checking whether your WordPress version is vulnerable =

Keel asks WordPress.org whether the installed version of WordPress has known vulnerabilities, using the core stable-check API (`https://api.wordpress.org/core/stable-check/1.0/`). This is WordPress.org's own service, on the same host WordPress already contacts for updates and translations, although WordPress itself never queries it.

* **What is sent.** No site data beyond the user-agent, which identifies the plugin and the site's home URL in the same way WordPress's own update requests identify the site.
* **How often.** Keel keeps the answer for a day. It asks again sooner only when WordPress's own update check offers a release that answer does not list yet, so a new security release is reported the day it ships.
* **If the service cannot be reached.** If WordPress.org is unreachable or answers with something unusable, the failure is remembered for five minutes so an outage does not slow down every admin screen, and Site Health reports that the status could not be determined rather than implying the site is fine.

WordPress.org's privacy policy is at https://wordpress.org/about/privacy/.

== Installation ==

1. In your WordPress dashboard, go to **Plugins → Add New**, search for "Keel Defaults", and install it. You can also upload the zip through **Plugins → Add New → Upload Plugin**, or copy the plugin folder into `wp-content/plugins/`.
2. Activate it. Keel's starting choices are applied on activation; nothing changes before that.
3. Visit **Settings → Site Defaults** to review the starting choices and adjust anything for this site.

Settings that could disrupt another service are off until you choose them, with one exception: Keel stops other sites displaying yours inside a frame. If your site is meant to be embedded, for example in an intranet dashboard, a visual-review service, or a kiosk or signage screen, set **Frame options** to "Leave unchanged" under Security and Attack Surface.

Deactivating stops Keel's behavior while keeping your choices for a future reactivation. Uninstalling removes its settings.

== Frequently Asked Questions ==

= What changes when I activate Keel? Will it break my site? =

Keel applies its starting choices and gives you one screen to review them. Comments, trackbacks, and pingbacks are turned off; public author archives are hidden; other sites cannot put yours inside a frame; and new uploads get lowercase filenames. Settings that could affect another service you rely on are left off until you enable them.

Nothing is deleted. Keel does not edit or remove your posts, pages, media, users, or comments. Disabling comments hides them and closes the forms; turning the setting off brings them back. Uninstalling removes only Keel's own settings.

Everything Keel does is visible and reversible, and each setting explains its practical effect before you change it. The default most likely to surprise you is frame protection: if another site or service is meant to display yours in a frame, that frame will usually show as a blank box. Set **Frame options** to "Leave unchanged" to allow it. Also review the settings after activation if a service publishes to your site through XML-RPC or depends on a feature you intend to turn off.

= Keel says this version is insecure. What should I do? =

Read the message in Site Health. Keel tells you whether a secure fix is available for the version of WordPress you are on, whether WordPress is offering it, and whether something is preventing automatic updates. If the install button is offered, an authorized administrator can use it to install that fix. If there is no secure fix, Keel says so clearly; plan an upgrade to a newer version rather than assuming one exists.

= Why has email stopped working on my staging site? =

Keel blocks outgoing email outside production by default. This protects real customers and clients when a live database is copied to staging or a local machine. It does not block email on your live site. Turn **Non-Production Email** off in **Settings → Site Defaults** when your test site needs to send email.

= Can I use Keel on client sites or multisite? =

Yes. Keel is built for independent sites, agencies, freelancers, and multisite networks. On a network, a Super Admin uses **Network Admin → Settings → Network Policy** to choose which defaults apply everywhere. The policy is visible and locked on each site, but it does not destroy the local choices underneath it; remove a policy later and each site returns to its own saved choice.

Passwords are the one setting that works differently on a network. The setting is stored per site, but WordPress keeps one list of users for the whole network, so a password set on any site becomes that person's password everywhere. For one rule across the network, set the password policy in Network Policy.

= Can I use Keel with another security or defaults plugin? =

Usually, yes, but choose one plugin to own any particular setting. Keel helps by showing likely overlaps in Site Health, so you can compare the two instead of discovering a disagreement later.

= Does Keel control plugin and theme auto-updates? =

No. Keel's update settings cover WordPress itself and translations only. Plugin and theme auto-updates stay under WordPress's own controls; on a network, that is the Automatic Updates column under **Network Admin → Plugins**.

= Why is there no password strength meter? =

WordPress's strength meter advises the person typing but cannot refuse a password, and passwords set through the REST API, WP-CLI, or a form without scripts never meet it. Keel instead checks length, known breaches, a blocklist, and personal details such as your own name on the server, where the checks cannot be bypassed.

= Can I set these in code instead? =

Yes. Many defaults can be set with a `wp-config.php` constant or a filter, for example `KEEL_DISABLE_HIBP` or `KEEL_ALLOW_NONPRODUCTION_MAIL`. A constant wins over the settings screen, and the screen shows when a setting is being overridden. The full list is in the plugin's documentation on GitHub.

== Screenshots ==

1. Site Health → Status. Whether your version of WordPress has known vulnerabilities, which secure fix is available for it, and what WordPress itself is offering.
2. The Passwords help tab. How Keel's password rules work, and exactly what the breach check sends: five characters of a hash, never the password.
3. Site Health → Info. Every default and its current state on one page, so you can see what Keel is doing to your site without opening the settings.
4. Settings → Site Defaults. Every default is one switch with the reason it exists written beside it.
5. Network Admin → Settings → Network Policy. A Super Admin chooses safeguards for every site on a multisite network; each site's own choices return when the policy is lifted.

== Credits ==

[Austin Ginder](https://github.com/austinginder) of Anchor Hosting ([anchor.host](https://anchor.host) · [@anchorhost](https://github.com/anchorhost)) reviewed Keel for security, and the plugin is better for it. Thank you, Austin.

Keel grew out of Better by Default, a WordPress defaults plugin written by the same author (@dknauss) as a teaching version for the Edmonton WordPress meetup, WPYEG: https://github.com/WPYEG/Better-by-Default

Better by Default is published under the GPL-3.0-or-later; its sole author additionally licenses the portions carried over here under the GPL-2.0-or-later. Keel keeps its core architecture and adds further hardening and admin defaults adapted from the Agency Experience plugin (GPL-2.0-or-later).

Agency Experience is itself a hard fork of the 10up Experience plugin by 10up (GPL-2.0-or-later): https://github.com/10up/10up-experience — so several of Keel's adapted defaults ultimately descend from code first written for 10up Experience. Copyright in that work is retained by 10up and its contributors, and 10up retains its marks; Keel is not affiliated with or endorsed by 10up. See LICENSE for the full GPL-2.0 text.

== Support This Plugin ==

Keel is free and will stay free. If it saves you an afternoon of securing a new site, or keeps a staging server from emailing your client's customers, you can support its maintenance through [GitHub Sponsors](https://github.com/sponsors/dknauss).

Bug reports and feature requests are welcome on the issue tracker: [https://github.com/dknauss/keel/issues](https://github.com/dknauss/keel/issues). If you have found a security problem, please report it privately rather than in a public issue — SECURITY.md ships with the plugin and says how.

== Changelog ==

Versions before 0.5.9 were not published to the directory. Their entries, the development history that led to the first release, are in `changelog.txt` in the plugin's repository: [https://github.com/dknauss/keel/blob/main/changelog.txt](https://github.com/dknauss/keel/blob/main/changelog.txt).

= 0.6.7 =
* Fixed: with XML-RPC Multicall off, which is the default, a site connected to WordPress.com through Jetpack could be cut off from it. WordPress.com manages a connected site through multicall, so the plugin list and settings changes failed on WordPress.com while Jetpack still reported itself as connected. Keel now lets through the requests Jetpack verifies as signed by WordPress.com, and still refuses every other multicall.
* Fixed: a refused multicall went out as HTTP 405 instead of an XML-RPC fault, so clients reported a transport error. It is now an ordinary fault (code -32601) on HTTP 200.
* Added: on a site connected through Jetpack, Site Health now reports when Keel's XML-RPC settings stop WordPress.com from reaching it.
* Changed: the Site Health check for plugins sharing Keel's settings is rewritten in plain language. Each item now names the Keel setting involved and the other plugins by their names, not their folder names, and the report says outright that sharing a hook is not evidence of a conflict. Settings that are not taking effect are listed by name, with the hook shown for developers.
* Changed: the Site Health check on your WordPress version is titled "This version of WordPress core is not currently flagged as insecure", and on an older release line it names the line that will eventually be retired, such as "the 7.0 line".
* Fixed: in a right-to-left admin, dragging the admin menu width slider pushed the content from the wrong side, and the widened menu covered the start of every label. The preview now moves the content from the right.
* Fixed: on the network settings screen, the introduction was held to a narrow column while the settings below it used the full width. The cap is gone, so the introduction follows the Network Admin's own width like the rest of the screen.

= 0.6.6 =
* Security: with comments disabled, the REST API went on serving a single comment by ID (/wp/v2/comments/123). The guard added in 0.6.1 to close that route checked for a function WordPress does not define, so it never ran. It now checks correctly, and the route answers 404.
* Added: the WordPress.org listing has a Live Preview button. The blueprint behind it was in the repository all along, but the deploy held it back from the upload, so the listing never had one.
* Fixed: on the day a security release ships, Site Health could go on reporting your version as the latest for up to a day. Keel cached WordPress.org's release status for a day; it now fetches it again as soon as WordPress learns of a release the cached answer does not list.
* Fixed: a setting locked in wp-config.php showed the preference stored underneath the lock, not what the constant enforces. With WP_AUTO_UPDATE_CORE set to true, Core Auto-Updates read "Maintenance/security releases only" on a site installing every release. Locked controls now show what is in force, and when background updates are switched off entirely, the note names the constant that did it.
* Changed: a locked setting now looks locked. The note beneath it reads as a statement rather than a hint, the control itself is visibly inactive, and both are styled on the network settings screen too.
* Fixed: a folded admin menu stayed pinned open at a custom width. It now folds, and the width applies only above 960px, where WordPress does not fold the menu automatically. The live preview follows the same rules, so it no longer shows a width that saving would not apply.
* Changed: clearer wording throughout the core update and security patch messages, with constant, filter and file names shown as code.
* Fixed: with automatic updates switched off or held back by an earlier failed update, the Site Health patch panel said Keel would not offer a deliberate install directly above a working Install button. It now says the patch can still be installed deliberately, and keeps the refusal for the cases where Keel's installer really does refuse.
* Fixed: the security patch panel on Dashboard › Updates said "the update offered above", but WordPress lists its offers below the panel. It now says "below".
* Fixed: on the network policy screen, the Admin Menu Width help told a Super Admin to drag a slider that screen does not have. The sentence is gone from both screens.
* Fixed: following a link to a setting highlights its row, and the highlight bar sat directly against the setting's label. The label now clears it, and in right-to-left languages the bar and the lock note's rule move to the right-hand side.
* Documentation: the WordPress.org listing showed the settings screen and the patch-status panel under each other's captions. The screenshots are retaken and numbered to match.
* Documentation: the FAQ now says Keel does not manage plugin and theme auto-updates, and explains why subsite administrators on multisite do not see the auto-update column.

= 0.6.5 =
* Fixed: the admin menu width slider stopped previewing the change as you dragged it, on any site that had a width saved. The preview was still there; Keel's own saved rule was overriding it, because that rule is marked important and the preview was not. The preview now outranks it, as it was always meant to.
* Documentation: the FAQ now states a limit that was unstated. Keel reports a setting that is not taking effect by watching WordPress filters. A plugin, theme or host that restyles the admin with CSS registers no filter, so there is nothing to observe - the admin menu width is the usual case, and a managed host styling the admin to its own design is not a conflict Keel can see or should fight.

= 0.6.4 =
* Fixed: the admin menu width slider offered "WordPress default (160px)" as its first stop, but that stop set no width at all - it only made Keel stand down. On a site where a theme, a host, or another plugin had widened the menu, it was the stop you would reach for and the one guaranteed to do nothing. There is now an explicit 160px stop that asserts core's width, and the first stop says what it does: "Leave unchanged".
* Fixed: the conflict notice reported settings shared with callbacks it could not trace and sent you to Site Health, where there were no open issues. The finding was there, but filed under a passing test - green, collapsed, and headed "No attributable policy overlap was found". Untraceable overlaps are now reported as a recommendation, so the notice and Site Health describe the same site.
* Changed: the conflict notice names Keel, drops a sentence the link beneath it already made, and says settings "may be contested" rather than asserting a contest the Site Health test itself declines to assert.

= 0.6.3 =
* Added: the security release on your own version line is now offered on the Updates screen, where WordPress sends you to update. WordPress builds that screen with `get_core_updates()`, which discards every offer flagged for automatic installation — and a same-line security patch is only ever offered that way. So the screen has always listed the newest release and never mentioned the patch. Keel adds it back, directly below the automatic-update settings that decide which release the site would take.
* Fixed: an install started from the Updates screen finished, then sent you to Site Health to find out whether it had worked. Worse, it usually said nothing when you got there: a successful install leaves the site secure, so the panel carrying the result correctly stops rendering. The result now appears on the screen the button was pressed on, once.
* Fixed: the Updates screen offer named the newest release from the WordPress.org stable check, and called it "the update offered above". That check and the update list WordPress renders refresh on their own schedules, so the two could disagree — or the screen could be offering nothing at all. It now reads the same list the screen does, and says nothing rather than inventing an update above.
* Fixed: the Site Health panel told you the Updates screen would not offer the patch, on sites where Keel had just added it there.
* Changed: the ladder markers are one symbol and one label per rung rather than several, and the plainer wording drops an explanation of release numbering nobody reading two version numbers needs.
* Screenshots retaken against the current wording.

= 0.6.2 =
* Fixed: the patch-status panel could promise a scheduled install the ladder directly beneath it contradicted. "Minor updates are permitted and the updater works" does not establish what WordPress would install: a site that also accepts major updates gets the highest release on offer, not the nearest, so the panel could say a patch was scheduled above a ladder marking a different release as the one WordPress would take. The claim is now made only when core's own selection is that patch, and names the release core would take instead when it is not.
* Fixed: the persistent admin notice repeated the same promise with no ladder beneath it to correct it. It renders on every admin screen, so it cannot afford to ask WordPress which release it would install — it now says automatic updating appears available and sends you to Site Health, which can answer.
* Fixed: the panel offered a deliberate install in states where Keel refuses one, and then, once corrected, claimed no install was possible at all. Both were wrong. Keel refuses a blocked install; a deployment workflow or WP-CLI may still manage it, and the wording now says which of those it is speaking for.
* Fixed: an unexpected `null` from WordPress's upgrader was reported as a successful install. Core documents a version string on success, so `null` establishes nothing.
* Added: two live matrix rows that leave the automatic updater operable — one where WordPress would take the same-line patch, one where major updates are permitted and it steps over. Every previous row switched the updater off, so no row had ever rendered the panel in the state these fixes are about.
* Screenshots retaken against a release WordPress.org flags, so the listing images show the current wording.

= 0.6.1 =
* Fixed: `/?author=N` still disclosed the author nicename, and attachment pages still rendered, on sites with those defaults enabled. Both redirects registered on `template_redirect` at the default priority, where core has already registered `redirect_canonical` during load — so they lost the tie on registration order every time. Both now run at priority 9.
* Fixed: a site with comments disabled still answered `/wp/v2/comments/123`. The filter covering every comment listing does not cover the single-item REST route, which reads its row without building a query.
* Fixed: the REST password policy resolved any user ID in the request before the route checked authorization, so the policy ran against another account's login, email and nicename and returned the answer in the validation error. It now resolves only the caller's own user, or one they may edit.
* Fixed: an empty or over-long password reached the breach-screening network call before anything cheap rejected it.
* Added: `tests/hook-precedence.php` and `tests/route-coverage.php`, which check that a registration wins its hook and covers its routes rather than merely existing. The first two fixes above were invisible to the previous suite.
* Thanks to Austin Ginder of Anchor Hosting for the security review these fixes come from.

= 0.6.0 =
* New: Site Health reports whether the installed version of WordPress has publicly known vulnerabilities. That is a different question from whether an update is available, and nothing in wp-admin answered it. WordPress.org publishes the answer at its core stable-check API, which core itself never queries.
* Where a patched release exists on your own release line, Keel names that release rather than the newest one. A 6.9.5 site is told about 6.9.7, not 7.1 — only the third number changes, so nothing is deprecated.
* New: the ladder of releases WordPress.org is currently offering this site, and which one WordPress would actually install. It takes the highest release your settings permit rather than the nearest, so a site accepting major updates skips the patch and jumps to the newest release.
* Fixed: the panel offered "Install this now from the Updates screen" when the patch appeared only as an automatic-update offer. `get_core_updates()` omits those offers, so following that button installed the newest release instead. Keel now asks core what that screen is showing, and distinguishes listed, hidden behind Show hidden updates, absent, and not yet known.
* Blockers name their own cause — the constant and the file it usually lives in, or the filter a plugin is using — instead of describing the situation in the abstract. Each one needs a different fix, and they now carry stable codes rather than being told apart by their translated text.
* New: install the patch from the Site Health panel, using WordPress's own upgrader with rollback enabled. The target is recomputed on the server and can only ever be the patched tip of your own release line, so it cannot cross a release line or move a site backwards. It refuses when files are not writable, when the site is a version-control checkout, or when the release's PHP or MySQL requirements are not met — that last check runs before anything is downloaded, because core otherwise reads those requirements out of the new version's own files and only finds out after unpacking it.
* Being blocked from automatic updates does not block a deliberate install. A disabled updater, a filter, or an earlier failed update are all reasons a patch will not arrive by itself, and installing it deliberately is the remedy for each.
* The other remediation switches minor auto-updates back on, and only where the stored option is genuinely what decides.

= 0.5.10 =
* The network settings screen is now **Network Policy** rather than "Keel Defaults". It decides settings for every site on a network and locks them, which is the opposite of what the per-site screen does, and the old name did not say so. The screen, its address and its behaviour are otherwise unchanged.
* Two links in Site Health pointed at "Settings → Keel", a menu that has not existed since that screen was renamed to Site Defaults. They now name the screen you will actually find. The same correction has been made to the multisite password help text and to this readme.

= 0.5.9 =
* Breach-cache entries can no longer be reached again after the plugin is removed and reinstalled. On a site with Redis or Memcached, WordPress keeps transients in the object cache rather than the database, where the uninstaller's queries cannot follow them. Each installation now has its own cache namespace, so anything left behind belongs to an installation that no longer exists.
* Rewrote the Help menu's Environments and Overlapping plugins tabs, and corrected how the environment type is described: WordPress reads the `WP_ENVIRONMENT_TYPE` constant and `wp_get_environment_type()` reports the result, not the other way round.

== Upgrade Notice ==

= 0.6.7 =
Fixes Jetpack: with multicall off (the default), WordPress.com could not manage a connected site. Requests Jetpack verifies as signed now get through. Also clearer Site Health wording and two admin layout fixes. No setting changes.

= 0.6.6 =
Security: with comments off, the REST API no longer serves a comment by ID. Locked settings show what wp-config.php enforces, Site Health notices a new security release the day it ships, and a folded admin menu stays folded. No setting changes.

= 0.6.5 =
The admin menu width slider previews again while you drag it. On any site with a width saved, Keel's own saved rule had been overriding its own preview, so the slider moved nothing on screen. No setting changes.

= 0.6.4 =
Two reporting fixes. The menu width slider's first stop claimed to set WordPress's 160px and set nothing; there is now a real 160px stop, and the first reads "Leave unchanged". The conflict notice no longer sends you to a Site Health page reporting nothing found. No setting changes.

= 0.6.3 =
The security release on your own version line is now offered on the Updates screen, not only in Site Health — WordPress omits it there, and that is the screen people go to. Installing from it now returns you there and tells you what happened. No setting changes.

= 0.6.2 =
Reporting fixes. The patch-status panel could promise an automatic install that the ladder beneath it contradicted, on sites that also accept major updates. It now agrees with what WordPress would actually install. No setting changes.

= 0.6.1 =
Security fixes. Two protections this plugin documents were not taking effect: the author and attachment redirects lost a priority race against WordPress itself, and disabled comments still answered one REST route. Also tightens the REST password policy. No setting changes.

= 0.6.0 =
Reports whether your WordPress version has publicly known vulnerabilities, names the patched release on your own line rather than the newest, and can install it using WordPress's own upgrader. Asks WordPress.org once a day. No setting changes; nothing installs unless you ask.

= 0.5.10 =
Renames the network screen to Network Policy and corrects links that named a menu which no longer exists. No setting changes and no behaviour changes.

= 0.5.9 =
Removing the plugin now fully clears breach-cache data on sites using Redis or Memcached. Help text rewritten; no setting changes.

= 0.5.8 =
Formatting fix in the Help menu. No behaviour changes.

= 0.5.7 =
Fixes breach-screening status being reported from missing evidence, dependent XML-RPC controls not updating without a reload, and locked sliders accepting edits. Recommended if you use any of those.

= 0.5.6 =
Breach-screening outages are now reported under Site Health rather than passing unnoticed. No change to what is or is not allowed as a password.

= 0.5.5 =
Conflict reports no longer print an internal marker where a plugin name would go, and the dashboard notice now agrees with Site Health about how many settings are affected.

= 0.5.4 =
Warnings about a setting being overridden by another plugin now reach the dashboard rather than only Site Health, and a comments heading no longer appears on posts with comments off.

= 0.5.3 =
Internal change to how Keel's own CSS and JavaScript reach the page; no setting changes and nothing looks different. The Canadian English catalog is no longer bundled — translations now come from translate.wordpress.org like every other locale.

= 0.5.2 =
On WordPress 6.4–6.9 an earlier release could switch the stored AI Connectors value off when you saved any setting. It has no effect before WordPress 7.0, where the control appears under Settings → Keel. To correct it now: `wp option patch update keel_settings disable_ai_connectors yes`

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
