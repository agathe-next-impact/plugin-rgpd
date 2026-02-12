<?php
/**
 * Plugin Name:       OmniPrivacy Pro
 * Plugin URI:        https://example.com/omniprivacy-pro
 * Description:       Système de gouvernance des données RGPD pour WordPress. Automatise le nettoyage, facilite l'audit PII et offre un portail de transparence aux visiteurs.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            OmniPrivacy
 * Author URI:        https://example.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       omniprivacy-pro
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OMNIPRIVACY_VERSION', '1.0.0' );
define( 'OMNIPRIVACY_PLUGIN_FILE', __FILE__ );
define( 'OMNIPRIVACY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OMNIPRIVACY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OMNIPRIVACY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Activation hook.
 */
function omniprivacy_activate() {
	require_once OMNIPRIVACY_PLUGIN_DIR . 'includes/class-omniprivacy-activator.php';
	OmniPrivacy_Activator::activate();
}
register_activation_hook( __FILE__, 'omniprivacy_activate' );

/**
 * Deactivation hook.
 */
function omniprivacy_deactivate() {
	require_once OMNIPRIVACY_PLUGIN_DIR . 'includes/class-omniprivacy-deactivator.php';
	OmniPrivacy_Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'omniprivacy_deactivate' );

/**
 * Load plugin textdomain.
 */
function omniprivacy_load_textdomain() {
	load_plugin_textdomain( 'omniprivacy-pro', false, dirname( OMNIPRIVACY_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'plugins_loaded', 'omniprivacy_load_textdomain' );

/**
 * Verify requirements and bootstrap the plugin.
 */
function omniprivacy_init() {
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		add_action( 'admin_notices', 'omniprivacy_php_notice' );
		return;
	}

	if ( ! extension_loaded( 'openssl' ) ) {
		add_action( 'admin_notices', 'omniprivacy_openssl_notice' );
		return;
	}

	require_once OMNIPRIVACY_PLUGIN_DIR . 'includes/class-omniprivacy-loader.php';
	$loader = new OmniPrivacy_Loader();
	$loader->run();
}
add_action( 'plugins_loaded', 'omniprivacy_init', 20 );

/**
 * Admin notice for PHP version requirement.
 */
function omniprivacy_php_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'OmniPrivacy Pro nécessite PHP 7.4 ou supérieur.', 'omniprivacy-pro' );
	echo '</p></div>';
}

/**
 * Admin notice for OpenSSL requirement.
 */
function omniprivacy_openssl_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'OmniPrivacy Pro nécessite l\'extension OpenSSL pour le chiffrement des données.', 'omniprivacy-pro' );
	echo '</p></div>';
}
