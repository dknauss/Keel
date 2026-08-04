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

## v0.2 — feature-complete for review

Everything a wordpress.org submission needs, before polish.

- [ ] **Multisite-aware seeding.** v1 target is multisite *compatible*, not governed:
      network-activatable without breakage, each subsite storing its own settings. Not
      free — the Better by Default base has essentially no multisite awareness (a single
      `register_activation_hook`, no per-site or new-subsite seeding). Port the
      network-aware lifecycle: seeding keyed on an `$is_network` flag plus a
      `wp_initialize_site` hook so new subsites get seeded.
- [ ] **Document the shared-user-table caveat.** The password policy acts on the
      network-wide user table, so on multisite it is effectively network-wide even when
      configured per-site. Documented in v1, not governed. (This applied to the
      reserved-usernames default too, until `500c561` removed it.)
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
- [ ] **Trim `uninstall.php`** to the option set Keel actually ships.

## v1.0 — wordpress.org submission

Plugin Review requirements, not niceties.

- [ ] **`readme.txt` to spec** — Stable tag, Tested up to, Contributors, and an explicit
      external-services disclosure for HIBP (`api.pwnedpasswords.com`, k-anonymity: only
      a SHA-1 prefix leaves the site, with an opt-out filter). Review blocks without it.
- [ ] **Expand `readme.txt` / `README.md`** once the feature set is frozen.
- [ ] **Trademark glance on "Keel"** — USPTO and CIPO, Nice classes 9/42 — before the name
      is in public print. The w.org slug is free and `keel.sh` is unrelated devops
      tooling, but neither is a clearance.
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

- [ ] **A PHP-version matrix in CI.** Keel claims a 7.4 floor and CI tests one version:
      whatever `ubuntu-latest` ships. Deliberately left out of keel#24 — "does this run
      on the floor we advertise" is a different question from "did this change break
      anything", and answering both in one job is how a green tick stops meaning
      something. Small once someone wants it.

## v1.x — verification and evidence

Keel's pitch is that it tells you exactly what it does to your site. That claim has to
be re-measurable, not asserted once.

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

- [ ] **A policy-collision report in Pixel's Site Health.** Two plugins can set the same
      policy through the same hook and the loser is silent: Keel and Pixel both register
      `auth_cookie_expiration` at priority 50, so the last one registered wins outright
      and neither compares. They agree today, which is exactly when nobody looks.
      Detect **by hook, not by plugin name** — a rival list only knows yesterday's
      plugins. Attribute a foreign callback by reflection → file path → `WP_PLUGIN_DIR`.
      The strongest form, and the one nobody has built: **test the effect, not the
      registration** — run `apply_filters()` with our callback in place, then again with
      it briefly removed. If removing ourselves changes nothing while another callback is
      on the hook, we are being overridden. That needs no knowledge of the other code and
      catches mu-plugins and themes too. Report, never deactivate: mutual exclusion is
      hostile and would not help against the third-party plugin nobody has a list for.
      Design carried over from a prototype that was written and discarded unmerged.

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

- [ ] **Connect the three test suites.** BBD asserts against a phrase Keel was shipping,
      and only a manual audit found it: the suites do not know about each other, and the
      matrix that does is a document, not a check. Cheapest useful form is a shared
      fixture — retired claims, and the conventions each repo already tests
      independently — read by all three, so retiring a phrase once retires it everywhere.
      Keel and BBD each have a guard now; Pixel has none.

## v2 — deferred by decision

- [ ] **Multisite governance.** Network-admin settings screen, network-scoped policy
      pushed to subsites, Super-Admin-only controls. Explicitly deferred from v1.

---

## Non-goals

Recording these so they stop being re-litigated:

- **Plugin/theme governance screens.** Dropped deliberately — blocking admin screens is a
  wordpress.org review problem, and it is not what a defaults plugin is for.
- **Growing past Better by Default scale.** The budget is roughly 2.5–3.5k LOC against
  the 1,329-LOC base. Pixel Managed Platform is ~24k. The smallness is the point: every
  default is one schema entry plus one bootstrap `if`-block.
- **Punching holes in security toggles for convenience.** Blocking anonymous REST takes
  oEmbed down with it. That consequence gets named in the help text; it does not get
  quietly worked around.
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
