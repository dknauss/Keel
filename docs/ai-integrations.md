# AI integrations

WordPress now has three separate AI surfaces, and they point in different
directions. Keel touches one of them today and plans to use a second. This page
records what each one is, what Keel does with it, and what is still undecided.

Facts about core and the MCP Adapter below were checked on 2026-10-12 against
core's source (`wordpress-develop` trunk, 7.2-alpha) and the adapter's
repository at 0.7.0. Both move quickly, so recheck before relying on a detail.

## The three pieces

| Piece | Direction | Where it lives | Since |
| --- | --- | --- | --- |
| AI Client and AI provider connectors | Outbound: the site sends prompts and content to an AI provider | Core | WordPress 7.0 |
| Abilities API | Neither by itself: a registry of named, typed actions with permission checks | Core | WordPress 6.9 |
| MCP Adapter | Inbound: an agent outside the site calls those abilities | Plugin (`mcp-adapter`, 0.7.0) | Requires 6.9 |

The distinction matters because one switch does not cover all three. Turning off
outbound AI does not stop an agent from calling in, and the reverse.

## What Keel does today

### AI Connectors (`disable_ai_connectors`, default on)

Returns `false` from core's `wp_supports_ai` filter, removes Settings →
Connectors from the menu, closes that screen with a 403, and fires
`keel_disable_ai_connectors` for plugins with their own providers. It is offered
only where `wp_supports_ai()` exists (7.0 and later). The full rationale is in
[WordPress default settings](wordpress-default-settings.md#disable-ai-connectors).

What the gate reaches, from core's source: `wp_supports_ai()` is consulted in
three places — connector setup (`connectors.php`), the connector registry, which
refuses `ai_provider` connectors, and the AI Client's prompt builder. The Abilities
API does not call it. **So this setting stops the site talking to AI providers; it
does not stop agents reaching the site through the MCP Adapter.** That is a
separate decision, made by whether the adapter is installed and which abilities
are public.

### Application Passwords (`disable_application_passwords`, default off)

The adapter's HTTP transport requires a logged-in user. The usual way a remote
client logs in is an application password (the `@automattic/mcp-wordpress-remote`
proxy uses them). With this setting on, that route is closed, and an MCP client
over HTTP has nothing to authenticate with unless another authentication plugin
supplies one. The STDIO transport, which runs through WP-CLI, is unaffected.

Keel's **REST Authentication** setting (`disable_rest`) does not change this. It
blocks anonymous REST requests, and the adapter's endpoint already refuses those.

### Environment

Core registers a `core/get-environment-info` ability that reports
`wp_get_environment_type()`. It does not know about Keel's
[Environment override](environment-detection.md#overriding-it-in-keel). An agent
asking core what environment a site is in can get `local` from a live site whose
administrator has overridden it to Production in Keel. That gap is one reason
for `keel/get-settings` below, which reports the environment Keel actually acts on.

## Planned: Keel's settings as abilities

Tracked in [ROADMAP.md](../ROADMAP.md) under Later; the design lives here.

**Why.** On 2026-10-12, auditing comments and pingbacks across seven sites, Keel
was the cause on three of them. Its settings could be read only with
`wp option get keel_settings` over SSH, or changed by driving the settings screen
in a browser. One site's plan had no SSH, so the browser was the only route.
Abilities would give scripts and agents one supported, validated way in.

**The set.** Small on purpose:

- `keel/get-settings`: the effective value of every setting and where it came
  from (stored, default, network policy, filter), the resolved environment and
  whether an override applies, and any recorded conflicts. Read-only.
- `keel/update-setting`: one key at a time, validated by the same sanitizer the
  settings screen uses, so there is one sanitizer rather than two. Refuses keys
  locked by network policy.
- `keel/site-posture`: the Site Health summary as data rather than prose.
  Read-only.

**Rules every Keel ability follows.**

- Registered on `wp_abilities_api_init`, inside a `keel` category registered on
  `wp_abilities_api_categories_init`, and only when `wp_register_ability()` exists.
  Keel's floor is 6.4, so on 6.4–6.8 there is nothing to register and nothing
  breaks.
- `permission_callback` is `current_user_can( 'manage_options' )`, the same as
  the settings screen.
- Input and output schemas on every ability. Core validates both.
- Annotations set honestly: the readers are `readonly` and `idempotent`;
  `update-setting` is `idempotent` (setting a value twice is the same as once)
  and not `destructive`, since every change can be set back.
- **Private by default.** Core defaults `meta.public` to `false`, and the adapter
  exposes an ability only when `meta.mcp.public` is `true`, or, failing that,
  `meta.public` is. Keel leaves both off. A site that wants agents to reach them
  turns that on knowingly, through a setting or a filter (to be decided). A
  settings writer reachable over the network is not something to inherit.

**Why not yet.**

- The adapter is young and has broken compatibility three times: it ships
  migration guides for 0.3.0, 0.5.0 and 0.7.0.
- Public exposure is still changing in core. The adapter's README says that from
  7.1, core applies `meta.public` to the REST API as well; on 6.9 and 7.0 REST
  exposure still needs `meta.show_in_rest`. Core trunk now derives `show_in_rest`
  from `public` when it is not set. Keel should register against one settled
  behaviour, not three.
- Authentication for a writing ability over HTTP needs a decision first. The
  common route is application passwords, which Keel can itself switch off (see
  above). A design that only works with them on would argue against one of Keel's
  own settings.

**Revisit when** the adapter has gone two minor releases without a migration
guide, or when core ships an MCP transport of its own, whichever comes first.
Borges and Maestro would follow the same pattern.

## Open questions

- Should exposure be a Keel setting (visible, counted among the defaults) or
  only a filter (out of sight for people who do not use agents)?
- Should `keel/update-setting` log each change (who, when, old and new value),
  given that an agent changing a security setting is exactly what an
  administrator will want to audit later?
- Does a network-activated Keel register abilities per site, per network, or
  both?

## Sources

- [Abilities API in WordPress 6.9](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/)
  (Make WordPress Core), and the [Abilities API handbook](https://developer.wordpress.org/apis/abilities-api/).
- `wordpress-develop` trunk: `src/wp-includes/ai-client.php` (`wp_supports_ai()`),
  `connectors.php`, `class-wp-connector-registry.php`,
  `ai-client/class-wp-ai-client-prompt-builder.php`, `abilities.php`
  (`core/get-environment-info`) and `abilities-api/class-wp-ability.php`
  (`DEFAULT_PUBLIC`, `DEFAULT_SHOW_IN_REST`, annotation defaults).
- [WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter) at 0.7.0:
  `includes/Abilities/McpAbilityExposure.php` (exposure rule), `docs/migration/`,
  and the README (transports, `is_user_logged_in()` check, application passwords,
  `meta.public` and REST from 7.1).
- [MCP Adapter on WordPress.org](https://wordpress.org/plugins/mcp-adapter/).
