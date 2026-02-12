<?php
/**
 * OmniPrivacy Pro — Contact Form 7 Compatibility
 *
 * Scan des soumissions Flamingo (flamingo_inbound),
 * récupération et suppression pour le portail visiteur.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_CF7_Scanner {

	use OmniPrivacy_PII_Field_Scanner;

	/**
	 * Initialise les hooks de compatibilité CF7.
	 */
	public function register_hooks() {
		if ( ! defined( 'FLAMINGO_VERSION' ) && ! class_exists( 'Flamingo_Inbound_Message' ) ) {
			return;
		}

		add_filter( 'omniprivacy_pii_scan_steps', array( $this, 'add_scan_steps' ) );
		add_filter( 'omniprivacy_pii_scan_total', array( $this, 'add_scan_total' ) );
		add_filter( 'omniprivacy_user_data', array( $this, 'add_user_data' ), 10, 2 );
		add_filter( 'omniprivacy_deletion_handlers', array( $this, 'register_deletion_handler' ) );
	}

	/**
	 * Ajoute l'étape de scan CF7/Flamingo.
	 *
	 * @param array $steps Étapes existantes.
	 * @return array
	 */
	public function add_scan_steps( $steps ) {
		$steps['flamingo'] = array( $this, 'scan_flamingo' );
		return $steps;
	}

	/**
	 * Ajoute le nombre de soumissions Flamingo au total.
	 *
	 * @param int $total Total courant.
	 * @return int
	 */
	public function add_scan_total( $total ) {
		global $wpdb;

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
				'flamingo_inbound',
				'publish'
			)
		);

		return $total + $count;
	}

	/**
	 * Scanne les soumissions Flamingo.
	 *
	 * @param int   $offset     Décalage.
	 * @param int   $batch_size Taille du lot.
	 * @param array $patterns   Patterns regex.
	 * @return bool True s'il reste des éléments.
	 */
	public function scan_flamingo( $offset, $batch_size, $patterns ) {
		global $wpdb;

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content
				FROM {$wpdb->posts}
				WHERE post_type = 'flamingo_inbound'
				AND post_status = 'publish'
				ORDER BY ID ASC
				LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			)
		);

		foreach ( $posts as $post ) {
			$this->scan_field( $post->post_title, 'flamingo', $post->ID, 'subject', $patterns );
			$this->scan_field( $post->post_content, 'flamingo', $post->ID, 'content', $patterns );

			// Scanner les métadonnées du formulaire (champs soumis).
			$meta = get_post_meta( $post->ID, '_field_', true );
			if ( is_array( $meta ) ) {
				foreach ( $meta as $key => $value ) {
					if ( is_string( $value ) && ! empty( $value ) ) {
						$this->scan_field( $value, 'flamingo', $post->ID, 'field_' . $key, $patterns );
					}
				}
			}

			// Scanner aussi les metas individuelles de champs Flamingo.
			$all_meta = get_post_meta( $post->ID );
			foreach ( $all_meta as $meta_key => $meta_values ) {
				if ( strpos( $meta_key, '_field_' ) === 0 && ! empty( $meta_values[0] ) ) {
					$this->scan_field( $meta_values[0], 'flamingo', $post->ID, $meta_key, $patterns );
				}
			}
		}

		return count( $posts ) >= $batch_size;
	}

	/**
	 * Ajoute les soumissions CF7 aux données utilisateur du portail.
	 *
	 * @param array  $data  Données existantes.
	 * @param string $email Email du visiteur.
	 * @return array
	 */
	public function add_user_data( $data, $email ) {
		global $wpdb;

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_date
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'flamingo_inbound'
				AND p.post_status = 'publish'
				AND pm.meta_key = '_from_email'
				AND pm.meta_value = %s
				ORDER BY p.post_date DESC
				LIMIT 100",
				$email
			)
		);

		$submissions = array();
		foreach ( $posts as $post ) {
			$submissions[] = array(
				'id'      => $post->ID,
				'subject' => $post->post_title,
				'date'    => $post->post_date,
				'type'    => 'cf7_submission',
			);
		}

		if ( ! empty( $submissions ) ) {
			$data['cf7_submissions'] = $submissions;
		}

		return $data;
	}

	/**
	 * Enregistre le handler de suppression CF7.
	 *
	 * @param array $handlers Handlers existants.
	 * @return array
	 */
	public function register_deletion_handler( $handlers ) {
		$handlers['cf7_submission'] = array( $this, 'delete_submission' );
		return $handlers;
	}

	/**
	 * Supprime une soumission Flamingo.
	 *
	 * @param int $post_id ID du post Flamingo.
	 * @return bool Succès.
	 */
	public function delete_submission( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'flamingo_inbound' !== $post->post_type ) {
			return false;
		}

		$result = wp_delete_post( $post_id, true );

		if ( $result ) {
			OmniPrivacy_Audit_Logger::log_system(
				'cf7_submission_deleted',
				'cf7',
				array( 'post_id' => $post_id )
			);
		}

		return (bool) $result;
	}

}
