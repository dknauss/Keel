<?php
/**
 * WP Master Toolkit — the free security modules that overlap this matrix.
 *
 * WPMT is a module registry rather than a settings screen: `wpmastertoolkit_settings`
 * maps a module's class name to '1', and `admin/class-handle-options.php` requires
 * and instantiates only the classes marked that way. A fresh install writes no
 * option at all, so every module is off — probing WPMT unconfigured measures the
 * plugin doing nothing, which is the single easiest way to publish a wrong
 * comparison.
 *
 * Enabled here, all free tier:
 *
 *   WPMastertoolkit_Disable_Xmlrpc          xmlrpc_enabled + a server-class swap
 *   WPMastertoolkit_Disable_REST_API        the REST teardown
 *   WPMastertoolkit_Obfuscate_Author_Slugs  author-enumeration defence
 *
 * Deliberately NOT enabled:
 *
 *   WPMastertoolkit_Disable_Feeds     would 404 the comment feed for reasons
 *                                     that have nothing to do with comments, the
 *                                     same trap ASE is configured around.
 *   WPMastertoolkit_Disable_Comments  cannot be: it is a pro module. That is a
 *                                     finding rather than a configuration
 *                                     choice, and the comment rows read as
 *                                     "not offered" rather than "does nothing".
 *
 * @package Keel
 */

update_option(
	'wpmastertoolkit_settings',
	array(
		'WPMastertoolkit_Disable_Xmlrpc'         => '1',
		'WPMastertoolkit_Disable_REST_API'       => '1',
		'WPMastertoolkit_Obfuscate_Author_Slugs' => '1',
	)
);

echo 'configured';
