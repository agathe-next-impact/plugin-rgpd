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
		$score_color = $data['score'] >= 75 ? '#00a32a' : ( $data['score'] >= 50 ? '#dba617' : '#d63638' );
		$score_label = $data['score'] >= 75 ? 'Conforme' : ( $data['score'] >= 50 ? 'Partiel' : 'Non conforme' );
		$integrity_color = $data['consent_valid'] ? '#00a32a' : '#d63638';
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<style>
				* { margin: 0; padding: 0; box-sizing: border-box; }
				body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1d2327; line-height: 1.5; }

				/* Header */
				.header { background: #2271b1; color: #fff; padding: 28px 32px; margin-bottom: 24px; }
				.header h1 { font-size: 22px; margin-bottom: 4px; color: #fff; }
				.header p { font-size: 11px; opacity: .85; }

				/* Section */
				.section { margin: 0 32px 20px; }
				.section-title { font-size: 13px; font-weight: 700; color: #2271b1; text-transform: uppercase; letter-spacing: .5px; border-bottom: 2px solid #2271b1; padding-bottom: 6px; margin-bottom: 12px; }

				/* Score card */
				.score-row { display: table; width: 100%; margin: 0 32px 20px; }
				.score-card { display: table-cell; width: 33%; background: #f8f9fa; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px; text-align: center; vertical-align: top; }
				.score-card + .score-card { margin-left: 12px; }
				.score-value { font-size: 36px; font-weight: 700; line-height: 1.1; }
				.score-label { font-size: 10px; color: #646970; text-transform: uppercase; letter-spacing: .3px; margin-top: 4px; }

				/* Tables */
				table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
				th { background: #f0f0f1; color: #646970; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; padding: 8px 10px; text-align: left; border-bottom: 1px solid #c3c4c7; }
				td { padding: 7px 10px; border-bottom: 1px solid #e8e8e8; font-size: 11px; }
				tr:nth-child(even) td { background: #fafafa; }

				/* Badges */
				.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; }
				.badge-success { background: #edfaef; color: #0a5c1a; }
				.badge-warning { background: #fef8e8; color: #8a6d00; }
				.badge-danger  { background: #fcecec; color: #8a1c1f; }

				/* Empty */
				.empty { color: #646970; font-style: italic; padding: 12px 0; }

				/* Footer */
				.footer { position: fixed; bottom: 0; left: 0; right: 0; background: #f0f0f1; border-top: 1px solid #c3c4c7; padding: 8px 32px; font-size: 9px; color: #646970; }
			</style>
		</head>
		<body>
			<!-- Header -->
			<div class="header">
				<h1><?php echo esc_html( $data['site_name'] ); ?></h1>
				<p><?php esc_html_e( 'Rapport d\'audit RGPD', 'omniprivacy-pro' ); ?> &mdash; <?php echo esc_html( $data['generated'] ); ?> &mdash; OmniPrivacy Pro v<?php echo esc_html( $data['plugin_ver'] ); ?></p>
			</div>

			<!-- Score cards -->
			<div style="margin: 0 32px 20px;">
				<table style="border: none; margin: 0;">
					<tr>
						<td style="width: 33%; background: #f8f9fa; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px; text-align: center; vertical-align: top;">
							<div class="score-value" style="color: <?php echo esc_attr( $score_color ); ?>;"><?php echo absint( $data['score'] ); ?>/100</div>
							<div class="score-label"><?php echo esc_html( $score_label ); ?></div>
						</td>
						<td style="width: 2%;"></td>
						<td style="width: 33%; background: #f8f9fa; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px; text-align: center; vertical-align: top;">
							<div class="score-value"><?php echo absint( $data['pii_total'] ); ?></div>
							<div class="score-label"><?php esc_html_e( 'PII actives', 'omniprivacy-pro' ); ?></div>
						</td>
						<td style="width: 2%;"></td>
						<td style="width: 33%; background: #f8f9fa; border: 1px solid #c3c4c7; border-radius: 4px; padding: 16px; text-align: center; vertical-align: top;">
							<div class="score-value"><?php echo absint( $data['consent_total'] ); ?></div>
							<div class="score-label" style="color: <?php echo esc_attr( $integrity_color ); ?>;">
								<?php echo $data['consent_valid'] ? esc_html__( 'Consentements - OK', 'omniprivacy-pro' ) : esc_html__( 'Consentements - Erreurs', 'omniprivacy-pro' ); ?>
							</div>
						</td>
					</tr>
				</table>
			</div>

			<!-- PII -->
			<div class="section">
				<div class="section-title"><?php esc_html_e( 'Donn\u00e9es personnelles d\u00e9tect\u00e9es', 'omniprivacy-pro' ); ?></div>
				<?php if ( ! empty( $data['pii_by_pattern'] ) ) : ?>
					<table>
						<tr>
							<th><?php esc_html_e( 'Type', 'omniprivacy-pro' ); ?></th>
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
					<p class="empty"><?php esc_html_e( 'Aucune donn\u00e9e personnelle active d\u00e9tect\u00e9e.', 'omniprivacy-pro' ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Consentements -->
			<div class="section">
				<div class="section-title"><?php esc_html_e( 'Registre des consentements', 'omniprivacy-pro' ); ?></div>
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
			</div>

			<!-- Nettoyages -->
			<div class="section">
				<div class="section-title"><?php esc_html_e( 'Historique des nettoyages', 'omniprivacy-pro' ); ?></div>
				<?php if ( ! empty( $data['cleanups'] ) ) : ?>
					<table>
						<tr>
							<th><?php esc_html_e( 'Action', 'omniprivacy-pro' ); ?></th>
							<th><?php esc_html_e( 'Date', 'omniprivacy-pro' ); ?></th>
						</tr>
						<?php foreach ( $data['cleanups'] as $cleanup ) : ?>
							<tr>
								<td><?php echo esc_html( $cleanup->action ); ?></td>
								<td><?php echo esc_html( $cleanup->created_at ); ?></td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php else : ?>
					<p class="empty"><?php esc_html_e( 'Aucun nettoyage enregistr\u00e9.', 'omniprivacy-pro' ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Demandes -->
			<div class="section">
				<div class="section-title"><?php esc_html_e( 'Demandes utilisateurs', 'omniprivacy-pro' ); ?></div>
				<?php if ( ! empty( $data['requests'] ) ) : ?>
					<table>
						<tr>
							<th>#</th>
							<th><?php esc_html_e( 'Statut', 'omniprivacy-pro' ); ?></th>
							<th><?php esc_html_e( 'Cr\u00e9\u00e9e', 'omniprivacy-pro' ); ?></th>
							<th><?php esc_html_e( 'Trait\u00e9e', 'omniprivacy-pro' ); ?></th>
						</tr>
						<?php foreach ( $data['requests'] as $request ) :
							$badge_class = 'badge-warning';
							if ( 'approved' === $request->status ) {
								$badge_class = 'badge-success';
							} elseif ( 'rejected' === $request->status ) {
								$badge_class = 'badge-danger';
							}
						?>
							<tr>
								<td><?php echo absint( $request->id ); ?></td>
								<td><span class="badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( ucfirst( $request->status ) ); ?></span></td>
								<td><?php echo esc_html( $request->created_at ); ?></td>
								<td><?php echo esc_html( $request->processed_at ?: '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php else : ?>
					<p class="empty"><?php esc_html_e( 'Aucune demande enregistr\u00e9e.', 'omniprivacy-pro' ); ?></p>
				<?php endif; ?>
			</div>

			<!-- Footer -->
			<div class="footer">
				<?php echo esc_html( $data['site_url'] ); ?> &mdash; OmniPrivacy Pro v<?php echo esc_html( $data['plugin_ver'] ); ?> &mdash; <?php esc_html_e( 'Document confidentiel', 'omniprivacy-pro' ); ?>
			</div>
		</body>
		</html>
		<?php
	}
}
