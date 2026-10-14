# Backport extraction review

2026-10-14, PR #251. Reviewed the entry point, module loading, callback
registration, screenshot tracking, translation references and existing behavioral
coverage. No automated review comments were present at review time.

Two safeguard gaps were corrected before merge:

- The loading test counted eight registrations but did not consume expected keys,
  so a valid duplicate could hide a missing registration. It now rejects duplicate
  keys and requires every expected hook exactly once.
- Screenshot review must follow all four extracted modules, not just presentation:
  policy/status/action code can affect visible messages or state. The detector
  now watches every extracted file, preserving the old whole-module scope.
  The guard test changes each of those files and verifies review invalidation.

No runtime behavior defect was found in the extraction. The original 33 function
bodies, callback names/priorities and cache values remain unchanged. The installer
still validates capabilities, nonce, target and offer before explicit execution.
The previous real multisite and browser checks are recorded in the incremental
refactoring plan. Lint, PHP compatibility and the full local suite passed after
these corrections; CI is required on the corrected head before merge.
