<?php
/**
 * OmniPrivacy Pro — Admin Dashboard Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$last_scan      = get_option( 'omniprivacy_last_scan_date', '' );
$dashboard_data = get_transient( 'omniprivacy_dashboard_stats' );

if ( false === $dashboard_data ) {
	$dashboard_data = array(
		'active_pii'    => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}omniprivacy_scan_results WHERE status = %s", 'active' ) ),
		'pending_reqs'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}omniprivacy_deletion_requests WHERE status = %s", 'pending' ) ),
		'total_cleaned' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}omniprivacy_audit_log WHERE action = %s", 'comment_anonymization' ) ),
	);

	$pdf_gen                  = new OmniPrivacy_PDF_Generator();
	$dashboard_data['score']  = $pdf_gen->calculate_compliance_score();

	set_transient( 'omniprivacy_dashboard_stats', $dashboard_data, 5 * MINUTE_IN_SECONDS );
}

$active_pii    = $dashboard_data['active_pii'];
$pending_reqs  = $dashboard_data['pending_reqs'];
$total_cleaned = $dashboard_data['total_cleaned'];
$score         = $dashboard_data['score'];
$score_class   = $score >= 75 ? 'good' : ( $score >= 50 ? 'medium' : 'poor' );
?>

<div class="wrap omniprivacy-wrap">
	<div class="omniprivacy-page-header">
		<span class="dashicons dashicons-shield"></span>
		<div class="omniprivacy-page-meta">
			<h1><?php esc_html_e( 'Tableau de bord', 'omniprivacy-pro' ); ?></h1>
			<p class="omniprivacy-page-desc"><?php esc_html_e( 'Vue d\'ensemble de la conformit\u00e9 RGPD de votre site.', 'omniprivacy-pro' ); ?></p>
		</div>
	</div>

	<div class="omniprivacy-dashboard-cards">
		<div class="omniprivacy-card">
			<h3><?php esc_html_e( 'Conformit\u00e9', 'omniprivacy-pro' ); ?></h3>
			<div class="omniprivacy-score <?php echo esc_attr( $score_class ); ?>">
				<?php echo absint( $score ); ?>/100
			</div>
			<span class="omniprivacy-score-label"><?php esc_html_e( 'Score global', 'omniprivacy-pro' ); ?></span>
		</div>

		<div class="omniprivacy-card">
			<h3><?php esc_html_e( 'PII d\u00e9tect\u00e9es', 'omniprivacy-pro' ); ?></h3>
			<div class="omniprivacy-score"><?php echo absint( $active_pii ); ?></div>
			<span class="omniprivacy-score-label">
				<?php if ( $last_scan ) : ?>
					<?php printf( esc_html__( 'Scan : %s', 'omniprivacy-pro' ), esc_html( $last_scan ) ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Aucun scan', 'omniprivacy-pro' ); ?>
				<?php endif; ?>
			</span>
		</div>

		<div class="omniprivacy-card">
			<h3><?php esc_html_e( 'Demandes en attente', 'omniprivacy-pro' ); ?></h3>
			<div class="omniprivacy-score"><?php echo absint( $pending_reqs ); ?></div>
			<span class="omniprivacy-score-label">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=omniprivacy-requests' ) ); ?>"><?php esc_html_e( 'G\u00e9rer', 'omniprivacy-pro' ); ?></a>
			</span>
		</div>

		<div class="omniprivacy-card">
			<h3><?php esc_html_e( 'Nettoyages', 'omniprivacy-pro' ); ?></h3>
			<div class="omniprivacy-score"><?php echo absint( $total_cleaned ); ?></div>
			<span class="omniprivacy-score-label"><?php esc_html_e( 'Anonymisations effectu\u00e9es', 'omniprivacy-pro' ); ?></span>
		</div>
	</div>

	<div class="omniprivacy-card">
		<h3><?php esc_html_e( 'Actions rapides', 'omniprivacy-pro' ); ?></h3>
		<div class="omniprivacy-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=omniprivacy-scan' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Lancer un scan PII', 'omniprivacy-pro' ); ?>
			</a>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=omniprivacy-reports&action=generate_pdf' ), 'omniprivacy_generate_pdf' ) ); ?>" class="button">
				<?php esc_html_e( 'Rapport PDF', 'omniprivacy-pro' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=omniprivacy-settings' ) ); ?>" class="button">
				<?php esc_html_e( 'R\u00e9glages', 'omniprivacy-pro' ); ?>
			</a>
		</div>
	</div>
</div>
