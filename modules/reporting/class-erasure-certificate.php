<?php
/**
 * OmniPrivacy Pro — Erasure Certificate
 *
 * Génère et envoie un certificat d'effacement par email
 * après approbation d'une demande de suppression.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Erasure_Certificate {

	/**
	 * Envoie le certificat d'effacement par email.
	 *
	 * @param string $email      Adresse email du destinataire.
	 * @param int    $request_id ID de la demande de suppression.
	 * @param array  $deleted    Éléments effectivement supprimés.
	 */
	public function send( $email, $request_id, $deleted ) {
		$transaction_id = $this->generate_transaction_id( $request_id );
		$certificate    = $this->build_certificate( $transaction_id, $request_id, $deleted );
		$signature      = OmniPrivacy_Encryption::hmac_sign( wp_json_encode( $certificate ) );

		$subject = sprintf(
			/* translators: %s: ID de transaction */
			__( 'Certificat d\'effacement — %s', 'omniprivacy-pro' ),
			$transaction_id
		);

		$body    = $this->render_email( $certificate, $signature );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		wp_mail( $email, $subject, $body, $headers );

		// Log dans l'audit.
		$this->log_audit( $request_id, $transaction_id );
	}

	/**
	 * Génère un ID de transaction unique.
	 *
	 * @param int $request_id ID de la demande.
	 * @return string ID de transaction.
	 */
	private function generate_transaction_id( $request_id ) {
		return 'ERS-' . strtoupper( substr( hash( 'sha256', uniqid( $request_id, true ) ), 0, 12 ) );
	}

	/**
	 * Construit les données du certificat.
	 *
	 * @param string $transaction_id ID de transaction.
	 * @param int    $request_id     ID de la demande.
	 * @param array  $deleted        Éléments supprimés.
	 * @return array Données du certificat.
	 */
	private function build_certificate( $transaction_id, $request_id, $deleted ) {
		// Catégoriser les éléments supprimés (sans les valeurs).
		$categories = array();
		foreach ( $deleted as $item ) {
			$type = $item['type'] ?? 'unknown';
			if ( ! isset( $categories[ $type ] ) ) {
				$categories[ $type ] = 0;
			}
			$categories[ $type ]++;
		}

		return array(
			'transaction_id' => $transaction_id,
			'request_id'     => $request_id,
			'site_name'      => get_bloginfo( 'name' ),
			'site_url'       => home_url(),
			'erasure_date'   => current_time( 'mysql' ),
			'categories'     => $categories,
			'total_items'    => count( $deleted ),
		);
	}

	/**
	 * Génère le HTML de l'email du certificat.
	 *
	 * @param array  $certificate Données du certificat.
	 * @param string $signature   Signature HMAC.
	 * @return string HTML de l'email.
	 */
	private function render_email( $certificate, $signature ) {
		ob_start();
		$template = OMNIPRIVACY_PLUGIN_DIR . 'templates/emails/erasure-certificate.php';
		if ( file_exists( $template ) ) {
			include $template;
		} else {
			?>
			<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
				<h2><?php esc_html_e( 'Certificat d\'effacement de données', 'omniprivacy-pro' ); ?></h2>

				<table style="width: 100%; border-collapse: collapse;">
					<tr>
						<td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;"><?php esc_html_e( 'Transaction', 'omniprivacy-pro' ); ?></td>
						<td style="padding: 8px; border: 1px solid #ddd;"><?php echo esc_html( $certificate['transaction_id'] ); ?></td>
					</tr>
					<tr>
						<td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;"><?php esc_html_e( 'Site', 'omniprivacy-pro' ); ?></td>
						<td style="padding: 8px; border: 1px solid #ddd;"><?php echo esc_html( $certificate['site_name'] ); ?></td>
					</tr>
					<tr>
						<td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;"><?php esc_html_e( 'Date d\'effacement', 'omniprivacy-pro' ); ?></td>
						<td style="padding: 8px; border: 1px solid #ddd;"><?php echo esc_html( $certificate['erasure_date'] ); ?></td>
					</tr>
					<tr>
						<td style="padding: 8px; border: 1px solid #ddd; font-weight: bold;"><?php esc_html_e( 'Éléments supprimés', 'omniprivacy-pro' ); ?></td>
						<td style="padding: 8px; border: 1px solid #ddd;"><?php echo absint( $certificate['total_items'] ); ?></td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Catégories concernées', 'omniprivacy-pro' ); ?></h3>
				<ul>
					<?php foreach ( $certificate['categories'] as $type => $count ) : ?>
						<li><?php printf( '%s : %d', esc_html( ucfirst( $type ) ), absint( $count ) ); ?></li>
					<?php endforeach; ?>
				</ul>

				<p style="font-size: 11px; color: #666;">
					<?php esc_html_e( 'Signature de vérification :', 'omniprivacy-pro' ); ?>
					<code><?php echo esc_html( $signature ); ?></code>
				</p>
			</div>
			<?php
		}
		return ob_get_clean();
	}

	/**
	 * Log dans le journal d'audit.
	 */
	private function log_audit( $request_id, $transaction_id ) {
		OmniPrivacy_Audit_Logger::log_system(
			'erasure_certificate_sent',
			'deletion_request',
			array(
				'request_id'     => $request_id,
				'transaction_id' => $transaction_id,
			)
		);
	}
}
