# Keel — roadmap

Where Keel is going, and what has to be true before each step. Milestone-level; the
task-level checklist is [TODO.md](TODO.md).

**Current version: `0.6.1`**. Keel was first published on wordpress.org as
`keel-defaults` on 2026-08-26. Requires WordPress 6.4+, PHP 7.4+, tested to 7.1.
GPL-2.0-or-later.

> **Provenance note.** The original planning document is `~/Code/pixel-lite-scope.md`,
> outside this repository — so it is invisible to anyone who clones Keel, and it has
> already drifted (it records a GPL-3 decision; the plugin shipped GPL-2.0-or-later in
> `7822926`). This roadmap is the in-repo source of truth for direction. Where the two
> disagree, the repository wins. Fold anything still live out of the scope doc and into
> here or TODO.md, then retire it.

---

## Next — offer the patch on the Updates screen

**The gap.** Keel's panel says "Install 6.4.10 now from the Updates screen" and links
there. In the `visible` state that works. In the `none` state — which is the common one —
the Updates screen will not offer 6.4.10 at all, because `get_core_updates()` drops every
`autoupdate` offer: an unconditional `continue` in `wp-admin/includes/update.php`, before
the `dismissed` and `available` options are even read. So the panel currently names a
screen and that screen does not deliver, which is a sharper version of the problem this
feature exists to solve.

The install button covers the `none` state, and it works. But it lives in Site Health,
and Site Health is not where an administrator goes to update WordPress.

**The fix.** `do_action( 'core_upgrade_preamble' )` fires at `wp-admin/update-core.php`
line 1139, inside the block that lists core updates. Keel can render the same-line offer
there, beside core's own "Update to version 7.1", and answer the omission where it
happens rather than somewhere else.

- **U1** — render the offer on `core_upgrade_preamble`, reusing the existing actions
  markup. The Updates screen does not filter through `wp_kses_post`, so the form
  survives; assert that against the rendered screen rather than the builder.
- **U2** — present it as the comparison being made: 7.1 is available, 6.4.10 fixes the
  vulnerability without the major change. Alongside core's block, not passing as part of
  it.
- **U3** — revisit the panel copy. "Install %s now from the Updates screen" becomes true
  in every state once U1 lands, and the `none`-state wording that explains the screen
  will not offer it can go.

**Sequencing.** PX is doing the same work as C13-C15 in `keel-px-core-patch-port.md`,
unreleased, so it goes first and Keel takes whatever that run teaches. Ships as 0.6.2;
no schema change, no migration.

## Now — release and observe 0.6.0

- [ ] **Release 0.6.0** — merge the final documentation and regression coverage,
      require CI on the exact merge commit, then tag that commit. No additional
      feature work belongs in the release candidate.
- [ ] **Validate the published 0.6.0 artifacts** — confirm the GitHub release ZIP,
      WordPress.org SVN tag and directory version all contain the tagged tree; then
      check the public screenshots, upgrade notice and both Playground links.
- [ ] **Observe the first post-release matrix** — review the next scheduled live
      rollback/forward run and early field reports before starting another release.

## Next — 0.7.0 privacy and content integrity

Build in this order. The first closes a measured disclosure. The shortcode work
comes next because it pairs a reader-facing correction with evidence in Site Health;
the typography control is useful but strategically smaller.

- [ ] **Keep password-protected posts out of site search** — a logged-in Subscriber
   can currently receive the title and excerpt of protected posts in search because
   core's `post_password = ''` search guard applies only to logged-out visitors.
   Preserve access for authors, editors and plugin-granted roles that may legitimately
   read the post. This composes with `disable_post_passwords`, which prevents new
   protected posts but does not address existing ones.
- [ ] **Hide broken-shortcode residue and report it in Site Health** — ship these as
   a pair: readers should not see raw shortcode debris, while administrators retain
   evidence that a plugin or shortcode handler is missing.
- [ ] **Leave typographic punctuation as typed** — add a focused toggle around
   `wptexturize`, with editor, feed and front-end coverage so the label does not
   promise more surfaces than the implementation controls.

## Then — 0.8.0 performance observability

WordPress already reports page-cache, persistent-object-cache, opcode-cache,
scheduled-event and aggregate autoload problems. Keel should not restate those
tests. It should supply the attribution and next action that the aggregate result
cannot: what is responsible, how confident that attribution is, and what an
administrator can safely inspect next.

- [ ] **Attribute oversized autoloaded options** — report the largest autoloaded
   option rows, their sizes and a probable plugin or theme owner where the name gives
   defensible evidence. Never expose option values, present an inferred owner as
   certain, delete an option, or claim that size alone proves waste.
- [ ] **Attribute cron pressure and update-delivery delays** — identify overdue and
   unusually frequent hooks, their schedules and probable owners, then connect the
   core-update subset to offer-cache age, the next scheduled check and the last known
   failure. Do not treat `DISABLE_WP_CRON` as failure when an external scheduler may
   be intentional, or promise when an update will run.

These checks establish a reusable diagnostic shape: stable result code, severity,
observed evidence, probable owner, confidence and suggested action. They are
report-only first, asynchronous and cached, bounded on large databases,
multisite-aware, and tested with persistent caches, external cron and localized
installs. Site Health must not become the performance problem it is diagnosing.

## Then — 0.9.0 update operations and support

- [ ] **Show the core-update delivery timeline** — bring the selected release,
   branch-tip status, offer freshness, next scheduled check and recent success or
   failure into one evidence-based view. Reuse core's answers and Keel's stable
   blocker codes; do not infer a guaranteed schedule from policy alone.
