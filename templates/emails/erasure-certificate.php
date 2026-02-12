<?php
/**
 * OmniPrivacy Pro — Erasure Certificate Email Template
 *
 * Variables disponibles : $certificate, $signature
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
	<h2 style="color: #1d2327;"><?php esc_html_e( 'Certificat d\'effacement de données', 'omniprivacy-pro' ); ?></h2>

	<p><?php esc_html_e( 'Conformément à votre demande, les données suivantes ont été supprimées de notre système.', 'omniprivacy-pro' ); ?></p>

	<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
		<tr>
			<td style="padding: 10px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold; width: 40%;">
				<?php esc_html_e( 'Transaction ID', 'omniprivacy-pro' ); ?>
			</td>
			<td style="padding: 10px; border: 1px solid #ddd;">
				<code><?php echo esc_html( $certificate['transaction_id'] ); ?></code>
			</td>
		</tr>
		<tr>
			<td style="padding: 10px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">
				<?php esc_html_e( 'Site', 'omniprivacy-pro' ); ?>
			</td>
			<td style="padding: 10px; border: 1px solid #ddd;">
				<?php echo esc_html( $certificate['site_name'] ); ?>
				(<?php echo esc_html( $certificate['site_url'] ); ?>)
			</td>
		</tr>
		<tr>
			<td style="padding: 10px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">
				<?php esc_html_e( 'Date d\'effacement', 'omniprivacy-pro' ); ?>
			</td>
			<td style="padding: 10px; border: 1px solid #ddd;">
				<?php echo esc_html( $certificate['erasure_date'] ); ?>
			</td>
		</tr>
		<tr>
			<td style="padding: 10px; border: 1px solid #ddd; background: #f9f9f9; font-weight: bold;">
				<?php esc_html_e( 'Éléments supprimés', 'omniprivacy-pro' ); ?>
			</td>
			<td style="padding: 10px; border: 1px solid #ddd;">
				<?php echo absint( $certificate['total_items'] ); ?>
			</td>
		</tr>
	</table>

	<h3 style="color: #1d2327;"><?php esc_html_e( 'Catégories concernées', 'omniprivacy-pro' ); ?></h3>
	<ul style="padding-left: 20px;">
		<?php foreach ( $certificate['categories'] as $type => $count ) : ?>
			<li><?php printf( '%s : %d', esc_html( ucfirst( $type ) ), absint( $count ) ); ?></li>
		<?php endforeach; ?>
	</ul>

	<hr style="border: none; border-top: 1px solid #e0e0e0; margin: 30px 0;" />

	<p style="color: #999; font-size: 11px;">
		<?php esc_html_e( 'Signature de vérification :', 'omniprivacy-pro' ); ?>
		<code style="font-size: 10px;"><?php echo esc_html( $signature ); ?></code>
	</p>

	<p style="color: #999; font-size: 11px;">
		<?php esc_html_e( 'Ce document fait foi en cas de contrôle. Conservez-le précieusement.', 'omniprivacy-pro' ); ?>
	</p>
</div>
