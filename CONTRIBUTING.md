# Contributing to Keel

Thanks for helping improve Keel. It is a plain PHP WordPress plugin with no build
step, no npm, and no Docker — the tests are standalone PHP scripts that stub the
handful of WordPress functions they need. If you have PHP and Composer, you can
run everything.

## Development setup

```bash
composer install
```

That is the whole setup. `vendor/` is untracked and only holds the linter.

## Quality gates

Run all three before opening a pull request. CI runs the same three on every push.

```bash
composer test
composer lint
composer lint:compat
```

`composer test` runs every file in `tests/` — the glob is deliberate, because an
earlier hand-maintained list left one spec file that never ran from the day it
merged. `composer lint:fix` fixes most style complaints automatically.

`composer lint:compat` is a separate gate from `composer lint` because it answers
a different question: not "is this written the way the project writes things" but
"will this still run on the PHP floor the plugin advertises". It uses
`phpcompat.xml`, which pins `testVersion` to `7.4-` — open ended, so it catches a
PHP 8 only call that would fatal on the floor and a construct PHP 8.2 deprecated
in the same pass. Raising or lowering the floor means editing that value along
with `keel.php`, `composer.json` and `readme.txt`.

It exists because the other gates cannot see this class of bug. `php -l` on 7.4
accepts any PHP 8 only *function* call — the syntax is valid, and the call only
fails when the line executes — and the unit suite reaches some of the plugin, not
all of it. Dropping `get_debug_type()` into `keel_defaults_asset_url()` and
running everything confirms the shape of the hole: `php -l` on 7.4, the full unit
suite on 7.4, and `composer lint` all stay green, and only `composer lint:compat`
goes red.

Note that it is pinned to a prerelease. The newest stable PHPCompatibility is
9.3.5 from December 2019, which predates PHP 8 and therefore passes every PHP 8
only call in silence — a gate that cannot fail. `phpcompat.xml` carries the long
version of that reasoning.

`composer lint:syntax` parses every PHP file on whatever PHP is on your PATH. It
is not in the list above because on one modern interpreter it tells you little
that `composer test` has not already; its job is in CI, where the same script
runs once per version in the matrix and is the only thing standing between a
parse error and the PHP the plugin claims to support. Run it locally if you want
to check a file parses without running anything.

There is also an integration script that exercises the teardown behaviour against
a real site, which CI does not run because it needs one:

```bash
composer test:integration
```

## What a new default looks like

Keel is schema-driven. Adding a default is usually two edits:

1. An entry in `keel_defaults_schema()` in `includes/schema.php`.
2. A block in `keel_defaults_bootstrap()` in `includes/bootstrap.php` that
   registers the hooks when the default is enabled.

Display copy lives in `includes/strings.php`, keyed by the same schema key, so
the schema stays structure-only. Several defaults share a bootstrap block where
they genuinely belong together — the XML-RPC family is one — so "one entry, one
block" is the shape rather than a rule.

## Conventions the tests enforce

These are not style preferences; `tests/naming-conventions.php` fails on them.

- **Function prefix `keel_defaults_`**, with two deliberate exceptions:
  `keel_hibp_*` for the breach-lookup subsystem, and a function named after a
  filter it applies, so `keel_environments()` applies `keel_environments`.
- **`defined( 'ABSPATH' ) || exit;`** at the top of every file in `includes/`.
- **A docblock on every function.**
- **Read toggles with `keel_defaults_enabled()`**, not by comparing to `'yes'`.

Three more are enforced elsewhere: settings-screen row headings are Title Case
(`tests/settings-heading-case.php`), everything the plugin stores must be
removed by `uninstall.php` (`tests/uninstall-coverage.php`), and any document
that says how many defaults there are has to agree with the schema
(`tests/default-count.php`).

That last one is why adding a default is a slightly wider change than the two
edits above: `README.md`, `readme.txt`, `TODO.md`, `ROADMAP.md` and
`SECURITY.md` all state the count, and `SECURITY.md` also breaks it down by how
the defaults ship. The test names every file and line that needs moving, so run
`composer test` and follow the failures rather than hunting for them.

## Never recompute what the system already answers

If core or Keel already computes a value, call the thing that computes it. Do
not reimplement the logic, and do not copy the condition that guards it.

This is not a style preference either, but no test catches it and no linter can:
reimplemented logic is perfectly valid code that merely disagrees with its
source. It is found by review, or by the bug report months later.

It is the single defect that recurred most while building `includes/backports.php`,
in four distinct places:

