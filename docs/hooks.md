# Hooks reference

Every filter and action Keel offers, in one place. Keel has 33 filters and 2
actions. Each one is listed with its arguments, its default, the setting that has
to be on for it to run, and where it lives in the source.

The settings screen is the first place to change Keel. Reach for a hook when the
change belongs in code you deploy, such as a site-specific plugin or a
must-use plugin, or when no setting covers what you need. A hook can only adjust
a default that is switched on. If the setting is off, its hooks never run.

Most hooks are read on every request that uses them, so a filter added in a
must-use plugin or in a regular plugin loaded before `init` takes effect at once.
The [per-setting reference](wordpress-default-settings.md) explains why each
default behaves the way it does. Constants that switch features off, and the
`wp-config.php` constants that lock settings, are in
[Constants and locked settings](constants-and-locks.md).

## Security headers

All four run on `wp_headers` at priority 99, only while **Security Headers** is
on (`security_headers`, default on). `keel_x_frame_options` also needs
**Frame Options** set to a value.

| Hook | Type | Arguments and default | Source |
| --- | --- | --- | --- |
| `keel_disable_x_frame_options` | filter | `bool $disabled` = `false`. Return `true` to leave `X-Frame-Options` alone. | `includes/security.php` |
| `keel_x_frame_options` | filter | `string $value` = the **Frame Options** setting (`SAMEORIGIN` by default). An existing weaker value is raised to this one; a stronger one is kept. | `includes/security.php` |
| `keel_disable_x_content_type_options` | filter | `bool $disabled` = `false`. Return `true` to skip `X-Content-Type-Options: nosniff`. | `includes/security.php` |
| `keel_disable_referrer_policy` | filter | `bool $disabled` = `false`. Return `true` to skip `Referrer-Policy`. | `includes/security.php` |
| `keel_referrer_policy` | filter | `string $value` = `strict-origin-when-cross-origin`. An empty string sends nothing. A policy another component already set is always kept. | `includes/security.php` |

The Customizer preview is always left alone, whatever these return, because core
sets its own frame header there so the preview can load.

## REST API

| Hook | Type | Arguments and default | Source |
| --- | --- | --- | --- |
| `keel_public_rest_routes` | filter | `string[] $prefixes` = `array( '/oembed/1.0' )`. Route prefixes, each with a leading slash, that anonymous visitors may still reach while **REST Authentication** (`disable_rest`) is on. | `includes/security.php` |

```php
add_filter( 'keel_public_rest_routes', function ( $prefixes ) {
	$prefixes[] = '/my-plugin/v1/public';
	return $prefixes;
} );
```

Every route you add here is open to anyone. Author names are still stripped from
oEmbed responses whenever this carve-out is in play.

## Passwords

These run while **Password Strength** (`require_strong_passwords`) is on.

| Hook | Type | Arguments and default | Source |
| --- | --- | --- | --- |
| `keel_minimum_password_length` | filter | `int $minimum` = `15`. Counted in characters, not bytes. | `includes/password-policy.php` |
| `keel_password_blocklist` | filter | `string[] $blocklist` = six well-known passwords. Compared case-insensitively. Catches the obvious ones when the breach lookup cannot run. | `includes/password-policy.php` |
| `keel_weak_roles` | filter | `string[] $roles` = the **Password Policy Exemptions** setting (subscriber by default). A user is exempt only when every role they hold is listed. | `includes/password-policy.php` |
| `keel_disable_hibp` | filter | `bool $disabled` = `false`, `string $password`. Return `true` to skip the Have I Been Pwned lookup. Length and the other rules still apply. Site Health reports it as switched off in code. | `includes/password-hibp.php` |
| `keel_password_is_pwned` | filter | `bool $pwned`, `string $password`. Return `true` to reject a password, for example one found in your own breach list. Returning `false` does not clear an unknown lookup result. | `includes/password-hibp.php` |
| `keel_hibp_request_timeout` | filter | `int $seconds` = `4`, at least 1. | `includes/password-hibp.php` |
| `keel_hibp_max_response_bytes` | filter | `int $bytes` = `131072` (128 KB), at least 1024. A larger reply is treated as cut short and gives an unknown result. | `includes/password-hibp.php` |
| `keel_hibp_budget_policy` | filter | `array{capacity:int, interval:int}` = `capacity` 10, `interval` 60. How many fresh lookups the site or network may make per interval in seconds. Capacity must be 1 to 100 and the interval 1 to 3600, or the default is used. On multisite the budget is shared by the whole network. | `includes/password-hibp-budget.php` |
| `keel_password_rescreen_interval` | filter | `int $seconds` = 30 days. How long a login re-check result stays valid. Never less than one hour. | `includes/password-login-screening.php` |
| `keel_password_rescreen_skip_roles` | filter | `string[] $roles` = empty, so every role is checked. Leaves accounts out of the login re-check when every role they hold is listed. They are still screened when they set a password. | `includes/password-login-screening.php` |

```php
// Leave subscribers out of the login breach re-check.
add_filter( 'keel_password_rescreen_skip_roles', fn() => array( 'subscriber' ) );
```

## Comments, authors and attachments

