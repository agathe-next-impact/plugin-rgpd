<?php
/**
 * OmniPrivacy Pro — Audit Logger
 *
 * Helper centralisé pour l'enregistrement des actions dans le journal d'audit.
 * Évite la duplication de la logique d'insertion dans chaque module.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Audit_Logger {

	/**
	 * Enregistre une entrée dans le journal d'audit.
	 *
	 * @param string      $action      Identifiant de l'action (ex: 'comment_anonymization').
	 * @param string      $actor       Acteur (ex: 'system', login utilisateur).
	 * @param string|null $target_type Type de cible (ex: 'comments', 'post').
	 * @param int|null    $target_id   ID de la cible.
	 * @param array       $details     Détails supplémentaires (sera chiffré).
	 */
	public static function log( $action, $actor = 'system', $target_type = null, $target_id = null, $details = array() ) {
		global $wpdb;

		$details['timestamp'] = current_time( 'mysql' );

		$encrypted_details = '';
		if ( ! empty( $details ) ) {
			$encrypted_details = OmniPrivacy_Encryption::encrypt( wp_json_encode( $details ) );
		}

		$wpdb->insert(
			$wpdb->prefix . 'omniprivacy_audit_log',
			array(
				'action'            => sanitize_text_field( $action ),
				'actor'             => sanitize_text_field( $actor ),
				'target_type'       => $target_type ? sanitize_text_field( $target_type ) : null,
				'target_id'         => $target_id ? absint( $target_id ) : null,
				'details_encrypted' => $encrypted_details,
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Raccourci pour les actions système.
	 *
	 * @param string      $action      Identifiant de l'action.
	 * @param string|null $target_type Type de cible.
	 * @param array       $details     Détails supplémentaires.
	 */
	public static function log_system( $action, $target_type = null, $details = array() ) {
		self::log( $action, 'system', $target_type, null, $details );
	}

	/**
	 * Raccourci pour les actions utilisateur (récupère automatiquement le login).
	 *
	 * @param string      $action      Identifiant de l'action.
	 * @param string|null $target_type Type de cible.
	 * @param int|null    $target_id   ID de la cible.
	 * @param array       $details     Détails supplémentaires.
	 */
	public static function log_user( $action, $target_type = null, $target_id = null, $details = array() ) {
		$current_user = wp_get_current_user();
		$actor        = $current_user->ID > 0 ? $current_user->user_login : 'anonymous';
		self::log( $action, $actor, $target_type, $target_id, $details );
	}

	/**
	 * Récupère les entrées du journal d'audit.
	 *
	 * @param array $args Arguments de filtrage.
	 * @return array Entrées du journal.
	 */
	public static function get_entries( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'action'      => '',
			'actor'       => '',
			'target_type' => '',
			'date_from'   => '',
			'date_to'     => '',
			'per_page'    => 50,
			'offset'      => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( $args['action'] ) {
			$where[]  = 'action = %s';
			$params[] = $args['action'];
		}

		if ( $args['actor'] ) {
			$where[]  = 'actor = %s';
			$params[] = $args['actor'];
		}

		if ( $args['target_type'] ) {
			$where[]  = 'target_type = %s';
			$params[] = $args['target_type'];
		}

		if ( $args['date_from'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['date_from'];
		}

		if ( $args['date_to'] ) {
			$where[]  = 'created_at <= %s';
			$params[] = $args['date_to'];
		}

		$where_sql = implode( ' AND ', $where );
		$params[]  = absint( $args['per_page'] );
		$params[]  = absint( $args['offset'] );

		$query = "SELECT * FROM {$wpdb->prefix}omniprivacy_audit_log WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $wpdb->get_results( $wpdb->prepare( $query, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Compte le nombre total d'entrées.
	 *
	 * @param array $args Arguments de filtrage (mêmes que get_entries sauf per_page/offset).
	 * @return int Nombre total.
	 */
	public static function count_entries( $args = array() ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = $args['action'];
		}

		if ( ! empty( $args['target_type'] ) ) {
			$where[]  = 'target_type = %s';
			$params[] = $args['target_type'];
		}

		$where_sql = implode( ' AND ', $where );
		$query     = "SELECT COUNT(*) FROM {$wpdb->prefix}omniprivacy_audit_log WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $params ) ) {
			return (int) $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) $wpdb->get_var( $wpdb->prepare( $query, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
