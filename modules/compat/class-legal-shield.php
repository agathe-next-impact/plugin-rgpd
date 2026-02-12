<?php
/**
 * OmniPrivacy Pro — Legal Shield (Bouclier Légal)
 *
 * Gestion centralisée des règles de rétention légale.
 * Protège les données soumises à des obligations fiscales/légales
 * contre la suppression prématurée.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Legal_Shield {

	/**
	 * Règles de rétention par défaut.
	 *
	 * @var array
	 */
	private $rules = array();

	public function __construct() {
		$this->rules = $this->get_default_rules();
		$this->rules = apply_filters( 'omniprivacy_legal_shield_rules', $this->rules );
	}

	/**
	 * Initialise les hooks du bouclier légal.
	 */
	public function register_hooks() {
		add_filter( 'omniprivacy_user_data', array( $this, 'mark_locked_items' ), 20, 2 );
	}

	/**
	 * Retourne les règles de rétention par défaut.
	 *
	 * @return array
	 */
	private function get_default_rules() {
		return array(
			'order' => array(
				'retention_years' => 10,
				'reason'          => __( 'Obligation fiscale (art. L123-22 du Code de commerce)', 'omniprivacy-pro' ),
				'date_callback'   => array( $this, 'get_order_date' ),
			),
		);
	}

	/**
	 * Vérifie si un élément est protégé par une règle de rétention.
	 *
	 * @param string $type Type de l'élément.
	 * @param int    $id   ID de l'élément.
	 * @return array|false Détails de la protection ou false.
	 */
	public function is_protected( $type, $id ) {
		if ( ! isset( $this->rules[ $type ] ) ) {
			return false;
		}

		$rule = $this->rules[ $type ];

		// Récupérer la date de l'élément.
		$date = null;
		if ( isset( $rule['date_callback'] ) && is_callable( $rule['date_callback'] ) ) {
			$date = call_user_func( $rule['date_callback'], $id );
		}

		if ( ! $date ) {
			// Si pas de date, on protège par précaution.
			return array(
				'locked'    => true,
				'reason'    => $rule['reason'],
				'lock_until' => __( 'Date inconnue', 'omniprivacy-pro' ),
			);
		}

		$retention_seconds = $rule['retention_years'] * YEAR_IN_SECONDS;
		$lock_until        = $date + $retention_seconds;

		if ( time() < $lock_until ) {
			return array(
				'locked'     => true,
				'reason'     => $rule['reason'],
				'lock_until' => wp_date( 'Y-m-d', $lock_until ),
			);
		}

		return false;
	}

	/**
	 * Filtre les données utilisateur pour marquer les éléments protégés.
	 *
	 * @param array  $data  Données utilisateur.
	 * @param string $email Email.
	 * @return array
	 */
	public function mark_locked_items( $data, $email ) {
		// Marquer les commandes WooCommerce.
		if ( ! empty( $data['orders'] ) && is_array( $data['orders'] ) ) {
			foreach ( $data['orders'] as $key => $order ) {
				$protection = $this->is_protected( 'order', $order['id'] );
				if ( $protection ) {
					$data['orders'][ $key ]['locked']     = true;
					$data['orders'][ $key ]['lock_reason'] = $protection['reason'];
					$data['orders'][ $key ]['lock_until']  = $protection['lock_until'];
				}
			}
		}

		return $data;
	}

	/**
	 * Filtre les items d'une demande de suppression en retirant les protégés.
	 *
	 * @param array $items Items à supprimer.
	 * @return array Items filtrés.
	 */
	public function filter_deletion_items( $items ) {
		return array_values( array_filter( $items, function ( $item ) {
			$type = $item['type'] ?? '';
			$id   = absint( $item['id'] ?? 0 );

			if ( ! $id ) {
				return true;
			}

			$protection = $this->is_protected( $type, $id );
			return false === $protection;
		} ) );
	}

	/**
	 * Récupère la date de création d'une commande WooCommerce.
	 *
	 * @param int $order_id ID de la commande.
	 * @return int|null Timestamp ou null.
	 */
	public function get_order_date( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}

		$date = $order->get_date_created();
		return $date ? $date->getTimestamp() : null;
	}
}
