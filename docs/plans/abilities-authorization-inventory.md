# Abilities authorization filters — scoping note

Prepared 2026-10-22. It answers one question: can Keel's overlap reporting cover
the WordPress 7.1 Abilities API authorization filters, and what could it honestly
say?

**Status, 2026-10-22:** option A is implemented on the development branch
(`includes/abilities-authorization.php`, `tests/abilities-authorization.php`) and
is unreleased. Options B and C are on the roadmap, possibly as a separate plugin.
Two decisions changed during implementation:

- Named core functions are reported, not excluded (end of point 4).
- The posture export is unchanged (point 5).

## Why this came up

Bad Sector's 2026-10-22 post ("In WordPress 7.1, a 'public' ability is not a
permitted one") found two 7.1 filters that decide authorization on every
execution path:

- `wp_ability_permission_result` (`WP_Ability::check_permissions()`) runs after the
  ability's own `permission_callback` and has the last word. A callback returning
  `true` can let an anonymous REST request run an admin-only ability that is
  exposed over REST.
- `wp_pre_execute_ability` (`WP_Ability::execute()`) runs first. Any return value
  other than the per-call `WP_Filter_Sentinel` skips input validation, the
  permission check, the execute callback and output validation, and that value is
  returned to the caller. This applies to direct `execute()` callers such as WP-CLI,
  not just REST.

The follow-up draft argues that the root risk is several plugins hooking these
filters with no one seeing the combined effect. Keel already solves that problem
for its own single-value policy hooks. WordPress Security Benchmark control 11.4
asks admins to grep for these hooks by hand.

Checked against core 7.1.3 (`wp-includes/abilities-api/class-wp-ability.php`,
lines 652 and 809).

## What the existing machinery does and does not transfer

`keel_defaults_policy_overlap_report()` only answers one question: is another plugin
registered on a hook where Keel itself is present? It skips any hook without a
Keel registration (`$keel_present`), excludes Keel's own callbacks, and only flags
attributable rivals on `authoritative` hooks. Four of the brief's points follow
from that.

### 1. This is an inventory, not an overlap

Keel sets no policy on either filter, so there is no "Keel versus another plugin"
to report. Adding the two hooks to `keel_defaults_policy_hooks()` would do nothing,
because the `$keel_present` gate would skip them. Bypassing that gate would also
break the map's meaning: every entry there names a Keel setting
(`keel_defaults_policy_setting_for_hook()`) and is asserted against Keel's own
registrations.

So the reusable part is the **attribution layer**, not the overlap report:

- `keel_defaults_callback_plugin_dir()`
- `keel_defaults_callback_theme()`
- `keel_defaults_callback_mu_plugin()`
- `keel_defaults_core_roots()`
- `keel_defaults_plugin_name()`

These work on any callable, read the registry without invoking anything, and
already handle symlinks, must-use loaders and themes. The new code would be a
sibling, `keel_defaults_ability_authorization_inventory()`: a walk over
`$wp_filter` for the named hooks, bounded like
`keel_defaults_registration_entries()` (at most 1,000 entries, and a malformed or
oversized registry means unknown coverage). It would keep its own result shape
and its own request-local cache.

### 2. A single registration is the finding

On Keel's hooks, a second plugin means "possible contest". On these hooks, **one**
callback is enough to change who can do what, whether it grants (`true`), denies
(`false`/`WP_Error`) or short-circuits. Legitimate uses exist: the core docblock
names MFA gates and temporary elevation, and caching, rate limiting and maintenance
mode for the pre-execute filter. So a registration is something to review, not
proof of a fault.

That means a different severity ladder from the conflict test:

| Evidence | Site Health status |
| --- | --- |
| Filters absent (core before 7.1) | Test not registered |
| No callbacks on either filter | `good` |
| One or more callbacks, all attributable | `recommended` (review each) |
| Any core helper or untraceable callback | `recommended`, with stronger wording |
| Measured deny→grant on an anonymous request (option B below) | `critical` |

Structure alone never produces `critical`. That matches the rule behind
divergence: only a measurement outranks an inference.

### 3. Divergence cannot run once per request; a passive observer can

Keel's divergence check compares one predicted value per hook
(`keel_defaults_policy_expectations()`) with the value the chain settled on. Here
there is no prediction. The result depends on ability × user × input × channel,
and Keel has no expectation for any of them. A per-request probe would have to
pick one point in that space and call permission callbacks to see the answer,
which means running other plugins' code. Keel withdrew that approach in 0.5.1
because a check that reports collisions must not be able to cause them.