- **`is_disabled()` was rebuilt from its parts.** Core does
  `$disabled = defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED;`
  and *then* passes that through the `automatic_updater_disabled` filter, so a
  filter can re-enable an updater the constant switched off. Checking the
  constant and the filter separately produced a verdict core did not share.
- **A precondition was omitted because it was not obvious.**
  `WP_Automatic_Updater::should_update()` refuses when
  `request_filesystem_credentials()` fails, before any policy decision is
  reached. Rebuilding "can this update run" by listing the checks we remembered
  missed the one we did not.
- **Keel's own policy comparison was copied.**
  `in_array( $policy, array( 'minor', 'all' ), true )` was duplicated out of
  `keel_defaults_allow_minor_core_updates()`. Adding a policy value would leave
  the copy quietly wrong.
- **The condition registering a filter was copied.** `bootstrap.php` decides
  when Keel's policy filters are hooked; duplicating that condition elsewhere
  means changing one and not the other.

The last two were introduced *by the fix for the first two*, which is the useful
part of the story. The pull is strongest exactly when you are already deep in
someone else's logic and it feels quicker to restate a line than to call it.

In practice:

- Ask core: `is_disabled()`, `is_vcs_checkout()`, `wp_is_file_mod_allowed()`,
  `request_filesystem_credentials()`.
- Ask Keel: call the filter callback, or `keel_defaults_get()`.
- To find out whether a filter is in play, use `has_filter()` rather than
  re-deriving the condition that registered it.
- Where a value must be interpreted rather than fetched — mapping a status
  string to a verdict, say — allowlist the values you understand and treat
  everything else as unknown. Falling through to a benign default is how an
  unrecognised value becomes a reassuring answer.

## Registering is not the same as taking effect

A hook that is registered, on the right filter, with a correct callback, can still
never run. Three ways Keel has managed it, all found by an outside reviewer rather
than by the suite:

**Losing a priority tie.** Core registers `redirect_canonical` on `template_redirect`
at the default 10, in `default-filters.php`, during load — before any plugin file is
read. Registering at the same priority loses on registration order, every time. Two
of Keel's redirects shipped that way and simply did not happen: `/?author=N` kept
disclosing the author nicename while `/author/slug/` redirected correctly. If a
callback must precede a core one, say so with a number and say why in a comment.
`tests/hook-precedence.php` enforces it, per hook and in the required direction.

**Filtering a path the request does not take.** `comments_pre_query` covers
`WP_Comment_Query`, which is every listing path — and not
`/wp/v2/comments/123`, which reads the row through `WP_Comment::get_instance()`
without building a query. The filter was correct and irrelevant. Before claiming a
thing is off, enumerate the routes that can expose it and check each one; a filter on
the common path is not coverage.

**Running before the check that would have stopped you.** `WP_REST_Server` calls
`sanitize_params()` and only afterwards consults the route's `permission_callback`.
Anything done in a sanitize callback is therefore done for unauthorized callers too.
Keel resolved an arbitrary user id there and ran the password policy against that
account, handing the result back in the error message. Sanitize callbacks may
validate their input; they may not act on identity.

The common shape: **the unit suite cannot see any of these**, because its
`add_action()` is a no-op stub — registrations are not observable and only callbacks
get tested. Where correctness lives in *how* something is registered rather than what
it does, the guard has to read the source or exercise a real request.

## Do not undo a documented decision to satisfy a finding

A review will sometimes recommend the obvious remedy for a real problem, and the
obvious remedy will sometimes be the thing a comment in the file already argues
against. The 0.6.0 review found that breach screening runs ahead of cheaper checks
and suggested putting the role gate first. The gate is deliberately last: breach
screening is universal so that low-privilege accounts, exempt from the length rule,
still get the one rule that costs them nothing.

Taking the suggestion would have traded a security property for a rate-limiting
problem. What shipped instead moves only the rejections that can never be valid for
any role, and the roadmap records that the real answer is a rate limit and that its
key is undecided. Narrow the problem honestly and say what is left rather than close
the ticket.

## Writing tests

The bar here is higher than "it passes", and it is the one thing worth reading
before adding a test.

**A test has to be able to fail.** Break the thing it covers and watch it go red
before you trust it. This project has shipped tests that passed for the wrong
reason more than once: a `stripos` that matched the filter name while claiming to
assert a constant; a fixture set whose realistic roles made a capability
deletable without any test noticing; a self-test that generated its cases from
the list it was testing, so removing an entry removed its own assertion.

If a test pins a **position** — first, last, interior — enumerate the positions
and cover each in both directions. If it consults a **list**, assert each entry
independently and write the expected list out rather than reading it back from
the code under test.

## Screenshots

