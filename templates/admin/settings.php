<?php
/**
 * OmniPrivacy Pro — Settings Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap omniprivacy-wrap">
	<div class="omniprivacy-page-header">
		<span class="dashicons dashicons-admin-generic"></span>
		<div class="omniprivacy-page-meta">
			<h1><?php esc_html_e( 'R\u00e9glages', 'omniprivacy-pro' ); ?></h1>
			<p class="omniprivacy-page-desc"><?php esc_html_e( 'Configuration des modules de conformit\u00e9 RGPD.', 'omniprivacy-pro' ); ?></p>
		</div>
	</div>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'omniprivacy_settings' );
		do_settings_sections( 'omniprivacy-settings' );
		submit_button();
		?>
	</form>

	<div class="omniprivacy-card-stack">
		<div class="omniprivacy-settings-section">
			<h2><?php esc_html_e( 'Portail visiteur', 'omniprivacy-pro' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: shortcode */
					esc_html__( 'Utilisez le shortcode %s sur une page pour afficher le portail de transparence.', 'omniprivacy-pro' ),
					'<code>[omniprivacy_portal]</code>'
				);
				?>
			</p>
		</div>

		<div class="omniprivacy-settings-section">
			<h2><?php esc_html_e( 'Registre des consentements', 'omniprivacy-pro' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: action hook */
					esc_html__( 'D\u00e9clenchez l\'enregistrement via : %s', 'omniprivacy-pro' ),
					"<code>do_action( 'omniprivacy_consent_recorded', \$action, \$categories, \$meta );</code>"
				);
				?>
			</p>
		</div>
	</div>
</div>
