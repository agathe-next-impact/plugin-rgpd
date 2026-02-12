<?php
/**
 * OmniPrivacy Pro — Deactivator
 *
 * Nettoie les tâches planifiées à la désactivation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Deactivator {

	/**
	 * Exécuté à la désactivation du plugin.
	 */
	public static function deactivate() {
		// Annuler toutes les tâches Action Scheduler.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'omniprivacy_anonymize_comments' );
			as_unschedule_all_actions( 'omniprivacy_rotate_logs' );
			as_unschedule_all_actions( 'omniprivacy_pii_scan_batch' );
		}

		// Annuler les tâches WP-Cron de fallback.
		wp_clear_scheduled_hook( 'omniprivacy_anonymize_comments' );
		wp_clear_scheduled_hook( 'omniprivacy_rotate_logs' );

		flush_rewrite_rules();
	}
}
