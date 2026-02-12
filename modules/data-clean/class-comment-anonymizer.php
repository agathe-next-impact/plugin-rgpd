<?php
/**
 * OmniPrivacy Pro — Comment Anonymizer
 *
 * Anonymise les commentaires dont la date dépasse la période de rétention.
 * Exclut les rôles configurés dans les réglages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Comment_Anonymizer {

	/**
	 * Exécute l'anonymisation des commentaires anciens.
	 * Appelé par Action Scheduler ou WP-Cron (quotidien).
	 */
	public function run() {
		global $wpdb;

		$retention_months = absint( get_option( 'omniprivacy_retention_months', 36 ) );
		$excluded_roles   = get_option( 'omniprivacy_excluded_roles', array( 'administrator' ) );
		$cutoff_date      = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_months} months" ) );

		// Récupérer les commentaires éligibles.
		$comments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_author, comment_author_email, comment_author_IP, user_id
				FROM {$wpdb->comments}
				WHERE comment_date < %s
				AND comment_author_email NOT LIKE %s",
				$cutoff_date,
				'%@anonymized.local'
			)
		);

		if ( empty( $comments ) ) {
			return;
		}

		$anonymized_count = 0;

		foreach ( $comments as $comment ) {
			// Vérifier si l'auteur a un rôle exclu.
			if ( $comment->user_id > 0 && $this->is_excluded_role( $comment->user_id, $excluded_roles ) ) {
				continue;
			}

			$wpdb->update(
				$wpdb->comments,
				array(
					'comment_author'       => __( 'Utilisateur Anonyme', 'omniprivacy-pro' ),
					'comment_author_email' => 'anon-' . $comment->comment_ID . '@anonymized.local',
					'comment_author_IP'    => '0.0.0.0',
					'comment_author_url'   => '',
				),
				array( 'comment_ID' => $comment->comment_ID ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			$anonymized_count++;
		}

		// Log dans la table d'audit.
		if ( $anonymized_count > 0 ) {
			$this->log_audit( $anonymized_count );
		}
	}

	/**
	 * Vérifie si un utilisateur a un rôle exclu.
	 *
	 * @param int   $user_id       ID utilisateur.
	 * @param array $excluded_roles Rôles à exclure.
	 * @return bool
	 */
	private function is_excluded_role( $user_id, $excluded_roles ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		return ! empty( array_intersect( $user->roles, $excluded_roles ) );
	}

	/**
	 * Enregistre l'action dans le journal d'audit.
	 *
	 * @param int $count Nombre de commentaires anonymisés.
	 */
	private function log_audit( $count ) {
		global $wpdb;

		$details = wp_json_encode( array(
			'comments_anonymized' => $count,
			'date'                => current_time( 'mysql' ),
		) );

		$wpdb->insert(
			$wpdb->prefix . 'omniprivacy_audit_log',
			array(
				'action'             => 'comment_anonymization',
				'actor'              => 'system',
				'target_type'        => 'comments',
				'details_encrypted'  => OmniPrivacy_Encryption::encrypt( $details ),
				'created_at'         => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
