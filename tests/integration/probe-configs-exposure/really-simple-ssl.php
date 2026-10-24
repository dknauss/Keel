<?php
/**
 * Really Simple Security — nothing to do before activation.
 *
 * Its options are written through rsssl_update_option(), which validates each
 * name against the plugin's own field registry and runs the plugin's
 * after-save actions. Neither exists until the plugin is loaded, so the real
 * configuration is really-simple-ssl.post.php.
 *
 * @package Keel
 */

echo 'deferred to post-activation';
