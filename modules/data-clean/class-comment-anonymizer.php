<?php
/**
 * OmniPrivacy Pro — Comment Anonymizer
 *
 * Anonymise les commentaires dont la date dépasse la période de rétention.
 * Exclut les rôles configurés dans les réglages.
 * Fonctionne par lots pour éviter les dépassements mémoire.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Comment_Anonymizer {

	/**
	 * Nombre de commentaires traités par lot.
	 */
	private const BATCH_SIZE = 200;

	/**
	 * Exécute l'anonymisation des commentaires anciens.
	 * Appelé par Action Scheduler ou WP-Cron (quotidien).
	 */
	public function run() {
		global $wpdb;

		$retention_months = absint( get_option( 'omniprivacy_retention_months', 36 ) );
		if ( 0 === $retention_months ) {
			return;
		}

		$excluded_roles = get_option( 'omniprivacy_excluded_roles', array( 'administrator' ) );
		if ( ! is_array( $excluded_roles ) ) {
			$excluded_roles = array( 'administrator' );
		}

		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_months} months" ) );

		// Traitement par lots pour ne pas saturer la mémoire sur de grosses bases.
		$offset           = 0;
		$anonymized_count = 0;
		$skipped_count    = 0;
		$role_cache       = array();

		do {
			$comments = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT comment_ID, comment_author, comment_author_email, comment_author_IP, user_id
					FROM {$wpdb->comments}
					WHERE comment_date < %s
					AND comment_author_email NOT LIKE %s
					ORDER BY comment_ID ASC
					LIMIT %d OFFSET %d",
					$cutoff_date,
					'%@anonymized.local',
					self::BATCH_SIZE,
					$offset
				)
			);

			if ( empty( $comments ) ) {
				break;
			}

			foreach ( $comments as $comment ) {
				// Vérifier si l'auteur a un rôle exclu (résultat mis en cache par user_id).
				if ( $comment->user_id > 0 ) {
					$uid = (int) $comment->user_id;
					if ( ! isset( $role_cache[ $uid ] ) ) {
						$role_cache[ $uid ] = $this->is_excluded_role( $uid, $excluded_roles );
					}
					if ( $role_cache[ $uid ] ) {
						$skipped_count++;
						continue;
					}
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

			// Les commentaires anonymisés ne matcheront plus au prochain tour
			// (email filtrée par le NOT LIKE), donc on n'incrémente l'offset
			// que du nombre de commentaires skippés dans ce batch.
			$offset += $skipped_count;
			$skipped_count = 0;

		} while ( count( $comments ) >= self::BATCH_SIZE );

		// Vider le cache des commentaires WordPress.
		if ( $anonymized_count > 0 ) {
			clean_comment_cache( array() );

			OmniPrivacy_Audit_Logger::log_system(
				'comment_anonymization',
				'comments',
				array(
					'comments_anonymized' => $anonymized_count,
					'retention_months'    => $retention_months,
					'cutoff_date'         => $cutoff_date,
				)
			);
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
}
