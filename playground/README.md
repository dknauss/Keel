# Playground blueprints

[WordPress Playground](https://playground.wordpress.net/) runs WordPress in the
browser, so the demo link in the README needs no host and installs nothing on
your machine.

## `blueprint-hosted.json`

Installs the rolling `latest` build — the zip `ci.yml` republishes on **every push
to `main`** — and opens **Settings → Keel**, which is the whole point of the
plugin: every default and its state on one screen.

That trigger is the point of this blueprint, and it was wrong until 2026-08-09.
The asset was written only by `release.yml`, which fires on version tags, so "the
rolling build" was really "the last tagged release" and this blueprint served the
same zip as `blueprint-stable.json`. Anything merged since the last tag — every
copy fix, every new default — was invisible in the demo, which is the one place a
reviewer looks to find out what the plugin currently does.

It also creates a published post, so the content defaults (comments, pingbacks,
author archives, attachment redirects) have something to act on. Without it the
site is empty and half the toggles look inert.

## `.wordpress-org/blueprints/blueprint.json`

A second blueprint, in a different directory, for a different consumer: the
wordpress.org plugin directory reads it to power the **Live Preview** button on
the listing page. It is not referenced from the README and nothing in this
repository links to it.

It is read from SVN, not from here. `wp-deploy.yml` stages `.wordpress-org/` as
the deploy action's `ASSETS_DIR`, and the action rsyncs that into SVN `assets/`
recursively, so this file lands at `assets/blueprints/blueprint.json` — the one
path the directory looks at. Until 2026-09-10 that staging step excluded
`blueprints/` as though it were working material like `brand/`, so the blueprint
never left the repository: the API returned no `preview_link`, the listing had
no Live Preview button, and `readme.txt` had been claiming a "try it live" link
since 0.3.0. `tests/release-workflows.php` now asserts the exclude is absent.

**It must not install Keel.** The directory mounts the copy of the plugin it is
serving into the Playground instance itself, before the blueprint runs. That is
the whole point of the preview — it shows the version on the listing, which is
the version that was reviewed. So this file carries no `installPlugin` step at
all: only the landing page, the login, and the sample post.

Until 2026-08-22 it did carry one, pointing at
`releases/download/latest/keel.zip`. Two things were wrong with that, and the
instruction that used to sit here — "keep the two in step" — is what kept it
wrong. The directory restricts blueprint resources to wordpress.org, so a GitHub
URL was unlikely to run at all; and `latest` is the **rolling build of `main`**,
so on the occasions it did run, the listing's preview would have demonstrated
code nobody had reviewed. A preview that installs something other than the
listed plugin is not a preview of the listing.

So the two blueprints are deliberately *not* in step, and only one thing has to
be carried across: the sample-post step. The directory preview is the first look
most people get, and without a post the content defaults have nothing to act on,
which is the "half the toggles look inert" problem these blueprints exist to
avoid.

## `blueprint-stable.json`

The same thing, installing from `releases/latest/download/`, which resolves to
the newest **non-prerelease** release — `v0.2.0` as of 2026-08-09. This is the
primary "try it live" link, because it is byte-identical to what somebody would
download, and it follows each new stable release without the URL changing.

It could not exist until there was a stable release: `v0.1.0-dev` and the rolling
`latest` are both marked pre-release, so that path returned 404 and a badge built
on it would have shipped broken. That is why the README carried one Playground
badge and not two until now.

## README badges

The README links both blueprints twice: as the two links under **Try it
first**, and as two badges at the end of the badge row, so the demo is visible
before anyone scrolls. The blue **Playground · stable release** badge opens
`blueprint-stable.json`; the amber **Playground · main build** badge opens
`blueprint-hosted.json`, amber because it runs code that has not been released.
Both point at the blueprints on `main` through `raw.githubusercontent.com`, so
a blueprint change reaches the badges as soon as it merges.

## Checking a change

Blueprints fail silently in the browser, so verify the zip URL still resolves
before trusting a green-looking demo:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -L \
  https://github.com/dknauss/keel/releases/download/latest/keel.zip
```

`200` means the blueprint has something to install. Anything else means the
Playground link in the README is broken regardless of how the blueprint reads.

## Focused Updates-screen verification (unreleased branch)

[Open the synthetic Updates demo](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fdknauss%2FKeel%2Fcodex%2Fupdates-screen-verification%2Fplayground%2Fblueprint-updates-screen.json).
This URL works after the branch and these files are pushed. It tracks
`codex/updates-screen-verification`, not a release or the rolling `latest` asset.
It installs the branch source archive through the official CORS proxy and lands
on `/wp-admin/update-core.php`. Direct GitHub ZIP fetches have failed CORS checks;
this uses a ZIP `url` resource, not `git:directory` or `github-proxy.com`.

Everything shown as update evidence is **synthetic**. A prominent admin notice
and section heading label it. This is an inspection fixture, not proof that a
WordPress.org request succeeded, a release exists, a cron job ran, or any update
will install. The actual WordPress version is left alone. No package URLs,
security release claims, or successful install results are fabricated.

Inspect these cases on the Updates screen:

1. The default two-day attempted check is stale. Use the **Synthetic recent
   attempt** link for five minutes, then switch back. Both states must retain
   the caveat that a check attempt does not establish a response.
2. The partial schedule snapshot omits both observed hooks. They must read
   **Unknown**, with the partial-coverage explanation, rather than Not scheduled.
3. The installed `synthetic-keel-demo/synthetic-keel-demo.php` plugin has a false
   baseline changed to true by a demo filter. The inactive `synthetic-keel-demo`
   theme has a true baseline changed to false. Other items have no fixture
   metadata and remain Unknown. The fixture theme need not be activated.
4. The automatic updater is disabled by the demo guard. WordPress HTTP requests,
   upgrader downloads, and pre-install execution return explicit demo errors.
   Read capabilities remain available so the report can render. `DISABLE_WP_CRON`
   also prevents traffic-driven cron; none of this describes a real site's policy.

`updates-screen-fixtures.php` is the readable demo-only MU-plugin source.
`build-updates-demo.py` embeds it into the self-contained
`blueprint-updates-screen.json`; regenerate that JSON after editing the fixture.
No runtime plugin file is changed. The fixture is installed only after Keel,
so the install guard cannot block the blueprint's initial plugin installation.

Local verification (Node 20.18+ and Python `jsonschema` required):

```bash
python3 playground/build-updates-demo.py
python3 playground/test-updates-demo.py
php -l playground/updates-screen-fixtures.php
php -l playground/verify-updates-demo.php
```

The test validates against the current official Blueprint schema, checks embedded
fixture parity, then boots a disposable WordPress with `@wp-playground/cli` and
mounts this clone's Keel code instead of fetching an unpushed branch. Its final
PHP step checks actual report rendering, both attempt ages, partial coverage,
changed extension decisions, visible fixture labeling, and installer/network
guards. This local pass does not verify the hosted URL, CORS, browser CSS, or the
parent branch's eventual code; perform the final hosted browser pass after push.

Local verification on 2026-10-23 passed with Playground CLI 3.1.57,
WordPress 7.1.3 and PHP 8.3 against source commit `7013953`: schema validation,
all real-WordPress fixture assertions, `composer test`, both demo PHP syntax
checks, and `git diff --check`. Hosted branch/browser verification remains a
separate step after integration and push.
