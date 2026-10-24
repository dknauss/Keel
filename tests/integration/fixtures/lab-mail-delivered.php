<?php
/**
 * Disposable lab mu-plugin; never install on a real site.
 *
 * Reports every wp_mail() as delivered, the way a working production mailer
 * would. Without it the lost-password form fails for real accounts as well as
 * unknown ones, and probe-exposure.sh's "does the reset form distinguish them"
 * row compares two failures. It also keeps the lab from sending anything.
 *
 * @package Keel
 */

add_filter( 'pre_wp_mail', '__return_true', 1 );
