<?php
/**
 * OmniPrivacy Pro — PDF Generator
 *
 * Génère un rapport d'audit PDF via DOMPDF.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_PDF_Generator {

	/**
	 * Génère et télécharge le rapport d'audit PDF.
	 */
	public function generate_report() {
		if ( ! current_user_can( 'manage_omniprivacy' ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'omniprivacy-pro' ) );
		}

		$data = $this->collect_report_data();
		$html = $this->render_report_html( $data );

		// Vérifier si DOMPDF est disponible.
		$autoload = OMNIPRIVACY_PLUGIN_DIR . 'vendor/autoload.php';
		if ( ! file_exists( $autoload ) ) {
			wp_die( esc_html__( 'DOMPDF non installé. Exécutez composer install.', 'omniprivacy-pro' ) );
		}

		require_once $autoload;

		$dompdf = new \Dompdf\Dompdf( array(
			'isRemoteEnabled'    => false,
			'isPhpEnabled'       => false,
			'defaultMediaType'   => 'print',
		) );

		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		$filename = 'omniprivacy-audit-' . gmdate( 'Y-m-d' ) . '.pdf';
		$dompdf->stream( $filename, array( 'Attachment' => true ) );
		exit;
	}

	/**
	 * Collecte les données pour le rapport.
	 *
	 * @return array Données du rapport.
	 */
	private function collect_report_data() {
		global $wpdb;

		// Score de conformité.
		$score = $this->calculate_compliance_score();

		// Historique des nettoyages.
		$cleanups = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action, created_at, details_encrypted
				FROM {$wpdb->prefix}omniprivacy_audit_log
				WHERE action IN ('comment_anonymization', 'log_rotation', 'pii_anonymize')
				ORDER BY created_at DESC
				LIMIT %d",
				50
			)
		);

		// Demandes traitées.
		$requests = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_email, status, created_at, processed_at
				FROM {$wpdb->prefix}omniprivacy_deletion_requests
				ORDER BY created_at DESC
				LIMIT %d",
				50
			)
		);

		// Résultats PII actifs par type de pattern.
		$pii_by_pattern = $wpdb->get_results(
			"SELECT pattern_type, COUNT(*) AS total
			FROM {$wpdb->prefix}omniprivacy_scan_results
			WHERE status = 'active'
			GROUP BY pattern_type
			ORDER BY total DESC"
		);

		$pii_total = 0;
		foreach ( $pii_by_pattern as $row ) {
			$pii_total += (int) $row->total;
		}

		// Statistiques du registre des consentements.
		$consent_stats = $wpdb->get_results(
			"SELECT action, COUNT(*) AS total
			FROM {$wpdb->prefix}omniprivacy_consent_log
			GROUP BY action"
		);

		$consent_total = 0;
		foreach ( $consent_stats as $row ) {
			$consent_total += (int) $row->total;
		}

		// Intégrité du registre.
		$consent_log  = new OmniPrivacy_Consent_Log();
		$integrity    = $consent_log->verify_integrity();

		return array(
			'score'           => $score,
			'cleanups'        => $cleanups,
			'requests'        => $requests,
			'pii_by_pattern'  => $pii_by_pattern,
			'pii_total'       => $pii_total,
			'consent_stats'   => $consent_stats,
			'consent_total'   => $consent_total,
			'consent_valid'   => $integrity['is_valid'],
			'generated'       => current_time( 'mysql' ),
			'plugin_ver'      => OMNIPRIVACY_VERSION,
			'site_name'       => get_bloginfo( 'name' ),
			'site_url'        => home_url(),
		);
	}

	/**
	 * Calcule le score de conformité (0-100).
	 *
	 * @return int Score.
	 */
	public function calculate_compliance_score() {
		$score = 0;

		// EXIF activé ? +25.
		if ( get_option( 'omniprivacy_exif_strip_enabled', 0 ) ) {
			$score += 25;
		}

		// Anonymisation commentaires active ? +25.
		$retention = get_option( 'omniprivacy_retention_months', 0 );
		if ( $retention > 0 ) {
			$score += 25;
		}

		// Rotation logs active ? +25.
		if ( get_option( 'omniprivacy_log_rotation_enabled', 0 ) ) {
			$score += 25;
		}

		// Demandes traitées dans les 30 jours ? +25.
		global $wpdb;
		$pending_old = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}omniprivacy_deletion_requests
				WHERE status = 'pending' AND created_at < %s",
				gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) )
			)
		);

		if ( 0 === (int) $pending_old ) {
			$score += 25;
		}

		return $score;
	}

	/**
	 * Génère le HTML du rapport.
	 *
	 * @param array $data Données du rapport.
	 * @return string HTML.
	 */
	private function render_report_html( $data ) {
		ob_start();
		$this->render_default_report( $data );
		return ob_get_clean();
	}

	/**
	 * Rapport HTML par défaut.
	 *
	 * @param array $data Données du rapport.
	 */
	private function render_default_report( $data ) {
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<style>
				body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #333; }
				h1 { color: #0073aa; }
				table { width: 100%; border-collapse: collapse; margin: 15px 0; }
				th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
				th { background: #f5f5f5; }
				.score { font-size: 48px; font-weight: bold; color: <?php echo $data['score'] >= 75 ? '#46b450' : ( $data['score'] >= 50 ? '#ffb900' : '#dc3232' ); ?>; }
			</style>
		</head>
		<body>
			<h1><?php echo esc_html( $data['site_name'] ); ?> — <?php esc_html_e( 'Rapport d\'audit RGPD', 'omniprivacy-pro' ); ?></h1>
			<p><?php printf( esc_html__( 'Généré le %s', 'omniprivacy-pro' ), esc_html( $data['generated'] ) ); ?></p>
			<p><?php printf( esc_html__( 'OmniPrivacy Pro v%s', 'omniprivacy-pro' ), esc_html( $data['plugin_ver'] ) ); ?></p>

			<h2><?php esc_html_e( 'Score de conformité', 'omniprivacy-pro' ); ?></h2>
			<p class="score"><?php echo absint( $data['score'] ); ?>/100</p>

			<h2><?php esc_html_e( 'Données personnelles détectées (PII)', 'omniprivacy-pro' ); ?></h2>
			<?php if ( ! empty( $data['pii_by_pattern'] ) ) : ?>
				<p><?php printf( esc_html__( 'Total actif : %d', 'omniprivacy-pro' ), absint( $data['pii_total'] ) ); ?></p>
				<table>
					<tr>
						<th><?php esc_html_e( 'Type de pattern', 'omniprivacy-pro' ); ?></th>
						<th><?php esc_html_e( 'Occurrences', 'omniprivacy-pro' ); ?></th>
					</tr>
					<?php foreach ( $data['pii_by_pattern'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->pattern_type ); ?></td>
							<td><?php echo absint( $row->total ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'Aucune donnée personnelle active détectée.', 'omniprivacy-pro' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Registre des consentements', 'omniprivacy-pro' ); ?></h2>
			<p>
				<?php printf( esc_html__( 'Entrées totales : %d', 'omniprivacy-pro' ), absint( $data['consent_total'] ) ); ?><br/>
				<?php if ( $data['consent_valid'] ) : ?>
					<strong style="color: #46b450;"><?php esc_html_e( 'Intégrité vérifiée', 'omniprivacy-pro' ); ?></strong>
				<?php else : ?>
					<strong style="color: #dc3232;"><?php esc_html_e( 'ATTENTION : incohérences détectées', 'omniprivacy-pro' ); ?></strong>
				<?php endif; ?>
			</p>
			<?php if ( ! empty( $data['consent_stats'] ) ) : ?>
				<table>
					<tr>
						<th><?php esc_html_e( 'Action', 'omniprivacy-pro' ); ?></th>
						<th><?php esc_html_e( 'Total', 'omniprivacy-pro' ); ?></th>
					</tr>
					<?php foreach ( $data['consent_stats'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( ucfirst( $row->action ) ); ?></td>
							<td><?php echo absint( $row->total ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Historique des nettoyages', 'omniprivacy-pro' ); ?></h2>
			<?php if ( ! empty( $data['cleanups'] ) ) : ?>
				<table>
					<tr><th><?php esc_html_e( 'Action', 'omniprivacy-pro' ); ?></th><th><?php esc_html_e( 'Date', 'omniprivacy-pro' ); ?></th></tr>
					<?php foreach ( $data['cleanups'] as $cleanup ) : ?>
						<tr>
							<td><?php echo esc_html( $cleanup->action ); ?></td>
							<td><?php echo esc_html( $cleanup->created_at ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'Aucun nettoyage enregistré.', 'omniprivacy-pro' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Demandes utilisateurs', 'omniprivacy-pro' ); ?></h2>
			<?php if ( ! empty( $data['requests'] ) ) : ?>
				<table>
					<tr>
						<th>#</th>
						<th><?php esc_html_e( 'Statut', 'omniprivacy-pro' ); ?></th>
						<th><?php esc_html_e( 'Créée', 'omniprivacy-pro' ); ?></th>
						<th><?php esc_html_e( 'Traitée', 'omniprivacy-pro' ); ?></th>
					</tr>
					<?php foreach ( $data['requests'] as $request ) : ?>
						<tr>
							<td><?php echo absint( $request->id ); ?></td>
							<td><?php echo esc_html( $request->status ); ?></td>
							<td><?php echo esc_html( $request->created_at ); ?></td>
							<td><?php echo esc_html( $request->processed_at ?: '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'Aucune demande enregistrée.', 'omniprivacy-pro' ); ?></p>
			<?php endif; ?>
		</body>
		</html>
		<?php
	}
}
