<?php
/**
 * Keel — REST gate on, author archives deliberately left visible.
 *
 * The posture probe-configs/keel.php does not cover, and the one that made a
 * real difference: with archives hidden, the author strip runs for that reason
 * and the REST gate's own obligation is never tested.
 *
 * Here the site has chosen to publish author archives, so `/author/x/` answers
 * 200 on purpose. oEmbed is still reachable through the `oembed/1.0` carve-out —
 * and it must not hand author identity to the anonymous caller who has just
 * been refused `/wp/v2/users`. Two independent reasons to strip the same
 * fields; this config exercises the second.
 *
 * Run with:
 *   PROBE_CONFIG_DIR=tests/integration/probe-configs-rest-gate \
 *     bash tests/integration/assert-privacy.sh keel
 *
 * @package Keel
 */

$settings = (array) get_option( 'keel_settings', array() );

$settings['disable_rest']                 = 'yes';
$settings['restrict_rest_user_discovery'] = 'yes';
$settings['disable_author_archives']      = 'no';
$settings['disable_comments']             = 'yes';
$settings['block_xmlrpc_endpoint']        = 'no';

update_option( 'keel_settings', $settings );

echo "configured keel (REST gate, author archives visible)\n";
