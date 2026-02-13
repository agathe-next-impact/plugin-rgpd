<?php
/**
 * OmniPrivacy Pro — Settings
 *
 * Page de réglages centrale avec onglets par module.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Settings {

	/**
	 * Ajoute les pages de menu d'administration.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'OmniPrivacy Pro', 'omniprivacy-pro' ),
			__( 'OmniPrivacy', 'omniprivacy-pro' ),
			'manage_omniprivacy',
			'omniprivacy-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-shield',
			80
		);

		add_submenu_page(
			'omniprivacy-dashboard',
			__( 'Tableau de bord', 'omniprivacy-pro' ),
			__( 'Tableau de bord', 'omniprivacy-pro' ),
			'manage_omniprivacy',
			'omniprivacy-dashboard',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'omniprivacy-dashboard',
			__( 'Scan PII', 'omniprivacy-pro' ),
			__( 'Scan PII', 'omniprivacy-pro' ),
			'manage_omniprivacy',
			'omniprivacy-scan',
			array( $this, 'render_scan' )
		);

		add_submenu_page(
			'omniprivacy-dashboard',
			__( 'Demandes de suppression', 'omniprivacy-pro' ),
			__( 'Demandes', 'omniprivacy-pro' ),
			'manage_omniprivacy',
			'omniprivacy-requests',
			array( $this, 'render_requests' )
		);

		add_submenu_page(
			'omniprivacy-dashboard',
			__( 'Rapports', 'omniprivacy-pro' ),
			__( 'Rapports', 'omniprivacy-pro' ),
			'manage_omniprivacy',
			'omniprivacy-reports',
			array( $this, 'render_reports' )
		);

		add_submenu_page(
			'omniprivacy-dashboard',
			__( 'Réglages', 'omniprivacy-pro' ),
			__( 'Réglages', 'omniprivacy-pro' ),
			'manage_omniprivacy',
			'omniprivacy-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Intercepte les exports fichiers (PDF, CSV) avant tout output HTML.
	 * Branché sur admin_init pour éviter la corruption du flux binaire.
	 */
	public function handle_file_exports() {
		if ( ! isset( $_GET['page'] ) || 'omniprivacy-reports' !== $_GET['page'] || ! isset( $_GET['action'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_omniprivacy' ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_GET['action'] ) );

		if ( 'generate_pdf' === $action ) {
			check_admin_referer( 'omniprivacy_generate_pdf' );
			$generator = new OmniPrivacy_PDF_Generator();
			$generator->generate_report();
			exit;
		}

		if ( 'export_consent_csv' === $action ) {
			check_admin_referer( 'omniprivacy_export_consent_csv' );
			$consent_log = new OmniPrivacy_Consent_Log();
			$consent_log->export_csv();
			exit;
		}
	}

	/**
	 * Enregistre les réglages via Settings API.
	 */
	public function register_settings() {
		// Section Data-Clean.
		add_settings_section(
			'omniprivacy_data_clean',
			__( 'Data-Clean', 'omniprivacy-pro' ),
			array( $this, 'section_data_clean_description' ),
			'omniprivacy-settings'
		);

		register_setting( 'omniprivacy_settings', 'omniprivacy_retention_months', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 36,
		) );

		add_settings_field(
			'omniprivacy_retention_months',
			__( 'Rétention des commentaires (mois)', 'omniprivacy-pro' ),
			array( $this, 'field_number' ),
			'omniprivacy-settings',
			'omniprivacy_data_clean',
			array( 'option' => 'omniprivacy_retention_months', 'min' => 1, 'max' => 120 )
		);

		register_setting( 'omniprivacy_settings', 'omniprivacy_excluded_roles', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_roles' ),
			'default'           => array( 'administrator' ),
		) );

		add_settings_field(
			'omniprivacy_excluded_roles',
			__( 'Rôles exclus de l\'anonymisation', 'omniprivacy-pro' ),
			array( $this, 'field_roles_checkboxes' ),
			'omniprivacy-settings',
			'omniprivacy_data_clean'
		);

		register_setting( 'omniprivacy_settings', 'omniprivacy_exif_strip_enabled', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'absint',
			'default'           => 1,
		) );

		add_settings_field(
			'omniprivacy_exif_strip_enabled',
			__( 'Nettoyage EXIF automatique', 'omniprivacy-pro' ),
			array( $this, 'field_checkbox' ),
			'omniprivacy-settings',
			'omniprivacy_data_clean',
			array( 'option' => 'omniprivacy_exif_strip_enabled' )
		);

		register_setting( 'omniprivacy_settings', 'omniprivacy_log_rotation_enabled', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'absint',
			'default'           => 1,
		) );

		add_settings_field(
			'omniprivacy_log_rotation_enabled',
			__( 'Rotation des logs activée', 'omniprivacy-pro' ),
			array( $this, 'field_checkbox' ),
			'omniprivacy-settings',
			'omniprivacy_data_clean',
			array( 'option' => 'omniprivacy_log_rotation_enabled' )
		);

		register_setting( 'omniprivacy_settings', 'omniprivacy_log_retention_days', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 30,
		) );

		add_settings_field(
			'omniprivacy_log_retention_days',
			__( 'Rétention des logs (jours)', 'omniprivacy-pro' ),
			array( $this, 'field_number' ),
			'omniprivacy-settings',
			'omniprivacy_data_clean',
			array( 'option' => 'omniprivacy_log_retention_days', 'min' => 1, 'max' => 365 )
		);

		// Section Scan PII.
		add_settings_section(
			'omniprivacy_pii_search',
			__( 'Scan PII', 'omniprivacy-pro' ),
			array( $this, 'section_pii_description' ),
			'omniprivacy-settings'
		);

		register_setting( 'omniprivacy_settings', 'omniprivacy_scan_batch_size', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 100,
		) );

		add_settings_field(
			'omniprivacy_scan_batch_size',
			__( 'Taille de lot (items par batch)', 'omniprivacy-pro' ),
			array( $this, 'field_number' ),
			'omniprivacy-settings',
			'omniprivacy_pii_search',
			array( 'option' => 'omniprivacy_scan_batch_size', 'min' => 10, 'max' => 500 )
		);

		register_setting( 'omniprivacy_settings', 'omniprivacy_custom_patterns', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );

		add_settings_field(
			'omniprivacy_custom_patterns',
			__( 'Patterns regex personnalisés', 'omniprivacy-pro' ),
			array( $this, 'field_textarea' ),
			'omniprivacy-settings',
			'omniprivacy_pii_search',
			array(
				'option'      => 'omniprivacy_custom_patterns',
				'description' => __( 'Un pattern par ligne au format : nom|regex (ex: ssn|/\b\d{3}-\d{2}-\d{4}\b/)', 'omniprivacy-pro' ),
			)
		);

		// Section Portail.
		add_settings_section(
			'omniprivacy_portal',
			__( 'Portail Visiteur', 'omniprivacy-pro' ),
			array( $this, 'section_portal_description' ),
			'omniprivacy-settings'
		);

		register_setting( 'omniprivacy_settings', 'omniprivacy_portal_page_id', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );

		add_settings_field(
			'omniprivacy_portal_page_id',
			__( 'Page du portail visiteur', 'omniprivacy-pro' ),
			array( $this, 'field_page_dropdown' ),
			'omniprivacy-settings',
			'omniprivacy_portal',
			array( 'option' => 'omniprivacy_portal_page_id' )
		);

		register_setting( 'omniprivacy_settings', 'omniprivacy_magic_link_expiry', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 3600,
		) );

		add_settings_field(
			'omniprivacy_magic_link_expiry',
			__( 'Expiration magic link (secondes)', 'omniprivacy-pro' ),
			array( $this, 'field_number' ),
			'omniprivacy-settings',
			'omniprivacy_portal',
			array( 'option' => 'omniprivacy_magic_link_expiry', 'min' => 600, 'max' => 86400 )
		);

		register_setting( 'omniprivacy_settings', 'omniprivacy_magic_link_rate_limit', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 3,
		) );

		add_settings_field(
			'omniprivacy_magic_link_rate_limit',
			__( 'Max. demandes magic link par heure', 'omniprivacy-pro' ),
			array( $this, 'field_number' ),
			'omniprivacy-settings',
			'omniprivacy_portal',
			array( 'option' => 'omniprivacy_magic_link_rate_limit', 'min' => 1, 'max' => 10 )
		);
	}

	/**
	 * Descriptions des sections.
	 */
	public function section_data_clean_description() {
		echo '<p>' . esc_html__( 'Configuration du nettoyage automatique des données.', 'omniprivacy-pro' ) . '</p>';
	}

	public function section_pii_description() {
		echo '<p>' . esc_html__( 'Configuration du moteur de scan des données personnelles.', 'omniprivacy-pro' ) . '</p>';
	}

	public function section_portal_description() {
		echo '<p>' . esc_html__( 'Configuration du portail de transparence pour les visiteurs.', 'omniprivacy-pro' ) . '</p>';
	}

	/**
	 * Champ numérique.
	 */
	public function field_number( $args ) {
		$value = get_option( $args['option'], 0 );
		printf(
			'<input type="number" name="%s" value="%d" min="%d" max="%d" class="small-text" />',
			esc_attr( $args['option'] ),
			absint( $value ),
			absint( $args['min'] ?? 0 ),
			absint( $args['max'] ?? 9999 )
		);
	}

	/**
	 * Champ checkbox simple.
	 */
	public function field_checkbox( $args ) {
		$value = get_option( $args['option'], 0 );
		printf(
			'<input type="checkbox" name="%s" value="1" %s />',
			esc_attr( $args['option'] ),
			checked( $value, 1, false )
		);
	}

	/**
	 * Champ textarea.
	 */
	public function field_textarea( $args ) {
		$value = get_option( $args['option'], '' );
		printf(
			'<textarea name="%s" rows="5" cols="60" class="large-text code">%s</textarea>',
			esc_attr( $args['option'] ),
			esc_textarea( $value )
		);
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Dropdown de sélection de page WordPress.
	 */
	public function field_page_dropdown( $args ) {
		$selected = get_option( $args['option'], 0 );
		wp_dropdown_pages( array(
			'name'              => esc_attr( $args['option'] ),
			'selected'          => absint( $selected ),
			'show_option_none'  => __( '— Sélectionner une page —', 'omniprivacy-pro' ),
			'option_none_value' => 0,
		) );
		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Checkboxes pour les rôles WordPress.
	 */
	public function field_roles_checkboxes() {
		$excluded = get_option( 'omniprivacy_excluded_roles', array( 'administrator' ) );
		$roles    = wp_roles()->get_names();

		foreach ( $roles as $slug => $name ) {
			printf(
				'<label><input type="checkbox" name="omniprivacy_excluded_roles[]" value="%s" %s /> %s</label><br/>',
				esc_attr( $slug ),
				checked( in_array( $slug, $excluded, true ), true, false ),
				esc_html( translate_user_role( $name ) )
			);
		}
	}

	/**
	 * Sanitize la liste des rôles.
	 */
	public function sanitize_roles( $input ) {
		if ( ! is_array( $input ) ) {
			return array( 'administrator' );
		}
		return array_map( 'sanitize_text_field', $input );
	}

	/**
	 * Rendu des pages admin.
	 */
	public function render_dashboard() {
		if ( ! current_user_can( 'manage_omniprivacy' ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'omniprivacy-pro' ) );
		}
		include OMNIPRIVACY_PLUGIN_DIR . 'templates/admin/dashboard.php';
	}

	public function render_scan() {
		if ( ! current_user_can( 'manage_omniprivacy' ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'omniprivacy-pro' ) );
		}
		include OMNIPRIVACY_PLUGIN_DIR . 'templates/admin/scan-results.php';
	}

	public function render_requests() {
		if ( ! current_user_can( 'manage_omniprivacy' ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'omniprivacy-pro' ) );
		}
		include OMNIPRIVACY_PLUGIN_DIR . 'templates/admin/deletion-requests.php';
	}

	public function render_reports() {
		if ( ! current_user_can( 'manage_omniprivacy' ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'omniprivacy-pro' ) );
		}
		include OMNIPRIVACY_PLUGIN_DIR . 'templates/admin/report-preview.php';
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_omniprivacy' ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'omniprivacy-pro' ) );
		}
		include OMNIPRIVACY_PLUGIN_DIR . 'templates/admin/settings.php';
	}
}
