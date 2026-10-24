<?php
/**
 * Keel — user-discovery defaults on, REST otherwise open.
 *
 * The REST gate stays off on purpose. With it on every REST row reads 401 and
 * measures the gate, not the users-route restriction that is Keel's answer to
 * the enumeration question. These are the shipped defaults for the three
 * settings that bear on it, written explicitly so the run does not depend on
 * whatever an earlier probe saved.
 *
 * @package Keel
 */

$settings = (array) get_option( 'keel_settings', array() );

$settings['restrict_rest_user_discovery'] = 'yes';
$settings['disable_author_archives']      = 'yes';
$settings['security_headers']             = 'yes';
$settings['frame_options']                = 'SAMEORIGIN';
$settings['disable_rest']                 = 'no';

update_option( 'keel_settings', $settings );

echo 'configured';
