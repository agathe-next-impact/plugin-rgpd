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
	 *
	 * @param array $items Éléments demandés.
	 * @return array Éléments filtrés.
	 */
	private function filter_locked_items( $items ) {
		return array_filter( $items, function ( $item ) {
			if ( 'order' === ( $item['type'] ?? '' ) && ! empty( $item['locked'] ) ) {
				return false;
			}
			return true;
		} );
	}

	/**
	 * Exécute la suppression effective des données.
	 *
	 * @param array $items Éléments à supprimer.
	 * @return array Éléments effectivement supprimés.
	 */
	private function execute_deletion( $items ) {
		$deleted = array();

		foreach ( $items as $item ) {
			$type = $item['type'] ?? '';
			$id   = absint( $item['id'] ?? 0 );

			if ( ! $id ) {
				continue;
			}

			switch ( $type ) {
				case 'comment':
					if ( wp_delete_comment( $id, true ) ) {
						$deleted[] = $item;
					}
					break;

				case 'account':
					if ( function_exists( 'wp_delete_user' ) ) {
						require_once ABSPATH . 'wp-admin/includes/user.php';
						wp_delete_user( $id );
						$deleted[] = $item;
					}
					break;

				case 'scan_result':
					// Anonymiser le résultat PII.
					$actions = new OmniPrivacy_PII_Actions();
					global $wpdb;
					$wpdb->update(
						$wpdb->prefix . 'omniprivacy_scan_results',
						array( 'status' => 'anonymized', 'matched_value' => __( '[Supprimé]', 'omniprivacy-pro' ) ),
						array( 'id' => $id ),
						array( '%s', '%s' ),
						array( '%d' )
					);
					$deleted[] = $item;
					break;
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
		global $wpdb;

		$details = wp_json_encode( array(
			'request_id' => $request_id,
			'email'      => $email,
			'action'     => $action,
		) );

		$wpdb->insert(
			$wpdb->prefix . 'omniprivacy_audit_log',
			array(
				'action'            => 'deletion_request_' . $action,
				'actor'             => wp_get_current_user()->user_login ?: 'visitor',
				'target_type'       => 'deletion_request',
				'target_id'         => $request_id,
				'details_encrypted' => OmniPrivacy_Encryption::encrypt( $details ),
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}
}
