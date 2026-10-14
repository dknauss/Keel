# Password extraction review

2026-10-14, PR #252. No automated review comments were present at review time.
Reviewed the security entry point, declaration order, public APIs, bootstrap
registration, HIBP privacy/failure/cache behavior, translations, packaging and
existing entry-point tests alongside the real WordPress lab record.

All 37 original security function bodies and signatures matched the base branch;
the public set was unchanged. The security entry point still exposes the moved
callbacks, and bootstrap retains all four password hooks. No runtime defect was
found. Corrected the entry-point test's stale header: profile validation uses
user_profile_update_errors, separate from the password-reset hook; there are four
integration hooks, including the REST argument guard and fallback.

No policy was changed to make a test pass. Existing unit tests and the prior
single-site lab cover scoping, trimmed passwords, actual REST rejection/success,
prefix-only bounded/padded lookup, caching, unknown/fail-open outcomes and opt-out.
The lab did not call the external HIBP service or drive browser reset/profile
forms. Lint, compatibility and the full suite must pass on the reviewed head
before merge; this record does not expand the lab's claims.
