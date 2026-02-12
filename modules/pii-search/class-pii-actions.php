<?php
/**
 * OmniPrivacy Pro — PII Actions
 *
 * Gestion des actions sur les résultats de scan : anonymiser, ignorer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_PII_Actions {

	/**
	 * Anonymise un élément détecté via AJAX.
	 */
	public function ajax_anonymize() {
		check_ajax_referer( 'omniprivacy_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_omniprivacy' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès non autorisé.', 'omniprivacy-pro' ) ) );
		}

		$item_id = absint( $_POST['item_id'] ?? 0 );
		if ( ! $item_id ) {
			wp_send_json_error( array( 'message' => __( 'ID invalide.', 'omniprivacy-pro' ) ) );
		}

		global $wpdb;

		$item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}omniprivacy_scan_results WHERE id = %d AND status = 'active'",
				$item_id
			)
		);

		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'Élément non trouvé.', 'omniprivacy-pro' ) ) );
		}

		// Remplacer la valeur dans la source.
		$censored = __( '[Censuré]', 'omniprivacy-pro' );
		$replaced = $this->replace_in_source( $item, $censored );

		if ( ! $replaced ) {
			wp_send_json_error( array( 'message' => __( 'Impossible de remplacer la valeur.', 'omniprivacy-pro' ) ) );
		}

		// Mettre à jour le statut du résultat.
		$wpdb->update(
			$wpdb->prefix . 'omniprivacy_scan_results',
			array( 'status' => 'anonymized' ),
			array( 'id' => $item_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Log dans l'audit.
		$this->log_audit( 'anonymize', $item );

		wp_send_json_success( array( 'message' => __( 'Élément anonymisé.', 'omniprivacy-pro' ) ) );
	}

	/**
	 * Marque un élément comme ignoré via AJAX.
	 */
	public function ajax_ignore() {
		check_ajax_referer( 'omniprivacy_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_omniprivacy' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès non autorisé.', 'omniprivacy-pro' ) ) );
		}

		$item_id = absint( $_POST['item_id'] ?? 0 );
		if ( ! $item_id ) {
			wp_send_json_error( array( 'message' => __( 'ID invalide.', 'omniprivacy-pro' ) ) );
		}

		global $wpdb;

		$item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}omniprivacy_scan_results WHERE id = %d AND status = 'active'",
				$item_id
			)
		);

		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'Élément non trouvé.', 'omniprivacy-pro' ) ) );
		}

		// Marquer comme ignoré.
		$wpdb->update(
			$wpdb->prefix . 'omniprivacy_scan_results',
			array( 'status' => 'ignored' ),
			array( 'id' => $item_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Ajouter aux éléments ignorés pour les futurs scans.
		$wpdb->replace(
			$wpdb->prefix . 'omniprivacy_ignored_items',
			array(
				'item_type'  => $item->source_type,
				'item_id'    => $item->source_id,
				'field_name' => $item->field_name,
				'marked_by'  => get_current_user_id(),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%s', '%d', '%s' )
		);

		wp_send_json_success( array( 'message' => __( 'Élément ignoré.', 'omniprivacy-pro' ) ) );
	}

	/**
	 * Remplace la valeur PII dans la source originale.
	 *
	 * @param object $item     Résultat de scan.
	 * @param string $replacement Texte de remplacement.
	 * @return bool Succès.
	 */
	private function replace_in_source( $item, $replacement ) {
		global $wpdb;

		// Whitelist stricte des colonnes autorisées par type de source (anti-injection de colonne).
		$allowed_fields = array(
			'post'    => array( 'post_title', 'post_content', 'post_excerpt' ),
			'comment' => array( 'comment_author', 'comment_author_email', 'comment_content' ),
		);

		switch ( $item->source_type ) {
			case 'post':
				$post = get_post( $item->source_id );
				if ( ! $post ) {
					return false;
				}
				$field = $item->field_name;
				if ( ! in_array( $field, $allowed_fields['post'], true ) ) {
					return false;
				}
				$current = $post->$field ?? '';
				$updated = str_replace( $item->matched_value, $replacement, $current );
				return (bool) $wpdb->update(
					$wpdb->posts,
					array( $field => $updated ),
					array( 'ID' => $item->source_id ),
					array( '%s' ),
					array( '%d' )
				);

			case 'postmeta':
				$current = get_post_meta( $item->source_id, $item->field_name, true );
				if ( is_string( $current ) ) {
					$updated = str_replace( $item->matched_value, $replacement, $current );
					return update_post_meta( $item->source_id, $item->field_name, $updated );
				}
				return false;

			case 'comment':
				$comment = get_comment( $item->source_id );
				if ( ! $comment ) {
					return false;
				}
				$field = $item->field_name;
				if ( ! in_array( $field, $allowed_fields['comment'], true ) ) {
					return false;
				}
				$current = $comment->$field ?? '';
				$updated = str_replace( $item->matched_value, $replacement, $current );
				return (bool) $wpdb->update(
					$wpdb->comments,
					array( $field => $updated ),
					array( 'comment_ID' => $item->source_id ),
					array( '%s' ),
					array( '%d' )
				);

			case 'media':
				if ( 'alt_text' === $item->field_name ) {
					$current = get_post_meta( $item->source_id, '_wp_attachment_image_alt', true );
					$updated = str_replace( $item->matched_value, $replacement, $current );
					return update_post_meta( $item->source_id, '_wp_attachment_image_alt', $updated );
				}
				$field_map = array( 'title' => 'post_title', 'caption' => 'post_excerpt' );
				$db_field  = $field_map[ $item->field_name ] ?? '';
				if ( $db_field ) {
					$post    = get_post( $item->source_id );
					$current = $post->$db_field ?? '';
					$updated = str_replace( $item->matched_value, $replacement, $current );
					return (bool) $wpdb->update(
						$wpdb->posts,
						array( $db_field => $updated ),
						array( 'ID' => $item->source_id ),
						array( '%s' ),
						array( '%d' )
					);
				}
				return false;

			default:
				return false;
		}
	}

	/**
	 * Log l'action dans le journal d'audit.
	 */
	private function log_audit( $action, $item ) {
		OmniPrivacy_Audit_Logger::log_user(
			'pii_' . $action,
			$item->source_type,
			$item->source_id,
			array(
				'action'       => $action,
				'field_name'   => $item->field_name,
				'pattern_type' => $item->pattern_type,
			)
		);
	}
}
