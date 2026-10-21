# Hosted Playground investigation — 2026-10-21

The reported first-attempt failure → Retry → missing CSS → refresh sequence was
not reproduced. No blueprint change is justified by these observations.
Attribution remains **uncertain**: neither Keel nor an upstream defect has been
established. The existing blueprints are unchanged.

## Reproduction environment and results

Tests used isolated Playwright Chromium contexts, with a new browser for each
invocation, no shared MCP browser, and no existing browser profile. Chromium was
156.0.8078.4 (cached headless shell build 1248), Node 24.21.0, macOS arm64.
The hosted runtime used `remote.html?v=16a098b0e927b8fb3525ced104f0157344894802`;
WordPress reported 7.1.3, PHP was requested as 8.3.

The probe extracts both exact hosted URLs from the root README at runtime:

- Rolling: `https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/Keel/main/playground/blueprint-hosted.json`
- Stable: `https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/dknauss/Keel/main/playground/blueprint-stable.json`

Four rolling, two stable, and one minimal no-Keel fresh-context starts rendered
their expected WordPress admin pages without a Retry screen. All captured
WordPress stylesheet links had a CSSStyleSheet. The Keel pages had 27 stylesheet
links, including `settings.css` and `locked-controls.css` from Keel 0.7.0.

Early exploratory captures stopped when WordPress rendered while the Playground
shell still said Loading. The final probe waits for that shell loading state to
clear as well. Two rolling runs and one stable run using the stricter condition
reached Autosaved with the nested document complete. The final rolling run also
visited Installed Plugins: Keel Defaults had the `active` class and a Deactivate
link. The sample post was present in Posts. Its full flow took about 19 seconds;
another completed stable boot took about 26 seconds. These are observations,
not proposed delay values.

## Network, console, and service worker

- Remote blueprint JSON returned 200 with `Access-Control-Allow-Origin: *`.
- The rolling ZIP followed the official CORS proxy's 302 to a proxied GitHub
  release asset, then returned 200 (`application/octet-stream`) with
  `Access-Control-Allow-Origin: https://playground.wordpress.net`.
- No console errors, page exceptions, CORS policy failures, or HTTP >=400
  responses occurred in the completed initial-boot captures.
- The shell, remote frame, and nested WordPress page had an active controller
  at `https://playground.wordpress.net/sw.js`; scoped WordPress requests were
  served through the service worker.
- `wp-cli.phar` returned 200 through the service worker and then logged
  `net::ERR_ABORTED`, including in completed boots. The final run confirmed the
  sample post exists, so that event alone does not establish a failed step.
- Console warned that loaded WordPress 7.1 differs from requested `latest`.
  No failure was demonstrated from that warning.
- The first exploratory rolling run included a diagnostic top-level refresh
  after a styled page had already rendered. It also rendered styled afterward;
  it does not reproduce the user's refresh-dependent recovery.

No old upstream issue was used as evidence of a current defect. The exact live
[remote blueprint](https://raw.githubusercontent.com/dknauss/Keel/main/playground/blueprint-hosted.json)
was also read independently. No production WordPress operations were performed.

## Running the diagnostic again

`probe-hosted.cjs` uses Playwright without adding a repository dependency:

```sh
PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright \
CHROMIUM_PATH=/absolute/path/to/chrome-headless-shell \
node playground/probe-hosted.cjs main /tmp/keel-probe-main
```

Use `stable` or `minimal` in place of `main`; use a separate output directory per
run. If Playwright and its browser are installed normally, both environment
variables may be omitted. The minimal control uses the same PHP/WP selections,
login, and admin landing page, with no plugin or custom step.

The JSON records requests/responses and headers, console and page errors,
service-worker discovery, frame state, stylesheets, plugin activation, and sample
post presence. PNGs capture each landing state. A visible Retry button is clicked
once and captured as a separate stage. `PROBE_RELOAD=1` explicitly enables a
subsequent diagnostic refresh; refresh is off by default. A boot-condition timeout
is recorded and exits with status 2. Other recorded observations need inspection;
exit 0 alone is not an assertion that every resource succeeded.

Evidence from this session is under `/tmp/keel-playground-evidence/`, especially
`final-main/main-1792606672846.json`, `final-main/main-initial.png`, and
`stable2/stable-1792606649952.json`. Temporary evidence is not committed.

## Limits

These successful starts cannot exclude intermittent CDN/network failures,
existing-profile service-worker/cache state, browser-specific behavior, or a
previous hosted runtime deployment. The probe supplies a repeatable capture
method for the next failure; sleeps, forced reloads, version pins, and replacing
WP-CLI were not added as speculative fixes. No branches or commits were changed.
