# WordPress "Sane Defaults" Reference

A menu of default settings that can be applied to just about any WordPress install to
tighten security, trim attack surface, clean up UX, and shave weight off the front end.

Each item lists a suggested **option key** (using a `keel_` prefix for the WPYEG workshop
plugin), a recommended **default value**, a short **description**, and a **code snippet** you
can drop into a plugin, an mu-plugin, or a theme's `functions.php`.

> Built for the **WPYEG — Edmonton WordPress Meetup** hands-on workshop. The companion
> `keel` plugin wires every one of these behind a toggle.

**How to read this:** the option key/default columns assume these are user-toggleable
settings. The snippet under each item is the "on" behavior — the code that runs when the
default is active. In a real plugin you'd wrap each snippet in a
`get_option( 'keel_...' ) === 'yes'` check so site owners can flip it. Snippets are shown
unwrapped for clarity.

A few items are flagged **plugin-specific** — they have no stable WordPress core equivalent
and depend on your own plugin's logic.

---

## 1. Security & Attack-Surface Reduction

### Restrict REST API User Discovery
- **Option:** `keel_restrict_rest_user_discovery`
- **Default:** `yes`
- **Why:** The `/wp/v2/users` endpoint leaks usernames (author slugs) to anonymous visitors,
  which hands attackers half of every brute-force credential. Closing it to logged-out users
  keeps the API working for authenticated tools while shutting the enumeration door.

```php
add_filter( 'rest_endpoints', function ( $endpoints ) {
    if ( ! is_user_logged_in() ) {
        unset( $endpoints['/wp/v2/users'] );
        unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
    }

    return $endpoints;
} );
```

### Disable REST API for Anonymous Requests
- **Option:** `keel_disable_rest`
- **Default:** `no` *(leave off unless the site is a pure brochure site — anonymous front-end
  blocks, embeds, and outside integrations rely on unauthenticated REST; the logged-in block
  editor is unaffected, since it authenticates with a cookie plus a REST nonce)*
- **Why:** Fully disabling REST is a blunt instrument. The safer posture is to require
  authentication for all REST calls, which blocks anonymous scraping without breaking the
  editor for logged-in users.

```php
add_filter( 'rest_authentication_errors', function ( $result ) {
    if ( ! empty( $result ) ) {
        return $result;
    }

    if ( ! is_user_logged_in() ) {
        return new WP_Error(
            'rest_not_logged_in',
            __( 'REST API restricted to authenticated users.' ),
            array( 'status' => 401 )
        );
    }

    return $result;
} );
```

### Harden XML-RPC (per-category, not all-or-nothing)
- **Options:** `keel_xmlrpc_allow_pingbacks` / `keel_xmlrpc_allow_remote_publishing` / `keel_xmlrpc_allow_multicall` / `keel_block_xmlrpc_endpoint`
- **Defaults:** `no` / `no` / `no` / `no`
- **Why:** XML-RPC is a legitimate but aging API. On a current, patched site it is not a
  backdoor or emergency-level vulnerability; it is additional attack and resource-consumption
  surface whose value is site-specific. Incoming pingbacks remain the clearest live risk,
  remote-publishing methods are another credential-authentication entrance, and
  `system.multicall` is a general batching wrapper whose security value is now modest.

  `add_filter( 'xmlrpc_enabled', '__return_false' )` is a common trap: despite its name, it only
  disables methods that require authentication. It does not block `xmlrpc.php`, pingbacks, or
  custom unauthenticated methods. The better model is to remove unused WordPress methods **by
  category**, keep the endpoint reachable when an integration needs it, and block or rate-limit
  unwanted traffic at the CDN/WAF/web-server edge.

Three independent categories, all off by default:

