<?php
/**
 * Security backport visibility: is this site actually patched, and if not, can it be?
 *
 * WordPress core never asks this question. It knows whether an update is
 * available, which is not the same thing: a site can be on a release with known
 * vulnerabilities while every admin screen looks perfectly calm. The answer
 * lives at api.wordpress.org/core/stable-check/1.0/, which core does not query.
 *
 * What that endpoint asserts is narrow and worth stating exactly: whether
 * WordPress.org presently classifies a given core version as insecure. It is not
 * a statement that a version is supported — only the latest release is — nor that
 * a site is secure, which depends on plugins, themes, PHP and configuration too.
 *
 * This file surfaces that answer, and — only on an explicit click — offers to
 * act on it.
 *
 * @package Keel
 */

defined( 'ABSPATH' ) || exit;

/*
 * Compatibility entry point and composition root. Modules define callbacks;
 * hooks are registered here only after all dependencies have loaded.
 * Status owns remote/cache reads, policy owns offer selection and blockers,
 * presentation owns reports/markup, and actions owns request handlers.
 */
require_once __DIR__ . '/backport-status.php';
require_once __DIR__ . '/backport-policy.php';
require_once __DIR__ . '/backport-presentation.php';
require_once __DIR__ . '/backport-actions.php';

add_action( 'set_site_transient_update_core', 'keel_defaults_refresh_stale_stable_check' );
add_action( 'wp_ajax_health-check-keel-backport', 'keel_defaults_backport_ajax' );
add_filter( 'site_status_tests', 'keel_defaults_register_backport_test' );
add_action( 'admin_post_keel_defaults_enable_minor_updates', 'keel_defaults_handle_enable_minor_updates' );
add_action( 'admin_notices', 'keel_defaults_backport_notice' );
add_action( 'wp_ajax_keel_defaults_dismiss_backport', 'keel_defaults_dismiss_backport_notice' );
add_action( 'admin_enqueue_scripts', 'keel_defaults_backport_notice_script' );

// The installer retains its own request validation and explicit execution path.
require_once __DIR__ . '/backport-install.php';
