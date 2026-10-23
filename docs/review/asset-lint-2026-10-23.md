# Browser-asset lint verification — 2026-10-23

Development tooling and style cleanup only; no new setting, version bump,
production change or release. #319 is merged as `0cf70bc`; #320's pending en_CA
language-pack task remains intact.

## Scope

`npm ci --ignore-scripts` installs exact, lockfile-backed development dependencies.
CI uses Node.js 24. ESLint 10.13.0 uses WordPress's ESLint plugin 28.0.0
`recommended-with-formatting` flat preset, scoped to browser JavaScript under
`assets/`, with browser globals and script source type. Stylelint 17.16.0 uses
`@wordpress/stylelint-config` 27.0.0 for all CSS under `assets/`, including the
root-level list-table styles. Both commands fail on warnings. Node lab harnesses,
maintenance scripts and inline PHP-generated assets are outside this scope.

The independent asset CI job runs on pull requests and pushes to main, with no
path filter. The existing rolling build now requires it alongside both PHP jobs.
The WordPress.org deployment and version-tag workflows are unchanged. No asset
compilation or runtime npm dependency is introduced. Manifests, lockfile, lint
configuration and `node_modules` are excluded from the plugin ZIP.

## Findings and fixes

The initial pass found 53 JavaScript and 12 CSS errors. JavaScript changes use
`const`, normalize IIFE/function/array formatting, remove an unused catch binding,
and defer two attribute reads until after early returns. CSS changes normalize
font weight to its equivalent numeric value, add spacing, and move lower-specificity
rules earlier. Moved rule groups set different properties from the rules they pass;
no selector, declaration value or effective cascade is intentionally changed.

Two local `selector-class-pattern` exceptions preserve existing PHP column IDs
`column-keel_thumb` and `column-keel_modified`. The rule remains enabled elsewhere;
there are no global rule exclusions. No product defect was established.

The existing workflow regression assertion was updated to require asset lint
before rolling publication. Contribution instructions now include npm setup and
the local checks. The development Node requirement does not change the plugin's
PHP 7.4 minimum.

## Validation

- Clean `npm ci --ignore-scripts` and `npm run lint` pass, covering three JS and
  five CSS files. npm reports zero audited vulnerabilities. WordPress's dependency
  tree emits upstream peer-range warnings for unused React plugins with ESLint 10;
  installation and the scoped vanilla-JS rules succeed without legacy-peer flags
  or dependency overrides.
- Temporary new browser-asset files prove the normal commands fail on an undefined
  JavaScript global (`no-undef`) and an unknown CSS property
  (`property-no-unknown`). Both probes were removed afterward.
- A synthetic Chromium browser fixture compares base and changed assets: dependent
  visibility and ARIA, slider readout/preview, locked-control keyboard refusal and
  value restoration, lock-summary reveal/focus, and horizontal table scrolling
  produce identical outcomes. Sampled computed styles for grouped rows, lock
  controls/markers and descriptions match in LTR and RTL. This is a focused DOM
  comparison, not a new real-WordPress or cross-browser accessibility sign-off.
- All standalone PHP tests and PHP coding standards pass. The workflow assertion
  includes the new asset dependency.
- The local ZIP build retains runtime assets and excludes all four new tooling
  files and `node_modules`. No package was published.
- JSON parsing, documentation links and `git diff --check` pass.

No production site was contacted for this change. Shared PHP runtime is unchanged;
counterpart porting is not applicable to Keel's asset tooling and style cleanup.
