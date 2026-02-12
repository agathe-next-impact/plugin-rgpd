<?php
/**
 * OmniPrivacy Pro — Log Rotator
 *
 * Rotation et anonymisation des journaux d'erreurs PHP accessibles via WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Log_Rotator {

	/**
	 * Exécute la rotation des logs.
	 * Appelé par Action Scheduler ou WP-Cron (hebdomadaire).
	 */
	public function run() {
		if ( ! get_option( 'omniprivacy_log_rotation_enabled', 1 ) ) {
			return;
		}

		$log_file = $this->get_log_path();
		if ( ! $log_file || ! is_writable( $log_file ) ) {
			return;
		}

		// Vérifier que le fichier est dans WP_CONTENT_DIR (sécurité).
		$real_path    = realpath( $log_file );
		$content_path = realpath( WP_CONTENT_DIR );
		if ( ! $real_path || ! $content_path || strpos( $real_path, $content_path ) !== 0 ) {
			return;
		}

		$retention_days = absint( get_option( 'omniprivacy_log_retention_days', 30 ) );
		$this->rotate_file( $log_file, $retention_days );
		$this->anonymize_ips_in_file( $log_file );

		$this->log_audit();
	}

	/**
	 * Détermine le chemin du fichier de log.
	 *
	 * @return string|false Chemin du fichier ou false.
	 */
	private function get_log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
			return WP_DEBUG_LOG;
		}

		$default = WP_CONTENT_DIR . '/debug.log';
		return file_exists( $default ) ? $default : false;
	}

	/**
	 * Supprime les entrées de log au-delà de la période de rétention.
	 *
	 * @param string $file_path     Chemin du fichier.
	 * @param int    $retention_days Nombre de jours à conserver.
	 */
	private function rotate_file( $file_path, $retention_days ) {
		$cutoff = strtotime( "-{$retention_days} days" );
		$lines  = file( $file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		if ( false === $lines ) {
			return;
		}

		$kept = array();
		foreach ( $lines as $line ) {
			// Tenter d'extraire un timestamp du format PHP error_log standard.
			if ( preg_match( '/^\[(\d{2}-\w{3}-\d{4}\s\d{2}:\d{2}:\d{2})\s/', $line, $matches ) ) {
				$timestamp = strtotime( $matches[1] );
				if ( $timestamp && $timestamp >= $cutoff ) {
					$kept[] = $line;
				}
			} else {
				// Conserver les lignes sans timestamp (suites de stacktrace, etc.).
				$kept[] = $line;
			}
		}

		file_put_contents( $file_path, implode( "\n", $kept ) . "\n", LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Anonymise les adresses IP dans le fichier de log.
	 *
	 * @param string $file_path Chemin du fichier.
	 */
	private function anonymize_ips_in_file( $file_path ) {
		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return;
		}

		// Remplacer les IPv4.
		$content = preg_replace(
			'/\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.\d{1,3}\b/',
			'$1.$2.$3.xxx',
			$content
		);

		file_put_contents( $file_path, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Enregistre l'action dans le journal d'audit.
	 */
	private function log_audit() {
		global $wpdb;

		$details = wp_json_encode( array(
			'action' => 'log_rotation',
			'date'   => current_time( 'mysql' ),
		) );

		$wpdb->insert(
			$wpdb->prefix . 'omniprivacy_audit_log',
			array(
				'action'             => 'log_rotation',
				'actor'              => 'system',
				'target_type'        => 'logs',
				'details_encrypted'  => OmniPrivacy_Encryption::encrypt( $details ),
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
