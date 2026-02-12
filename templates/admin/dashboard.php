<?php
/**
 * OmniPrivacy Pro — Admin Dashboard Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// Données du tableau de bord (mises en cache 5 min via transient).
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
	<h1><?php esc_html_e( 'OmniPrivacy Pro — Tableau de bord', 'omniprivacy-pro' ); ?></h1>

	<div class="omniprivacy-dashboard-cards">
		<div class="omniprivacy-card">
			<h3><?php esc_html_e( 'Score de conformité', 'omniprivacy-pro' ); ?></h3>
			<div class="omniprivacy-score <?php echo esc_attr( $score_class ); ?>">
				<?php echo absint( $score ); ?>/100
			</div>
		</div>

		<div class="omniprivacy-card">
			<h3><?php esc_html_e( 'Données personnelles détectées', 'omniprivacy-pro' ); ?></h3>
			<div class="omniprivacy-score"><?php echo absint( $active_pii ); ?></div>
			<p>
				<?php if ( $last_scan ) : ?>
					<?php printf( esc_html__( 'Dernier scan : %s', 'omniprivacy-pro' ), esc_html( $last_scan ) ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Aucun scan effectué', 'omniprivacy-pro' ); ?>
				<?php endif; ?>
			</p>
		</div>

		<div class="omniprivacy-card">
			<h3><?php esc_html_e( 'Demandes en attente', 'omniprivacy-pro' ); ?></h3>
			<div class="omniprivacy-score"><?php echo absint( $pending_reqs ); ?></div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=omniprivacy-requests' ) ); ?>" class="button">
				<?php esc_html_e( 'Gérer', 'omniprivacy-pro' ); ?>
			</a>
		</div>

		<div class="omniprivacy-card">
			<h3><?php esc_html_e( 'Nettoyages effectués', 'omniprivacy-pro' ); ?></h3>
			<div class="omniprivacy-score"><?php echo absint( $total_cleaned ); ?></div>
		</div>
	</div>

	<div class="omniprivacy-card">
		<h3><?php esc_html_e( 'Actions rapides', 'omniprivacy-pro' ); ?></h3>
		<div class="omniprivacy-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=omniprivacy-scan' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Lancer un scan PII', 'omniprivacy-pro' ); ?>
			</a>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=omniprivacy-reports&action=generate_pdf' ), 'omniprivacy_generate_pdf' ) ); ?>" class="button">
				<?php esc_html_e( 'Générer un rapport PDF', 'omniprivacy-pro' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=omniprivacy-settings' ) ); ?>" class="button">
				<?php esc_html_e( 'Réglages', 'omniprivacy-pro' ); ?>
			</a>
		</div>
	</div>
</div>
