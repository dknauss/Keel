# Keel

![Keel banner](.wordpress-org/banner-1544x500.png)

[![CI](https://github.com/dknauss/keel/actions/workflows/ci.yml/badge.svg)](https://github.com/dknauss/keel/actions/workflows/ci.yml) [![Latest Tag](https://img.shields.io/github/v/tag/dknauss/keel?include_prereleases)](https://github.com/dknauss/keel/tags) [![Docs](https://img.shields.io/badge/docs-available-0a7ea4.svg)](docs/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759b.svg?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![Tested up to WP 7.1](https://img.shields.io/badge/tested%20up%20to-WP%207.1-21759b.svg?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg?logo=php&logoColor=white)](https://www.php.net/)
[![▶ Playground (latest release)](https://img.shields.io/badge/▶_Playground-Latest_release-3858e9.svg?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/keel/main/playground/blueprint-stable.json) [![▶ Playground (main)](https://img.shields.io/badge/▶_Playground-main_branch-6e40c9.svg?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/keel/main/playground/blueprint-hosted.json)

**The missing settings and defaults every new WordPress site needs.**

Keel adds a modest menu of sensible security, update, privacy, content, media, email, UX, and performance defaults to WordPress — each one a switch under **Settings → Keel**. Nothing is hidden. Everything is explained. 

> **Current release: `0.5.8`.** Keel now has 39 defaults; the Site Health
> surface, multisite-aware seeding, network-wide policy
> and detection of other plugins controlling the same settings are in. Verified
> against WordPress 7.1 and clean under Plugin Check. See [ROADMAP.md](ROADMAP.md) for the
> milestones and [TODO.md](TODO.md) for what's in flight.

## Set and forget — What Keel establishes as a baseline

Activating Keel switches on sixteen of its thirty-nine defaults and applies a starting value to nine more, without writing to your content or deleting anything — every one is a switch on Settings → Keel, and turning it off puts WordPress back exactly as it shipped. 

Most of the immediate changes are quiet: users stop being listed for anonymous REST requests, new passwords must be long and never present in a known data breach, raw HTML is limited to Administrators, security headers go out, AI connectors are off, uploads get lowercase filenames, and the site starts warning you if its email sending capability looks broken. 

Three things change visibly on the first page load — comments, trackbacks and pingbacks are off everywhere including existing posts (nothing deleted, all reversible), author archives stop resolving, and `X-Frame-Options: SAMEORIGIN` stops other sites embedding yours — with two quieter ones behind them: attachment pages redirect to their parent post, and self-pingbacks and the emoji script are gone. The starting values are conservative: core takes security releases but not major versions, ten revisions are kept, logins last two days or fourteen with "Remember me", subscribers are exempt from the password rules, and the admin menu, front-end admin bar and login logo are left exactly as WordPress has them. One default is on but inert on a live site — outgoing email is blocked on any environment that isn't production, so a database copied to staging can't email real customers.

**Security and attack surface**

- REST User Discovery — hides users from anonymous REST requests
- Password Strength — requires strong passwords (length + breach screening; subscribers exempt by default)
- Unfiltered HTML — limits raw HTML and JavaScript to Administrators
- Security Headers — sends baseline security headers
- AI Connectors — disables AI provider connectors

**Content and public surfaces** 

- Comments — disables comments, trackbacks and pingbacks
- Pingbacks On New Posts — closes pingbacks/trackbacks on new posts
- Self-Pingbacks — disables self-pingbacks
- Author Archives — disables public author archives
- Attachment Pages — redirects them to the parent post
- Emoji Script — drops the emoji detection script

**Media Library** 

- Upload Filenames (lowercases new uploads)
- Image Sizes (shows generated sizes on attachments)

**Core Updates** 

— Minor/Maintenance Releases (auto-updates on minor releases, which simply reinforces the core default.)
- Translations (auto-updates translation files)

**Email**

- Email Deliverability (warns when site email looks broken)
- Non-Production Email (stops outgoing mail unless the site is production)

## What Keel looks like

<img src=".wordpress-org/screenshot-1.png" alt="Settings → Keel: each default is one switch with the reason it exists written beside it" width="900">

**Settings → Keel.** Every default is a single switch with a solid explanation next to it. Learn more in the dropdown help menu, site health, and repo docs.

<img src=".wordpress-org/screenshot-2.png" alt="The Passwords help tab, explaining length and breach screening in place of composition rules" width="900">

**The Passwords help tab.** Length and breach screening in place of composition
rules, with what the breach check actually sends spelled out — five characters of
a hash, never the password — and what it costs when the API is unreachable.

<img src=".wordpress-org/screenshot-3.png" alt="Site Health → Info showing every Keel default and its current state, grouped by category" width="900">

**Site Health → Info.** Every default and its current state on one read-only
screen, grouped by category and copyable as a block, so you can answer "what is
this plugin doing to my site?" without opening the settings and reading
checkboxes.

## What makes Keel different

Most "disable it" plugins close the front door and leave the back door open. That was surprising but the result of examining nine of the most-installed "disable things" plugins on wordpress.org. That study is documented here in [docs/competitive-teardown-matrix.md](docs/competitive-teardown-matrix.md) — every result a live HTTP or PHP probe against a real install.

Comments were switched off in each plugin's own settings, then the database was asked directly for approved comments with `get_comments()`:

- **Disable Comments** (1M+ installs) — the comment is still returned
- **Admin and Site Enhancements** (200k+) — still returned
- **Disable Comments RB** (100k+) — still returned
- **Simply Disable Comments** (6k+) — still returned
- **Keel** — nothing returned

The others stop at the theme template and the REST route. Keel is the only one in that
field that short-circuits `comments_pre_query`, so a Recent Comments widget from
another plugin, a custom `WP_Comment_Query`, or `wp_count_comments()` all see what the
setting says they should.

The same pattern runs through the rest: closing the REST API also means removing the
discovery link that advertises it, and disabling comments also means the comment feed
stops answering. The full comparison, and the cases where Keel makes a deliberate
trade instead, is in
[docs/competitive-teardown-matrix.md](docs/competitive-teardown-matrix.md).

Keel keeps oEmbed reachable when the REST gate is closed — alone among the four
plugins measured that close REST outright — so other sites can still embed your posts
instead of silently degrading them to bare links.

## Keel tells you when it is not working

A setting that silently stops working is worse than one that was never there, because it's misleading you. This happens when plugins and other code come into conflict with each other, like two people trying to operate the same switch. Keel is unique for detecting conflicts and advising you about them.

**Another plugin on the same filter.** Many defaults are applied through filters that
return a single value, so when two plugins register on one, only one answer survives —
no error, nothing logged — but the losing plugin goes on displaying the setting you think it
has applied. Keel names the other plugin where it can be identified, on the plugins screen
and in Site Health. That confirms an overlap, not necessarily a conflict, and it is not a
reason to deactivate anything out of hand.

**A setting that is measurably not taking effect.** Keel watches
what the filter chain actually settles on and compares it with what it asked for. This
catches disagreements and the cases that can't be detected through a common registration — a plugin that switches something off using
one of WordPress's own helper functions leaves nothing behind to identify it, so there
is no name to give you, but the disagreement is still measurable.

**Breached password screening that has stopped reaching the API.** Password screening fails open
by design; refusing a password because the Have I Been Pwned service is down would lock
people out of their own accounts, so Keel proceeds but warns you what is happening. An unreachable,
rate-limited, truncated or intercepted password lookup is recorded and reported under Site
Health, so a site whose HIBP screening broke a month ago can find out. Only the kind of
failure and when it happened are stored, never the password or its hash prefix.

## Email stops at the edge of production

A database carelessly copied down from production without scrubbing user data can bring real customer addresses *and*
whatever mail service production was using. A cron run, a bulk action or a
migration routine could email real people from a staging site or a laptop. Keel
suppresses outgoing mail on any environment that is not production, on by
default, and it says so in an admin notice rather than leaving you wondering
why a password reset never arrived.

"A local site cannot send mail anyway" is not a safeguard: measured on a stock
local install, the only thing stopping delivery is often an invalid default `From`
address, which a copied production `siteurl` removes. Commonly used SMTP
plugins, which a production copy carries along, may connects to
the real email provider and use it before you realize what is happening.

Keel short-circuits `wp_mail()` with `true`, not `false`, so a staging site does not
start exercising failure paths that production never takes. Turn it off under
**Settings → Keel**, or per-site with `KEEL_ALLOW_NONPRODUCTION_MAIL` or the
`keel_suppress_nonproduction_mail` filter. `keel_outgoing_mail_suppressed` fires
in place of the send, so a mail catcher can still record the message.

The second email default is a warning, not a change: Keel flags a production site
whose `From` address looks undeliverable, and it catches the bulk password reset that
reports success after sending zero emails.

## How it's built

One array — `keel_defaults_schema()` — is the single source of truth for structure.
It drives both the settings screen and the bootstrap that wires each *enabled*
default to its WordPress hook. Adding a default is usually one schema entry, one
`if`-block in bootstrap, and its display copy in `includes/strings.php` under the
same key; no new settings-page code. Several defaults share a bootstrap block where
they belong together — the XML-RPC family is one — so that is the shape rather than
a rule. A default is an opinionated filter behind a toggle.

Two things Keel does that most settings screens usually don't do:

- **Site Health reports the posture**, read-only — every default and its current
  state, so the site's actual configuration is legible without clicking through
  tabs.
- **It reports structural policy overlaps without executing foreign code.** Keel
  reports an attributable plugin only when both plugins are registered on an
  authoritative policy hook. That confirms shared ownership, not disagreement, so
  the report asks you to compare settings and never recommends deactivation from
  callback presence alone. Unattributable and compositional hooks stay
  informational. Keel also stays off a hook entirely when its setting would only
  repeat what WordPress already does.

## Try it in the browser

A [WordPress Playground](https://playground.wordpress.net/) blueprint spins up a
throwaway site with Keel installed, so you can see all 39 defaults and their
states without a host or a local WordPress.

**[▶ Try the latest release](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/keel/main/playground/blueprint-stable.json)** — byte-identical to what you would download, and it follows each new stable release.

**[▶ Try the rolling build](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/keel/main/playground/blueprint-hosted.json)** — the `latest` pre-release, for previewing changes before they are cut.

Both open **Settings → Keel** and create a published post, so the content
defaults — comments, pingbacks, author archives, attachment redirects — have
something to act on. An empty site makes half the toggles look inert.

`latest` is republished by `release.yml` on every version tag, not on every push
to `main`, so the rolling demo can sit behind the branch. Check what it is
actually serving before reading anything into it —
[`playground/README.md`](playground/README.md) has the one-line `curl`.

## Install

Copy the plugin folder into `wp-content/plugins/` and activate, or install the built
zip. On activation the documented defaults are seeded; then visit **Settings → Keel**.

## Licence and credits

[GPL-2.0-or-later](LICENSE) — the same terms as WordPress itself. Keel is a de-branded
evolution of [Better by Default](https://github.com/WPYEG/Better-by-Default) (WPYEG),
whose sole author also licenses the portions carried over here under GPL-2.0-or-later,
with further defaults adapted from the Pixel Managed Platform plugin — itself a hard
fork of [10up Experience](https://github.com/10up/10up-experience) by 10up
(GPL-2.0-or-later), from which several of those defaults ultimately descend. 10up
retains its copyright and marks; Keel is not affiliated with or endorsed by 10up. Full
attribution is in the Credits section of `readme.txt`.