| Hook | Type | Arguments and default | Runs while | Source |
| --- | --- | --- | --- | --- |
| `keel_allowed_comment_types` | filter | `string[] $types` = `array( 'note' )`. Comment types that keep working when comments are off. `note` keeps WordPress 6.9 editorial Notes working. Do not add `comment`. | **Comments** (`disable_comments`) | `includes/content.php` |
| `keel_comment_blocks` | filter | `string[] $blocks` = the core comment blocks. Removed from the inserter and from rendered output. | **Comments** | `includes/content.php` |
| `keel_feed_author_name` | filter | `string $name` = "Site Contributor" (translated). Replaces author names in feeds only. Bylines on the site are untouched. | **Author Archives** (`disable_author_archives`) | `includes/content.php` |
| `keel_keep_attachment_page` | filter | `bool $keep` = whether the theme ships `attachment.php` or `image.php`, `int $attachment_id`. Return `true` to keep the page, `false` to redirect anyway. | **Attachment Pages** (`redirect_attachment_pages`) | `includes/admin-ux.php` |
| `keel_shortcode_residue_tags` | filter | `string[] $tags` = empty. Bare tag names, such as `gallery_old`, that you confirm are left over from a removed plugin. Registered shortcodes always win. | **Broken Shortcodes** (`hide_shortcode_residue`) | `includes/shortcodes.php` |

## Email and environment

| Hook | Type | Arguments and default | Runs while | Source |
| --- | --- | --- | --- | --- |
| `keel_suppress_nonproduction_mail` | filter | `bool $suppress` = `true` on a non-production site while the setting is on. Return `false` for a staging site that has to send. The `KEEL_ALLOW_NONPRODUCTION_MAIL` constant is checked first. | **Non-Production Email** (`suppress_nonproduction_mail`) | `includes/email.php` |
| `keel_outgoing_mail_suppressed` | action | `array $atts`, the `wp_mail()` arguments that were held back. Fires instead of sending, so a mail catcher can still record the message. | **Non-Production Email** | `includes/email.php` |
| `keel_smtp_plugin_recommendation` | filter | `string $text` = a sentence recommending a trusted SMTP plugin. Shown in the misconfigured-email notice. Escaped on output. | **Email Deliverability** (`mail_failure_notice`) | `includes/email.php` |
| `keel_environments` | filter | `array $environments`, keyed `production`, `staging`, `development`, `local`. Each holds `label`, `icon` (a Dashicons class), `background_color` and `text_color`. Merged over the defaults, so you can change one key. | **Environment Indicator** | `includes/admin-ux.php` |
| `keel_local_host_suffixes` | filter | `string[] $suffixes` = `.test`, `.local`, `.localhost`, `.ddev.site`, `.lndo.site`. Host suffixes, each with its leading dot, that mark a local development install. | always | `includes/admin-ux.php` |
| `keel_private_host_suffixes` | filter | `string[] $suffixes` = `.internal`, `.lan`, `.home.arpa`, `.intranet`, `.corp`, `.localdomain`, `.example`, `.invalid`, `.nip.io`, `.sslip.io`. Hosts that look public but are not, so a site on them is never called mislabelled. | always | `includes/admin-ux.php` |
| `keel_environment_looks_mislabelled` | filter | `bool $mislabelled`. Return `false` for a site that really is a local or development copy on a public address. | always | `includes/admin-ux.php` |

How these fit together is in [Environment detection](environment-detection.md).

## Admin experience

| Hook | Type | Arguments and default | Runs while | Source |
| --- | --- | --- | --- | --- |
| `keel_heartbeat_interval` | filter | `int $seconds` = `60`. Clamped to 15–120, because post locks expire after 150 seconds. | **Heartbeat API** (`throttle_heartbeat`) | `includes/admin-ux.php` |
| `keel_heartbeat_drop_on_dashboard` | filter | `bool $drop` = `true`. Return anything but `true` to keep Heartbeat on the dashboard home. | **Heartbeat API** | `includes/admin-ux.php` |
| `keel_defaults_column_labels` | filter | `string[] $labels`, keyed by exact column ID, `string $post_type`. Short labels for crowded post list columns. | **Admin List Tables** set to either Tidy choice | `includes/list-table-columns.php` |
| `keel_disable_ai_connectors` | action | none. Fires once while AI connectors are being switched off, so a plugin with its own AI providers can unregister them or hide their screens. | **AI Connectors** (`disable_ai_connectors`, WordPress 7.0 and later) | `includes/bootstrap.php` |

## Conflict diagnostics

These shape what Keel reports when another plugin or theme uses the same
WordPress hooks. They change reports and notices only, never behavior.

| Hook | Type | Arguments and default | Source |
| --- | --- | --- | --- |
| `keel_policy_hooks` | filter | `array<string, string>`, WordPress hook name => `authoritative`, `additive` or `unconfirmed`. Only `authoritative` hooks produce an overlap report. | `includes/conflict-overlap.php` |
| `keel_policy_expectations` | filter | `array<string, bool\|null>`, WordPress hook name => the value Keel expects it to produce. A setting that produced something else is reported as not taking effect. `null` entries are dropped. | `includes/conflict-divergence.php` |
| `keel_conflict_notice_screens` | filter | `array<string, bool>`, screen ID => whether the notice can be dismissed there. Default: `plugins` and `settings_page_keel` (not dismissible) and `dashboard` (dismissible). | `includes/conflict-notices.php` |

How overlaps are classified is in
[the reference](wordpress-default-settings.md#when-another-plugin-touches-the-same-policy).

## Naming

Hook names start with `keel_`. `keel_defaults_column_labels` is the one that
also carries the function prefix. A function named after the filter it applies,
such as `keel_environments()`, is allowed by the naming rules in
[CONTRIBUTING.md](../CONTRIBUTING.md#conventions-the-tests-enforce).

When you add a hook, add a row here in the same pull request.
