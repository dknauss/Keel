<?php
/**
 * Clearfy — pingback/XML-RPC teardown on, comments off everywhere.
 *
 * Clearfy stores options through its Factory framework, which prefixes every key
 * with `wbcr_clearfy_`. The values below were read off the plugin's own gating
 * code, not guessed:
 *
 *   includes/classes/class.configurate-security.php   remove_x_pingback
 *   components/comments-plus/includes/boot.php        disable_comments
 *
 * `remove_x_pingback` is one switch that turns on the whole XML-RPC block:
 * `xmlrpc_enabled` false, the X-Pingback header, the RSD link, `pings_open`,
 * `xmlrpc_methods` filtering and an `xmlrpc_call` guard. Clearfy does not offer
 * them separately, so a probe of its per-method behaviour is a probe of that one
 * checkbox.
 *
 * Deliberately left alone: nothing here disables feeds. Admin and Site
 * Enhancements is configured the same way for the same reason — a 404 on a
 * comment feed has to be attributable to the comment teardown rather than to a
 * blanket feed switch.
 *
 * @package Keel
 */

update_option( 'wbcr_clearfy_remove_x_pingback', 1 );
update_option( 'wbcr_clearfy_protect_author_get', 1 );

/*
 * `wbcr_clearfy_disable_comments`, not `wbcr_comments_plus_disable_comments`.
 *
 * The bundled component declares `'prefix' => 'wbcr_comments_plus_'` in its own
 * header, and that is what a source read gives you — but when it loads inside
 * Clearfy the component's plugin object carries Clearfy's prefix, so that is the
 * option it actually reads. Setting the declared name left the component inert
 * while every surface said it was configured: the class was loaded, the helper
 * function defined, the option present in the database with the right value.
 *
 * It was caught by asking the plugin rather than reading it —
 * `WCM_Plugin::app()->getOptionName( 'disable_comments' )` — after noticing that
 * `comments_open` still carried nothing but core's own callback.
 */
update_option( 'wbcr_clearfy_disable_comments', 'disable_comments' );

echo 'configured';
