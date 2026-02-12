<?php
/**
 * OmniPrivacy Pro — Log Rotator
 *
 * Rotation et anonymisation des journaux d'erreurs PHP accessibles via WordPress.
 * Prend en charge les fichiers volumineux en lecture par flux.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Log_Rotator {

	/**
	 * Taille maximum d'un fichier de log à traiter (50 Mo).
	 */
	private const MAX_FILE_SIZE = 52428800;

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

		// Vérifier que le fichier est dans WP_CONTENT_DIR (sécurité anti-traversée).
		$real_path    = realpath( $log_file );
		$content_path = realpath( WP_CONTENT_DIR );
		if ( ! $real_path || ! $content_path || strpos( $real_path, $content_path ) !== 0 ) {
			return;
		}

		// Vérifier la taille du fichier.
		$file_size = filesize( $log_file );
		if ( false === $file_size || $file_size > self::MAX_FILE_SIZE ) {
			// Fichier trop volumineux : on le tronque plutôt que de le traiter ligne par ligne.
			$this->truncate_large_file( $log_file );
			return;
		}

		$retention_days = absint( get_option( 'omniprivacy_log_retention_days', 30 ) );
		$lines_before   = $this->count_lines( $log_file );

		$this->rotate_file( $log_file, $retention_days );
		$this->anonymize_ips_in_file( $log_file );

		$lines_after = $this->count_lines( $log_file );

		OmniPrivacy_Audit_Logger::log_system(
			'log_rotation',
			'logs',
			array(
				'file'           => basename( $log_file ),
				'lines_removed'  => max( 0, $lines_before - $lines_after ),
				'retention_days' => $retention_days,
			)
		);
	}

	/**
	 * Détermine le chemin du fichier de log.
	 *
	 * @return string|false Chemin du fichier ou false.
	 */
	private function get_log_path() {
		// Support WP_DEBUG_LOG personnalisé.
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && file_exists( WP_DEBUG_LOG ) ) {
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

		$kept            = array();
		$last_kept       = true;

		foreach ( $lines as $line ) {
			// Tenter d'extraire un timestamp des formats PHP error_log standard.
			// Format 1 : [DD-Mon-YYYY HH:MM:SS zone]
			// Format 2 : [YYYY-MM-DD HH:MM:SS]
			if ( preg_match( '/^\[(\d{2}-\w{3}-\d{4}\s\d{2}:\d{2}:\d{2})/', $line, $matches ) ) {
				$timestamp  = strtotime( $matches[1] );
				$last_kept  = ( $timestamp && $timestamp >= $cutoff );
			} elseif ( preg_match( '/^\[(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})/', $line, $matches ) ) {
				$timestamp  = strtotime( $matches[1] );
				$last_kept  = ( $timestamp && $timestamp >= $cutoff );
			}
			// Les lignes sans timestamp (stacktrace, continuation) suivent le sort de la dernière entrée datée.

			if ( $last_kept ) {
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
		if ( false === $content || empty( $content ) ) {
			return;
		}

		// Anonymiser IPv4 : remplacer le dernier octet par xxx.
		$content = preg_replace(
			'/\b(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}\b/',
			'$1.xxx',
			$content
		);

		// Anonymiser IPv6 : remplacer les 4 derniers groupes.
		$content = preg_replace(
			'/\b([0-9a-fA-F]{1,4}:[0-9a-fA-F]{1,4}:[0-9a-fA-F]{1,4}:[0-9a-fA-F]{1,4}):[0-9a-fA-F:]+\b/',
			'$1:xxxx:xxxx:xxxx:xxxx',
			$content
		);

		file_put_contents( $file_path, $content, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Tronque un fichier de log volumineux (garde les 1000 dernières lignes).
	 *
	 * @param string $file_path Chemin du fichier.
	 */
	private function truncate_large_file( $file_path ) {
		$lines = array();
		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return;
		}

		// Lire les 1000 dernières lignes.
		$buffer = array();
		while ( ( $line = fgets( $handle ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$buffer[] = $line;
			if ( count( $buffer ) > 1000 ) {
				array_shift( $buffer );
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		file_put_contents( $file_path, implode( '', $buffer ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		OmniPrivacy_Audit_Logger::log_system(
			'log_truncation',
			'logs',
			array( 'file' => basename( $file_path ), 'reason' => 'file_too_large' )
		);
	}

	/**
	 * Compte le nombre de lignes d'un fichier.
	 *
	 * @param string $file_path Chemin du fichier.
	 * @return int Nombre de lignes.
	 */
	private function count_lines( $file_path ) {
		$count  = 0;
		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return 0;
		}
		while ( fgets( $handle ) !== false ) {
			$count++;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $count;
	}
}
