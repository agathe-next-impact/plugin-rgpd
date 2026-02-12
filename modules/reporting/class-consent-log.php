<?php
/**
 * OmniPrivacy Pro — Consent Log
 *
 * Journal chiffré et immuable des consentements cookies.
 * Chaînage de hash pour garantir l'intégrité.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Consent_Log {

	/**
	 * Enregistre un consentement.
	 * Déclenché via do_action( 'omniprivacy_consent_recorded', $action, $categories, $meta ).
	 *
	 * @param string $action     'accept' ou 'refuse'.
	 * @param array  $categories Catégories de cookies (analytics, marketing, etc.).
	 * @param array  $meta       Métadonnées supplémentaires (IP, user-agent, etc.).
	 */
	public function record( $action, $categories, $meta = array() ) {
		global $wpdb;

		$action     = sanitize_text_field( $action );
		$ip_hash    = hash( 'sha256', $meta['ip'] ?? '' );
		$ua_hash    = hash( 'sha256', $meta['user_agent'] ?? '' );
		$categories = array_map( 'sanitize_text_field', (array) $categories );

		// Payload complet (chiffré).
		$payload = wp_json_encode( array(
			'action'     => $action,
			'categories' => $categories,
			'ip_hash'    => $ip_hash,
			'user_agent' => $meta['user_agent'] ?? '',
			'timestamp'  => current_time( 'mysql' ),
			'referer'    => $meta['referer'] ?? '',
		) );

		$encrypted_payload = OmniPrivacy_Encryption::encrypt( $payload );

		// Chaînage de hash (intégrité).
		$prev_hash = $this->get_last_entry_hash();
		$entry_hash = hash( 'sha256', $prev_hash . $encrypted_payload . current_time( 'mysql' ) );

		$wpdb->insert(
			$wpdb->prefix . 'omniprivacy_consent_log',
			array(
				'ip_hash'           => $ip_hash,
				'action'            => $action,
				'categories_json'   => wp_json_encode( $categories ),
				'user_agent_hash'   => $ua_hash,
				'payload_encrypted' => $encrypted_payload,
				'prev_hash'         => $prev_hash,
				'entry_hash'        => $entry_hash,
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Récupère le hash de la dernière entrée du journal.
	 *
	 * @return string Hash ou chaîne vide si première entrée.
	 */
	private function get_last_entry_hash() {
		global $wpdb;

		$hash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT entry_hash FROM {$wpdb->prefix}omniprivacy_consent_log ORDER BY id DESC LIMIT %d",
				1
			)
		);

		return $hash ?: '';
	}

	/**
	 * Vérifie l'intégrité de la chaîne de hash.
	 *
	 * @return array Résultat de la vérification avec les éventuelles incohérences.
	 */
	public function verify_integrity() {
		global $wpdb;

		$entries = $wpdb->get_results(
			"SELECT id, payload_encrypted, prev_hash, entry_hash, created_at
			FROM {$wpdb->prefix}omniprivacy_consent_log ORDER BY id ASC"
		);

		$errors    = array();
		$prev_hash = '';

		foreach ( $entries as $entry ) {
			// Vérifier que prev_hash correspond.
			if ( $entry->prev_hash !== $prev_hash ) {
				$errors[] = array(
					'entry_id' => $entry->id,
					'error'    => 'prev_hash_mismatch',
				);
			}

			// Vérifier le hash de l'entrée.
			$expected_hash = hash( 'sha256', $entry->prev_hash . $entry->payload_encrypted . $entry->created_at );
			if ( $entry->entry_hash !== $expected_hash ) {
				$errors[] = array(
					'entry_id' => $entry->id,
					'error'    => 'entry_hash_mismatch',
				);
			}

			$prev_hash = $entry->entry_hash;
		}

		return array(
			'total_entries' => count( $entries ),
			'is_valid'      => empty( $errors ),
			'errors'        => $errors,
		);
	}

	/**
	 * Récupère les entrées du journal pour l'interface admin (lecture seule).
	 *
	 * @param array $args Arguments de filtrage (date_from, date_to, per_page, offset).
	 * @return array Entrées du journal.
	 */
	public function get_entries( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'per_page'  => 50,
			'offset'    => 0,
			'date_from' => '',
			'date_to'   => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$where = array( '1=1' );
		$params = array();

		if ( $args['date_from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = sanitize_text_field( $args['date_from'] );
		}

		if ( $args['date_to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = sanitize_text_field( $args['date_to'] );
		}

		$where_sql = implode( ' AND ', $where );
		$params[]  = absint( $args['per_page'] );
		$params[]  = absint( $args['offset'] );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, ip_hash, action, categories_json, created_at
				FROM {$wpdb->prefix}omniprivacy_consent_log
				WHERE {$where_sql}
				ORDER BY created_at DESC
				LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			)
		);
	}
}
