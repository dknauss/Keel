# Keel — WordPress Defaults Reference

A menu of default settings that can be applied to just about any WordPress install to
tighten security, trim attack surface, clean up UX, and shave weight off the front end.

Each item lists Keel's **schema key**, its **default value**, a short **description**, and a
**code snippet** showing the WordPress behaviour behind that setting. Keel wires every one of
these behind an individual toggle under **Settings → Keel**.

**How to read this:** the snippet under each item is the "on" behaviour — the code that runs
when the default is active. Keel stores all settings as one array option (`keel_settings`) and
reads each key through `keel_defaults_get( 'key' )` / `keel_defaults_enabled( 'key' )`, so each
snippet effectively runs behind that check. Snippets are shown unwrapped for clarity.

A few items are flagged **plugin-specific** — they have no stable WordPress core equivalent
and depend on Keel's own logic.

---

## 1. Security and Attack-Surface Reduction

### Restrict REST API User Discovery
- **Key:** `restrict_rest_user_discovery`
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
- **Key:** `disable_rest`
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
- **Options:** `xmlrpc_allow_pingbacks` / `xmlrpc_allow_remote_publishing` / `xmlrpc_allow_multicall` / `block_xmlrpc_endpoint`
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
defence-in-depth against batching, not a password control: WordPress 4.4 prevented it from being
used as a password-guessing multiplier, because after the first failed authentication in one
XML-RPC request later attempts fail without testing more credentials. Multicall can still batch other work, including pingback calls, but
pingbacks are also directly callable and do not depend on it. See
[WordPress Trac #34336](https://core.trac.wordpress.org/ticket/34336).

```php
add_filter( 'wp_xmlrpc_server_class', function ( $class ) {
    if ( 'yes' === get_option( 'keel_block_xmlrpc_endpoint', 'no' ) ) {
        return 'Keel_Blocked_XMLRPC_Server';     // serve_request() → 403 for everything
    }
    if ( 'yes' !== get_option( 'keel_xmlrpc_allow_multicall', 'no' ) ) {
        return 'Keel_Multicall_Disabled_Server'; // extends wp_xmlrpc_server, overrides multiCall() → IXR_Error
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
> live to a scanner, so Keel always drops them — no toggle:
> `unset( $methods['demo.sayHello'], $methods['demo.addTwoNumbers'] )`.

### Application Passwords — leave available (don't reflexively disable)
- **Key:** `disable_application_passwords`
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
> **Note:** they authenticate REST/XML-RPC without the login form, so a two-factor plugin never
> challenges them. That's a real trade — but it's core behaviour, and the alternatives are worse.
> Use core's `wp_is_application_passwords_available_for_user` filter to withhold them per account
> (e.g. from human 2FA accounts) if that gap matters.

### Require Strong Passwords
- **Key:** `require_strong_passwords`
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
> **Note:** Keel ships a working `keel_password_is_pwned()`. It queries the Have
> I Been Pwned range API by k-anonymity (only the first 5 SHA-1 characters leave the site, never
> the password), requests `Add-Padding` and ignores the padded count-0 rows, caches each prefix
> for a few hours, and **fails open** when HIBP is unreachable so an outage can't block password
> changes. A strength estimator (`keel_zxcvbn_score()` via `bjeavons/zxcvbn-php`) is still yours
> to add if you want one. Server-side validation is the enforcement layer; pair it with the core
> JS meter for UX, but never trust the client alone.

### Disable AI Connectors
- **Key:** `disable_ai_connectors`
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
> `false` in `wp-config.php` to hard-lock the disabled posture above the plugin layer. Keel
> additionally fires a `disable_ai_connectors` action as a seam for AI
> integrations core does not know about (a plugin's own provider, say).

> **This is the one default that does not appear on every supported WordPress.** Keel's
> floor is 6.4 and connectors arrived in 7.0, so on 6.4–6.9 there is no `wp_supports_ai`
> gate to filter and no Connectors screen to close. The schema entry names the core
> function it needs (`'requires' => 'wp_supports_ai'`) and the settings screen and Site
> Health both skip it where that function is absent — a switch that cannot move anything
> is worse than one that is not offered. The key stays in the schema and is still seeded,
> so a site that upgrades to 7.0 finds the setting already at its documented default.

---

### Limit Unfiltered HTML to Administrators
- **Key:** `limit_unfiltered_html_to_admins`
- **Default:** `yes`
- **Why:** By default an Editor can save raw HTML and `<script>`. That makes stored XSS a
  capability granted to every editorial account, not a bug. Removing `unfiltered_html` from
  everyone below Administrator means a compromised editorial login can publish bad copy but
  not bad JavaScript. On multisite core already restricts it to Super Admins.

The filter runs at nearly the highest priority so it is the final word, and reads the
already-resolved capability array rather than calling `current_user_can()` — which would
recurse, because `user_has_cap` fires inside every capability check.

```php
add_filter( 'user_has_cap', function ( $allcaps ) {
    if ( empty( $allcaps['manage_options'] ) ) {
        $allcaps['unfiltered_html'] = false;
    }

    return $allcaps;
}, PHP_INT_MAX - 1 );
```

### Password Policy Exemptions
- **Key:** `password_exempt_roles`
- **Default:** `array( 'subscriber' )`
- **Why:** A 15-character minimum is right for accounts that can publish or configure, and
  disproportionate for an account that can only read. The exemption covers the length,
  blocklist and personal-context rules. **Breach screening is never waived** — a password
  already published in a breach costs its owner nothing to avoid, whatever the role.

The list offered in the UI is built from the roles registered on the site, and only roles
holding no content or settings capability appear. That is why Contributor is not offered: it
can write drafts, so a stolen Contributor login can put content into the site. A user holding
several roles is exempt only if every one of them is.

```php
// Enforced unless every role the user holds is exempt.
$exempt = apply_filters( 'keel_weak_roles', array( 'subscriber' ) );
$roles  = (array) $user->roles;

if ( array_diff( $roles, $exempt ) === array() ) {
    return true;   // skip length, blocklist, personal-context — not the breach check
}
```

## 2. Content, Comments and Public Surfaces

### Disable Comments, Trackbacks, and Pingbacks
- **Key:** `disable_comments`
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
- **Key:** `disable_pingbacks`
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
- **Key:** `disable_author_archives`
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
- **Key:** `redirect_attachment_pages`
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
- **Key:** `disable_emojis`
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

### Disable Self-Pingbacks
- **Key:** `disable_self_pingbacks`
- **Default:** `yes`
- **Why:** Linking to your own post generates a pingback from the site to itself, which shows
  up as a comment awaiting moderation. Nobody has ever wanted this. It is separate from the
  pingback settings above because those govern *incoming* pings from other sites.

```php
add_action( 'pre_ping', function ( &$links ) {
    $home = get_option( 'home' );

    foreach ( $links as $i => $link ) {
        if ( 0 === strpos( $link, $home ) ) {
            unset( $links[ $i ] );
        }
    }
} );
```

### Hide Post Password Protection
- **Key:** `disable_post_passwords`
- **Default:** `no`
- **Why:** Per-post passwords are a weak sharing mechanism people reach for because it is
  visible in the publish box, not because it fits. Hiding the control steers editors toward
  proper access control.

**This hides the UI; it does not disable the feature.** A post that already has a password
keeps it and keeps working, and the field stays visible on those posts so its owner can
remove it. Anything else would strand content behind a password with no way to clear it.

```php
add_action( 'admin_print_footer_scripts', function () {
    // Scoped CSS only, and only where no password is already set.
    echo '<style>.edit-post-post-visibility, #visibility-radio-password { display: none; }</style>';
} );
```

### Limit Post Revisions
- **Key:** `post_revisions_limit`
- **Default:** `10` on a new activation; existing installations migrate to `-1`
  so updating does not replace their previous unlimited behavior
- **Why:** Revisions are valuable recovery history, but unlimited copies can grow large on
  frequently edited sites. A moderate cap keeps recovery useful without unbounded retention.
  Use `-1` for unlimited or `0` to disable future revisions. Changing the setting does not
  immediately delete revisions already stored; pruning occurs through later saves.

```php
add_filter( 'wp_revisions_to_keep', function ( $number, $post ) {
    return 10;
}, 10, 2 );
```

WordPress defines `WP_POST_REVISIONS` to `true` itself when wp-config does not. A numeric or
false value therefore proves an operator supplied a distinguishable policy and Keel stands
down. An explicit `true` is indistinguishable from core's default `true`; no plugin loaded
afterward can honestly claim to know which file defined it.

## 3. Admin and Front-End UX

### Title-Only Admin Search
- **Key:** `title_only_admin_search`
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
- **Key:** `frontend_admin_bar_behavior`
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

### Force the Classic Editor
- **Key:** `force_classic_editor`
- **Default:** `no`
- **Why:** Some sites and clients are still on classic workflows. Off by default because
  changing the editor is intrusive; on, it must be complete.

"One setting" here means four filters that have to agree. The per-post-type gate is the one
most implementations forget: it is a **separate decision in core**, not a fallback for the
per-post gate, so a custom post type registered with `show_in_rest` opens in the block editor
regardless of the other three.

```php
add_filter( 'use_block_editor_for_post', '__return_false' );
add_filter( 'use_block_editor_for_post_type', '__return_false' );
add_filter( 'gutenberg_can_edit_post', '__return_false' );
add_filter( 'use_widgets_block_editor', '__return_false' );
```

### Lowercase Upload Filenames
- **Key:** `lowercase_upload_filenames`
- **Default:** `yes`
- **Why:** `Photo.JPG` and `photo.jpg` are the same file on macOS and Windows and two
  different files on a Linux server. Mixed-case uploads produce links that work locally and
  404 in production, and duplicate media on migration. Priority 20 so it runs after core's
  own sanitizing.

```php
add_filter( 'sanitize_file_name', function ( $filename ) {
    return strtolower( $filename );
}, 20 );
```

### Media Sizes Panel
- **Key:** `media_sizes_panel`
- **Default:** `yes`
- **Why:** When a theme or plugin registers image sizes, nothing in the admin tells you which
  ones were actually generated for a given attachment. A read-only panel on the attachment
  screen answers "did this crop get made, and at what dimensions" without a database query.

Read-only by design — it writes nothing and regenerates nothing.

```php
add_action( 'add_meta_boxes_attachment', function () {
    add_meta_box( 'keel-media-sizes', __( 'Generated sizes', 'keel' ), function ( $post ) {
        $meta = wp_get_attachment_metadata( $post->ID );
        // list $meta['sizes'] — name, dimensions, file
    }, null, 'side' );
} );
```

### Helper List-Table Columns
- **Key:** `helper_list_columns`
- **Default:** `no`
- **Why:** Adds the columns you end up wanting: ID, featured image and modified date on posts
  and pages; file size on the Media library; registration date and last login on Users.

**Last login is recorded from when this is switched on, not retroactively** — WordPress does
not store it, so there is no history to backfill. Existing accounts show blank until their
next sign-in.

```php
add_filter( 'manage_users_columns', function ( $columns ) {
    $columns['keel_last_login'] = __( 'Last login', 'keel' );

    return $columns;
} );

add_action( 'wp_login', function ( $login, $user ) {
    update_user_meta( $user->ID, 'keel_last_login', time() );
}, 10, 2 );
```

### Admin Menu Width
- **Key:** `admin_menu_width`
- **Default:** `default`
- **Why:** Long plugin names wrap in core's 160px sidebar and turn the menu into a wall. A
  wider menu is the cheapest fix.

Every width and margin carries `!important`, which is not cargo cult: core sets the width in
the colour-scheme stylesheet at equal specificity, and in the 783–960px band it also applies
`.auto-fold #adminmenu`. A class plus an ID beats an ID, so without `!important` the menu
collapses to 36px in that band — exactly as if no width had been chosen, at a laptop's common
zoom level.

```php
add_action( 'admin_head', function () {
    echo '<style>
        #adminmenu, #adminmenuback, #adminmenuwrap { width: 200px !important; }
        #wpcontent, #wpfooter { margin-left: 200px !important; }
    </style>';
} );
```

### Environment Indicator
- **Key:** `environment_indicator`
- **Default:** `no`
- **Why:** A colour-coded label in the admin bar showing Production, Staging, Development or
  Local. A cheap guard against running a destructive action on the wrong site.

The value comes from core's `wp_get_environment_type()`, which reads `WP_ENVIRONMENT_TYPE`
and otherwise returns `production` — it does no host inspection. When that constant is
undefined, Keel falls back to matching the host name against the domains local tooling uses.
See `environment-detection.md` for the list and the reasoning.

```php
add_action( 'admin_bar_menu', function ( $bar ) {
    $bar->add_menu( array(
        'id'    => 'keel-environment-indicator',
        'title' => ucfirst( wp_get_environment_type() ),
    ) );
}, 7 );
```

### Non-Production Email
- **Key:** `suppress_nonproduction_mail`
- **Default:** `yes`
- **Why:** A database copied from production brings real customer addresses with it — and
  usually whatever mail service production was configured to use. A cron run, a bulk action,
  or a migration routine then emails real people from a staging site or a laptop.

**"A local site cannot send mail anyway" is not a safeguard.** Measured on a stock local
install: the only thing preventing delivery was the default `From` address
`wordpress@localhost` being invalid — an accident of the hostname, and one a production
`siteurl` removes by definition. With a valid `From`, `wp_mail()` returned `true` having
handed the message to the local transport. An SMTP plugin, which a production copy also
carries, skips that question entirely and connects to the real provider.

The environment is read through `keel_defaults_current_environment()` — the same resolver
behind the admin-bar indicator, host fallback included — so the label and this behaviour
cannot disagree, and a local install that never set `WP_ENVIRONMENT_TYPE` is still caught.

The short-circuit returns **success**, not failure. Callers branch on that value — "we have
emailed you a link", an order marked notified — and answering false would exercise the failure
path on staging while the success path never runs, hiding bugs rather than surfacing them.

```php
add_filter( 'pre_wp_mail', function ( $pre, $atts ) {
    if ( 'production' === wp_get_environment_type() ) {
        return $pre;   // null: carry on and send
    }

    do_action( 'keel_outgoing_mail_suppressed', $atts );

    return true;       // "sent", without sending
}, PHP_INT_MAX, 2 );
```

Override with `keel_suppress_nonproduction_mail`, or the `KEEL_ALLOW_NONPRODUCTION_MAIL`
constant, for the staging site that genuinely has to send.

### Mail Failure Notice
- **Key:** `mail_failure_notice`
- **Default:** `yes`
- **Why:** When `wp_mail()` fails, WordPress says nothing. Password resets silently do not
  arrive, and the first anyone hears is a user saying they never got the email. This surfaces
  the failure in the admin where someone can act on it.

```php
add_action( 'admin_notices', function () {
    if ( ! keel_defaults_mail_looks_configured() ) {
        echo '<div class="notice notice-warning"><p>' .
            esc_html__( 'This site has no configured mail transport. Password resets may not arrive.', 'keel' ) .
            '</p></div>';
    }
} );
```

## 4. Login and Session Policy

### Disable Remember Me
- **Key:** `disable_remember_me`
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

### Session Lengths
- **Keys:** `session_regular_days`, `remember_me_days`
- **Defaults:** `2`, `14`
- **Why:** Core gives every login two days and every remembered login fourteen. Both are
  policy, not physics. Shortening the regular session limits the window on a walked-away-from
  desk; the remembered length is the one a client will notice, so it stays at core's default
  unless you lower it deliberately.

Two things here are easy to get wrong, and Keel handles both:

**Priority.** Registered at `50`, not the default `10`. Another plugin filtering
`auth_cookie_expiration` at `10` would otherwise be the last word, and a session policy that
any other plugin can silently override is not a policy.

**Coherence.** A remembered login must never be *shorter* than a regular one — otherwise
ticking "Remember Me" shortens the session, which is the opposite of what the box says. The
clamp runs at use, not only at save, so a value written by WP-CLI, a migration, or another
plugin is caught too.

```php
add_filter( 'auth_cookie_expiration', function ( $expiration, $user_id, $remember ) {
    $regular = 2 * DAY_IN_SECONDS;   // session_regular_days

    if ( ! $remember ) {
        return $regular;
    }

    return max( $regular, 14 * DAY_IN_SECONDS );   // remember_me_days, clamped up
}, 50, 3 );
```

### Login Logo and Link
- **Key:** `login_logo_behavior`
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
> `login_logo_link_home` toggle. That was redundant — a replacement logo should always link
> home — so the toggle is gone and the behavior option alone covers it.

---

### Throttle the Heartbeat API
- **Key:** `throttle_heartbeat`
- **Default:** `no`
- **Why:** The Heartbeat API polls `admin-ajax.php` every 15 seconds on the dashboard and
  every 60 while editing. On shared hosting a couple of idle open tabs can be a meaningful
  share of a site's PHP workers. Slowing it costs nothing except autosave latency.

Dropping it on the dashboard specifically is where most of the saving is, because that is the
tab people leave open. It is deregistered at `admin_enqueue_scripts` rather than `init`:
`wp_deregister_script()` calls `wp_scripts()`, which builds the entire core script registry,
so doing it at `init` forces that work on every request just to remove one script.

```php
add_filter( 'heartbeat_settings', function ( $settings ) {
    $settings['interval'] = 60;

    return $settings;
} );
```

## 5. Update Policy

### Automatically install core maintenance/security releases

*(`core_update_policy`, default `minor`)*

The default enables in-branch maintenance and security releases (`x.y.z`) while leaving major
core releases (`x.y`) for a tested rollout. The settings screen can also allow every
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

*(`auto_update_translations`, default `yes`)*

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

**Remove the WordPress version fingerprint** *(opt-in — key `remove_version`, default `no`)*.
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

**Send baseline security headers** *(key `security_headers`, default `yes`)*. Two headers with
essentially no downside: `nosniff` stops the browser second-guessing a declared `Content-Type`,
and `Referrer-Policy` keeps full URLs from leaking to other sites.

Note what is *not* in this group. `X-Frame-Options` is a separate setting
(key `frame_options`, default `SAMEORIGIN`) because it is the only one of the three that can
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

All 38, in schema order. The **Setting** column is the label as it appears under
**Settings → Keel**, and the **Category** column is the schema `group`, which is the
fieldset it renders under — so this table and the settings screen can be read side by
side. An earlier version of this table listed 24 of the 38 and said nothing about
covering only some, which reads as a full inventory when it is not.

| Setting | Schema key | Default | Category |
| --- | --- | --- | --- |
| REST user discovery | `restrict_rest_user_discovery` | `yes` | Security |
| REST authentication | `disable_rest` | `no` | Security |
| XML-RPC pingbacks | `xmlrpc_allow_pingbacks` | `no` | Security |
| XML-RPC remote publishing | `xmlrpc_allow_remote_publishing` | `no` | Security |
| XML-RPC multicall | `xmlrpc_allow_multicall` | `no` | Security |
| XML-RPC endpoint | `block_xmlrpc_endpoint` | `no` | Security |
| Application Passwords | `disable_application_passwords` | `no` | Security |
| Password Strength | `require_strong_passwords` | `yes` | Security |
| Password Policy Exemptions | `password_exempt_roles` | `['subscriber']` | Security |
| Unfiltered HTML | `limit_unfiltered_html_to_admins` | `yes` | Security |
| Version Fingerprint | `remove_version` | `no` | Security |
| Security Headers | `security_headers` | `yes` | Security |
| Frame Options | `frame_options` | `SAMEORIGIN` | Security |
| AI Connectors | `disable_ai_connectors` | `yes` | Security |
| Core Auto-Updates | `core_update_policy` | `minor` | Updates |
| Translations | `auto_update_translations` | `yes` | Updates |
| Comments | `disable_comments` | `yes` | Content |
| Pingbacks On New Posts | `disable_pingbacks` | `yes` | Content |
| Self-Pingbacks | `disable_self_pingbacks` | `yes` | Content |
| Author Archives | `disable_author_archives` | `yes` | Content |
| Attachment Pages | `redirect_attachment_pages` | `yes` | Content |
| Emoji Script | `disable_emojis` | `yes` | Content |
| Post Passwords | `disable_post_passwords` | `no` | Content |
| Post Revision Retention | `post_revisions_limit` | `10` | Content |
| Classic Editor | `force_classic_editor` | `no` | Editor |
| Upload Filenames | `lowercase_upload_filenames` | `yes` | Media |
| Image Sizes | `media_sizes_panel` | `yes` | Media |
| Email Deliverability | `mail_failure_notice` | `yes` | Email |
| Non-Production Email | `suppress_nonproduction_mail` | `yes` | Email |
| Admin Search | `title_only_admin_search` | `no` | UX |
| Front-End Admin Bar | `frontend_admin_bar_behavior` | `''` | UX |
| Admin Menu Width | `admin_menu_width` | `default` | UX |
| Admin List Columns | `helper_list_columns` | `no` | UX |
| Environment Indicator | `environment_indicator` | `no` | UX |
| Remember Me | `disable_remember_me` | `no` | Login |
| Regular Session Length | `session_regular_days` | `2` | Login |
| Remember Me Length | `remember_me_days` | `14` | Login |
| Login Logo | `login_logo_behavior` | `keep_default` | Branding |
| Heartbeat API | `throttle_heartbeat` | `no` | Performance |

---

### Implementation notes
- Load these from an **mu-plugin** or a dedicated plugin, not the theme, so policy survives
  theme switches.
- Gate every snippet behind its `keel_defaults_enabled()` / `keel_defaults_get()` check so site owners keep control. There is no per-setting option row: everything lives in the single `keel_settings` array option, which is why the keys above carry no `keel_` prefix.
- `DISALLOW_FILE_EDIT` and `AUTOSAVE_INTERVAL` still belong in wp-config. Keel can govern
  revision retention through core's filter; a numeric or false `WP_POST_REVISIONS` constant
  remains operator-owned and locks the corresponding controls.
- Explicit update constants remain operator-owned: the settings screen reports
  `WP_AUTO_UPDATE_CORE`, `AUTOMATIC_UPDATER_DISABLED`, and `DISALLOW_FILE_MODS` rather than
  silently fighting them.
- Test REST and comment changes against the block editor before shipping; those two touch the
  most core functionality.

---

## When another plugin touches the same policy

WordPress runs every callback on a filter in priority order and then uses the final value.
Callback presence alone therefore proves no collision: two callbacks can agree, and structured
results can carry independent restrictions. `keel_defaults_policy_overlap_report()` reports
three evidence levels:

| state | evidence and response |
|---|---|
| `confirmed` | A safe replay demonstrated that the final governed outcome differs. An actionable warning is allowed. |
| `compatible` | Both plugins touch the hook, but the governed final outcome still matches Keel. Informational only. |
| `unconfirmed` | Replay is unsafe, failed, or attribution is incomplete. Informational only, with no deactivation advice. |

`pre_wp_mail` and `comments_pre_query` are final-value filters, not callback short circuits:
every callback runs before core examines the non-null result. They are left unconfirmed because
blindly replaying mail or query callbacks on a Site Health request can have side effects.
`rest_authentication_errors` is compositional and is not accused from registration alone.
For block restrictions, the projector compares only whether comment blocks remain excluded;
another plugin removing unrelated blocks is compatible.

Two rules keep the report worth reading.

**Both sides, or it is not a contest.** A hook is only reported when Keel is itself
registered on it. Keel stands down on several — the session filter is not registered at
all when the policy matches WordPress's own — and a hook Keel is not on is another plugin
doing its job, not a conflict.

**Only safe evidence is actionable.** Capability direction is never inferred from a source
mention, and a core helper such as `__return_false` is labelled unattributable rather than
assigned to whichever plugin happened to contain the same words in a file.

The map is filterable through `keel_policy_hooks`, and the notice placements through
`keel_conflict_notice_screens`.

### Outgoing mail is the deliberate exception

Keel registers `pre_wp_mail` at `PHP_INT_MAX` and discards any value already set, because
a site that is not production must not send mail whatever else decided. That means Keel
wins on purpose, and a mail catcher or logger will never see the message. The report says
so rather than leaving somebody to work it out, and names the action to hook instead:

```php
add_action( 'keel_outgoing_mail_suppressed', function ( $atts ) {
    // Same arguments wp_mail() was called with, fired in place of the send.
} );
```
