<?php
/**
 * OmniPrivacy Pro — Settings Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap omniprivacy-wrap">
	<h1><?php esc_html_e( 'OmniPrivacy Pro — Réglages', 'omniprivacy-pro' ); ?></h1>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'omniprivacy_settings' );
		do_settings_sections( 'omniprivacy-settings' );
		submit_button();
		?>
	</form>

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
				esc_html__( 'Déclenchez l\'enregistrement via : %s', 'omniprivacy-pro' ),
				"<code>do_action( 'omniprivacy_consent_recorded', \$action, \$categories, \$meta );</code>"
			);
			?>
		</p>
	</div>
</div>
