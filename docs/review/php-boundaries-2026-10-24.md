# Focused PHP boundary review — 2026-10-24

Baseline: `facde24`, the merge of #324. Development remains unreleased.

## Findings and fixes

- The legacy minor-update action checked `update_core` without independently
  requiring `manage_network_options` on multisite. Added the network check to
  both the handler and its action presentation. This is defense in depth for
  delegated/custom capability configurations; standard multisite site admins
  are already denied `update_core` by WordPress. The existing nonce-protected
  action link remains supported, including GET. This pass does not classify that
  existing nonce-protected method as an authorization bypass.
- Nested arrays in range and multiselect inputs produced conversion warnings;
  objects could produce conversion errors. Numeric fields could convert
  non-scalar input to an unintended number. Reject non-scalar numbers/ranges
  before conversion and discard non-scalar multiselect members. Valid existing
  values and the role allowlist remain unchanged.
- The backport policy action linked to a retired settings slug and section.
  Corrected the link to the current core-update-policy control.

## Executable evidence

`tests/php-boundaries.php` calls the real callbacks with minimal WordPress test
doubles. It verifies missing authority, delegated multisite authority, invalid
nonces, and the order of authorization/nonce checks before a policy write. An
authorized valid request reaches the intercepted write boundary; the test does
not claim to exercise the redirect or perform a database write. Network-save and
backport AJAX denial cases terminate before collection or mutation. The action
renderer withholds network controls from a delegated site administrator.

Every numeric, range, select and multiselect schema field receives nested arrays
and objects, with PHP warnings treated as failures. Results must match the
sanitizer's safe defaults. Before correction, the authority test reached the
write boundary unexpectedly; after that correction, malformed input failed with
an array-to-string warning. Both now pass.

Existing posture-export tests exercise method, capability, site-bound nonce and
privacy projection. Existing installer, network-lock, AJAX collector, password
entry-point, REST-gate and malicious-cache tests complement the new cases.

## Source review and limits

Reviewed the admin-post and AJAX registrations, custom network save, settings
sanitizer, posture export, diagnostic disclosure/rendering, installer request
validation, and policy action presentation. Read-only presentation flags do not
authorize writes. Diagnostic output uses escaping or bounded sanitized markup;
the export projects permitted fields rather than dumping stored data. SQL
collectors use prepared values and bounded queries; cron deserialization disables
classes and limits depth. Outbound update-status and HIBP requests use fixed
service endpoints rather than request-provided destinations.

This is a focused source and standalone-test review, not a complete penetration
test or a real WordPress HTTP authorization lab. No production site was touched.
Third-party capability/filter behavior, native WordPress nonce implementation,
and installation/filesystem actions were not newly exercised in this pass.

Counterpart: not applicable — AX has a different settings sanitizer and no
matching legacy enable-minor-updates action or Keel policy-action presentation.
Its separate role-list conversion paths were not changed or certified here.

## Verification

Passed: `composer test`, `composer lint`, `composer lint:compat`,
`composer analyse`, `composer test:types`, `npm run lint`,
`composer audit --locked --no-interaction`,
`npm audit --omit=dev --audit-level=high`, and `git diff --check`.
PR CI is separate evidence and must be checked before merge.
