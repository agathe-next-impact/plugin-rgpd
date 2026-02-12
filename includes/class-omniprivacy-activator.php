<?php
/**
 * OmniPrivacy Pro — Activator
 *
 * Crée les tables personnalisées et configure les capabilities à l'activation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Activator {

	/**
	 * Exécuté à l'activation du plugin.
	 */
	public static function activate() {
		self::check_requirements();
		self::create_tables();
		self::add_capabilities();
		self::set_default_options();

		update_option( 'omniprivacy_version', OMNIPRIVACY_VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Vérifie les pré-requis système.
	 */
	private static function check_requirements() {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			deactivate_plugins( OMNIPRIVACY_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'OmniPrivacy Pro nécessite PHP 7.4 ou supérieur.', 'omniprivacy-pro' ),
				'Plugin Activation Error',
				array( 'back_link' => true )
			);
		}

		if ( ! extension_loaded( 'openssl' ) ) {
			deactivate_plugins( OMNIPRIVACY_PLUGIN_BASENAME );
			wp_die(
				esc_html__( 'OmniPrivacy Pro nécessite l\'extension OpenSSL.', 'omniprivacy-pro' ),
				'Plugin Activation Error',
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Crée les tables personnalisées via dbDelta.
	 */
	private static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Résultats de scan PII.
		$sql = "CREATE TABLE {$wpdb->prefix}omniprivacy_scan_results (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_type varchar(50) NOT NULL,
			source_id bigint(20) unsigned NOT NULL,
			field_name varchar(100) NOT NULL,
			matched_value text NOT NULL,
			pattern_type varchar(50) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			scan_date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY source_idx (source_type, source_id),
			KEY status_idx (status)
		) $charset_collate;";
		dbDelta( $sql );

		// Demandes de suppression.
		$sql = "CREATE TABLE {$wpdb->prefix}omniprivacy_deletion_requests (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_email varchar(255) NOT NULL,
			items_json longtext NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			admin_note text,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			processed_at datetime DEFAULT NULL,
			processed_by bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY (id),
			KEY email_idx (user_email(191)),
			KEY status_idx (status)
		) $charset_collate;";
		dbDelta( $sql );

		// Tokens magic link.
		$sql = "CREATE TABLE {$wpdb->prefix}omniprivacy_magic_tokens (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash varchar(64) NOT NULL,
			email varchar(255) NOT NULL,
			expires_at datetime NOT NULL,
			used tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY token_hash_idx (token_hash),
			KEY email_idx (email(191)),
			KEY expires_idx (expires_at)
		) $charset_collate;";
		dbDelta( $sql );

		// Journal des consentements (chiffré, immutable).
		$sql = "CREATE TABLE {$wpdb->prefix}omniprivacy_consent_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ip_hash varchar(64) NOT NULL,
			action varchar(20) NOT NULL,
			categories_json text NOT NULL,
			user_agent_hash varchar(64) NOT NULL,
			payload_encrypted text NOT NULL,
			prev_hash varchar(64) NOT NULL DEFAULT '',
			entry_hash varchar(64) NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY created_idx (created_at)
		) $charset_collate;";
		dbDelta( $sql );

		// Journal d'audit.
		$sql = "CREATE TABLE {$wpdb->prefix}omniprivacy_audit_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			action varchar(100) NOT NULL,
			actor varchar(255) NOT NULL,
			target_type varchar(50) DEFAULT NULL,
			target_id bigint(20) unsigned DEFAULT NULL,
			details_encrypted text,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY action_idx (action),
			KEY created_idx (created_at)
		) $charset_collate;";
		dbDelta( $sql );

		// Éléments ignorés.
		$sql = "CREATE TABLE {$wpdb->prefix}omniprivacy_ignored_items (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			item_type varchar(50) NOT NULL,
			item_id bigint(20) unsigned NOT NULL,
			field_name varchar(100) NOT NULL,
			marked_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY item_unique_idx (item_type, item_id, field_name)
		) $charset_collate;";
		dbDelta( $sql );
	}

	/**
	 * Ajoute la capability manage_omniprivacy au rôle administrateur.
	 */
	private static function add_capabilities() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( 'manage_omniprivacy' );
		}
	}

	/**
	 * Définit les options par défaut.
	 */
	private static function set_default_options() {
		$defaults = array(
			'omniprivacy_retention_months'    => 36,
			'omniprivacy_excluded_roles'      => array( 'administrator' ),
			'omniprivacy_exif_strip_enabled'  => 1,
			'omniprivacy_log_rotation_enabled' => 1,
			'omniprivacy_log_retention_days'  => 30,
			'omniprivacy_scan_batch_size'     => 100,
			'omniprivacy_portal_page_id'     => 0,
			'omniprivacy_magic_link_expiry'   => 3600,
			'omniprivacy_magic_link_rate_limit' => 3,
		);

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( $key ) ) {
				add_option( $key, $value );
			}
		}
	}
}
