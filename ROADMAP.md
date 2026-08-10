# Keel — roadmap

Where Keel is going, and what has to be true before each step. Milestone-level; the
task-level checklist is [TODO.md](TODO.md).

**Current version: `0.2.0`.** Requires WordPress 6.4+, PHP 7.4+, tested to 7.0.
GPL-2.0-or-later.

> **Provenance note.** The original planning document is `~/Code/pixel-lite-scope.md`,
> outside this repository — so it is invisible to anyone who clones Keel, and it has
> already drifted (it records a GPL-3 decision; the plugin shipped GPL-2.0-or-later in
> `7822926`). This roadmap is the in-repo source of truth for direction. Where the two
> disagree, the repository wins. Fold anything still live out of the scope doc and into
> here or TODO.md, then retire it.

---

## Next up

Chosen deliberately over the probe-coverage items below, which strengthen claims
already made rather than adding capability.

- [x] **Multisite governance** — done 2026-08-09 (keel#89). Network Admin →
      Settings → Keel Defaults lets a Super Admin decide any of the 38 settings for
      the whole network; sites see those as locked and cannot change them.

      **Enforced at read, not pushed into subsites.** Writing values into every
      site's options would overwrite settings the site owner chose, need undoing if
      the policy were relaxed, and disagree with the network screen the moment a
      subsite saved its own form. Resolving at read means policy can be set and
      unset without touching a single subsite — verified on a real two-site network:
      a subsite storing the opposite value obeys the policy while the policy stands,
      and returns to its own value the moment it is lifted.

      **Presence is the switch.** A key in the network option is managed; a key
      absent is the site's own business. There is no separate "enforce" flag,
      because a flag that can disagree with a value is a third state to keep
      consistent and a fourth to get wrong.

      A wp-config constant still beats a network policy on the site screen. It is
      the operator's highest-level declaration and it is true of that site
      specifically, so saying "your network admin set this" when wp-config decided
      would send somebody to argue with the wrong person.

      `readme.txt` no longer has to explain the shared-user-table problem and then
      say Keel will not solve it — the FAQ now points at the screen that does.

---

## v0.2 — feature-complete for review — **complete 2026-08-04**

Everything a wordpress.org submission needs, before polish. Every item below is
closed; the milestone is done.

- [x] **Multisite-aware seeding** — done 2026-08-04 (keel#29). Network activation seeds
      every existing site, paged at 100; `wp_initialize_site` seeds subsites created
      later, but only when Keel is network-active, since a per-site activation means a
      new subsite does not have the plugin at all. Nothing is ever overwritten.

      Worth recording why it went unnoticed: an unseeded subsite *looks* correct.
      `keel_defaults_get()` falls back to the schema default, so the settings screen shows
      the documented values and the site behaves as documented. The divergence only
      appears when a default changes in a later release and the unseeded sites move with
      it while the seeded ones keep what they were given.
- [x] **Document the shared-user-table caveat** — done 2026-08-04. In the Passwords
      help tab, rendered only on multisite so a single-site admin is not told about a
      constraint that cannot apply to them, and in the readme FAQ where someone
      evaluating the plugin will look. The framing that took a second pass: the setting
      is stored per site but does not *act* per site, so the strictest site on a network
      sets the floor for anyone who changes their password there. Documented, not
      governed — network-wide policy stays a v2 item.
- [x] **Reference doc coverage** — done 2026-08-04 (keel#18). All 38 schema keys have an
      entry. It was 16 missing, not the handful assumed here: thirteen absent outright,
      three more (`remove_version`, `security_headers`, `frame_options`) described in
      prose but never keyed, so searching for the key found nothing.
- [x] **Schema-key reconcile** — done 2026-08-04 (keel#18). Worse than described: the
      mismatch was not BBD naming but a prefix that has never existed. Every entry listed a
      standalone `keel_<key>` option, when settings live in the single `keel_settings`
      array read through `keel_defaults_get()` — which the document's own introduction
      states three paragraphs above the first contradiction. Two quick-reference rows named
      keys with no counterpart in any version of the plugin.
- [x] **`uninstall.php`** — done 2026-08-04 (keel#27), and "trim" was the wrong verb.
      There was no `uninstall.php` and no `register_uninstall_hook`: nothing was cleaned
      up at all, so deleting the plugin left `keel_settings`, every user's
      `keel_last_login`, and every accumulated breach-cache transient behind. Now removed
      across every site on a network, with `tests/uninstall-coverage.php` reading the
      plugin's own source for storage keys so a default added later cannot silently start
      leaving data behind.

## v1.0 — wordpress.org submission

Plugin Review requirements, not niceties.

- [x] **`readme.txt` to spec** — done 2026-08-04 (keel#23). The header fields and the
      HIBP disclosure were already right; Installation, Frequently Asked Questions and
      Upgrade Notice were missing. `tests/readme-spec.php` now pins the contract rather
      than the prose — every field Review parses, the five-tag limit, the 150-character
      short description, the sections, and each named part of the external-services
      disclosure. The assertion that matters most is `Stable tag` against the plugin
      `Version`: they drift silently, the failure lands at release, and it ships the
      wrong code to every existing install.
- [x] **Expand `readme.txt` / `README.md`** — done 2026-08-04. The feature set froze at
      38 defaults with v0.2, which is what this was waiting on. `README.md` gained the
      measured comparison against the field; `readme.txt` gained the same in prose, a
      Site Health paragraph, and an FAQ for running alongside another defaults plugin.
      Two stale claims went with it: the "in progress" status, and an FAQ line saying
      REST authentication stops other sites embedding your posts — no longer true since
      the `oembed/1.0` carve-out.
- [x] **Trademark glance on "Keel"** — closed 2026-08-04 **as a decision, not as a search.**
      Read the next paragraph before treating this as cleared.

      What was established: the **wordpress.org slug is free** — `keel` and `keel-defaults`
      both return "Plugin not found", and a directory search for "keel" returns four
      unrelated plugins. That was the practical blocker for submission and it is clear.

      What was **not** established: the register. USPTO Trademark Search and CIPO's
      database are JavaScript applications with no reachable public API, and every
      third-party mirror (Justia, Trademarkia, uspto.report) refuses automated requests
      with a 403. No search of live marks in Nice classes 9 or 42 was performed.

      One lead, unverified: **KEEL SYSTEMS LLC** appears as a USPTO owner, holding
      "CAMTRACK" (serial 87756860, 2018) for database-management software. An entity
      named Keel operating in software — not a registered KEEL word mark.

      To actually do it, in a browser: `tmsearch.uspto.gov` and CIPO's Canadian
      Trademarks Database, word mark `keel`, live marks, classes 9 and 42; or WIPO's
      Global Brand Database for both at once. Expect boat and shipping marks to dominate
      the results — the ones that matter are software and SaaS. Note the shipped name is
      "Keel Defaults", not bare "Keel".

      **This remains a legal question and nothing here is advice.** The item is closed
      because the release-blocking part is answered and the rest is the owner's call, not
      because a register was searched.

      What a common-law sweep on 2026-08-04 did turn up, so whoever runs the real search
      starts from something: **keel.so** (London, founded 2022 — a code-first backend and
      operations platform, developer-facing and actively marketed), **keel.money**
      (fintech, virtual IBANs), and **Keel Info Solution** (keelis.com, custom software
      development). The earlier note here said the w.org slug is free and `keel.sh` is
      unrelated devops tooling; that understated the field. There are at least three
      commercial software users of the name, and `keel.so` is the one closest to the
      goods a filing would cover. Coexistence in a narrow channel is common and this does
      not make the name unusable — it makes a paid search worth having before the name is
      in print.
- [x] **Test spine** — done 2026-08-04 (keel#24), and the item as written was wrong.
      It said the regression suite, metrics guard, doc-coverage and badge sync "still
      reference the pre-rename tree". Nothing did. The sentence was carried over from the
      scope document, which describes Pixel's tooling: Keel has no metrics guard and no
      badge to re-point, and the doc-coverage check (`tests/docs-consistency.php`) was
      written here from scratch.

      Checking it found two real defects instead. `composer test` listed nineteen test
      files by name when there were twenty, so `tests/readme-spec.php` was never run from
      the moment it merged — a hand-maintained list silently stops running the next test
      somebody adds, and still exits zero. It enumerates `tests/*.php` now. And nothing
      ran on a pull request at all: `release.yml` fires on version tags, so lint and the
      suite first ran at the last possible moment to find a failure. `ci.yml` runs
      `php -l`, `composer lint` and `composer test` on every pull request and push to
      `main`.

      The instructive part is which side was stale. `release.yml` already enumerated, so
      the release gate was right and the developer-facing command was wrong — a
      contributor got a green local run for a suite that was not running everything.

- [x] **A PHP-version matrix in CI** — done 2026-08-04. `ci.yml` gains a `compat` job
      running the syntax check and the full suite on 7.4, 8.0, 8.1, 8.2, 8.3 and 8.4, so
      the floor every header claims is tested rather than asserted. `fail-fast: false`,
      because a failure on the floor and a failure on the newest release are different
      problems and you want both.

      Kept separate from the existing job on purpose: `test` answers "did this change
      break anything" with the linter, `compat` answers "does it still run where we say
      it does" without one. Folding them together would run phpcs six times for no
      reason, or let a coding-standards failure read as a compatibility failure.

      No `composer install` in the matrix — the plugin has no runtime dependencies and
      the suite has no autoloader, so installing dev tooling would test whether phpcs
      supports PHP 7.4, which is not the question.
- [x] **Probe Clearfy (50k installs) and WP Master Toolkit (5k)** — done 2026-08-09
      (keel#86). Both measured; the matrix moves them from code review to live, and
      two of its claims needed correcting.

      **Clearfy 403s `xmlrpc.php` outright**, which the code review missed. Its
      careful per-method work — unsetting the pingback methods, the `xmlrpc_call`
      guard — sits behind a door that is already shut, so it is not granular in
      practice and the XML-RPC rows moved from ⚠️ to ❌. The "no REST teardown in
      the free tier" claim was confirmed rather than corrected.

      **WP Master Toolkit's REST teardown 401s oEmbed**, where Keel's stays 200 —
      the trade Keel's non-goals argue from the other side. Its comment teardown is
      a **pro** module, so the free tier reads exactly like stock: feed 200,
      `X-Pingback` advertised, and a posted comment lands in the database. That is
      "not offered" rather than "does nothing".

      Neither closes server-side comment reads, so headline finding 3 now covers
      every comment-capable plugin in the field. Neither breaks the block editor.

      Configuring them took two attempts each, and both traps are recorded in the
      probe configs: WPMT writes no option on activation, so an unconfigured probe
      measures every module off; and Clearfy's bundled comments component declares
      one option prefix and reads another, which left it inert while the class was
      loaded, the helper defined and the option present with the right value.

- [x] **Probe Classic Editor (9M) and Disable Gutenberg (500k)** — done 2026-08-09
      (keel#87). The editor surface needed a harness of its own:
      `tests/integration/probe-editor.sh` renders the post-edit screen as a
      logged-in administrator and counts what came back.

      All three replace the editor — the block-editor container is gone from the
      edit and new-post screens in every one, with TinyMCE loaded instead. Classic
      Editor is the only plugin in the whole matrix that needs no configuration at
      all; Disable Gutenberg ships `disable-all => 1` in its defaults and is the
      only one that adds per-row editor links to the posts list.

      Two things the run taught the harness. `auth_redirect()` resolves its scheme
      through an empty string, so wp-admin validates the **auth** cookie while REST
      accepts `logged_in` — sending only the latter gets a 302 to wp-login and the
      probe reports every marker as 0, which reads exactly like a plugin stripping
      the editor. The script sends both and now refuses to run if the edit screen
      is not a 200. And a filter registered from an admin-only hook is invisible
      under CLI, which is why Disable Gutenberg's `use_block_editor_for_post_type`
      reads `block` there while its rendered screen is the classic editor — the
      precise reason this column could not be settled by reading code.
- [~] **Re-run the matrix against a classic theme.** Keel done 2026-08-09 (keel#79);
      the other nine plugins are not, and their two rendered-markup rows remain
      block-theme figures.

      **Keel's teardown is theme-independent, measured rather than assumed.** With
      Keel configured, switching Twenty Twenty-Five to Twenty Twenty-One changed
      *nothing* — not the two markup rows, not any of the other thirty-odd probes.
      Both report `html.comment_form 0` and `html.comments_block 0`.

      The reason is that none of it runs at the theme layer. Keel closes comments in
      the data — `comments_open()` false, post-type support removed,
      `get_default_comment_status()` closed — so a classic theme's
      `comments_template()` renders nothing because `comment_form()` is gated on
      `comments_open()`, and a block theme renders nothing because the same state
      reaches `render_block`. One mechanism, two themes.

      The item was right to exist even though the answer was clean, and the stock
      baseline shows why: `html.comments_block` reads **0 on a classic theme with no
      plugins at all**, because `wp-block-comments` is a block-theme marker. On a
      classic install that row scores every plugin as passing, including ones that do
      nothing. A comparison run there would have published a false result for the
      whole field.
- [~] **Re-run against an older supported WordPress.** Keel done 2026-08-09
      (keel#82) on **6.4**, the exact floor the header claims — six releases below
      everything else in the matrix.

      **Zero differences across all 38 probes**, and the control is the part that
      makes that mean something: stock 6.4 and stock 7.0.2 are byte-identical with
      no plugins, while Keel moves 26 rows against stock on 6.4. The comparison can
      show a difference; there is not one to show.

      The HTTP probe cannot see the admin, so the settings screen, both Site Health
      surfaces and the info stylesheet were rendered on each version too — identical
      output apart from the nonce, and no PHP diagnostics raised from `plugins/keel/`
      on either. Every WordPress function Keel calls exists on both; the only names
      undefined on 6.4 are undefined on 7.0.2 as well, being multisite-only and
      called behind `is_multisite()`.

      The diagnostics listener was verified by planting an undefined variable and
      watching it report, since a clean result from a check that cannot fire is
      worth nothing.

      **Left open, and why this is `[~]`:** 6.4 was measured on PHP 8.5, single
      site. The floor is a *pair* — `Requires at least: 6.4` and `Requires PHP: 7.4`
      — and the corner that matters is old WordPress on old PHP, which this run did
      not touch. Nor did it exercise multisite, which is precisely where the three
      functions above live. The other plugins in the matrix remain measured on
      7.0.2 only.
- [x] **Make the probe harness part of the repo** — done 2026-08-04 (keel#21).
      `tests/integration/probe-teardown.sh`, plugin-agnostic, with the lab setup and the
      five traps in `tests/integration/README.md`. Rewritten for the repo rather than
      moved: paths are required arguments, and the authenticated session is minted per
      run instead of a committed cookie.

- [x] **An XML-RPC help tab, in Keel** — done 2026-08-09 (keel#69). The tab covers
      the family rather than the one orphaned paragraph: what XML-RPC is, why four
      switches instead of one, the Jetpack constraint, why blocking inside WordPress
      still costs a request, and the out-of-date reputation of `system.multicall`.
      `block_xmlrpc_endpoint`'s description drops from 62 words to 45 and points at
      the tab. Guarded four ways in `tests/settings-copy.php`, each break-tested.

      **Pixel still needs the paragraph moved** into the XML-RPC tab it already has.
      Tracked there, not here.


- [x] **A policy-collision report in Site Health** — done 2026-08-04, in *both*
      plugins (px#238, keel#26). Detects by hook rather than by plugin name and
      attributes foreign callbacks by reflection into `WP_PLUGIN_DIR`, so a plugin
      nobody has heard of is named like a familiar one. Reports only hooks where a
      callback replaces its input, and never says which plugin to keep — a check
      answering that would be a plugin arguing for its own retention.

      **The better fix turned out not to be the report.** Better by Default asked
      whether the setting says anything WordPress does not already do and stopped
      registering when it did not (WPYEG#41); Keel and Pixel followed (keel#39,
      px#241). `auth_cookie_expiration` went from three contestants at the same
      priority to **zero at defaults**, and the check now reports it uncontested
      because the conflict shrank rather than because it stopped looking.

      Still unbuilt, and still the strongest form: **test the effect, not the
      registration** — run `apply_filters()` with our callback in place, then again
      with it removed, and compare. Needs no knowledge of the other code and would
      catch mu-plugins and themes, which reflection-based attribution skips.
- [x] **Decide whether Keel's policy filters compare or assert** — decided 2026-08-09
      (keel#72). **Both, and the rule is: compare where an incoming value is evidence of
      a decision, assert where it is not.** The two filters were not inconsistent; the
      reason they differ was simply never written down.

      What settles it is what an incoming value proves on each hook, and that is a fact
      about core rather than a preference:

      - **`wp_headers`.** Core never seeds `X-Frame-Options` into the array —
        `send_frame_options_header()` emits it with `header()` and never touches the
        filter (`wp-includes/functions.php:7194`). So a value present in the array means
        some layer decided on one. That is evidence, ranking it is meaningful, and
        refusing to downgrade is right.
      - **`auth_cookie_expiration`.** Core *always* passes a number — 2 or 14 days from
        `wp_set_auth_cookie()` (`wp-includes/pluggable.php:1082`, `1091`). "There is an
        incoming value" therefore carries no information whatsoever, and core's own
        default is indistinguishable from another plugin's deliberate one. Comparing
        would cap a site's explicit 30-day setting at core's 14 while the settings screen
        went on displaying 30.

      The item's stated worry — that comparing hands victory to whichever plugin is most
      aggressive — is real but downstream of that. Even if shorter-wins were desirable,
      there is nothing here to compare *against*.

      **One live defect fell out of asking.** Core's Customizer sets
      `X-Frame-Options: SAMEORIGIN` on the previewed front end at priority 10 so the
      preview loads in its iframe (`WP_Customize_Manager::filter_iframe_security_headers()`).
      Keel runs at 99, so a site configured `DENY` escalated core's value — confirmed by
      running both filters in order on a real install. The preview kept working only
      because core sets `frame-ancestors 'self'` in the same call and CSP takes
      precedence over X-Frame-Options wherever both appear. A functional header surviving
      behind another header's protection is not something to keep leaning on, and
      "stronger" is the wrong question when the incoming value exists to make a feature
      work rather than to state a posture. Keel now leaves a Customizer preview alone.

      `tests/policy-conflicts.php` pins the rule rather than the two behaviours, because
      the regression to fear is a tidying refactor that makes both filters agree in
      style — and either direction is a real break. Break-tested in both directions plus
      the Customizer carve-out.

      Worth recording how the session half was nearly under-tested: a single
      incoming-value-insensitivity check happened to exercise only one of that
      function's three exits, and a `min()` planted on the Remember Me disabled branch
      passed it. The assertions enumerate all three now.

- [~] **Connect the three test suites.** Partly done 2026-08-04. Keel and BBD each
      guard retired claims independently, and `tests/integration/assert-privacy.sh`
      now runs the *same behavioural probes* against any of the three by slug, which
      is the cross-repo check that was missing — an author-identity leak lived in two
      plugins while the fix sat in the third. Pixel's probe config stays outside this
      repo (`PROBE_CONFIG_DIR`), since it is private and Keel is public.

      What remains is the shared *fixture*: retired phrases and copy conventions are
      still duplicated per repo, so retiring a phrase once does not retire it
      everywhere. Pixel still has no copy guard at all.

- [~] **An accessibility sweep of the settings screen.** Static sweep done
      2026-08-09 (keel#74). The four surfaces below were audited against WCAG 2.1
      AA; two carried real defects and are fixed, two are reported unchanged and
      still want a screen reader. The premise held up exactly as written — there
      was real care in the code, almost no coverage of it, and a hard failure
      living inside the careful part.

      **Fixed.**

      - **The environment indicator failed WCAG 1.4.3.** Staging rendered white on
        `#d79d00` at **2.41:1**, against the 4.5:1 that applies at the admin bar's
        text size. It had shipped that way since the indicator was written, and it
        was invisible in review because `#d79d00` reads as a perfectly ordinary
        amber — a colour is three bytes, and whether it is legible is arithmetic
        nobody does by hand. Now `#8f6800` (5.06:1, and 3.14:1 against the bar
        itself). Production, development and local always passed.
      - **The width slider announced a number instead of a word.** The value is a
        position, the meaning is "Narrow" or "240px", and only the position was
        exposed — a screen reader said "2" while the visible output beside it read
        "240px" (WCAG 4.1.2). `aria-valuetext` now carries the word and tracks the
        stored value. Its fieldset was also the only one of 28 with no `<legend>`.
      - **The readout announced twice.** With `aria-valuetext` carrying the word,
        the `<output>` — implicitly a polite live region — repeated it on every
        arrow-key press. It is now `aria-live="off"`: visible, and silent. That
        answers the item's open question about noise; it was noise.

      **Reported, deliberately unchanged.**

      - **Locked controls.** `disabled` removes a control from the tab order, so
        the `aria-describedby` lock note is not announced on focus — there is no
        focus. The deliberate "reason before label" ordering therefore does not
        happen. It is *not* unreachable: the note renders as visible `<p>` text
        immediately after the control, so linear reading finds it. The accessible
        fix is `aria-disabled` plus a focusable control, which changes what the
        form submits and undoes the hidden-field preservation that keeps a save
        under a constant from flipping the stored value. Not worth doing blind.
      - **Dependent rows.** Hidden with `display:none`, which is the correct
        technique — it removes them from the accessibility tree rather than
        leaving a phantom. Focus cannot be stranded, because hiding is always
        driven by a change on the controlling checkbox, which holds focus at that
        moment. What is missing is any announcement that a row appeared, and any
        programmatic controller/row relationship. This is a quality gap rather
        than a conformance failure: a row disappearing when it cannot apply is not
        a change of context under 3.2.2, and the Settings help tab already
        explains the behaviour.

      **Guarded.** `tests/accessibility.php` computes WCAG relative luminance and
      fails any environment colour under 4.5:1 — checking the formula against
      known values first, since a contrast test that quietly computed nonsense
      would pass everything. It also pins that colour is never the only signal,
      and that the narrow-viewport label is *clipped* rather than
      `display:none`'d. `tests/settings-render.php` pins `aria-valuetext` in both
      states, the silent output, and a `<legend>` on all 28 fieldsets.

      **Still open, and the reason this is `[~]` and not `[x]`:** none of this is
      a screen-reader pass. Everything above is markup and arithmetic. Whether the
      slider is pleasant to operate in NVDA, whether the lock note is found before
      the control it explains, and whether a returning dependent row is noticed at
      all are questions only VoiceOver/NVDA can answer — and the second and third
      are exactly where the two unchanged findings sit.

## v2 — deferred by decision

*(Multisite governance was here. Promoted to Next up, 2026-08-04.)*

---

## Non-goals

Recording these so they stop being re-litigated:

- **Plugin/theme governance screens.** Dropped deliberately — blocking admin screens is a
  wordpress.org review problem, and it is not what a defaults plugin is for.
- **Growing past Better by Default scale.** The budget is roughly 2.5–3.5k LOC against
  the 1,329-LOC base. Pixel Managed Platform is ~24k. The smallness is the point: every
  default is one schema entry plus one bootstrap `if`-block.
- **Quiet holes in security toggles.** Rewritten 2026-08-04, after measurement showed the
  absolute version was not supported by the facts. It read: blocking anonymous REST takes
  oEmbed down with it, and that does not get worked around. The rule now is narrower and
  keeps what was actually worth having:

  > A public route may stay reachable past a security gate only if it discloses nothing
  > the gate was closed to protect. Holes are announced, filterable, and tested — never
  > quiet.

  What changed the position: blocking oEmbed costs less than assumed and the leak costs
  more. Self-embeds resolve internally — `wp_oembed_get()` on your own post renders
  without an HTTP request — so closing the route never touches your own site, only other
  sites embedding your posts, which degrade to a plain link. But left open it answered
  anonymous callers with `author_name` and an `author_url` carrying the account nicename,
  on a site whose `/wp/v2/users` was returning 401 two lines away. The hole was cheap to
  keep and the disclosure was the whole problem, so `keel#32` keeps the route and the
  author fields are now stripped whenever the gate is on.
- **A server-side password entropy meter.** Decided 2026-08-03. The only credible way to
  add one is vendoring `bjeavons/zxcvbn-php` — 5.9 MB, 4.6 MB of it dictionaries —
  into a plugin whose entire `require` block is `php >= 7.4`. Core's zxcvbn is
  JavaScript only (`wp-includes/js/zxcvbn.min.js`, registered in `script-loader.php`,
  no PHP counterpart), so it is advisory UI that Keel already gets for free and cannot
  enforce with: stock WordPress accepts `aaa` through WP-CLI, REST, or a form post with
  JS disabled. The policy stays length + Have I Been Pwned + local blocklist +
  personal-context. The residual exposure is a long, invented, unbreached, low-entropy
  password, and the help text says so rather than implying otherwise. Revisit only if a
  dependency-free estimator with a real dictionary appears.
