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
wordpress.org plugin directory reads it from there to power the **Live Preview**
button on the listing page. It is not referenced from the README and nothing in
this repository links to it.

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

## Checking a change

Blueprints fail silently in the browser, so verify the zip URL still resolves
before trusting a green-looking demo:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -L \
  https://github.com/dknauss/keel/releases/download/latest/keel.zip
```

`200` means the blueprint has something to install. Anything else means the
Playground link in the README is broken regardless of how the blueprint reads.
