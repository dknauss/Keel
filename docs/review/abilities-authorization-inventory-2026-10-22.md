# Abilities authorization inventory — 2026-10-22

Development work on `claude/abilities-authorization-inventory`, off `main` at
`67767de`. No version bump, release, tag or deployment. The settings schema and
defaults are unchanged. This implements option A of the
[scoping note](../plans/abilities-authorization-inventory.md).

On core 7.1+ (gated on `WP_Ability` and `WP_Filter_Sentinel`, both loaded on
every request), Site Health lists every callback on `wp_ability_permission_result`
and `wp_pre_execute_ability`:

- Callbacks are attributed to a plugin, theme or must-use plugin with the existing
  attribution layer.
- The six core `__return_*` helpers are reported with their effect: `__return_true`
  grants every permission check, the others deny, and on the pre-execute filter
  every one short-circuits.
- Other core functions found there are named as registered by untraceable code.
  Core registers nothing on either filter.
- Anything else is counted as untraceable.

One registration makes the result `recommended`. Structure alone never produces
`critical`. Each hook is read through `keel_defaults_registration_entries()`, so it
is bounded to 1,000 entries, and a malformed or oversized registry is partial, not
clean.

No callback is invoked. Nothing is persisted. No file path is shown. The posture
export is unchanged. The description is empty for anyone without
`view_site_health_checks`.

## Verification

- `tests/abilities-authorization.php` covers:
  - each callable form: function, `Class::method`, instance method, closure;
  - each owner kind: plugin, theme, must-use plugin, core helper, core function,
    untraceable closure;
  - unsupported cores, empty, oversized and malformed registries, and access;
  - the absence of file paths.

  Every fixture callback throws or exits. Six deliberate mutations each turned the
  test red: helper effect, core-function branch, version gate, partial coverage,
  empty-versus-partial status, and the capability gate.
- `composer test` (74 files), `lint`, `lint:compat`, `lint:syntax`, `analyse` (now
  twenty modules at level 6) and `test:types` pass.
- Real WordPress 7.1.3 / PHP 8.4 on a disposable Studio site, `keel-abilities-lab`,
  with the worktree symlinked as `keel-defaults` and a check that the branch's
  function exists:
  - **Keel only.** The test is registered through the real `site_status_tests`
    filter, both hooks have zero entries, and the result is `good`. This confirms
    that stock 7.1.3 registers nothing on either filter.
  - **Fixture plugin added.** The fixture registers a named callback and
    `__return_true` on the permission filter, and `__return_false` on pre-execute.
    Each callback appends to a sentinel file.
    - Before the inventory ran, an anonymous `check_permissions()` on the
      admin-only `core/get-site-info` ability returned `true`, and `execute()`
      returned `false` from the short-circuit. The sentinel fired, so the
      instrument is live. This reproduces the Bad Sector finding.
    - Sentinel cleared, then the inventory as admin:
      - status `recommended`;
      - permission filter: 2 entries, owner "Lab Ability Gate", `__return_true` →
        `grants_all`;
      - pre-execute: 1 entry, `__return_false` → `short_circuits_all`;
      - both effect sentences shown, no path in the description, and the sentinel
        absent afterwards.
  - **Subscriber.** `view_site_health_checks` is false and the description is
    empty. No `debug.log` was written.

## Not verified

- The Site Health screen itself was not driven in a browser. Evidence is from
  calling the registered test function and the real filter.
- Multisite was not run. The inventory is current-site and current-request, like
  the registration audit.
- Must-use and theme attribution were exercised only in the unit fixture, not on
  the lab.
- Core before 7.1 was not run on a real site. The gate is unit-tested.
