<?php
/**
 * Sucuri Security — nothing to do before activation.
 *
 * Its settings go through SucuriScanOption, which keeps them in the plugin's
 * own storage rather than in a plain option; the class is only loaded with the
 * plugin. See sucuri-scanner.post.php.
 *
 * @package Keel
 */

echo 'deferred to post-activation';
