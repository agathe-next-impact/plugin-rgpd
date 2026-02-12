<?php
/**
 * OmniPrivacy Pro — Loader
 *
 * Orchestrateur principal : charge les modules et enregistre les hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Loader {

	/**
	 * Registre des actions WordPress.
	 *
	 * @var array
	 */
	private $actions = array();

	/**
	 * Registre des filtres WordPress.
	 *
	 * @var array
	 */
	private $filters = array();

	/**
	 * Initialise le loader et charge les dépendances.
	 */
	public function __construct() {
		$this->load_dependencies();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_module_hooks();
	}

	/**
	 * Charge les fichiers nécessaires.
	 */
	private function load_dependencies() {
		require_once OMNIPRIVACY_PLUGIN_DIR . 'includes/class-omniprivacy-encryption.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'includes/class-omniprivacy-audit-logger.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'includes/class-omniprivacy-settings.php';

		// Module Data-Clean.
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/data-clean/class-comment-anonymizer.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/data-clean/class-exif-stripper.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/data-clean/class-log-rotator.php';

		// Module PII Search.
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/pii-search/class-pii-scanner.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/pii-search/class-pii-results.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/pii-search/class-pii-actions.php';

		// Module User Portal.
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/user-portal/class-magic-link.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/user-portal/class-data-viewer.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/user-portal/class-deletion-request.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/user-portal/class-portal-shortcode.php';

		// Module Reporting.
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/reporting/class-pdf-generator.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/reporting/class-consent-log.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/reporting/class-erasure-certificate.php';

		// Module Compatibilité (chargé conditionnellement).
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/compat/trait-pii-field-scanner.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/compat/class-legal-shield.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/compat/class-woocommerce-scanner.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/compat/class-cf7-scanner.php';
		require_once OMNIPRIVACY_PLUGIN_DIR . 'modules/compat/class-wpforms-scanner.php';
	}

	/**
	 * Enregistre les hooks d'administration.
	 */
	private function define_admin_hooks() {
		$settings = new OmniPrivacy_Settings();

		$this->add_action( 'admin_menu', $settings, 'add_admin_menu' );
		$this->add_action( 'admin_init', $settings, 'register_settings' );
		$this->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_assets' );
	}

	/**
	 * Enregistre les hooks publics.
	 */
	private function define_public_hooks() {
		$portal = new OmniPrivacy_Portal_Shortcode();

		$this->add_action( 'wp_enqueue_scripts', $this, 'enqueue_public_assets' );
		$this->add_action( 'init', $portal, 'register_shortcode' );
	}

	/**
	 * Enregistre les hooks des modules.
	 */
	private function define_module_hooks() {
		// Data-Clean.
		$anonymizer = new OmniPrivacy_Comment_Anonymizer();
		$exif       = new OmniPrivacy_Exif_Stripper();
		$logs       = new OmniPrivacy_Log_Rotator();

		$this->add_action( 'omniprivacy_anonymize_comments', $anonymizer, 'run' );
		$this->add_filter( 'wp_handle_upload', $exif, 'strip_exif', 10, 1 );
		$this->add_action( 'omniprivacy_rotate_logs', $logs, 'run' );

		// PII Search.
		$scanner = new OmniPrivacy_PII_Scanner();
		$actions = new OmniPrivacy_PII_Actions();

		$this->add_action( 'omniprivacy_pii_scan_batch', $scanner, 'process_batch' );
		$this->add_action( 'wp_ajax_omniprivacy_start_scan', $scanner, 'ajax_start_scan' );
		$this->add_action( 'wp_ajax_omniprivacy_scan_progress', $scanner, 'ajax_scan_progress' );
		$this->add_action( 'wp_ajax_omniprivacy_anonymize_item', $actions, 'ajax_anonymize' );
		$this->add_action( 'wp_ajax_omniprivacy_ignore_item', $actions, 'ajax_ignore' );

		// User Portal.
		$magic_link = new OmniPrivacy_Magic_Link();
		$deletion   = new OmniPrivacy_Deletion_Request();

		$this->add_action( 'wp_ajax_nopriv_omniprivacy_request_magic_link', $magic_link, 'ajax_send_link' );
		$this->add_action( 'wp_ajax_omniprivacy_request_magic_link', $magic_link, 'ajax_send_link' );
		$this->add_action( 'wp_ajax_nopriv_omniprivacy_submit_deletion', $deletion, 'ajax_submit_deletion' );
		$this->add_action( 'wp_ajax_omniprivacy_submit_deletion', $deletion, 'ajax_submit_deletion' );
		$this->add_action( 'wp_ajax_omniprivacy_approve_deletion', $deletion, 'ajax_approve' );
		$this->add_action( 'wp_ajax_omniprivacy_reject_deletion', $deletion, 'ajax_reject' );

		// Reporting.
		$consent = new OmniPrivacy_Consent_Log();
		$this->add_action( 'omniprivacy_consent_recorded', $consent, 'record', 10, 3 );

		// Compatibilité tierce.
		$legal_shield = new OmniPrivacy_Legal_Shield();
		$legal_shield->register_hooks();

		$woo_compat = new OmniPrivacy_WooCommerce_Scanner();
		$woo_compat->register_hooks();

		$cf7_compat = new OmniPrivacy_CF7_Scanner();
		$cf7_compat->register_hooks();

		$wpforms_compat = new OmniPrivacy_WPForms_Scanner();
		$wpforms_compat->register_hooks();

		// Planification des tâches récurrentes.
		$this->add_action( 'init', $this, 'schedule_recurring_tasks' );
	}

	/**
	 * Planifie les tâches récurrentes via Action Scheduler ou WP-Cron.
	 */
	public function schedule_recurring_tasks() {
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( 'omniprivacy_anonymize_comments' ) ) {
				as_schedule_recurring_action( time(), DAY_IN_SECONDS, 'omniprivacy_anonymize_comments' );
			}
			if ( ! as_has_scheduled_action( 'omniprivacy_rotate_logs' ) ) {
				as_schedule_recurring_action( time(), WEEK_IN_SECONDS, 'omniprivacy_rotate_logs' );
			}
		} else {
			if ( ! wp_next_scheduled( 'omniprivacy_anonymize_comments' ) ) {
				wp_schedule_event( time(), 'daily', 'omniprivacy_anonymize_comments' );
			}
			if ( ! wp_next_scheduled( 'omniprivacy_rotate_logs' ) ) {
				wp_schedule_event( time(), 'weekly', 'omniprivacy_rotate_logs' );
			}
		}
	}

	/**
	 * Charge les assets CSS/JS de l'administration.
	 *
	 * @param string $hook_suffix Page admin courante.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( strpos( $hook_suffix, 'omniprivacy' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'omniprivacy-admin',
			OMNIPRIVACY_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			OMNIPRIVACY_VERSION
		);

		// Dashboard JS uniquement sur la page tableau de bord.
		if ( false !== strpos( $hook_suffix, 'omniprivacy-dashboard' ) ) {
			wp_enqueue_script(
				'omniprivacy-admin-dashboard',
				OMNIPRIVACY_PLUGIN_URL . 'assets/js/admin-dashboard.js',
				array( 'jquery' ),
				OMNIPRIVACY_VERSION,
				true
			);
		}

		// Scan + actions JS sur les pages scan et demandes.
		if ( false !== strpos( $hook_suffix, 'omniprivacy-scan' ) || false !== strpos( $hook_suffix, 'omniprivacy-requests' ) ) {
			wp_enqueue_script(
				'omniprivacy-admin-scan',
				OMNIPRIVACY_PLUGIN_URL . 'assets/js/admin-scan.js',
				array( 'jquery' ),
				OMNIPRIVACY_VERSION,
				true
			);

			wp_localize_script( 'omniprivacy-admin-scan', 'omniprivacyAdmin', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'omniprivacy_admin_nonce' ),
				'i18n'    => array(
					'scanRunning'  => __( 'Scan en cours...', 'omniprivacy-pro' ),
					'scanComplete' => __( 'Scan terminé.', 'omniprivacy-pro' ),
					'confirmAnon'  => __( 'Confirmer l\'anonymisation ?', 'omniprivacy-pro' ),
				),
			) );
		}
	}

	/**
	 * Charge les assets CSS/JS publics.
	 */
	public function enqueue_public_assets() {
		if ( ! is_page() ) {
			return;
		}

		global $post;
		if ( ! has_shortcode( $post->post_content, 'omniprivacy_portal' ) ) {
			return;
		}

		wp_enqueue_style(
			'omniprivacy-front',
			OMNIPRIVACY_PLUGIN_URL . 'assets/css/front.css',
			array(),
			OMNIPRIVACY_VERSION
		);

		wp_enqueue_script(
			'omniprivacy-front-portal',
			OMNIPRIVACY_PLUGIN_URL . 'assets/js/front-portal.js',
			array( 'jquery' ),
			OMNIPRIVACY_VERSION,
			true
		);

		wp_localize_script( 'omniprivacy-front-portal', 'omniprivacyPortal', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'omniprivacy_portal_nonce' ),
			'i18n'    => array(
				'sending'       => __( 'Envoi en cours...', 'omniprivacy-pro' ),
				'linkSent'      => __( 'Lien envoyé ! Vérifiez votre email.', 'omniprivacy-pro' ),
				'requestSent'   => __( 'Demande envoyée avec succès.', 'omniprivacy-pro' ),
				'error'         => __( 'Une erreur est survenue.', 'omniprivacy-pro' ),
			),
		) );
	}

	/**
	 * Enregistre une action dans le registre.
	 */
	public function add_action( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->actions[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Enregistre un filtre dans le registre.
	 */
	public function add_filter( $hook, $component, $callback, $priority = 10, $accepted_args = 1 ) {
		$this->filters[] = compact( 'hook', 'component', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Exécute le loader en enregistrant tous les hooks WordPress.
	 */
	public function run() {
		foreach ( $this->actions as $hook ) {
			add_action( $hook['hook'], array( $hook['component'], $hook['callback'] ), $hook['priority'], $hook['accepted_args'] );
		}

		foreach ( $this->filters as $hook ) {
			add_filter( $hook['hook'], array( $hook['component'], $hook['callback'] ), $hook['priority'], $hook['accepted_args'] );
		}
	}
}
