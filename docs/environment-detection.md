# Environment detection

The environment indicator labels the admin bar with the site's environment so
you can tell at a glance which site you are acting on. The label comes from
core's `wp_get_environment_type()`, which returns one of `production`,
`staging`, `development`, or `local`.

## Where the value comes from

Core decides the environment from, in order:

1. The `WP_ENVIRONMENT_TYPE` constant in `wp-config.php`.
2. The `WP_ENVIRONMENT_TYPE` environment variable.
3. Otherwise, `production`.

Core does no host-name inspection at all. A local install that has not set the
constant therefore reports `production`, which is the wrong end of the scale to
be wrong on — the indicator is meant to stop you acting on a live site by
mistake, and a local site that claims to be production trains you to ignore it.

## The host fallback

When — and only when — `WP_ENVIRONMENT_TYPE` is undefined, Keel checks the host
name of `home_url()` and reports `local` if it belongs to a known local
development tool. Setting the constant disables this entirely: an explicit
declaration always wins, including one that says `production` on a `.test` host.

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

For a site that wants no host guessing at all, define the constant instead —
that path skips the fallback entirely:

```php
define( 'WP_ENVIRONMENT_TYPE', 'staging' );
```

## Changing the labels and colours

The four environments, their labels, icons, and colours come from
`keel_environments()` and are filterable with `keel_environments`. Filtering one
environment leaves the others at their defaults.
