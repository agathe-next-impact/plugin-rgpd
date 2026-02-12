<?php
/**
 * OmniPrivacy Pro — Deletion Request
 *
 * Gestion des demandes de suppression des données utilisateur.
 * Inclut le workflow admin d'approbation/rejet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Deletion_Request {

	/**
	 * Handler AJAX pour la soumission de demande de suppression depuis le portail visiteur.
	 */
	public function ajax_submit_deletion() {
		check_ajax_referer( 'omniprivacy_portal_nonce', 'nonce' );

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Adresse email invalide.', 'omniprivacy-pro' ) ) );
		}

		$raw_items = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();
		if ( ! is_array( $raw_items ) || empty( $raw_items ) ) {
			wp_send_json_error( array( 'message' => __( 'Aucun élément sélectionné.', 'omniprivacy-pro' ) ) );
		}

		// Décoder les items JSON envoyés depuis le formulaire.
		$items = array();
		foreach ( $raw_items as $raw ) {
			$decoded = json_decode( sanitize_text_field( $raw ), true );
			if ( is_array( $decoded ) && ! empty( $decoded['type'] ) && ! empty( $decoded['id'] ) ) {
				$items[] = array(
					'type' => sanitize_text_field( $decoded['type'] ),
					'id'   => absint( $decoded['id'] ),
				);
			}
		}

		if ( empty( $items ) ) {
			wp_send_json_error( array( 'message' => __( 'Éléments invalides.', 'omniprivacy-pro' ) ) );
		}

		$request_id = $this->submit_request( $email, $items );

		if ( false === $request_id ) {
			wp_send_json_error( array( 'message' => __( 'Impossible de soumettre la demande.', 'omniprivacy-pro' ) ) );
		}

		wp_send_json_success( array(
			'message'    => __( 'Votre demande a été soumise. Vous recevrez un email de confirmation.', 'omniprivacy-pro' ),
			'request_id' => $request_id,
		) );
	}

	/**
	 * Soumet une nouvelle demande de suppression (depuis le portail visiteur).
	 *
	 * @param string $email Adresse email du demandeur.
	 * @param array  $items Éléments sélectionnés pour la suppression.
	 * @return int|false ID de la demande ou false.
	 */
	public function submit_request( $email, $items ) {
		global $wpdb;

		// Filtrer les éléments protégés (bouclier légal).
		$items = $this->filter_locked_items( $items );

		if ( empty( $items ) ) {
			return false;
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'omniprivacy_deletion_requests',
			array(
				'user_email' => sanitize_email( $email ),
				'items_json' => wp_json_encode( $items ),
				'status'     => 'pending',
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return false;
		}

		$request_id = $wpdb->insert_id;

		// Notifier l'admin.
		$this->notify_admin( $request_id, $email );

		return $request_id;
	}

	/**
	 * Approuve une demande de suppression (AJAX admin).
	 */
	public function ajax_approve() {
		check_ajax_referer( 'omniprivacy_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_omniprivacy' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès non autorisé.', 'omniprivacy-pro' ) ) );
		}

		$request_id = absint( $_POST['request_id'] ?? 0 );
		if ( ! $request_id ) {
			wp_send_json_error( array( 'message' => __( 'ID invalide.', 'omniprivacy-pro' ) ) );
		}

		global $wpdb;

		$request = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}omniprivacy_deletion_requests WHERE id = %d AND status = 'pending'",
				$request_id
			)
		);

		if ( ! $request ) {
			wp_send_json_error( array( 'message' => __( 'Demande non trouvée.', 'omniprivacy-pro' ) ) );
		}

		// Exécuter la suppression.
		$items   = json_decode( $request->items_json, true );
		$deleted = $this->execute_deletion( $items );

		// Mettre à jour le statut.
		$wpdb->update(
			$wpdb->prefix . 'omniprivacy_deletion_requests',
			array(
				'status'       => 'approved',
				'processed_at' => current_time( 'mysql' ),
				'processed_by' => get_current_user_id(),
			),
			array( 'id' => $request_id ),
			array( '%s', '%s', '%d' ),
			array( '%d' )
		);

		// Générer et envoyer le certificat d'effacement.
		$certificate = new OmniPrivacy_Erasure_Certificate();
		$certificate->send( $request->user_email, $request_id, $deleted );

		// Log dans l'audit.
		$this->log_audit( 'approved', $request_id, $request->user_email );

		wp_send_json_success( array( 'message' => __( 'Demande approuvée. Données supprimées.', 'omniprivacy-pro' ) ) );
	}

	/**
	 * Rejette une demande de suppression (AJAX admin).
	 */
	public function ajax_reject() {
		check_ajax_referer( 'omniprivacy_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_omniprivacy' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès non autorisé.', 'omniprivacy-pro' ) ) );
		}

		$request_id = absint( $_POST['request_id'] ?? 0 );
		$note       = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';

		if ( ! $request_id ) {
			wp_send_json_error( array( 'message' => __( 'ID invalide.', 'omniprivacy-pro' ) ) );
		}

		if ( empty( $note ) ) {
			wp_send_json_error( array( 'message' => __( 'Un motif de rejet est obligatoire.', 'omniprivacy-pro' ) ) );
		}

		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'omniprivacy_deletion_requests',
			array(
				'status'       => 'rejected',
				'admin_note'   => $note,
				'processed_at' => current_time( 'mysql' ),
				'processed_by' => get_current_user_id(),
			),
			array( 'id' => $request_id ),
			array( '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);

		$this->log_audit( 'rejected', $request_id, '' );

		wp_send_json_success( array( 'message' => __( 'Demande rejetée.', 'omniprivacy-pro' ) ) );
	}

	/**
	 * Filtre les éléments protégés par le bouclier légal.
	 * Délègue au Legal Shield pour une évaluation centralisée.
	 *
	 * @param array $items Éléments demandés.
	 * @return array Éléments filtrés.
	 */
	private function filter_locked_items( $items ) {
		$legal_shield = new OmniPrivacy_Legal_Shield();
		return $legal_shield->filter_deletion_items( $items );
	}

	/**
	 * Exécute la suppression effective des données.
	 *
	 * @param array $items Éléments à supprimer.
	 * @return array Éléments effectivement supprimés.
	 */
	private function execute_deletion( $items ) {
		$deleted = array();

		// Charger les handlers d'extension (WooCommerce, CF7, WPForms, etc.).
		$ext_handlers = apply_filters( 'omniprivacy_deletion_handlers', array() );

		foreach ( $items as $item ) {
			$type = $item['type'] ?? '';
			$id   = absint( $item['id'] ?? 0 );

			if ( ! $id ) {
				continue;
			}

			$success = false;

			switch ( $type ) {
				case 'comment':
					$success = wp_delete_comment( $id, true );
					break;

				case 'account':
					if ( function_exists( 'wp_delete_user' ) ) {
						require_once ABSPATH . 'wp-admin/includes/user.php';
						wp_delete_user( $id );
						$success = true;
					}
					break;

				case 'scan_result':
					global $wpdb;
					$wpdb->update(
						$wpdb->prefix . 'omniprivacy_scan_results',
						array( 'status' => 'anonymized', 'matched_value' => __( '[Supprimé]', 'omniprivacy-pro' ) ),
						array( 'id' => $id ),
						array( '%s', '%s' ),
						array( '%d' )
					);
					$success = true;
					break;

				default:
					// Déléguer aux handlers d'extension.
					if ( isset( $ext_handlers[ $type ] ) && is_callable( $ext_handlers[ $type ] ) ) {
						$success = call_user_func( $ext_handlers[ $type ], $id );
					}
					break;
			}

			if ( $success ) {
				$deleted[] = $item;
			}
		}

		return $deleted;
	}

	/**
	 * Notifie l'administrateur d'une nouvelle demande.
	 *
	 * @param int    $request_id ID de la demande.
	 * @param string $email      Email du demandeur.
	 */
	private function notify_admin( $request_id, $email ) {
		$admin_email = get_option( 'admin_email' );
		$subject     = sprintf(
			/* translators: %d: ID de la demande */
			__( '[OmniPrivacy] Nouvelle demande de suppression #%d', 'omniprivacy-pro' ),
			$request_id
		);

		$admin_url = admin_url( 'admin.php?page=omniprivacy-requests' );
		$message   = sprintf(
			/* translators: 1: email, 2: URL admin */
			__( "Une nouvelle demande de suppression a été soumise par %1\$s.\n\nGérer les demandes : %2\$s", 'omniprivacy-pro' ),
			$email,
			$admin_url
		);

		wp_mail( $admin_email, $subject, $message );
	}

	/**
	 * Log dans le journal d'audit.
	 */
	private function log_audit( $action, $request_id, $email ) {
		$current_user = wp_get_current_user();
		$actor        = $current_user->ID > 0 ? $current_user->user_login : 'visitor';

		OmniPrivacy_Audit_Logger::log(
			'deletion_request_' . $action,
			$actor,
			'deletion_request',
			$request_id,
			array(
				'request_id' => $request_id,
				'email'      => $email,
			)
		);
	}
}
