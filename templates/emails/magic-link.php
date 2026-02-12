<?php
/**
 * OmniPrivacy Pro — Magic Link Email Template
 *
 * Variables disponibles : $link, $expiry_minutes, $site_name
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
	<h2 style="color: #1d2327;"><?php echo esc_html( $site_name ); ?></h2>

	<p><?php esc_html_e( 'Vous avez demandé l\'accès à vos données personnelles sur notre site.', 'omniprivacy-pro' ); ?></p>

	<p><?php esc_html_e( 'Cliquez sur le bouton ci-dessous pour accéder de manière sécurisée à vos informations :', 'omniprivacy-pro' ); ?></p>

	<p style="text-align: center; margin: 30px 0;">
		<a href="<?php echo esc_url( $link ); ?>"
		   style="background: #2271b1; color: #fff; padding: 14px 28px; text-decoration: none; border-radius: 4px; display: inline-block; font-size: 16px;">
			<?php esc_html_e( 'Accéder à mes données', 'omniprivacy-pro' ); ?>
		</a>
	</p>

	<p style="color: #666; font-size: 13px;">
		<?php
		printf(
			/* translators: %d: minutes de validité */
			esc_html__( 'Ce lien est valable %d minutes et ne peut être utilisé qu\'une seule fois.', 'omniprivacy-pro' ),
			absint( $expiry_minutes )
		);
		?>
	</p>

	<p style="color: #666; font-size: 13px;">
		<?php esc_html_e( 'Si vous n\'avez pas effectué cette demande, vous pouvez ignorer cet email.', 'omniprivacy-pro' ); ?>
	</p>

	<hr style="border: none; border-top: 1px solid #e0e0e0; margin: 30px 0;" />

	<p style="color: #999; font-size: 11px;">
		<?php echo esc_html( $site_name ); ?> — <?php esc_html_e( 'Conformité RGPD propulsée par OmniPrivacy Pro', 'omniprivacy-pro' ); ?>
	</p>
</div>
