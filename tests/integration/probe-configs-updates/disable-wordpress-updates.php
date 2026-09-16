<?php
/**
 * Disable All WordPress Updates — Security Mode on.
 *
 * Measured with Security Mode ON, and that is a deliberately generous reading of
 * a plugin called "Disable All WordPress Updates". Left at its default the
 * option is false, every update path is closed, and it would score a straight
 * column of "does not do this" against a question it is not trying to answer.
 * Its Security Mode checkbox is the one setting in the plugin that takes the
 * same position as everything else in this field — minor core updates install
 * themselves, nothing else does — so it is the setting that makes the comparison
 * a comparison.
 *
 * The default is not thereby hidden: it is a row in the matrix.
 *
 * Option name and type from OSDWP_Security_Mode::OPTION_NAME, which registers a
 * boolean and reads it with get_option( self::OPTION_NAME, false ).
 *
 * @package Keel
 */

update_option( 'osdwp_security_mode', true );

echo 'configured: osdwp_security_mode=on';