`.wordpress-org/screenshot-1..4.png` are the wordpress.org listing images *and*
the ones `README.md` shows, so they go stale the moment the settings screen or
the Site Health surface changes — and a stale screenshot is invisible from inside
the repository. They drifted once already: twenty-one commits touched those
screens between capture and the day somebody looked.

```bash
composer verify:screenshots
```

That names every commit touching the admin screens since the screenshots were last
*reviewed* — `.wordpress-org/.screenshots-reviewed` records that commit. Reviewed
rather than changed, because a UI change does not always alter the picture: an
ARIA-only fix, or a new screen these four do not show, leaves the images
byte-identical, and comparing against the images' own commit made the check
impossible to satisfy in exactly that case. If the pictures are unchanged,
confirm you looked and record it with `git rev-parse HEAD > .wordpress-org/.screenshots-reviewed`. It is not part of `composer test` on purpose: it reads git history,
and CI checks out at depth 1, where the range query would find nothing and pass
vacuously. Run it before a release or after touching an admin screen.

To retake them, against a site running the current code:

```bash
node bin/screenshots.mjs --url http://localhost:8881 --wp @keel
```

It needs Playwright (`npm i playwright`) and mints its own admin session, so
nothing has to be logged in first. `tests/readme-spec.php` separately pins that
every screenshot file is captioned in `readme.txt` and shown in `README.md`.

## Coding standards

- Target WordPress 6.4+ and PHP 7.4+ unless the plugin header and `readme.txt`
  change deliberately.
- Runtime files are `keel.php`, `includes/`, `uninstall.php`, and `readme.txt`.
  Everything else is excluded from the built zip by `.distignore`.
- Sanitize on input, escape on output, and use capability checks with nonces for
  anything that changes state.
- Keep user-facing strings translatable with the `keel` text domain.
- Write "and", not "&", in prose, headings and UI copy.
- A default should be reversible. Turning it off should restore WordPress's
  behaviour without leaving anything behind.

## Pull requests

- Use a focused branch and say what changed for the user.
- Say what you ran to verify it. "Tests pass" is less useful than "broke X and
  watched Y fail".
- Update `README.md`, `readme.txt`, `ROADMAP.md` or `docs/` when behaviour or
  requirements change. `tests/readme-spec.php` and `tests/docs-consistency.php`
  will tell you if you missed one.
- If the change rests on a decision shared with the sibling plugins, restate the
  reasoning here rather than citing a document this repository does not contain.

## Releases

**The hold is lifted.** Keel was listed on wordpress.org on 2026-08-26, which is
the condition the hold named, and the maintainer lifted it releasing 0.5.10.
Releases are ordinary work again.

Cutting one, in order, because the order is what keeps a tag from pointing at a
commit that does not build:

1. Bump the version in every place that states it — `keel.php` **twice**, the
   `Version:` header and the `KEEL_DEFAULTS_VERSION` constant below it; then
   `readme.txt` (`Stable tag` **and** a new changelog entry **and** an Upgrade
   Notice entry), `README.md`, `ROADMAP.md`, `SECURITY.md`. The constant is the
   cache-buster on every enqueued asset, which is why it is checked separately
   and why missing it ships stale CSS to everyone who updates. Do not work from
   this list — `tests/docs-consistency.php` knows it better than prose does, and
   this paragraph was itself written a version short.
2. Open it as a pull request and let CI go green.
3. Merge, and only then push the `v*` tag.

Tag last. `release.yml` fires on the tag and runs the checks itself, so tagging
first means a failure discovered when the tag already exists and a GitHub
Release may already have been published.

Pre-release tags (`-dev`, `-alpha`, `-beta`, `-rc`) are GitHub-only: `wp-deploy.yml`
refuses them, because wordpress.org has no pre-release concept and pushing one
would make it the stable version every user updates to.

### What the hold was, kept because the record matters

Releases were held while the wordpress.org submission sat in review. The reason
was disclosure, not risk: a plugin in the queue is reviewed as the version that
was submitted, and every release in the meantime is one the maintainer has to go
and tell the reviewer about — twice in a single day, each time for something
found after submission rather than before it.

Four releases were made under it anyway, and the exceptions are recorded in
[ROADMAP.md](ROADMAP.md) rather than quietly dropped: 0.5.1 and 0.5.2 for
defects that silently reversed a setting the site had chosen, 0.5.3 because the
review team asked for a corrected package, and 0.5.4 — the weak one, recorded as
such — for a real but not urgent defect.

## Security

Do not report vulnerabilities in public issues or pull requests. See
[SECURITY.md](SECURITY.md).
