<?php
/**
 * OmniPrivacy Pro — Uninstall
 *
 * Supprime toutes les tables et options créées par le plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Supprimer les tables personnalisées.
$tables = array(
	$wpdb->prefix . 'omniprivacy_scan_results',
	$wpdb->prefix . 'omniprivacy_deletion_requests',
	$wpdb->prefix . 'omniprivacy_magic_tokens',
	$wpdb->prefix . 'omniprivacy_consent_log',
	$wpdb->prefix . 'omniprivacy_audit_log',
	$wpdb->prefix . 'omniprivacy_ignored_items',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Supprimer les options.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'omniprivacy_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Supprimer les tâches Action Scheduler.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'omniprivacy_anonymize_comments' );
	as_unschedule_all_actions( 'omniprivacy_rotate_logs' );
	as_unschedule_all_actions( 'omniprivacy_pii_scan_batch' );
}

// Supprimer la capability personnalisée.
$role = get_role( 'administrator' );
if ( $role ) {
	$role->remove_cap( 'manage_omniprivacy' );
}
