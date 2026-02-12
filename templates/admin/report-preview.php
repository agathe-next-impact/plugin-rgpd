<?php
/**
 * OmniPrivacy Pro — Report Preview Template
 *
 * Utilisé à la fois pour l'aperçu admin et la génération PDF.
 * La variable $is_pdf est définie à true lors de la génération PDF.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_pdf_context = isset( $is_pdf ) && $is_pdf;

if ( ! $is_pdf_context ) :
	// Vérifier si une demande de génération PDF est en cours (avec nonce CSRF).
	if ( isset( $_GET['action'] ) && 'generate_pdf' === $_GET['action'] ) {
		check_admin_referer( 'omniprivacy_generate_pdf' );
		$generator = new OmniPrivacy_PDF_Generator();
		$generator->generate_report();
		exit;
	}
?>

<div class="wrap omniprivacy-wrap">
	<h1><?php esc_html_e( 'Rapports', 'omniprivacy-pro' ); ?></h1>

	<div class="omniprivacy-card">
		<h3><?php esc_html_e( 'Rapport d\'audit RGPD', 'omniprivacy-pro' ); ?></h3>
		<p><?php esc_html_e( 'Générez un rapport PDF complet incluant le score de conformité, l\'historique des nettoyages et les demandes traitées.', 'omniprivacy-pro' ); ?></p>
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=omniprivacy-reports&action=generate_pdf' ), 'omniprivacy_generate_pdf' ) ); ?>" class="button button-primary">
			<?php esc_html_e( 'Télécharger le rapport PDF', 'omniprivacy-pro' ); ?>
		</a>
	</div>

	<div class="omniprivacy-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Intégrité du registre des consentements', 'omniprivacy-pro' ); ?></h3>
		<?php
		$consent_log = new OmniPrivacy_Consent_Log();
		$integrity   = $consent_log->verify_integrity();
		?>
		<p>
			<?php printf( esc_html__( 'Entrées totales : %d', 'omniprivacy-pro' ), absint( $integrity['total_entries'] ) ); ?><br/>
			<?php if ( $integrity['is_valid'] ) : ?>
				<strong style="color: #46b450;"><?php esc_html_e( 'Intégrité vérifiée — Aucune altération détectée.', 'omniprivacy-pro' ); ?></strong>
			<?php else : ?>
				<strong style="color: #dc3232;">
					<?php printf( esc_html__( 'ATTENTION : %d incohérences détectées.', 'omniprivacy-pro' ), count( $integrity['errors'] ) ); ?>
				</strong>
			<?php endif; ?>
		</p>
	</div>
</div>

<?php endif; ?>
