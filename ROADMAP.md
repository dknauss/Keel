# Keel — roadmap

Where Keel is going, and what has to be true before each step. Milestone-level; the
task-level checklist is [TODO.md](TODO.md).

**Current version: `0.1.0-dev`.** Requires WordPress 6.4+, PHP 7.4+, tested to 7.0.
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

- [ ] **Multisite governance.** Network-admin settings screen, network-scoped policy
      pushed to subsites, Super-Admin-only controls. Promoted from v2 on 2026-08-04.

      The reason it is worth doing now is that the gap is already documented and
      user-visible. `readme.txt` tells multisite operators that the password policy is
      stored per site but takes effect network-wide — because WordPress keeps one user
      table — and that "the strictest site on the network sets the floor for anyone who
      changes their password on it." That is an honest description of a design that
      nobody chose, and the FAQ has to spend a paragraph explaining it.

      Network-scoped policy replaces that paragraph with a setting. It is also the one
      remaining item that changes what the plugin can do, rather than what is known
      about it.

      Prior art is in Pixel: `wpmu_options` / `ms_save_settings` for the network screen,
      `get_site_option` fallbacks, and Super-Admin-only capability checks. Multisite-aware
      seeding already landed here in keel#29, so the lifecycle half is done.

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
- [x] **Reference doc coverage** — done 2026-08-04 (keel#18). All 37 schema keys have an
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
      37 defaults with v0.2, which is what this was waiting on. `README.md` gained the
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
- [ ] **Probe Clearfy (50k installs) and WP Master Toolkit (5k).** Both were code-reviewed
      only in [docs/competitive-teardown-matrix.md](docs/competitive-teardown-matrix.md);
      neither has been measured. They are the closest architectural peers to Keel — a
      menu of independently toggleable defaults rather than a single-purpose switch — so
      they are the most informative comparison and the most likely to surface a technique
      worth adopting. Review suggested Clearfy has the field's other correct pingback
      teardown (`xmlrpc_methods` plus an `xmlrpc_call` guard) and no REST teardown at all
      in the free tier; WP Master Toolkit pairs `xmlrpc_enabled` with a
      `wp_xmlrpc_server_class` swap. Confirm or correct both by measurement.
- [ ] **Probe Classic Editor (9M) and Disable Gutenberg (500k)** on the editor surface,
      which the current matrix covers by code review only.
- [ ] **Re-run the matrix against a classic theme.** Every measurement to date is from a
      single block-theme install. Several comment-teardown rows — anything touching
      rendered markup or the comment template — are theme-dependent by construction.
- [ ] **Re-run against an older supported WordPress.** Keel claims 6.4+; the matrix was
      measured on 7.0.2 only.
- [x] **Make the probe harness part of the repo** — done 2026-08-04 (keel#21).
      `tests/integration/probe-teardown.sh`, plugin-agnostic, with the lab setup and the
      five traps in `tests/integration/README.md`. Rewritten for the repo rather than
      moved: paths are required arguments, and the authenticated session is minted per
      run instead of a committed cookie.

- [ ] **An XML-RPC help tab, in Keel and in Pixel.** The copy convention sends reasoning
      to the help tab, and `block_xmlrpc_endpoint` is the one description still carrying
      it — a third of its 62 words is "PHP still starts for each blocked request; blocking
      at the host, CDN or firewall is lighter", which is why-we-chose-this, not
      what-it-costs-you. Parked until now because it meant a tab for one paragraph. Pixel
      already has an XML-RPC tab, so the two sides differ: Keel needs the tab, Pixel needs
      the paragraph moved into the one it has.

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
- [ ] **Decide whether Keel's policy filters compare or assert.** They disagree with each
      other today. `keel_defaults_set_frame_option_header()` reads what another layer
      already sent, ranks it, and refuses to downgrade a stronger value.
      `keel_defaults_session_length()` ignores the incoming `$expiration` entirely
      whenever its own setting is non-zero — it compares its own two values with `max()`
      and never compares against anyone else's. Same plugin, two philosophies, and only
      the header one survives running alongside a sibling. The awkward part is that
      "stricter" for a session means *shorter*, so a comparing version hands victory to
      whichever plugin is most aggressive — which is not obviously right either. Worth a
      decision either way, rather than an accident.

- [~] **Connect the three test suites.** Partly done 2026-08-04. Keel and BBD each
      guard retired claims independently, and `tests/integration/assert-privacy.sh`
      now runs the *same behavioural probes* against any of the three by slug, which
      is the cross-repo check that was missing — an author-identity leak lived in two
      plugins while the fix sat in the third. Pixel's probe config stays outside this
      repo (`PROBE_CONFIG_DIR`), since it is private and Keel is public.

      What remains is the shared *fixture*: retired phrases and copy conventions are
      still duplicated per repo, so retiring a phrase once does not retire it
      everywhere. Pixel still has no copy guard at all.

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
