# Environment detection

`keel_defaults_current_environment()` answers which environment this site is,
returning one of core's four names — `production`, `staging`, `development`, or
`local`. Two features read it, and they read the same function on purpose, so
the label on screen and the behaviour behind it cannot disagree:

- **The environment indicator** labels the admin bar, so you can tell at a
  glance which site you are acting on. It is off by default, because on a
  production site it would sit on every screen all the time; it earns its place
  where production, staging and local copies are open side by side. The
  Environment setting changes what it says, never whether it appears. Between
  783px and 960px the label collapses to its colored icon, and at 782px and
  below — where core hides most toolbar items — it stays as a touch-sized icon.
  Either way the text label remains the accessible name.
- **Non-production email suppression** stops outgoing mail on anything that is
  not production. That one is on by default, so a wrong answer here does not
  just mislabel a site — it decides whether mail leaves it. A database copied
  down from a live site carries real customer addresses and production's mail
  settings, so a cron run on a laptop can email real people. Keel says so in an
  admin notice, and WordPress still reports each held message as sent, so code
  that branches on `wp_mail()` takes the same path it will in production rather
  than an error path that only ever runs on staging.

## Where the value comes from

Core decides the environment from, in order:

1. The `WP_ENVIRONMENT_TYPE` constant in `wp-config.php`.
2. The `WP_ENVIRONMENT_TYPE` environment variable.
3. Otherwise, `production`.

Core does no host-name inspection at all. A local install that has declared
neither therefore reports `production`, which is the wrong end of the scale to
be wrong on — the indicator is meant to stop you acting on a live site by
mistake, and a local site that claims to be production trains you to ignore it.

## The host fallback

When — and only when — the site has declared no environment of its own, Keel
checks the host name of `home_url()` and reports `local` if it belongs to a
known local development tool. A declaration disables the fallback entirely: an
explicit value always wins, including one that says `production` on a `.test`
host.

**Either mechanism counts as a declaration.** The environment variable is the
documented way to set the environment and is what DDEV, Lando and wp-env
generate, so testing only for the constant would relabel a site that declared
itself `staging` through the variable — on a `client.ddev.site` host, exactly
the case the heuristic would answer wrongly. `keel_defaults_environment_is_declared()`
checks both.

A declaration only counts when core would accept it. Core silently falls back to
`production` for any value outside its four names, and inheriting that fallback
would paint a red **Production** badge across somebody's laptop on the strength
of a typo — so a misspelled `WP_ENVIRONMENT_TYPE` leaves the host fallback in
play rather than being taken at its word.

| Host | Tool |
| --- | --- |
| `localhost`, `127.0.0.1`, `::1` | wp-env, MAMP, XAMPP, `wp server`, WordPress Studio |
| `*.test` | Laravel Valet, Herd, Laragon, VVV |
| `*.local` | Local by WP Engine |
| `*.localhost` | reserved for loopback by RFC 6761 |
| `*.ddev.site` | DDEV |
| `*.lndo.site` | Lando |

Matching is case-insensitive and runs against the **host name only**, so an
explicit port does not defeat it: `http://mysite.test:8080` is recognized, and
so is `http://localhost:8881`.

Two notes on the list:

`.test` and `.localhost` are reserved for exactly this purpose by RFC 6761, and
`.example`, `.invalid`, and `.localhost` are likewise never delegated. `.local`
is the one entry that is not reserved for development — it belongs to
mDNS/Bonjour under RFC 6762 and can collide on a network using Bonjour service
discovery. It is included because Local by WP Engine uses it by default.

WordPress Studio serves on `localhost` with an explicit port, but it also sets
`WP_ENVIRONMENT_TYPE` to `local` itself. Studio sites are therefore detected
through core, and never reach the host fallback.

## Changing the list

Filter `keel_local_host_suffixes`. Each entry includes its leading dot and is
compared against the end of the host name:

```php
add_filter(
	'keel_local_host_suffixes',
	function ( $suffixes ) {
		$suffixes[] = '.wip';
		return $suffixes;
	}
);
```

The loopback names (`localhost`, `127.0.0.1`, `::1`) are matched separately and
are not part of this filter.

For a site that wants no host guessing at all, declare the environment instead —
that path skips the fallback entirely. Either form works:

```php
define( 'WP_ENVIRONMENT_TYPE', 'staging' );
```

```bash
export WP_ENVIRONMENT_TYPE=staging
```

The constant wins if both are set. Use one of core's four names; anything else
is ignored and the host fallback applies.

## Overriding it in Keel

The **Environment** setting (Admin and Front-End UX) defaults to Automatic, which
is everything above. Choosing Production, Staging, Development or Local makes
Keel use that instead, ahead of the declared environment and the host fallback.
A stored value outside those four is treated as Automatic.

