<?php
/**
 * Sucuri Security 2.8.1 — "Disable XML-RPC" hardening applied.
 *
 * The same call the hardening screen's "Apply Hardening" button makes
 * (SucuriScanSettingsHardening::xmlrpc() in src/settings-hardening.php), after
 * which that screen reports "XML-RPC (xmlrpc.php) has been disabled."
 *
 * @package Keel
 */

SucuriScanOption::updateOption( ':hardening_xmlrpc', 'enabled' );

echo 'hardening_xmlrpc=', SucuriScanOption::getOption( ':hardening_xmlrpc' ), "\n";
