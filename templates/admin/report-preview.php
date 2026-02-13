<?php
/**
 * OmniPrivacy Pro — Report Preview Template
 *
 * Page admin d'aperçu des rapports et déclenchement de la génération PDF / CSV.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Génération PDF.
if ( isset( $_GET['action'] ) && 'generate_pdf' === $_GET['action'] ) {
	check_admin_referer( 'omniprivacy_generate_pdf' );
	$generator = new OmniPrivacy_PDF_Generator();
	$generator->generate_report();
	exit;
}

// Export CSV du registre des consentements.
if ( isset( $_GET['action'] ) && 'export_consent_csv' === $_GET['action'] ) {
	check_admin_referer( 'omniprivacy_export_consent_csv' );
	$consent_export = new OmniPrivacy_Consent_Log();
	$consent_export->export_csv();
	exit;
}

// Données pour l'aperçu.
global $wpdb;

$pdf_gen = new OmniPrivacy_PDF_Generator();
$score   = $pdf_gen->calculate_compliance_score();

$score_class = $score >= 75 ? 'good' : ( $score >= 50 ? 'medium' : 'poor' );

$pii_total = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}omniprivacy_scan_results WHERE status = %s",
		'active'
	)
);

$pii_by_pattern = $wpdb->get_results(
	"SELECT pattern_type, COUNT(*) AS total
	FROM {$wpdb->prefix}omniprivacy_scan_results
	WHERE status = 'active'
	GROUP BY pattern_type
	ORDER BY total DESC"
);

$requests_stats = $wpdb->get_results(
	"SELECT status, COUNT(*) AS total
	FROM {$wpdb->prefix}omniprivacy_deletion_requests
	GROUP BY status"
);

$consent_log = new OmniPrivacy_Consent_Log();
$integrity   = $consent_log->verify_integrity();
$entries     = $consent_log->get_entries( array( 'per_page' => 20 ) );
?>

<div class="wrap omniprivacy-wrap">
	<h1><?php esc_html_e( 'Rapports', 'omniprivacy-pro' ); ?></h1>

	<!-- Exports -->
	<div class="omniprivacy-card">
		<h3><?php esc_html_e( 'Exports', 'omniprivacy-pro' ); ?></h3>
		<p><?php esc_html_e( 'Générez un rapport PDF complet ou exportez le registre des consentements au format CSV.', 'omniprivacy-pro' ); ?></p>
		<div class="omniprivacy-actions">
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=omniprivacy-reports&action=generate_pdf' ), 'omniprivacy_generate_pdf' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Rapport PDF', 'omniprivacy-pro' ); ?>
			</a>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=omniprivacy-reports&action=export_consent_csv' ), 'omniprivacy_export_consent_csv' ) ); ?>" class="button">
				<?php esc_html_e( 'Consentements CSV', 'omniprivacy-pro' ); ?>
			</a>
		</div>
	</div>

	<!-- Aperçu conformité -->
	<div class="omniprivacy-dashboard-cards" style="margin-top: 20px;">
		<div class="omniprivacy-card">
			<h3><?php esc_html_e( 'Score de conformité', 'omniprivacy-pro' ); ?></h3>
			<div class="omniprivacy-score <?php echo esc_attr( $score_class ); ?>">
				<?php echo absint( $score ); ?>/100
			</div>
		</div>

		<div class="omniprivacy-card">
			<h3><?php esc_html_e( 'PII actives', 'omniprivacy-pro' ); ?></h3>
			<div class="omniprivacy-score"><?php echo absint( $pii_total ); ?></div>
		</div>

		<div class="omniprivacy-card">
			<h3><?php esc_html_e( 'Consentements', 'omniprivacy-pro' ); ?></h3>
			<div class="omniprivacy-score"><?php echo absint( $integrity['total_entries'] ); ?></div>
			<?php if ( $integrity['is_valid'] ) : ?>
				<p style="text-align: center; color: #46b450; font-weight: 600;"><?php esc_html_e( 'Intégrité OK', 'omniprivacy-pro' ); ?></p>
			<?php else : ?>
				<p style="text-align: center; color: #dc3232; font-weight: 600;">
					<?php printf( esc_html__( '%d incohérences', 'omniprivacy-pro' ), count( $integrity['errors'] ) ); ?>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<!-- PII par type -->
	<?php if ( ! empty( $pii_by_pattern ) ) : ?>
		<div class="omniprivacy-card" style="margin-top: 20px;">
			<h3><?php esc_html_e( 'Données personnelles détectées par type', 'omniprivacy-pro' ); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type de pattern', 'omniprivacy-pro' ); ?></th>
						<th><?php esc_html_e( 'Occurrences', 'omniprivacy-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pii_by_pattern as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->pattern_type ); ?></td>
							<td><?php echo absint( $row->total ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<!-- Demandes de suppression -->
	<?php if ( ! empty( $requests_stats ) ) : ?>
		<div class="omniprivacy-card" style="margin-top: 20px;">
			<h3><?php esc_html_e( 'Demandes de suppression', 'omniprivacy-pro' ); ?></h3>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Statut', 'omniprivacy-pro' ); ?></th>
						<th><?php esc_html_e( 'Total', 'omniprivacy-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $requests_stats as $stat ) : ?>
						<tr>
							<td>
								<span class="omniprivacy-status-badge <?php echo esc_attr( $stat->status ); ?>">
									<?php echo esc_html( ucfirst( $stat->status ) ); ?>
								</span>
							</td>
							<td><?php echo absint( $stat->total ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<!-- Dernières entrées du registre des consentements -->
	<div class="omniprivacy-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Dernières entrées du registre des consentements', 'omniprivacy-pro' ); ?></h3>
		<?php if ( ! empty( $entries ) ) : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( '#', 'omniprivacy-pro' ); ?></th>
						<th><?php esc_html_e( 'Action', 'omniprivacy-pro' ); ?></th>
						<th><?php esc_html_e( 'Catégories', 'omniprivacy-pro' ); ?></th>
						<th><?php esc_html_e( 'IP (hash)', 'omniprivacy-pro' ); ?></th>
						<th><?php esc_html_e( 'Date', 'omniprivacy-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<td><?php echo absint( $entry->id ); ?></td>
							<td>
								<span class="omniprivacy-status-badge <?php echo 'accept' === $entry->action ? 'approved' : 'rejected'; ?>">
									<?php echo esc_html( ucfirst( $entry->action ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $entry->categories_json ); ?></td>
							<td><code><?php echo esc_html( substr( $entry->ip_hash, 0, 12 ) . '...' ); ?></code></td>
							<td><?php echo esc_html( $entry->created_at ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'Aucun consentement enregistré.', 'omniprivacy-pro' ); ?></p>
		<?php endif; ?>
	</div>
</div>
