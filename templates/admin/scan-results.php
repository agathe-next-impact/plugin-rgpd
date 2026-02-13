<?php
/**
 * OmniPrivacy Pro — Scan Results Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$results_table = new OmniPrivacy_PII_Results();
$results_table->prepare_items();
?>

<div class="wrap omniprivacy-wrap">
	<div class="omniprivacy-page-header">
		<span class="dashicons dashicons-search"></span>
		<div class="omniprivacy-page-meta">
			<h1><?php esc_html_e( 'Scan PII', 'omniprivacy-pro' ); ?></h1>
			<p class="omniprivacy-page-desc"><?php esc_html_e( 'D\u00e9tection des donn\u00e9es personnelles dans vos contenus.', 'omniprivacy-pro' ); ?></p>
		</div>
	</div>

	<div class="omniprivacy-card">
		<h3><?php esc_html_e( 'Nouveau scan', 'omniprivacy-pro' ); ?></h3>
		<p><?php esc_html_e( 'Analysez vos commentaires, utilisateurs et contenus \u00e0 la recherche de donn\u00e9es personnelles expos\u00e9es.', 'omniprivacy-pro' ); ?></p>
		<button id="omniprivacy-start-scan" class="button button-primary"
				data-label="<?php esc_attr_e( 'Lancer un nouveau scan', 'omniprivacy-pro' ); ?>">
			<?php esc_html_e( 'Lancer un nouveau scan', 'omniprivacy-pro' ); ?>
		</button>
		<div class="omniprivacy-scan-progress" style="display:none;">
			<div class="omniprivacy-scan-progress-bar"></div>
		</div>
		<div id="omniprivacy-scan-status" style="display:none; margin-top: 10px;"></div>
	</div>

	<div class="omniprivacy-table-wrap">
		<form method="get">
			<input type="hidden" name="page" value="omniprivacy-scan" />
			<?php $results_table->display(); ?>
		</form>
	</div>
</div>
