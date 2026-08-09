# Playground blueprints

[WordPress Playground](https://playground.wordpress.net/) runs WordPress in the
browser, so the demo link in the README needs no host and installs nothing on
your machine.

## `blueprint-hosted.json`

Installs the rolling `latest` build — the zip `release.yml` republishes on every
version tag — and opens **Settings → Keel**, which is the whole point of the
plugin: every default and its state on one screen.

It also creates a published post, so the content defaults (comments, pingbacks,
author archives, attachment redirects) have something to act on. Without it the
site is empty and half the toggles look inert.

## `.wordpress-org/blueprints/blueprint.json`

A second blueprint, in a different directory, for a different consumer: the
wordpress.org plugin directory reads it from there to power the **Live Preview**
button on the listing page. It is not referenced from the README and nothing in
this repository links to it.

It installs the same `latest` zip and lands on the same screen. It differs only
in the PHP version it prefers and in not going through the CORS proxy, which the
directory does not need.

Keep the two in step. The sample-post step in particular has to be in both: the
directory preview is the first look most people get, and without a post the
content defaults have nothing to act on, which is the "half the toggles look
inert" problem this blueprint exists to avoid.

## `blueprint-stable.json`

The same thing, installing from `releases/latest/download/`, which resolves to
the newest **non-prerelease** release — `v0.2.0` as of 2026-08-04. This is the
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