The override applies only on the site it was chosen on. Keel records the site's
host when the override is set; a copy of the database served from any other host
— a laptop, a staging site — ignores it, uses the automatic environment, and shows
a notice saying so. Otherwise setting Production on the live site and copying its
database down would make the copy send real mail. Saving some other setting on the
copy does not adopt the override; choosing it again there does.

The override changes Keel only — the indicator, non-production email, and the
email-misconfiguration notice that depends on it. WordPress
core and every other plugin keep reading `WP_ENVIRONMENT_TYPE`, so it is a stopgap
while the real declaration is fixed, not a substitute for fixing it.

## A live site labelled local

The case the override exists for. WordPress Studio writes
`define( 'WP_ENVIRONMENT_TYPE', 'local' )` into `wp-config.php`, and its export —
which is also what a push sends — includes `wp-config.php`. A push that replaces
the live site's file carries `local` with it. Seen on WordPress.com in October
2026, where it did more than relabel the site:

- Keel held back the site's email, comment notifications included.
- WordPress.com sign-in disappeared from the login page and returned once the
  constant said `production` again; Jetpack treats `local` sites as offline.
- Incoming pingbacks stopped regardless of Keel's own pingback setting. On those
  sites `xmlrpc_methods` carried WordPress.com's
  `wpcomsh_disable_incoming_pings_in_non_production_envs` filter (observed with
  `wp eval` over SSH), and `pingback.ping` came back as soon as the environment
  was `production`.

Keel now warns administrators, without a toggle and without a dismiss button, when
`WP_ENVIRONMENT_TYPE` declares `local` on a public host or on WordPress.com
(`IS_ATOMIC`), or declares `development` on WordPress.com. `development` on an
ordinary public host is not flagged: managed hosts run real development
environments on public names, and the advice to choose Production would turn
their mail suppression off. The notice names the declared value and the host.

The advice is specific, because "fix wp-config.php" is not enough on hosts that
keep the file somewhere unexpected:

- It says whether the value comes from the constant or from a server environment
  variable. The constant wins over the variable, as it does in core.
- It names the `wp-config.php` Keel found: beside WordPress, one directory up, or
  beside the content directory. The last is where it lives on WordPress.com,
  which shares one copy of core between sites.
- It gives the WP-CLI command that sets the constant:
  `wp config set WP_ENVIRONMENT_TYPE production --type=constant`. That edits
  `wp-config.php` itself; if the constant is defined in a file it includes (Bedrock's
  `config/application.php`, a `wp-config-local.php`), change it there instead,
  because a second definition does not take effect.
- On multisite, a site administrator who cannot manage the network is told to ask
  the network administrator, without the path or the command.

Keel never writes `wp-config.php` itself. It reads the operator's constants and
reports on them; changing them is the operator's call.

### After the override

Choosing an override clears the first warning but not the fault, so a second
one replaces it: **Keel's Environment setting is covering for a wrong WordPress
label**. It stays, with the same advice, until `wp-config.php` agrees, and then
asks for the setting to go back to Automatic. Without it, the override would
have hidden the only sign that Jetpack and WordPress.com still saw `local`.

An override that repeats the wrong label — Local on a live site WordPress already
calls local — fixes nothing, so it does not count: the original warning stays, and
Site Health stays critical. A site that really is a local copy on a public address
says so with the `keel_environment_looks_mislabelled` filter.

An override that says what the site already reports is redundant. It is harmless
until the site changes on purpose — a leftover Production on a copy meant to be
staging would go on sending mail — so Keel's own settings screen suggests setting
it back to Automatic.

### Site Health and the Network Admin

**Tools → Site Health** carries the same findings in one check, worst first: a
live site labelled local is critical; an override covering for the label, an
override ignored on a copied database, or a production site overridden to a
non-production environment while Keel holds back its email, is recommended;
agreement passes, and says so.

On multisite, `WP_ENVIRONMENT_TYPE` is set once for the whole network, so the
Network Admin shows the label warning too. Overrides are per site and are
reported on each site.

"Public" is decided conservatively, because a false alarm tells somebody their
laptop is a live site. Not public: loopback names, the local-tool suffixes above,
private, reserved and shared (100.64.0.0/10) IP addresses, IPv4 written as IPv6,
single-label hosts, and hosts ending in `.internal`, `.lan`, `.home.arpa`,
`.intranet`, `.corp`, `.localdomain`, `.example`, `.invalid`, `.nip.io` or
`.sslip.io` (filterable with `keel_private_host_suffixes`). Staging is never flagged, since staging sites
legitimately live on public hosts. A local or development copy that really does live on
a public address can silence the warning with:

```php
add_filter( 'keel_environment_looks_mislabelled', '__return_false' );
```

## Changing the labels and colours

The four environments, their labels, icons, and colours come from
`keel_environments()` and are filterable with `keel_environments`. Filtering one
environment leaves the others at their defaults.