- [ ] **Toggle plugin and theme auto-updates** — three states, `enabled`, `disabled`
   and `unset`, applied through the `auto_update_plugin` and `auto_update_theme`
   filters. `unset` is the default and must stay it: the per-item checkboxes on the
   Plugins screen are a deliberate choice, and a defaults plugin silently overriding
   them would be the failure this project exists to avoid.

   **Not "minor updates only", and the reason is structural.** That split is real for
   core because core defines and enforces its own versioning —
   `Core_Upgrader::should_update_to_version()` implements the channels, and it is
   called for `'core'` only. Plugins and themes reach the same decision through
   `! empty( $item->autoupdate )` and the `auto_update_{$type}s` opt-in array. There
   is no channel because wordpress.org neither requires nor verifies semantic
   versioning: a plugin may ship `1.9 → 2.0` for a typo and `3.4.1 → 3.4.2` for a
   break. A "security and maintenance only" label is honest on core and a guess on
   plugins, and it would fail selectively — auto-installing precisely the breaking
   releases whose authors do not use semver.

   Report instead of guessing: how many items have auto-updates on, whether the
   option or a filter is deciding, and **which items a filter is overriding**, which
   nothing surfaces today. If risk-tiering is wanted later, the honest inputs are a
   moved `requires`/`requires_php` or the author's own `upgrade_notice`, not the
   version number.
