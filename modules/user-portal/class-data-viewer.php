<?php
/**
 * OmniPrivacy Pro — Data Viewer
 *
 * Affiche les données personnelles associées à un email
 * dans le portail de transparence visiteur.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Data_Viewer {

	/**
	 * Récupère toutes les données associées à un email.
	 *
	 * @param string $email Adresse email du visiteur.
	 * @return array Données regroupées par catégorie.
	 */
	public function get_user_data( $email ) {
		$data = array(
			'comments'     => $this->get_comments( $email ),
			'account'      => $this->get_account( $email ),
			'scan_results' => $this->get_scan_results( $email ),
		);

		// WooCommerce.
		if ( class_exists( 'WooCommerce' ) ) {
			$data['orders'] = $this->get_woocommerce_orders( $email );
		}

		return apply_filters( 'omniprivacy_user_data', $data, $email );
	}

	/**
	 * Récupère les commentaires d'un email.
	 *
	 * @param string $email Adresse email.
	 * @return array Commentaires.
	 */
	private function get_comments( $email ) {
		$comments = get_comments( array(
			'author_email' => $email,
			'status'       => 'all',
			'number'       => 100,
		) );

		$result = array();
		foreach ( $comments as $comment ) {
			$result[] = array(
				'id'      => $comment->comment_ID,
				'date'    => $comment->comment_date,
				'content' => wp_trim_words( $comment->comment_content, 20 ),
				'post'    => get_the_title( $comment->comment_post_ID ),
				'type'    => 'comment',
			);
		}

		return $result;
	}

	/**
	 * Récupère les informations de compte WordPress.
	 *
	 * @param string $email Adresse email.
	 * @return array|null Données de compte ou null.
	 */
	private function get_account( $email ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return null;
		}

		return array(
			'id'           => $user->ID,
			'display_name' => $user->display_name,
			'registered'   => $user->user_registered,
			'type'         => 'account',
		);
	}

	/**
	 * Récupère les résultats de scan PII associés à un email.
	 *
	 * @param string $email Adresse email.
	 * @return array Résultats.
	 */
	private function get_scan_results( $email ) {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_type, source_id, field_name, pattern_type
				FROM {$wpdb->prefix}omniprivacy_scan_results
				WHERE matched_value = %s AND status = 'active'",
				$email
			)
		);

		$formatted = array();
		foreach ( $results as $result ) {
			$formatted[] = array(
				'id'           => $result->id,
				'source_type'  => $result->source_type,
				'source_id'    => $result->source_id,
				'field_name'   => $result->field_name,
				'pattern_type' => $result->pattern_type,
				'type'         => 'scan_result',
			);
		}

		return $formatted;
	}

	/**
	 * Récupère les commandes WooCommerce.
	 *
	 * @param string $email Adresse email.
	 * @return array Commandes (sans montants).
	 */
	private function get_woocommerce_orders( $email ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$legal_shield = new OmniPrivacy_Legal_Shield();

		$orders = wc_get_orders( array(
			'billing_email' => $email,
			'limit'         => 100,
		) );

		$result = array();
		foreach ( $orders as $order ) {
			$order_date = $order->get_date_created();
			$protection = $legal_shield->is_protected( 'order', $order->get_id() );

			$item = array(
				'id'      => $order->get_id(),
				'number'  => $order->get_order_number(),
				'date'    => $order_date ? $order_date->date( 'Y-m-d' ) : '',
				'status'  => wc_get_order_status_name( $order->get_status() ),
				'locked'  => false !== $protection,
				'type'    => 'order',
			);

			if ( false !== $protection ) {
				$item['lock_reason'] = $protection['reason'];
				$item['lock_until']  = $protection['lock_until'];
			}

			$result[] = $item;
		}

		return $result;
	}
}
