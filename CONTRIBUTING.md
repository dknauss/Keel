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

`.wordpress-org/screenshot-1..3.png` are the wordpress.org listing images *and*
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
ARIA-only fix, or a new screen these three do not show, leaves the images
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

## Releases are on hold

**Do not tag a release while the wordpress.org submission is in review.** Merge
to `main` as normal; do not bump the version, do not push a `v*` tag, and do not
publish.

The reason is disclosure, not risk. A plugin in the review queue is reviewed as
the version that was submitted, and every release made in the meantime is one the
maintainer has to go and tell the reviewer about — twice already in a single day,
each time for something found after the submission rather than before it. Holding
is cheaper than the note, and a queue that is about to clear makes the note
pointless anyway.

Work that would otherwise be a release accumulates on `main` and ships as one
version when the queue clears. Something genuinely urgent — a security fix, or a
defect bad enough that shipping late is worse than the disclosure — overrides
this, and then the disclosure is part of the job rather than an accident.

Lifting this is a decision for the maintainer once the plugin is listed. Until
then, if you are an agent session and the work you just finished feels like it
wants a version bump, it does not.

## Security

Do not report vulnerabilities in public issues or pull requests. See
[SECURITY.md](SECURITY.md).