What a bounded, passive observer *can* do is bracket the filter:

- **Permission flips.** An observer at the earliest priority on
  `wp_ability_permission_result` records the incoming `$permission` (what the
  ability's own `permission_callback` returned). An observer at `PHP_INT_MAX`
  records the outgoing value. If the two differ in meaning (deny→grant or
  grant→deny), a filter changed the decision. Both observers return the value
  untouched.
- **Short-circuits.** An observer at `PHP_INT_MAX` on `wp_pre_execute_ability`
  sees a value that is not a `WP_Filter_Sentinel`. That means some earlier
  callback short-circuited the call, so no permission check ran.

Each event is recorded as a bounded key (at most 100 keys, written only when a new
key appears, like `keel_policy_divergence`):

- ability name
- direction (`grant`, `deny` or `short_circuit`)
- channel (`rest` if `wp_is_serving_rest_request()`, `cli` if `WP_CLI`, else
  `php`)
- caller class (`anonymous`, `authenticated`, or `can_manage_options`)
- first-seen time

Nothing is recorded about the input, the user ID or the request URL.

**What it could honestly claim:** "Since *time*, on at least one real invocation,
a filter changed *ability*'s permission result from denied to granted for a
logged-out REST request." That is a measured fact about traffic the site actually
served.

**What it cannot claim:**

- That untried combinations behave the same way. Silence is not safety.
- Which callback caused the change, unless the inventory shows only one
  registration on that filter.
- That the change was wrong. An MFA plugin flips grant→deny by design.

The bracketing also has the same ordering limits as divergence. A callback
registered at `PHP_INT_MAX` before Keel's runs after it, and one at the earliest
priority before Keel's runs before it. The observer sees most of the chain, not
all of it. It also adds a little work to every ability invocation, which is why
it would be opt-in.

### 4. `__return_true` resolves to core

`add_filter( 'wp_ability_permission_result', '__return_true' )` reflects to
`wp-includes/functions.php`. Today's report marks a core helper on a policy hook
as `KEEL_DEFAULTS_UNATTRIBUTED` and says only that it "cannot be traced". There is
also a second problem. `_wp_filter_build_unique_id()` returns the bare string for
a string callable, so two plugins registering `__return_true` at the same priority
collapse into **one** entry. Keel worked around that for its own hooks with
`keel_defaults_return_false()` and its siblings, but it cannot do the same for
callbacks it does not register.

Proposed reporting:

1. **State the effect, even when the owner is unknown.** The `__return_*` helpers
   are pure constants, so Keel can say what they do without running them.
   - On the permission filter, `__return_true` means "grants every permission check
     that reaches this priority". `__return_false`, `__return_null`,
     `__return_zero`, `__return_empty_string` and `__return_empty_array` all mean
     "denies every check", since core coerces any non-bool to `false`.
   - On the pre-execute filter, **any** `__return_*` short-circuits every ability
     and skips its permission check, because none of them return the sentinel.

   Those effects come from a closed table keyed on the helper's name, not from
   inference. That makes an untraced `__return_true` here the most specific
   finding this report can make.
2. **Say "at least one" for helpers.** Because of the identity collapse, a helper
   entry is reported as "one or more plugins or themes" registered it.
3. **Optional load-phase attribution (deferred; option C).** Core fires
   `plugin_loaded`/`mu_plugin_loaded` after each file is included. Diffing the two
   hooks' entries between consecutive events attributes file-scope registrations,
   including `__return_true`, to the plugin just loaded. The limits:
   - Keel only sees plugins that load after it (alphabetically after
     `keel-defaults`), unless a must-use shim is added.
   - It misses registrations made later, on `init` or `rest_api_init`.
   - A helper already registered at that priority produces no diff, because of
     the identity collapse.

   It is useful, but it is partial and requires a new load-order dependency, so it
   is not recommended for the first pass.

On Keel's policy hooks, named callbacks under core roots are treated as platform
behavior and excluded. That rule does not hold here. Core registers nothing on
either filter in 7.1.3, so a core function found on one was put there by other
code. `add_filter( 'wp_ability_permission_result', 'is_user_logged_in' )` would
grant every logged-in user. The implementation therefore names any non-helper
core function it finds as "registered by code that cannot be traced", instead of
hiding it.

### 5. Privacy

`keel_defaults_posture_export()` promises "no plugin inventory … callback paths"
and lists `no_inventory_or_identifiers` in its limitations. Ability names identify
installed plugins (`woocommerce/…`), so they count as inventory too.

**As implemented, option A adds nothing to the export.** The export runs no
reflection and reads only cached evidence, and option A caches nothing. Its
inventory exists only inside the Site Health request. If option B ever stores
observations, the export would carry counts only, in this shape:

```json
"abilities_authorization": {
  "scope": "current_site",
  "coverage": "unknown | cached_not_revalidated | partial",
  "permission_result_callbacks": null,
  "pre_execute_callbacks": null,
  "core_helper_callbacks": null,
  "untraceable_callbacks": null,
  "observed_grant_flips": null,
  "observed_short_circuits": null
}
```

`null` means not collected, as elsewhere in the export. Site Health is
authenticated and gated by `view_site_health_checks`, so it can name plugins,
themes, must-use file names and ability names, as the conflict test does today. It
never shows file paths. If anything is added to Site Health **Info**, which people
paste into support forums, it gets the export's counts-only treatment.

## Options

| | Scope | Runs others' code | Cost | Claim strength |
| --- | --- | --- | --- | --- |
| **A. Inventory** | Registered callbacks on both filters, attributed, with helper effects | No | Site Health load only | Structural: "these can change authorization" |
| **B. A + passive flip observer** (opt-in) | Adds measured deny→grant, grant→deny and short-circuit events | No | Two observers per invocation; writes only on a new key | Measured, for traffic actually served |
| **C. A + load-phase attribution** | Names some `__return_*` owners | No | `$wp_filter` diff per plugin load | Partial; load-order dependent |
| **D. Active probe** | Calls `check_permissions()` as anonymous for each ability | **Yes** | Per scan | Rejected: runs other plugins' code, and a null-input probe proves little |

Adjacent filters that could be added to the inventory later:

- `wp_register_ability_args` can replace `permission_callback` or change REST
  exposure at registration. That is arguably as strong as these two filters, and
  invisible afterwards.
- `wp_ability_validate_input` can bypass validation.
- `wp_ability_execute_result` and `wp_get_abilities_item_include` change output
  and discovery, not authorization. Leave them out.

## Recommendation

Ship **A** first, as its own Site Health test. Keep it out of the conflict notice
and its dismissal fingerprint: MFA and elevation plugins legitimately hook these
filters, and a permanent dashboard banner for them would teach people to ignore
the notice. Scope A to the two named filters, registered only where core has them
(7.1+). Consider `wp_register_ability_args` as the one extension. Then consider
**B** as an opt-in setting once A has been run against real sites. Defer **C**.
Never build **D**.

## What Site Health would say (option A)

**Label (none):** "No plugin is overriding ability permissions"

> WordPress 7.1 lets plugins change the outcome of an ability's permission check,
> or skip it entirely. Nothing on this site is registered to do either. This
> checks registrations in this request; it does not run any plugin's code.

**Label (some):** "Plugins can change who may run abilities on this site" —
`recommended`, badge Security

> Abilities are actions that AI tools, the REST API and WP-CLI can run on this
> site. Each one checks permission before it runs. The code below can change
> that answer, or skip the check. One registration is enough, so each is worth
> understanding even when no other plugin is involved. Many are intentional, for
> example a two-factor gate or a cache.
>
> - **Overrides permission results** (`wp_ability_permission_result`): *Plugin A*,
>   *must-use plugin host-loader.php*
> - **Can skip the permission check entirely** (`wp_pre_execute_ability`):
>   *Plugin B*
>
> Check each plugin's documentation for why it uses these filters. Keel reports
> what is registered, not what it does for a given user or request.

**Added when a core helper is present:**

> One or more plugins or themes registered WordPress's `__return_true` helper on
> `wp_ability_permission_result`. That **grants every permission check** that
> reaches it, for every ability, user and channel, including logged-out requests to
> abilities exposed over REST. The helper belongs to WordPress, so nothing records
> who added it. Your active plugins, theme and must-use plugins are the place to
> look.

(For `wp_pre_execute_ability`: "…which **skips the permission check for every
ability**, including WP-CLI and other direct calls.")

**Added with option B, when a grant flip has been seen** — `critical`:

> On at least one real request since *date*, a filter changed *ability*'s
> permission check from denied to granted for a logged-out REST request. Keel saw
> this happen; it cannot say which callback did it unless only one is listed
> above, and it has not tested any other combination of user, input or channel.

## Not covered by any option

- Whether a given ability's own `permission_callback` is correct.
- Whether `show_in_rest` or MCP exposure is appropriate.
- Requests not observed, under option B.
- Network-wide state: every finding is current-site and current-request, like the
  registration audit.
