<?php
/**
 * OmniPrivacy Pro — WooCommerce Compatibility
 *
 * Scan des tables HPOS (wc_orders, wc_order_addresses),
 * récupération et suppression des commandes pour le portail visiteur.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_WooCommerce_Scanner {

	use OmniPrivacy_PII_Field_Scanner;

	/**
	 * Initialise les hooks de compatibilité WooCommerce.
	 */
	public function register_hooks() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'omniprivacy_pii_scan_steps', array( $this, 'add_scan_steps' ) );
		add_filter( 'omniprivacy_pii_scan_total', array( $this, 'add_scan_total' ) );
		add_filter( 'omniprivacy_deletion_handlers', array( $this, 'register_deletion_handler' ) );
	}

	/**
	 * Ajoute les étapes de scan WooCommerce.
	 *
	 * @param array $steps Étapes existantes.
	 * @return array
	 */
	public function add_scan_steps( $steps ) {
		$steps['wc_orders']    = array( $this, 'scan_orders' );
		$steps['wc_addresses'] = array( $this, 'scan_order_addresses' );
		return $steps;
	}

	/**
	 * Ajoute le nombre d'éléments WooCommerce au total du scan.
	 *
	 * @param int $total Total courant.
	 * @return int
	 */
	public function add_scan_total( $total ) {
		global $wpdb;

		if ( $this->has_hpos_tables() ) {
			$orders    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table count.
			$addresses = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_addresses" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static table count.
			$total    += $orders + $addresses;
		} else {
			// Fallback CPT : les commandes sont déjà comptées via wp_posts/wp_postmeta.
			$total += 0;
		}

		return $total;
	}

	/**
	 * Scanne la table wc_orders (HPOS) pour détecter des PII.
	 *
	 * @param int   $offset     Décalage.
	 * @param int   $batch_size Taille du lot.
	 * @param array $patterns   Patterns regex.
	 * @return bool True s'il reste des éléments à traiter.
	 */
	public function scan_orders( $offset, $batch_size, $patterns ) {
		if ( ! $this->has_hpos_tables() ) {
			return false;
		}

		global $wpdb;

		$orders = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, billing_email, ip_address, customer_note
				FROM {$wpdb->prefix}wc_orders
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			)
		);

		foreach ( $orders as $order ) {
			$this->scan_field( $order->billing_email, 'wc_order', $order->id, 'billing_email', $patterns );
			$this->scan_field( $order->ip_address, 'wc_order', $order->id, 'ip_address', $patterns );
			$this->scan_field( $order->customer_note, 'wc_order', $order->id, 'customer_note', $patterns );
		}

		return count( $orders ) >= $batch_size;
	}

	/**
	 * Scanne la table wc_order_addresses (HPOS) pour détecter des PII.
	 *
	 * @param int   $offset     Décalage.
	 * @param int   $batch_size Taille du lot.
	 * @param array $patterns   Patterns regex.
	 * @return bool True s'il reste des éléments à traiter.
	 */
	public function scan_order_addresses( $offset, $batch_size, $patterns ) {
		if ( ! $this->has_hpos_tables() ) {
			return false;
		}

		global $wpdb;

		$addresses = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, order_id, address_type, first_name, last_name, email, phone, address_1, address_2, city, postcode
				FROM {$wpdb->prefix}wc_order_addresses
				ORDER BY id ASC
				LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			)
		);

		$fields = array( 'first_name', 'last_name', 'email', 'phone', 'address_1', 'address_2', 'city', 'postcode' );

		foreach ( $addresses as $addr ) {
			foreach ( $fields as $field ) {
				if ( ! empty( $addr->$field ) ) {
					$source_field = $addr->address_type . '_' . $field;
					$this->scan_field( $addr->$field, 'wc_address', $addr->order_id, $source_field, $patterns );
				}
			}
		}

		return count( $addresses ) >= $batch_size;
	}

	/**
	 * Enregistre le handler de suppression pour les commandes WooCommerce.
	 *
	 * @param array $handlers Handlers existants.
	 * @return array
	 */
	public function register_deletion_handler( $handlers ) {
		$handlers['order'] = array( $this, 'delete_order' );
		return $handlers;
	}

	/**
	 * Supprime (anonymise) une commande WooCommerce.
	 *
	 * @param int $order_id ID de la commande.
	 * @return bool Succès.
	 */
	public function delete_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// Vérifier le bouclier légal.
		$order_date = $order->get_date_created();
		if ( $order_date && $order_date->getTimestamp() > strtotime( '-10 years' ) ) {
			return false;
		}

		// Anonymiser les données personnelles de la commande.
		$anonymized = __( '[Données supprimées]', 'omniprivacy-pro' );

		$order->set_billing_first_name( $anonymized );
		$order->set_billing_last_name( '' );
		$order->set_billing_email( 'anonymized@anonymized.local' );
		$order->set_billing_phone( '' );
		$order->set_billing_address_1( $anonymized );
		$order->set_billing_address_2( '' );
		$order->set_billing_city( '' );
		$order->set_billing_postcode( '' );

		$order->set_shipping_first_name( $anonymized );
		$order->set_shipping_last_name( '' );
		$order->set_shipping_address_1( '' );
		$order->set_shipping_address_2( '' );
		$order->set_shipping_city( '' );
		$order->set_shipping_postcode( '' );

		$order->set_customer_ip_address( '0.0.0.xxx' );
		$order->set_customer_note( '' );

		$order->save();

		OmniPrivacy_Audit_Logger::log_system(
			'woocommerce_order_anonymized',
			'order',
			array( 'order_id' => $order_id )
		);

		return true;
	}

	/**
	 * Vérifie si les tables HPOS WooCommerce existent.
	 *
	 * @return bool
	 */
	private function has_hpos_tables() {
		global $wpdb;

		static $has_tables = null;
		if ( null !== $has_tables ) {
			return $has_tables;
		}

		$table = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$wpdb->prefix . 'wc_orders'
			)
		);

		$has_tables = ! empty( $table );
		return $has_tables;
	}

}