```php
add_filter( 'xmlrpc_methods', function ( $methods ) {
    // 1. Incoming pingbacks.
    if ( 'yes' !== get_option( 'keel_xmlrpc_allow_pingbacks', 'no' ) ) {
        unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
    }
    // 2. Remote publishing (blogging apps) — the credential-authenticated methods.
    if ( 'yes' !== get_option( 'keel_xmlrpc_allow_remote_publishing', 'no' ) ) {
        foreach ( array_keys( $methods ) as $name ) {
            if ( preg_match( '/^(wp|metaWeblog|mt|blogger)\./', (string) $name ) ) {
                unset( $methods[ $name ] );
            }
        }
    }
    return $methods;
}, PHP_INT_MAX );

// Remote publishing also gates xmlrpc_enabled and the RSD discovery link.
add_filter( 'xmlrpc_enabled', function ( $enabled ) {
    return 'yes' === get_option( 'keel_xmlrpc_allow_remote_publishing', 'no' ) ? $enabled : false;
} );

// Pingbacks off → drop the X-Pingback discovery header.
add_filter( 'wp_headers', function ( $headers ) {
    if ( 'yes' !== get_option( 'keel_xmlrpc_allow_pingbacks', 'no' ) ) {
        unset( $headers['X-Pingback'] );
    }
    return $headers;
} );
```