- [ ] **Export a sanitized Keel posture report** — make settings, effective state,
   conflicts, update operability and diagnostic evidence easy to share for support.
   Exclude secrets, option values, user data and unnecessary site identifiers.

   Prior art: [VerCheck API](https://wordpress.org/plugins/vercheck-api/) and
   [Updawa](https://wordpress.org/plugins/updawa/) both serve this inventory over
   bearer-token REST rather than as an export — the genre is settled, and neither has
   found an audience yet (20 and 0 installs). Worth reading for payload shape, and
   worth departing from twice. It reports that an update exists;
   Keel can report that a version is *known vulnerable*, which is the distinction
   0.6.0 was built on. And a complete list of installed plugins and versions is
   reconnaissance material — it sits against Keel's own `remove_version` default and
   its anonymous-REST gate, so any such surface must be authenticated, opt-in, and
   argued for rather than assumed useful.

## Later

- [ ] **Close the monitoring gap the 0.6.0 review found** — the important one, and
  not the one the review named. It recommended adding `user_has_cap` and
  `auth_cookie_expiration` to the expectations mapping; both were already in both
  maps, so that was not the cause. The cause is categorical.

  Keel's conflict detection answers one question: *is another plugin contesting this
  setting?* Every finding it could not see was a different question — *is this
  setting actually taking effect?*

  | Finding | Why detection was blind to it |
  | --- | --- |
  | REST single-item comment route | Nothing contested anything. Keel's filter was simply not on that code path. A **coverage gap**, not a conflict. |
  | `/?author=N` disclosure | Core won a priority tie. Keel's registration looked correct and lost. A **precedence loss**, not a conflict. |
  | Attachment redirect | Same precedence loss. |
  | Sanitize-before-authorization | Core's own dispatch order. Not a conflict in any sense. |

  Three things would close it, in increasing cost:

  1. **Registration invariants, checked in tests.** Done:
     `tests/hook-precedence.php` holds a map of hooks where Keel must run before
     or after a known core callback, and fails any registration that does not
     declare a priority on the correct side. `tests/route-coverage.php` does the
     same for filter coverage, keyed on whole registrations so a hook name that is
     a substring of another cannot pass vacuously. Cheap, static, and between them
     they would have caught three of the four.
  2. **Route-coverage checklists.** For each policy that claims something is off,
     enumerate the paths that can expose it and assert each is covered — for
     comments: `WP_Comment_Query`, `get_comment()`, the REST collection, the REST
     single item, feeds, admin. This is what would have caught the REST route, and
     it is a test rather than runtime code.
  3. ~~**Outcome probes in Site Health.**~~ **Rejected 2026-09-01**, and replaced
     by 3a below. Three reasons, in order of how fatal they are:

     - **A probe cannot observe the finding that motivated it.**
       `keel_defaults_hide_rest_comment()` returns early unless
       `wp_is_rest_request()` (`includes/content.php`). An admin-load probe is not
       a REST request, so the guard deliberately does not run. Observing it would
       mean either faking `wp_is_rest_request()` — lying about the request type
       inside a live page load, where other plugins read the same function — or a
       loopback HTTP request, which tests the network as much as the plugin and
       fails for reasons that have nothing to do with Keel.
     - **A probe needs a subject it cannot have.** "Ask `get_comment()` for a known
       id" requires a known id. A fixture comment writes to the database and hides
       a row the administrator did not make, breaking *report-only* and *nothing
       hidden* at once. A real comment may not exist — and a site with comments
       disabled and none stored is exactly the site whose answer matters.
     - **The cost was the smallest objection.** It was the one first raised, but a
       probe that cannot run in the right context and has nothing valid to ask
       about would not be worth building at any price.

  3a. **Registration audit.** What static tests genuinely cannot see is runtime
     interference: another plugin unhooking a Keel callback, or registering ahead
     of one, on a site whose plugin set no test knows about. That needs no probe.
     `has_filter( $hook, $callback )` returns the registered priority or `false`,
     so comparing the live hook array against what Keel registered is pure
     introspection — no network, no database, no fixture, and nothing executed.

     It reports the same two failures the review found, from the running site
     rather than the source: a registration that is missing, and one whose
     priority no longer beats the core callback it must precede. Where
     `tests/hook-precedence.php` reads the repository, this reads the install.

     It also fits the mechanism already here rather than adding one.
     `keel_defaults_policy_divergences()` is passive — it observes hooks that
     were going to fire anyway, which is why it costs nothing. An audit that reads
     an array Keel has already built keeps that property; a probe that makes work
     in order to watch itself does not.

  Do 1 and 2 first — both done. Report-only remains the rule, and 3a stays inside
  it: reading the hook array changes nothing and asks nobody.

- [ ] **Rate-limit the breach lookup** — undecided. a5e38bb moved the free
  rejections ahead of the network call, which narrows the amplification the review
  found without closing it: an attacker sending plausible passwords still gets one
  outbound request and one cache entry each. Closing it means a rate limit keyed to
  something, and every candidate key has a cost — per user punishes shared accounts,
  per IP punishes NAT, global punishes everyone during an attack. Decide the key
  before writing the code.

- [ ] **Report what this site already sends to WordPress.org** — undecided, and the
  most Keel-shaped idea to come out of the 0.6.0 work. Duane Storey's
  [deep look at the WordPress API](https://duanestorey.com/posts/down-the-rabbit-hole-a-deep-look-at-the-wordpress-api)
  documents what `core/version-check`, `plugins/update-check` and `themes/update-check`
  actually transmit: the site URL and WordPress version in the user-agent, `wp_install`
  and `wp_blog` headers, PHP and MySQL versions, enabled extensions, multisite status,
  user counts, and metadata for every installed plugin and theme whether active or not.

  None of it is disclosed in wp-admin, and there is no opt-out — while wordpress.org's
  own plugin guidelines require exactly that disclosure from plugins. Keel discloses
  its single stable-check call in detail in readme.txt; core's far larger transmission
  is invisible. Surfacing an invisible default is the whole premise of this plugin, and
  this is the largest one nobody has surfaced.

  Report-only, and probably nothing more: these requests are how updates arrive at all,
  so a switch would be a foot-gun of the kind 0.6.0 exists to warn about. Decide the
  scope before promoting it.

Explore database-growth reporting (revision volume, expired transients and unusually
large rows), media-storage amplification and richer multisite/network diagnostics
only after the 0.8 framework proves that the measurements can be cheap and honest.
Start each as report-only; cleanup is a separate, explicitly authorized feature.

Do not pursue brittle performance folklore as defaults: disabling jQuery or REST,
combining assets indiscriminately, or presenting tiny request removals as measured
site-speed gains. The milestone sections below retain measured proposals, rejected
ideas and larger directions that are not accepted into the queue. Promote another
item into the active sequence only when its behaviour, tradeoffs and acceptance
tests are decided.

## 0.6.0 completion record

**Keel is listed and installable.** Approved 2026-08-25 on the 0.5.3 package and
first published 2026-08-26. Before this 0.6.0 work, the directory and plugin API
served 0.5.10, requiring WordPress 6.4 and PHP 7.4 and tested to 7.1.

That closes the question this section used to open with. It asked whether the first
SVN push should be 0.5.3, the approved package, or 0.5.4, the tag — worth deciding
deliberately rather than by whichever was checked out. Events decided it: five more
versions landed before the first push and what went up was 0.5.9.

**Security patch status and the deliberate same-line installer shipped in 0.6.0**,
over a series of pull requests rather than the one this section expected.
[#134](https://github.com/dknauss/Keel/pull/134) added the Site
Health test reporting whether the installed core version is currently flagged
insecure — a question core never asks and no admin screen answers.
[#135](https://github.com/dknauss/Keel/pull/135) replaced Keel's own reconstruction
of the updater's state with core's answers to the same questions.
[#136](https://github.com/dknauss/Keel/pull/136) added the ladder of releases
WordPress.org is actually offering, and which one core would take.
[#137](https://github.com/dknauss/Keel/pull/137) made the route depend on what the
Updates screen actually shows instead of assuming it can deliver the patch.
[#139](https://github.com/dknauss/Keel/pull/139) gave updater blockers stable codes,
and [#141](https://github.com/dknauss/Keel/pull/141) added the deliberate installer
specified in [#138](https://github.com/dknauss/Keel/issues/138). The later release
passes added the live rollback/forward matrix, compatibility fixes, screenshots and
copy corrections that made the feature releasable.

What #137 cost is worth recording, because it was not the code. The fix itself was
small; the review found seven further defects, six of them the same shape — a
sentence asserting a cause the data underneath it could not establish. Policy did not
establish what cron would install. An empty selection did not establish that updates
were off, then did not establish that a cache was stale. A selection did not
establish that the updater could write. Each was reachable only by rendering the
whole panel, which is why each one shipped. The panel's state is now resolved once,
in `keel_defaults_selection_state()` and `keel_defaults_backport_route()`, so the
combinations can be stated in a test rather than staged through a render.

### The release path has run end to end

Settled 2026-08-30 by `v0.5.10`, and worth keeping as record because the section it
replaces argued the opposite from evidence that was correct at the time.

The tag fired `release.yml`, which called `wp-deploy.yml`, which authenticated and
committed. WordPress.org serves 0.5.10 and SVN carries tags for both 0.5.9 and
0.5.10. The credential that failed twice on 2026-08-26 was corrected fourteen minutes
after the last failure, and this run is the first to exercise it — so the pipeline's
state has moved from unknown to known-good on the two links that had never run: the
credential, and the `release.yml` to `wp-deploy.yml` wiring added after `v0.5.9`.

0.5.9 still reached the directory by hand. That is history now rather than a pending
risk, and the manual `workflow_dispatch` this section used to recommend is no longer
needed.

The human gate is unchanged: the `wordpress-org` environment carries
`required_reviewers`, and a tag does not deploy without someone approving it.

### The hold is lifted

The maintainer lifted it releasing 0.5.10. Listing on 2026-08-26 met the condition
CONTRIBUTING named, and that section now describes how to cut a release rather than
why not to.

The hold paragraphs below stay where they are, as record rather than policy. The
exceptions they document are what the review team was told at the time, and a hold
that vanishes from the file it was written in reads as though it never applied.

**Exceptions used for 0.5.3 and 0.5.4.** 0.5.3 is the answer to the review itself:
the Plugins team pended the submission and asked for a corrected package, so a new
version was the thing being requested rather than a release made alongside the
review. It routes every stylesheet and script through `wp_enqueue`, drops a
comparative claim the guidelines do not allow, stops shipping the compiled en_CA
catalog, and clears the pre-rename settings option on uninstall.

0.5.4 is the weaker of the two and is recorded as such. It carries a real defect —
core's Comments Title block rendered a comments heading on posts with comments
switched off, because Keel reported the count as an int where core compares against
the string — and a diagnostic that was reaching Site Health but not the dashboard.
Neither is the "silently reversed a setting the site chose" class that justified
0.5.1 and 0.5.2. Shipping it during the hold was a judgement call by the
maintainer, not an urgent-defect override, and the disclosure obligation applies.

**Exception used for 0.5.2:** saving any setting on WordPress 6.4–6.9 silently
rewrote the stored AI Connectors value from on to off, because the control is
hidden on those versions and the sanitiser read its absence as an unticked box.
A default the site chose being reversed without saying so is the urgent-defect
exception in CONTRIBUTING; the corrected package is reuploaded and the version
change disclosed to the review team. The general hold remains after it.

**Exception used for 0.5.1:** the submitted build executed third-party callbacks
inside automatic policy-overlap diagnostics. Removing that behavior is the
urgent-defect exception described in CONTRIBUTING; reuploading the corrected
package and disclosing the version change to the review team are part of this
release. The general hold remains in place afterwards.

The submission milestone is closed: the initial v0.5 release is out, `Stable tag`
matches, Plugin Check passes with its findings reviewed, and the plugin has been
through a first review pass.

**The review came back pended** (2026-08-25), on four points: every `<style>` and
`<script>` written straight into the page rather than enqueued, a prohibited
comparative claim in the description, bundled translation catalogs, and an automated
flag against guideline 11 that needed no change. All four were answered in 0.5.3, and
approval followed the same day. Read this paragraph as the record of what the review
asked for, not as a description of where the submission stands — it cleared.
The v0.5 group below has been decided rather than deferred — two of its four were
taken and are done, two were declined with the reasons recorded, which is what
"selected scope" means in that heading.

**The four v0.4 candidates have now been decided** (2026-08-25), which is what that
section was waiting for. Three are accepted and one is rejected outright. Broken
shortcodes was briefly held — the strip alone destroys the evidence that a plugin is
missing — and is accepted on the condition that the strip and the Site Health report
ship together. The decision records what Keel will and will not absorb, and each
accepted item carries what it costs, measured rather than assumed.

The item below is kept because it is the last thing that moved and the section reads
oddly empty without it.

- [x] **Multisite governance** — done 2026-08-09 (keel#89). Network Admin →
      Settings → Network Policy lets a Super Admin decide any of the 38 settings for
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

      Verified on a real network by `tests/integration/verify-network.sh`
      (keel#90), which is the coverage every multisite path in this plugin was
      missing. Seeding, uninstall and now policy had only ever been proven against
      stubs the plugin's own tests declare — a stub that is wrong about WordPress
      lets all of them pass while the plugin misbehaves on a network.

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
- [x] **Reference doc coverage** — done 2026-08-04 (keel#18). Every schema key then present received an
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
- [x] **Expand `readme.txt` / `README.md`** — done 2026-08-04. The initial feature set
      had settled with v0.2, which is what this was waiting on. `README.md` gained the
      measured comparison against the field; `readme.txt` gained the same in prose, a
      Site Health paragraph, and an FAQ for running alongside another defaults plugin.
      Two stale claims went with it: the "in progress" status, and an FAQ line saying
      REST authentication stops other sites embedding your posts — no longer true since
      the `oembed/1.0` carve-out.
- [x] **The submission name, the slug, and the text domain** — settled 2026-08-22.
      Submitted as **"Keel Defaults"**, slug `keel-defaults`, text domain
      `keel-defaults`, and that is the install directory too.

      The directory builds the permalink from the name a plugin is submitted under and
      then serves language packs as `{slug}-{locale}.mo`. "Keel Defaults" against a text
      domain of `keel` meant every catalog from translate.wordpress.org would land on a
      filename nothing asks for — English everywhere, no error, nothing to search for.
      Three values in three files agreeing by hand, and the slug is permanent once
      assigned.

      The first attempt went the other way: rename the plugin to "Keel" and keep the
      domain, since the domain, the catalog filenames, the zip folder and the settings
      heading were all `keel` already. Plugin Check rejects that outright —
      `plugin_header_unsupported_plugin_name`, the `Plugin Name` header must contain at
      least five latin letters or numbers *because the slug is generated from it*. Four
      letters is not a submittable name, so a slug of `keel` was never available and the
      shorter name was never the cheaper option. Worth recording: nothing in the readme
      spec or the header docs says this, and it is the kind of rule you find by running
      the tool rather than by reading.

      So the domain moved instead, along with the catalog filenames and the folder
      inside the release zip — WordPress identifies a plugin by `folder/file.php`, so
      the zip has to carry the directory the plugin will really install into.
      `tests/readme-spec.php` now derives the slug the way the directory does and fails
      if the name, the slug and the domain stop agreeing.
- [x] **Competing-plugin detection, widened** — done 2026-08-22. The check existed
      and covered almost nothing: 7 hooks mapped, 3 reported, against the 62 Keel
      registers. Missing were the collisions that actually happen — the editor
      filters the Classic Editor plugin owns, the comment teardown any
      disable-comments plugin contests, and outgoing mail.

      The prerequisite was a bug, not a feature. `keel_defaults_competing_plugins()`
      never asked whether Keel was on the hook it was examining, and Keel stands
      down on several — `auth_cookie_expiration` is registered only when the
      session policy differs from WordPress's own. So a site that left session
      length alone, running any plugin that set one, was told more than one plugin
      was setting the same defaults when exactly one was, and told to go
      deactivate something. Widening the map without fixing that first would have
      multiplied the false positive by the number of hooks added.

      Worth recording why it survived: the harness pointed `WP_PLUGIN_DIR` at a
      path nothing resolved inside, so every callback attributed to nothing and
      only negative assertions were possible. The positive case — a rival
      *is* reported — had never been written, because it could not be.

	  Superseded in the initial v0.5 release by effect replay, then corrected again
	  in v0.5.1. A
	  diagnostic cannot prove another plugin's callback is safe to execute: a hook
	  clone does not isolate mail, database writes, network calls, globals, exits,
	  or object state. Detection is structural again and its copy says exactly what
	  that evidence proves—both plugins participate in the same authoritative
	  policy hook, not that their configured outcomes disagree.

      A hook earns an entry only where losing has a consequence somebody can act
      on; `the_generator` is the shape of thing left out, since two plugins both
      emptying it reach the same place. An unmapped hook is not a gap, and a guard
      asserts the map only names hooks `includes/` actually registers — the
      reverse is deliberately not asserted.

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

      **Multisite closed 2026-08-10 (keel#91).** A second 6.4 lab installed
      directly as a network runs `tests/integration/verify-network.sh` clean — all
      ten checks, including the three functions the single-site run could not reach
      and the whole network policy layer. Seeding a subsite created after
      activation behaves on 6.4 exactly as on 7.0.2.

      **Still open, and why this stays `[~]`:** 6.4 served on **PHP 7.4**. CI runs
      the unit suite on 7.4, so the language floor is tested; what is untested is
      the oldest supported WordPress running on the oldest supported PHP as a live
      site, which needs a 7.4 runtime this machine does not have. The other plugins
      in the matrix also remain measured on 7.0.2 only.

      A trap worth carrying: the first 6.4 lab was WordPress **7.0.3** by the next
      morning, through a background core update it never announced
      (`auto_core_update_notified` records it). The original measurement stands —
      the version was verified in the same session it was taken — but a lab pinned
      to an old release does not stay pinned unless the updater is switched off.
      Now in the integration README, with the two constants to set.
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


- [x] **A policy-collision report in Site Health** — done 2026-08-04, rebuilt
      effect-first for the initial v0.5 release, and made non-executing in 0.5.1.
      It detects by hook rather than by plugin name,
      attributes every callable form it safely can (including symlinked and
      single-file plugins), and never says which plugin to keep—a check answering
      that would be a plugin arguing for its own retention.

      **The better fix turned out not to be the report.** Better by Default asked
      whether the setting says anything WordPress does not already do and stopped
      registering when it did not (WPYEG#41); Keel and Pixel followed (keel#39,
      px#241). `auth_cookie_expiration` went from three contestants at the same
      priority to **zero at defaults**, and the check now reports it uncontested
      because the conflict shrank rather than because it stopped looking.

      **The effect replay shipped in the initial v0.5 release and was removed in
      0.5.1.** No
      allowlist can make arbitrary third-party PHP safe to run in a privileged
      admin request. Keel now confirms only structural overlap: Keel is registered
      on an authoritative hook and reflection attributes another registered
      callback to an active plugin. Unknown provenance and compositional hooks
      remain informational. Source-code scanning stays removed; it inferred intent
      without proving runtime behavior.
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

      **Fixed 2026-08-10 (keel#93), both of the two left standing.**

      - **Locked controls announce their reason.** `disabled` takes a control out
        of the tab sequence, so the `aria-describedby` note naming *why* it cannot
        be changed is announced on a focus that never happens — the reason was put
        first in that attribute deliberately and then could not be heard.
        `aria-disabled` keeps it focusable and announced as unavailable.

        That was only safe once the lock became real. It had been presentational:
        `keel_defaults_config_lock()` ran at render time and nothing checked it on
        save, so a crafted POST wrote a locked setting happily. It never took
        effect — the constant and the network policy both win when the value is
        read — but the stored value drifted from what the screen showed.
        `keel_defaults_sanitize_site()` now keeps the stored value for any locked
        key, and a focusable control is a submittable one, so the enforcement is
        the half that makes the accessibility fix defensible.

      - **Dependent rows say what governs them.** Each carries an id, and the
        controlling input points `aria-controls` at it and keeps `aria-expanded`
        in step. The link used to be a data attribute this plugin's own script
        read, which assistive technology cannot see.

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

## v0.4 — candidate defaults — **decided 2026-08-25**

Four defaults surveyed from [coffee2code's plugin
catalogue](https://coffee2code.com/wp-plugins/) on 2026-08-10. Sixty-nine plugins,
of which these four are the only ones shaped like a Keel default: one toggle, one
core filter, no new admin UI, and reversible by turning it off.

**The feature set was frozen after v0.2 deliberately, and the LOC budget is a
stated non-goal below.** So this was a list to decide on, not a list to work
through. Each of these already exists as a mature, narrowly-scoped plugin, and for
a site that wants exactly one of them, installing that plugin is a legitimate
answer — Keel absorbing it mainly saves a plugin from the list.

**Outcome: three accepted, one rejected.** Marked in the house convention
below — `[x]` is built, `[~]` is not taken, and an accepted item stays `[ ]` until
it ships. The reasoning is kept in full, including where the original justification turned out to
be wrong on measurement, because a decision recorded without its evidence gets
re-argued.

- [ ] **Keep password-protected posts out of site search — accepted.** The only one
      of the four that closes a real leak rather than adding a convenience. One
      filter on the search query. Prior art: coffee2code's Omit Passworded Posts
      From Search.

      **The original justification here was wrong, and the corrected one is
      narrower.** This entry used to say a protected post "still surfaces its title,
      and usually an excerpt, in site search results". Measured on WordPress 7.1
      with a control post alongside the protected one:

      | searcher | result |
      | --- | --- |
      | logged out (anonymous visitor) | 0 results — no leak |
      | logged in as **Subscriber** | 1 result: title *and* excerpt exposed |

      Core already handles the anonymous case. `WP_Query::parse_search()` appends
      `AND post_password = ''`, but only inside `if ( ! is_user_logged_in() )` — a
      guard old enough to behave the same on 6.4, Keel's floor. So the exposure is
      **logged-in users only**, and it is worth more rather than less for that:
      the sites with registered non-editor users are membership sites, shops and
      communities, where "any subscriber can read the titles and excerpts of every
      protected post" is a real disclosure. The probe leaked the excerpt *"Summary:
      layoffs planned for Q3."* to a Subscriber account.

      **Related to `disable_post_passwords`, and not made redundant by it.** That
      hides the password control *in the editor*, so it stops new protected posts
      being made and does nothing about the ones already there — which are exactly
      the posts still leaking. The two compose: one stops the supply, this one
      stops the disclosure.

      **The scenarios to get right, because "protected posts" can be disabled from
      several directions at once:**

      - *A user who is entitled to the post.* Excluding by `post_password != ''`
        for every logged-in user takes the post away from its own author and from
        editors, who can open it regardless. Front-end search would stop finding a
        post the searcher can read — a false negative that reads as broken search,
        not as privacy. The filter needs a carve-out for users who can already read
        or edit the post, not a flat exclusion.
      - *A membership plugin that grants protected-post access.* Several deliberately
        let logged-in members read protected posts. Keel hiding those from search
        hides content the member is entitled to find, and the site owner would have
        no way to connect the two. This is a case for the divergence/overlap
        reporting to notice, not for Keel to win silently.
      - *A plugin that neutralises passwords rather than hiding the UI.* Some strip
        `post_password` on save, or filter `post_password_required()` to false. Then
        the post is publicly readable while Keel is still excluding it from search:
        the search index disagrees with what a visitor can actually open. Excluding
        a *readable* post is the worst of both — it neither protects anything nor
        finds anything.
      - *Keel's own `disable_post_passwords` on the same site.* Harmless but worth
        stating: the search filter becomes a no-op once the last protected post is
        gone, and must not error on a site that has none.

      The shape that survives all four is "exclude posts the current user cannot
      read", not "exclude posts with a password".

- [ ] **Straight quotes — accepted.** `add_filter( 'run_wptexturize', '__return_false' )`,
      so punctuation is left as typed. Structurally the shape of `disable_emojis`:
      one core filter, one toggle, nothing left behind when it is off. Wanted by
      sites publishing code, technical documentation, or anything where a curly
      apostrophe corrupts a copied string. Prior art: wpuntexturize.

      **The name undersells the blast radius, and the setting copy must not.**
      `wptexturize()` is not a smart-quotes filter; quotes are one of its jobs.
      Turning it off also reverts `...` to three periods instead of an ellipsis,
      ` (tm)` to literal text instead of `™`, en and em dashes back to hyphens,
      the multiplication sign back to `x`, prime marks for feet and inches, and
      the cockney contractions (`'twas`, `'tis`, `'twere`) core special-cases.
      A toggle labelled "straight quotes" that silently changes six other things
      is the kind of surprise Keel exists not to spring, so this one is labelled
      for what it does: leave typographic punctuation as typed.

      **Prior work, from the Dirtbag research** (`docs/wordpress-contributions.md`
      in dknauss/Dirtbag). The complaint that usually sends people looking for this
      toggle is narrower than the toggle: core
      [Trac #18549](https://core.trac.wordpress.org/ticket/18549), resurfaced as
      [Gutenberg #42345](https://github.com/WordPress/gutenberg/issues/42345). An
      apostrophe immediately after a closing inline tag — `<strong>He</strong>'s` —
      curls the wrong way, into U+2018 LEFT single quote instead of U+2019, because
      `wptexturize()` splits content into text runs at HTML boundaries and the
      run beginning `'s` looks like an opening quote. A patch is submitted upstream
      ([wordpress-develop #12249](https://github.com/WordPress/wordpress-develop/pull/12249),
      green on trunk) with a standalone stopgap plugin at
      [dknauss/wp-texturize-inline-quote-fix](https://github.com/dknauss/wp-texturize-inline-quote-fix).

      That matters here in two ways. It is the argument for **not** making this a
      default-on setting: most people hitting the bug want the one case fixed, not
      typography switched off site-wide, and the targeted fix already exists. And it
      is the argument for Keel being the right layer for the blunt switch anyway —
      the Dirtbag analysis concludes a *theme* must not disable texturization for
      every site, but that "a plugin or mu-plugin can", which is precisely this
      toggle. Off by default, opt-in, and the help text should point at the
      narrower fix for anyone whose actual problem is #18549.

- [~] **Drop the browser nag — rejected.** Removing the "your browser is out of
      date" dashboard widget. Not deferred: decided against, and not to be
      re-proposed. Prior art: No Browser Nag.

      **This is the dashboard-widgets decision again**, already made in the v0.5
      survey below and reached independently here. Keel removes a dashboard widget
      when the feature behind it is disabled — Recent Comments goes with comments —
      but a widget that is ordinary core UI rather than residue of a policy Keel
      applies is not Keel's to remove. Calling it noise is a preference, not a
      default, and sites wanting a curated dashboard are better served by an
      admin-customisation plugin. Nothing about the browser notice distinguishes it
      from Activity or Events and News.

      It is in fact the weakest case of the three, because WordPress already lets
      each user hide this widget from Screen Options. A site-wide setting that
      pre-empts a per-user checkbox takes the choice from the person who has it and
      gives it to the site.

      The original entry's point about the *update* nag stands and is worth keeping
      rather than dropping with the rest: hiding a pending core update from the
      people who can apply it argues against Keel's own update defaults, so neither
      half of that prior art belongs here.

- [ ] **Hide broken shortcodes — accepted, with the report.** Stripping the residue
      of shortcodes whose plugin is gone, rather than printing `[some_shortcode]` to
      visitors — *and* telling an administrator which posts they are in. Prior art:
      Hide Broken Shortcodes, which does the first half only.

      **Accepted on the condition that both halves ship together.** The strip alone
      was the wrong feature, and shipping it first "for now" would be the same
      mistake in instalments.

      The risk is that hiding the residue also hides the diagnosis. `[some_shortcode]`
      appearing on a page is ugly, but it is *evidence*: a plugin the content depends
      on is missing or deactivated, and it is visible precisely where the missing
      output was supposed to be. A filter that strips it makes the page tidy and the
      breakage silent — the post now renders as though it were complete, and the one
      signal that would have sent somebody to reactivate the plugin is gone. That is
      the same failure Keel's own conflict reporting exists to prevent, arriving from
      the other direction.

      Second, smaller risk: square brackets in ordinary prose are not rare — citations,
      editorial insertions, `[sic]`, code samples outside a code block. Anything
      claiming to strip "unregistered shortcodes" has to be sure the thing it removed
      was ever meant to be one.

      **The shape that has it both ways** — hide the residue from visitors, surface it
      to the people who can act. Strip on the front end for readers, leave it visible
      to anyone who can edit the post, and report the affected posts and the missing
      tags in Site Health beside the policy-overlap report. The strip is safe once the
      information is moved rather than destroyed.

      **The report must not be a scan.** A Site Health check that walks every post
      looking for unregistered tags is the one genuinely expensive way to build this,
      and it would run on a screen an administrator opens when something is already
      wrong — the worst moment to make them wait. It is also unnecessary, because the
      front-end filter is already reading every rendered post: the residue is found as
      a by-product of work being done anyway.

      So it takes the same shape as the divergence observer in `conflicts.php`, for the
      same reasons and with the same properties: record only when there is something to
      record, write only when the answer changes, cap what is stored, and let the record
      expire rather than having anything clear it. A site with no broken shortcodes
      never writes, and Site Health reads a record instead of building one. The
      observer is a proven pattern here rather than a new one, which is most of the
      argument for accepting this now.

      Bounded deliberately: the record holds post IDs and tag names, capped, not
      content. A page nobody visits is not in it, and that is correct — an unvisited
      page has no broken output to see.

### What these cost, measured

Taken together on 2026-08-25, on the WordPress 7.1 test install, because "one
filter" hides a wide range of actual cost and the three accepted items sit in
different places on it.

**The hot path is already fine, and was left alone.** The divergence observers run
on every front-end request, and `comments_open` / `pings_open` fire once per post in
a loop — the shape most likely to be a problem. Measured at **0.020 ms per observer
call**, 0.81 ms for the 40 calls a twenty-post archive makes. `keel_defaults_policy_expectations()`
is rebuilt on each call and memoising it would roughly halve that, which is not worth
a request-scoped cache that can go stale against a setting saved mid-request. Adding
`pings_open` in 0.5.4 was suspected of being a per-post regression and measurement
says it is not. Recorded so it is not re-optimised on suspicion.

**Straight quotes is a saving, not a cost.** `wptexturize()` is one of the more
expensive filters core runs over content — several regex passes per text run.
Turning it off makes those pages faster. It is the only item here that gives
performance back.

**The search default costs nothing for most traffic.** The measurement that corrected
its justification also hands it a free guard: core already excludes protected posts
for logged-out users, so the filter returns immediately when `! is_user_logged_in()`.
Anonymous visitors — the majority of requests on nearly every site — pay one boolean.
Search queries are rare against page views, and the capability check the carve-out
needs is once per query, not once per row.

**Broken shortcodes is the only one that touches every rendered post, and the scan is
not where its cost is.** Measured on 3.4 KB of prose: an unguarded regex over content
with no shortcodes is 0.0005 ms per post; a `strpos( $content, '[' )` gate first cuts
that by 70%, to 0.0002 ms. Both are noise. The gate is worth having because it is one
line, not because the regex was a problem.

The cost that would matter is the report, and it is designed out rather than tuned:
a Site Health check that walks every post to find residue is expensive and runs at
the worst moment. Recording during the render that is already happening makes the
report free to read and costs the front end nothing beyond the scan above.

**The rule the three of them share.** Do the work where the work is already being
done, guard on the cheap test before the expensive one, and let a healthy site pay
nothing — the same reasoning already written into the divergence observer, which
reads no storage at all unless there is something to record.

---

**Smaller, in an area Keel already owns.** `Remember Me Controls` overlaps
`disable_remember_me`, `session_regular_days` and `remember_me_days` almost
entirely; the one thing missing is *checking the box by default*, which is a
couple of lines in a group that already exists rather than a new default.

**Ruled out, and worth recording so it is not re-proposed.** `Restrict Usernames`
is the reserved-usernames default Keel **removed before its first stable
release**. The reasoning
stands: the list is long, opinionated, and includes names an ordinary site
legitimately uses — `manager`, `marketing`, `sales`, `office`. WordPress's own
`illegal_user_logins` filter does it in one call for anyone who wants it.

Most of the remaining sixty-odd are out of scope structurally rather than by
judgement: template tags, shortcodes and widgets (`Linkify *`, `Get Custom Field
Values`, `Text Replace`) are content features, not defaults; several add admin UI
rather than set a default, and `helper_list_columns` is precedent for one such
thing rather than for a category; `Configure SMTP` handles credentials and
`Disable Directory Listings` writes server files, both against the non-goals
below.

---

## v0.5 — selected scope

Surveyed 2026-08-23 against nineteen "disable something" plugins installed
together on a WordPress 7.1 site — Admin and Site Enhancements, Disabler, Disable
Comments, Disable Blog, Featherweight, Disable Gutenberg, Classic Editor and
twelve narrower ones. The survey was run to answer three questions: do they do
what they claim, do any of them do Keel's own features better, and is anything
missing here that belongs.

The answer to the second was no. Measured by files actually loaded on an admin
request, Keel now does 39 defaults in 14 files and roughly 250 KB; Disabler pulls in 212 files
and 1.4 MB to reach a comparable feature set, shipping Action Scheduler and
monolog to switch things off. The comment claim in `readme.txt` was re-verified
the hard way — a comment written straight to the table with `$wpdb`, then queried
with Keel on and with Disable Comments 2.8.0 alone in its strongest mode. Keel
returns nothing; Disable Comments returns the comment while reporting a count of
zero. The claim holds against the current version.

The answer to the third was these four. What made them worth considering was not that
competitors have them — competitors have dozens of things Keel should not have —
but that every one of them sits directly against work Keel has already done, and
each is one core filter behind one control. That is the same shape as the existing set.

**Decided 2026-08-23: take revisions control as the one new setting, and close
author feeds as completion of the existing author-archive default.** Do not take
broad embed disabling or dashboard cleanup. The feature set had been deliberately frozen
deliberately; one new setting earns an exception because Keel already recommends
the exact policy and currently sends the user to `wp-config.php` to get it. The
author-feed fix adds no setting. The two rejected items would trade away useful
core behaviour or hide ordinary admin UI for a preference.

- [~] **Disable embeds — not taken** ([#100](https://github.com/dknauss/Keel/issues/100)). The proposal joined two different surfaces.
      `wp-embed.js` is conditionally enqueued on a page that contains another
      WordPress site's post embed, but the discovery links advertise this site's
      posts to other consumers. Removing both is not a front-end payload default;
      it partially disables the provider behaviour Keel deliberately preserved
      when it kept `oembed/1.0` reachable past the REST gate.

      Removing the host script alone would save nothing on pages without a
      WordPress post embed and would make the ones that have one less robust.
      That is too small and too conditional a gain to justify another switch.

- [x] **Return 404 for author feeds with author archives** ([#101](https://github.com/dknauss/Keel/issues/101)). Done 2026-08-23. Do not add a broad feed switch.
      The main feed is a public publishing surface readers deliberately subscribe
      to, and per-post-type feeds are equally capable of being intentional. An
      author feed is different: it is the feed form of an archive Keel already
      disables, and it keeps the account nicename reachable after the archive is
      gone.

      WordPress already marked an author feed as `is_author()`, so Keel's broad
      author redirect closed it with a 301; the original issue's claim that the
      feed remained served was wrong. The completed change is narrower and more
      accurate: the machine endpoint now returns a deliberate 404 before the HTML
      archive keeps its existing home-page redirect. Global and post-type feeds
      remain untouched, and a real-query integration probe guards the two flags.

- [x] **Revisions control — accepted as the one new setting** ([#102](https://github.com/dknauss/Keel/issues/102)). Done 2026-08-23. `readme.txt` recommends `WP_POST_REVISIONS` in
      `wp-config.php` and offers no switch, which is the only place in the plugin
      that tells somebody to go and edit a file to get a default Keel is otherwise
      happy to set. Held by Admin and Site Enhancements and Disabler.

      New activations seed 10; existing installations migrate to `-1` so an
      update does not silently replace WordPress's previous unlimited policy.
      Zero disables revisions and `-1` means unlimited. A numeric or false
      `WP_POST_REVISIONS` value wins and locks both site and network controls.
      Core itself defines the constant to `true` when wp-config says nothing, so
      an explicit `true` is indistinguishable from that default and cannot be
      presented as a detectable operator lock.

- [~] **Disable dashboard widgets — not taken** ([#103](https://github.com/dknauss/Keel/issues/103)). Keel removes a dashboard widget
      when the feature behind it is disabled — Recent Comments goes with comments
      — but Activity, Quick Draft, and Events and News are ordinary core UI, not
      residue of a policy Keel already applies. Calling them noise is a preference,
      not a default.

      Site Health makes the mismatch sharper: Keel puts its own posture report
      there, so removing the widget that points at it would work against one of the
      plugin's most useful surfaces. Sites that want a curated dashboard are
      better served by an admin-customisation plugin.

- [~] **Disabling the blog — not taken** (decided 2026-08-25). Deregistering `post`,
      and with it `category` and `post_tag`, so a site that does not publish posts
      stops carrying the machinery for them. Prior art: Disable Blog, which is one
      of the plugins in the teardown below.

      **The teardown is the argument against it.** Disable Blog is measured there
      taking collateral with it: the block editor stops working for the type it
      removed, and oEmbed answers 404. Absorbing an approach this repository has
      already documented as having those costs would need a better answer than
      intending to do it more carefully.

      It also fails the shape every other default holds to. A default here is one
      toggle over one filter, and turning it off puts WordPress back as it was.
      Deregistering a post type is structural rather than filtered: posts are not
      deleted but become unqueryable and unreachable, admin menus go, and anything
      holding a post ID has a broken reference. Switching it back restores the type
      and not necessarily the templates, menus and integrations that adapted while
      it was gone. It would be the first default here that is not cleanly
      reversible. Taxonomies are worse — `category` and `post_tag` reach into
      permalinks, feeds and the REST index, and unregistering them surfaces as a
      404 weeks later.

      **The safe half is already here, and the rest belongs elsewhere.** A site that
      does not publish posts is served by the defaults Keel already ships: author
      archives off, feeds closed, comments off, attachment pages redirected — every
      one a filter, every one reversible. Beyond that the useful version is *hiding*
      rather than deregistering, which is admin-surface work and belongs to a
      plugin that owns the admin surface. [Maestro](https://github.com/dknauss/admin-menu-maestro)
      does that per role, and is explicit that hiding declutters without locking
      access — the right split, because a defaults plugin should not be the thing
      that makes content unreachable.

Explicitly **not** taken from the survey, recorded so the decision is not made
twice: change-login-URL (obscurity, and WPS Hide Login owns it), maintenance mode,
captcha, media replacement, view-as-role, limit login attempts. The first five are
site-management features rather than defaults. Login-attempt limiting has real
security value and no home in Keel as it stands — it needs per-request storage,
lockout state and an unlock path, which would make it the first thing here that
writes on an anonymous request.

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
