<?php
/**
 * Disable WordPress Update Notifications — core notifications off.
 *
 * Its flagship setting, and the only one of its four that touches core. There is
 * no minor-only position to take: the plugin has no policy controls at all, it
 * suppresses the notice. Configuring it to its own headline behaviour is the
 * only reading that measures the plugin rather than an empty option.
 *
 * Keys and shape from its own save handler, which builds the array from
 * isset( $_POST[...] ) and so stores booleans under these four names.
 *
 * @package Keel
 */

update_option(
	'dwun_plugin_options',
	array(
		'dpun_setting'  => false,
		'dwtu_setting'  => false,
		'dwcun_setting' => true,
		'den_setting'   => false,
	)
);

echo 'configured: dwcun_setting=on (core update notifications suppressed)';