`system.multicall` **can't be removed with the `xmlrpc_methods` filter** — `IXR_Server::setCallbacks()`
re-adds it after the filter runs — so refuse it with a replacement server. This is modest
defense-in-depth against batching, not a major password control: since WordPress 4.4, after the
first failed authentication in one XML-RPC request, later authentication attempts fail without
testing more credentials. Multicall can still batch other work, including pingback calls, but
pingbacks are also directly callable and do not depend on it. See
[WordPress Trac #34336](https://core.trac.wordpress.org/ticket/34336).

```php
add_filter( 'wp_xmlrpc_server_class', function ( $class ) {
    if ( 'yes' === get_option( 'keel_block_xmlrpc_endpoint', 'no' ) ) {
        return 'Wpyeg_Blocked_XMLRPC_Server';     // serve_request() → 403 for everything
    }
    if ( 'yes' !== get_option( 'keel_xmlrpc_allow_multicall', 'no' ) ) {
        return 'Wpyeg_Multicall_Disabled_Server'; // extends wp_xmlrpc_server, overrides multiCall() → IXR_Error
    }
    return $class;
} );
```

> **Jetpack:** Jetpack currently requires a publicly accessible XML-RPC endpoint, so never apply
> the blanket 403 on a Jetpack site. Turning off incoming pingbacks is the low-risk change. Removing
> core publishing methods or refusing multicall leaves `jetpack.*` registrations untouched, but
> method registration alone is not a compatibility guarantee; test the Jetpack connection and the
> features the site uses. Keep Remote Publishing enabled until that testing proves it unnecessary.
> A plugin-level 403 still boots WordPress and occupies PHP; only an edge block prevents the request
> from reaching PHP. See [Jetpack's current requirements](https://jetpack.com/support/getting-started-with-jetpack/).
> **`demo.*`:** the inert `demo.sayHello`/`demo.addTwoNumbers` methods still confirm XML-RPC is
> live to a scanner, so the companion plugin always drops them — no toggle:
> `unset( $methods['demo.sayHello'], $methods['demo.addTwoNumbers'] )`.

### Application Passwords — leave available (don't reflexively disable)
- **Option:** `keel_disable_application_passwords`
- **Default:** `no` *(available)*
- **Why:** The reflexive advice is "disable them," but that's usually the wrong call.
  Application Passwords are hashed, per-application, individually revocable credentials that
  carry the same access as the owning account — and core supports them for REST and XML-RPC.
  They normally bypass an interactive 2FA challenge, so create them on a least-privileged account.
  Prohibiting them doesn't remove an
  integration's need; it pushes people to a third-party auth plugin or a shared login —
  credentials that are harder to isolate and revoke and that bypass 2FA the same way. Keep them
  available; offer an opt-in to prohibit them for sites whose policy forbids non-interactive
  credentials.
  See the [WordPress Application Passwords documentation](https://developer.wordpress.org/advanced-administration/security/application-passwords/).

```php
// Off by default — the feature stays available. Only prohibit when explicitly opted in.
add_filter( 'wp_is_application_passwords_available', function ( $available ) {
    return 'yes' === get_option( 'keel_disable_application_passwords', 'no' ) ? false : $available;
} );
```
> **Note:** they authenticate REST/XML-RPC without the login form, so a 2FA companion never
> challenges them. That's a real trade — but it's core behavior, and the alternatives are worse.
> Use core's `wp_is_application_passwords_available_for_user` filter to withhold them per account
> (e.g. from human 2FA accounts) if that gap matters.

### Require Strong Passwords
- **Option:** `keel_require_strong_passwords`
- **Default:** `yes`
- **Why:** Core ships a password meter but won't *enforce* strength. Enforce it server-side —
  but follow current **OWASP/NIST** guidance: favor **length and breached-password screening**
  over forced composition rules. NIST 800-63B explicitly *discourages* upper/lower/number/symbol
  requirements — they push users toward predictable patterns like `Password1!` without adding
  real entropy.

```php
add_action( 'user_profile_update_errors', 'keel_enforce_strong_password', 10, 3 );
add_action( 'validate_password_reset', function ( $errors, $user ) {
    keel_enforce_strong_password( $errors, true, $user );
}, 10, 2 );

function keel_enforce_strong_password( $errors, $update, $user ) {
    $password = isset( $_POST['pass1'] ) ? (string) $_POST['pass1'] : '';

    if ( '' === $password ) {
        return; // No password change requested.
    }

    // Length first — NIST favours length over composition.
    if ( strlen( $password ) < 15 ) {
        $errors->add( 'pass_too_short', __( '<strong>Error:</strong> Password must be at least 15 characters.' ) );
        return;
    }

    // Strength + breach screening beat forced upper/lower/number/symbol rules:
    // require "medium" or better on the bundled zxcvbn meter, and reject passwords
    // that appear in a known breach corpus.
    if ( keel_zxcvbn_score( $password ) < 3 || keel_is_pwned( $password ) ) {
        $errors->add( 'pass_too_weak', __( '<strong>Error:</strong> Choose a stronger password that has not appeared in a known data breach.' ) );
    }
}
```
> **Note:** the companion plugin ships a working `keel_password_is_pwned()`. It queries the Have
> I Been Pwned range API by k-anonymity (only the first 5 SHA-1 characters leave the site, never
> the password), requests `Add-Padding` and ignores the padded count-0 rows, caches each prefix
> for a few hours, and **fails open** when HIBP is unreachable so an outage can't block password
> changes. A strength estimator (`keel_zxcvbn_score()` via `bjeavons/zxcvbn-php`) is still yours
> to add if you want one. Server-side validation is the enforcement layer; pair it with the core
> JS meter for UX, but never trust the client alone.

### Disable AI Connectors
- **Option:** `keel_disable_ai_connectors`
- **Default:** `yes`
- **Why:** AI connectors can transmit unpublished content, media, prompts, and user data to
  third-party services. WordPress 7.0 added a core gate for exactly this, so the default
  posture is off-until-asked-for rather than on-by-inheritance.

WordPress 7.0 introduced the `wp_supports_ai` filter (default `true`), which decides whether
the current request may use AI. Returning `false` stops core's AI provider connectors from
registering:

```php
add_filter( 'wp_supports_ai', '__return_false' );

// Settings → Connectors configures those providers, so take the menu out too.
add_action( 'admin_menu', function () {
    remove_submenu_page( 'options-general.php', 'options-connectors.php' );
}, 11 );

// Removing a menu hides the link, it does not block the URL. Close the screen.
add_action( 'admin_init', function () {
    global $pagenow;
    if ( 'options-connectors.php' === $pagenow ) {
        wp_die( esc_html__( 'AI connectors are disabled on this site.' ), '', array( 'response' => 403 ) );
    }
} );
```

> **Note:** core also honours a `WP_AI_SUPPORT` constant, which a deployment can set to
> `false` in `wp-config.php` to hard-lock the disabled posture above the plugin layer. The
> workshop plugin additionally fires a `keel_disable_ai_connectors` action as a seam for AI
> integrations core does not know about (a plugin's own provider, say).

---

## 2. Content, Comments & Public Surfaces

### Disable Comments, Trackbacks, and Pingbacks
- **Option:** `keel_disable_comments`
- **Default:** `yes`
- **Why:** For most business/brochure sites, comments are pure spam surface. This closes
  comments everywhere, drops existing open threads from the UI, and removes the admin menu.

```php
// Close comments and pings on the front end for all post types.
add_filter( 'comments_open', '__return_false', 20, 2 );
add_filter( 'pings_open', '__return_false', 20, 2 );

// Hide existing comments.
add_filter( 'comments_array', '__return_empty_array', 20, 2 );

// Remove support so meta boxes disappear.
add_action( 'init', function () {
    foreach ( get_post_types() as $type ) {
        if ( post_type_supports( $type, 'comments' ) ) {
            remove_post_type_support( $type, 'comments' );
            remove_post_type_support( $type, 'trackbacks' );
        }
    }
} );

// Strip the admin menu + admin-bar node.
add_action( 'admin_menu', function () {
    remove_menu_page( 'edit-comments.php' );
} );
add_action( 'wp_before_admin_bar_render', function () {
    global $wp_admin_bar;
    $wp_admin_bar->remove_node( 'comments' );
} );
```

### Disable Pingbacks and Trackbacks (defaults for new posts)
- **Option:** `keel_disable_pingbacks`
- **Default:** `yes`
- **Why:** Even with comments on, pingbacks/trackbacks are low-value and spammy. This forces
  the "closed" default for any newly created content.

```php
add_filter( 'pre_option_default_pingback_flag', '__return_zero' );
add_filter( 'pre_option_default_ping_status', function () {
    return 'closed';
} );
```

### Disable Public Author Archives
- **Option:** `keel_disable_author_archives`
- **Default:** `yes`
- **Why:** Author archive URLs (`/author/{slug}/`) are another username-enumeration path and
  usually thin, duplicate content. Redirect them home.

```php
add_action( 'template_redirect', function () {
    if ( is_author() ) {
        wp_safe_redirect( home_url( '/' ), 301 );
        exit;
    }
} );
```

### Redirect Attachment Pages
- **Option:** `keel_redirect_attachment_pages`
- **Default:** `yes`
- **Why:** Standalone attachment pages (`?attachment_id=…`) are thin, index-bloating pages that
  expose media out of context. Core agrees: **WordPress 6.4** added a `wp_attachment_pages_enabled`
  option, set to `0` on new installs (core redirects to the file) and `1` on sites upgraded from
  earlier, which keeps rendering them. This default overrides the destination, preferring the
  **parent post** — landing on a real article beats landing on a bare JPEG.

Two details matter more than the toggle itself.

**Do not fall back to the homepage.** Unattached media has no parent, and that is common — anything
uploaded straight into the Media Library. Pointing all of those at `/` is a soft-404 pattern search
engines read badly. Fall back to the file, which is what core does.

**Respect a theme that built these pages.** A theme shipping `attachment.php` or `image.php` opted
into rendering them — the photography and portfolio case — and redirecting past it silently deletes
a feature someone wrote on purpose.

```php
/**
 * Decide the target separately from performing the redirect, so the decision is
 * testable without a request.
 */
function keel_attachment_redirect_target( $attachment_id ) {
    $keep = (bool) locate_template( array( 'attachment.php', 'image.php' ) );
    if ( apply_filters( 'keel_keep_attachment_page', $keep, $attachment_id ) ) {
        return '';
    }

    $parent = wp_get_post_parent_id( $attachment_id );

    // Parent post if there is one; otherwise the file — never the homepage.
    return $parent ? (string) get_permalink( $parent ) : (string) wp_get_attachment_url( $attachment_id );
}

add_action( 'template_redirect', function () {
    if ( ! is_attachment() ) {
        return;
    }

    $target = keel_attachment_redirect_target( get_queried_object_id() );
    if ( '' === $target ) {
        return;
    }

    wp_safe_redirect( $target, 301 );
    exit;
} );
```

> **Offloaded media:** if the file lives on S3 or a CDN, `wp_safe_redirect()` will refuse the
> off-site host and bounce to `wp-admin`. Add that one host via `allowed_redirect_hosts` for the
> redirect rather than reaching for the unguarded `wp_redirect()`.

### Disable Emojis
- **Option:** `keel_disable_emojis`
- **Default:** `yes`
- **Why:** Core injects an emoji detection script and inline CSS on every page. Modern
  browsers render emoji natively, so this is dead weight (an extra script + a DNS lookup).

```php
add_action( 'init', function () {
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
    remove_action( 'admin_print_styles', 'print_emoji_styles' );
    remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
    remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
    remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

    // Stop the emoji DNS-prefetch hint too.
    add_filter( 'emoji_svg_url', '__return_false' );
} );
```

---

## 3. Admin & Front-End UX

### Title-Only Admin Search
- **Option:** `keel_title_only_admin_search`
- **Default:** `no`
- **Why:** On big sites, the admin list-table search scans post content and can be painfully
  slow. Restricting it to titles is much faster — but it changes editor expectations, so it's
  off by default. **Narrow the search *columns*, don't replace the whole search clause:** the
  `post_search_columns` filter keeps core's term parsing, `-exclusions`, and the logged-out
  `post_password` guard intact, where a raw `posts_search` string throws all of that away.

```php
add_filter( 'post_search_columns', function ( $columns, $search, WP_Query $query ) {
    if ( is_admin() && $query->is_main_query() ) {
        return array( 'post_title' );
    }
    return $columns;
}, 10, 3 );
```
> **Note:** `post_search_columns` landed in WordPress 6.2. The older pattern — returning a
> hand-built `posts_search` SQL string — *replaces* core's entire search clause and silently
> drops term parsing, `-term` exclusions, and the `AND post_password = ''` guard core appends for
> logged-out users. Prefer the columns filter.

### Disable Front-End Admin Bar
- **Option:** `keel_frontend_admin_bar_behavior`
- **Default:** `''` (unchanged) — or `hide_for_non_admins` as a common hardening default
- **Why:** The floating admin bar on the front end nudges layout, leaks that a user is logged
  in, and is rarely needed for subscribers/customers. Two common policies below.

```php
// Option A: hide the admin bar on the front end for everyone.
add_filter( 'show_admin_bar', '__return_false' );

// Option B: hide it only for users who can't manage options (keep it for admins).
add_filter( 'show_admin_bar', function ( $show ) {
    return current_user_can( 'manage_options' ) ? $show : false;
} );
```

---

## 4. Login & Session Policy

### Disable Remember Me
- **Option:** `keel_disable_remember_me`
- **Default:** `no`
- **Why:** On shared or kiosk machines, a persistent "Remember Me" cookie is a risk. Removing
  the checkbox forces short-lived sessions. Off by default because it hurts convenience.

```php
add_action( 'login_footer', function () {
    ?>
    <script>
        (function () {
            var wrap = document.querySelector('.login form #rememberme');
            if (wrap && wrap.closest('p')) { wrap.closest('p').style.display = 'none'; }
        })();
    </script>
    <?php
} );

// Belt-and-suspenders: never honor a "remember" flag server-side.
add_filter( 'auth_cookie_expiration', function ( $length, $user_id, $remember ) {
    return $remember ? 2 * DAY_IN_SECONDS : $length;
}, 10, 3 );
```

### Change Remember Me Session Length
- **Option:** `keel_remember_me_policy` / `keel_remember_me_days` / `keel_session_regular_hours`
- **Defaults:** `default` / `5` / `0`
- **Why:** Core's "Remember Me" is 14 days — often too long. This lets you cap the persistent
  session (e.g. 5 days) and, optionally, shorten the regular (non-remembered) session too.

```php
add_filter( 'auth_cookie_expiration', function ( $expiration, $user_id, $remember ) {
    if ( $remember ) {
        return 5 * DAY_IN_SECONDS;   // keel_remember_me_days
    }

    return 12 * HOUR_IN_SECONDS;     // keel_session_regular_hours
}, 10, 3 );
```

### Login Logo & Link
- **Option:** `keel_login_logo_behavior`
- **Default:** `keep_default` *(leave the login screen untouched)*
- **Why:** The default WordPress "W" on `wp-login.php` links to wordpress.org — a subtle brand
  and trust leak. Removing or replacing it is worthwhile, but changing the login screen out of
  the box is intrusive, so the safe default is to **leave it alone** and let an administrator opt
  in. Behaviors: `keep_default` (unchanged), `remove_logo` (recommended — drop the logo and the
  wp.org link), `unlink_logo` (keep the logo, kill the link), `replace_logo` (swap in the site
  logo/icon, linked to the site home).

```php
$behavior = get_option( 'keel_login_logo_behavior', 'keep_default' );

if ( 'remove_logo' === $behavior ) {
    add_action( 'login_head', function () {
        echo '<style>#login h1 a, .login h1 a { display: none; }</style>';
    } );
}

// Whenever the logo is removed or replaced, point the header link at the site home
// instead of wordpress.org — a replacement logo always links home, so there is no
// separate toggle for it.
if ( in_array( $behavior, array( 'remove_logo', 'unlink_logo', 'replace_logo' ), true ) ) {
    add_filter( 'login_headerurl', 'home_url' );
    add_filter( 'login_headertext', function () {
        return get_bloginfo( 'name' );
    } );
}
```
> **Note:** an earlier version of this reference paired the behavior with a separate
> `keel_login_logo_link_home` toggle. That was redundant — a replacement logo should always link
> home — so the toggle is gone and the behavior option alone covers it.

---

## 5. Update Policy

### Automatically install core maintenance/security releases

*(`keel_core_update_policy`, default `minor`)*

The default enables in-branch maintenance and security releases (`x.y.z`) while leaving major
core releases (`x.y`) for a tested agency rollout. The settings screen can also allow every
stable release, make core updates manual, or leave the decision unchanged.

```php
add_filter( 'allow_minor_auto_core_updates', '__return_true' );
add_filter( 'allow_major_auto_core_updates', '__return_false' );
add_filter( 'allow_dev_auto_core_updates', '__return_false' );
```

Keel does not register those filters when `WP_AUTO_UPDATE_CORE` is defined in
`wp-config.php`; an explicit operator-level policy wins and the settings screen reports that
the control is locked. `AUTOMATIC_UPDATER_DISABLED` and `DISALLOW_FILE_MODS` can prevent the
updater from running at all, so the screen warns about those overrides too.

Major releases should be tested on staging and deployed within 30 days, not frozen
indefinitely. Expedite the rollout when a security fix is unavailable on the installed branch.
Only the latest WordPress major release is officially supported; security backports to older
branches are a courtesy, not a guaranteed support policy.

References:

- [Configuring Automatic Background Updates](https://developer.wordpress.org/advanced-administration/upgrade/upgrading/)
- [`Core_Upgrader::should_update_to_version()`](https://developer.wordpress.org/reference/classes/core_upgrader/should_update_to_version/)
- [Supported WordPress versions](https://wordpress.org/documentation/article/supported-versions/)

### Automatically update translations

*(`keel_auto_update_translations`, default `yes`)*

WordPress, plugin, and theme language packs are low-risk and update automatically. Turning the
setting off explicitly refuses translation auto-updates.

```php
add_filter( 'auto_update_translation', '__return_true' );
```

Plugin and theme **code** updates are intentionally left to WordPress's individual per-item
choices. The plugin ecosystem has no enforceable semantic-versioning or security-release
metadata, so a generic defaults plugin cannot safely infer that `2.4` is harmless while `3.0`
is risky. Agencies can maintain a reviewed allowlist in their fleet-management tooling.

---

## 6. Additional Recommended Defaults

Beyond your list, these are the defaults I'd reach for on nearly every build.

### Security

**Disable the theme/plugin file editor.** Removes the in-dashboard code editor so a
compromised admin account can't rewrite PHP on the fly. Set in `wp-config.php`:

```php
define( 'DISALLOW_FILE_EDIT', true );
```

**Remove the WordPress version fingerprint** *(opt-in — `keel_remove_version`, default `no`)*.
Stops the generator tag broadcasting your exact core version.

Deliberately **not** on by default, because this is obscurity rather than hardening. It does
not make an out-of-date site any safer, and it is not even a complete cover: the version still
leaks through asset query strings (`?ver=`), feeds, and readme files. What it genuinely buys is
less automated scanner noise in your logs — worth opting into, not worth presenting as
security.

```php
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );
```

**Send baseline security headers** *(`keel_security_headers`, default `yes`)*. Two headers with
essentially no downside: `nosniff` stops the browser second-guessing a declared `Content-Type`,
and `Referrer-Policy` keeps full URLs from leaking to other sites.

Note what is *not* in this group. `X-Frame-Options` is a separate setting
(`keel_frame_options`, default `SAMEORIGIN`) because it is the only one of the three that can
break a working site: blocking cross-origin framing also blocks *legitimate* embedding — a
client intranet, a partner site, a preview or proofing tool — and it usually fails as a silent
blank frame. Bundling it with `nosniff` would mean a site that needs to be embeddable has to
give up `nosniff` as well. Set it to *leave unchanged* when a host or CDN already sends it.

```php
add_filter( 'wp_headers', function ( $headers ) {
    // Only fill in what nothing else has set — a host or CDN may own these.
    if ( ! isset( $headers['X-Content-Type-Options'] ) ) {
        $headers['X-Content-Type-Options'] = 'nosniff';
    }
    if ( ! isset( $headers['Referrer-Policy'] ) ) {
        $headers['Referrer-Policy'] = 'strict-origin-when-cross-origin';
    }
    return $headers;
} );

// Framing, separately, so it can be changed without giving up the above.
add_filter( 'wp_headers', function ( $headers ) {
    if ( ! isset( $headers['X-Frame-Options'] ) ) {
        $headers['X-Frame-Options'] = 'SAMEORIGIN'; // or DENY
    }
    return $headers;
} );
```

> **Caveat on the `isset()` guards:** PHP can only see headers set in PHP. One added by nginx,
> Apache, or a CDN is invisible here, so this cannot catch every duplicate — check the actual
> response, not just this code. Headers are ultimately an edge concern; this is the fallback for
> when you do not control the edge.

**Disable self-pingbacks.** Stops your own internal links from creating pingback noise.

```php
add_action( 'pre_ping', function ( &$links ) {
    $home = home_url();
    foreach ( $links as $key => $link ) {
        if ( 0 === strpos( $link, $home ) ) {
            unset( $links[ $key ] );
        }
    }
} );
```

### UX

**Sensible admin cleanup.** Hide the "Try Gutenberg"/welcome nags and the WordPress logo in
the admin bar for a calmer dashboard.

```php
add_action( 'wp_before_admin_bar_render', function () {
    global $wp_admin_bar;
    $wp_admin_bar->remove_node( 'wp-logo' );
} );
```

**Increase the autosave interval and cap post revisions** so the editor writes to the DB less
often and revisions don't balloon table size. In `wp-config.php`:

```php
define( 'AUTOSAVE_INTERVAL', 120 ); // seconds
define( 'WP_POST_REVISIONS', 10 );  // keep the last 10 per post
```

**Raise the "Howdy" and default email sender** to something branded — small touches, but they
stop the site looking like a stock install. Filter `wp_mail_from` and `wp_mail_from_name`:

```php
add_filter( 'wp_mail_from', function () { return 'no-reply@example.com'; } );
add_filter( 'wp_mail_from_name', function () { return get_bloginfo( 'name' ); } );
```

### SEO

**Trim the `wp_head` clutter** — shortlinks, WLW manifest, and adjacent-post `rel` links are
rarely useful and add markup.

```php
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'wp_shortlink_wp_head' );
remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
```

**Keep core sitemaps on (or hand off to your SEO plugin).** Core ships `wp-sitemap.xml`; make
sure exactly one system owns sitemaps to avoid conflicting signals. If an SEO plugin handles
it, disable core's:

```php
add_filter( 'wp_sitemaps_enabled', '__return_false' );
```

**Set a canonical, and noindex thin archives.** Redirecting attachment/author pages (above)
already helps; consider `noindex` on internal search results and paginated tag archives via
your SEO plugin's defaults.

### Performance

**Throttle the Heartbeat API** so autosave/lock polling doesn't hammer `admin-ajax.php`,
especially on shared hosting.

```php
add_filter( 'heartbeat_settings', function ( $settings ) {
    $settings['interval'] = 60; // default is 15–60s; 60 is gentle
    return $settings;
} );

// Optionally disable Heartbeat on the dashboard home where it's least needed.
add_action( 'init', function () {
    if ( is_admin() ) {
        global $pagenow;
        if ( 'index.php' === $pagenow ) {
            wp_deregister_script( 'heartbeat' );
        }
    }
} );
```

**Defer non-critical scripts** — *no plugin setting, because core does this properly now.*

Since **WordPress 6.3**, `wp_enqueue_script()` takes a loading strategy, so deferral belongs on
the script being enqueued rather than in a filter that rewrites everyone's `<script>` tags:

```php
wp_enqueue_script(
    'my-theme-front',
    get_theme_file_uri( 'build/front.js' ),
    array(),
    '1.0.0',
    array( 'strategy' => 'defer' ) // or 'async'
);
```

The older pattern — hooking `script_loader_tag` and string-replacing ` src=` with ` defer src=`
across every handle — predates that API and is worth retiring. It cannot know which scripts are
safe to defer, so it breaks anything expecting synchronous jQuery or a particular execution
order, and it hands you a blunt on/off switch where core now gives you per-script control. If
you inherit a site that still does it, replacing it with `strategy` is a real improvement rather
than a lateral move.

**Remove query strings from static assets** for better proxy/CDN caching (many CDNs skip
querystring'd URLs by default).

```php
add_filter( 'style_loader_src', 'keel_strip_asset_ver', 15 );
add_filter( 'script_loader_src', 'keel_strip_asset_ver', 15 );
function keel_strip_asset_ver( $src ) {
    if ( strpos( $src, 'ver=' ) ) {
        $src = remove_query_arg( 'ver', $src );
    }
    return $src;
}
```
> **Caveat:** stripping `ver` weakens cache-busting on deploys. Prefer versioning assets by
> filename/hash if you use this.

---

## Quick-Reference Table

| Setting | Option key | Default | Category |
| --- | --- | --- | --- |
| Disable Comments/Trackbacks/Pingbacks | `keel_disable_comments` | `yes` | Content |
| Disable Pingbacks (new-post default) | `keel_disable_pingbacks` | `yes` | Content |
| Restrict REST User Discovery | `keel_restrict_rest_user_discovery` | `yes` | Security |
| Disable REST (anon) | `keel_disable_rest` | `no` | Security |
| XML-RPC categories: pingbacks / remote publishing / multicall | `keel_xmlrpc_allow_pingbacks` / `_remote_publishing` / `_multicall` | `no` (each) | Security |
| Block XML-RPC endpoint | `keel_block_xmlrpc_endpoint` | `no` | Security |
| Application Passwords (leave available) | `keel_disable_application_passwords` | `no` | Security |
| Require Strong Passwords | `keel_require_strong_passwords` | `yes` | Security |
| Disable AI Connectors *(PMP-specific)* | `keel_disable_ai_connectors` | `yes` | Security |
| Core automatic updates | `keel_core_update_policy` | `minor` | Updates |
| Translation automatic updates | `keel_auto_update_translations` | `yes` | Updates |
| Disable Public Author Archives | `keel_disable_author_archives` | `yes` | Content |
| Disable Emojis | `keel_disable_emojis` | `yes` | Performance |
| Redirect Attachment Pages | `keel_redirect_attachment_pages` | `yes` | SEO |
| Title-Only Admin Search | `keel_title_only_admin_search` | `no` | UX |
| Front-End Admin Bar Behavior | `keel_frontend_admin_bar_behavior` | `''` | UX |
| Disable Remember Me | `keel_disable_remember_me` | `no` | Login |
| Remember Me Policy / Days | `keel_remember_me_policy` / `_days` | `default` / `5` | Login |
| Regular Session Hours | `keel_session_regular_hours` | `0` | Login |
| Login Logo Behavior | `keel_login_logo_behavior` | `keep_default` | Branding |

---

### Implementation notes
- Load these from an **mu-plugin** or a dedicated plugin, not the theme, so policy survives
  theme switches.
- Gate every snippet behind its `get_option()` toggle so site owners keep control.
- `wp-config.php` constants (`DISALLOW_FILE_EDIT`, `AUTOSAVE_INTERVAL`, `WP_POST_REVISIONS`)
  can't be toggled from options — surface them in your docs as recommended manual settings.
- Explicit update constants remain operator-owned: the settings screen reports
  `WP_AUTO_UPDATE_CORE`, `AUTOMATIC_UPDATER_DISABLED`, and `DISALLOW_FILE_MODS` rather than
  silently fighting them.
- Test REST and comment changes against the block editor before shipping; those two touch the
  most core functionality.
