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
	<h1><?php esc_html_e( 'Scan PII — Données personnelles détectées', 'omniprivacy-pro' ); ?></h1>

	<div class="omniprivacy-card" style="margin-bottom: 20px;">
		<button id="omniprivacy-start-scan" class="button button-primary button-hero"
				data-label="<?php esc_attr_e( 'Lancer un nouveau scan', 'omniprivacy-pro' ); ?>">
			<?php esc_html_e( 'Lancer un nouveau scan', 'omniprivacy-pro' ); ?>
		</button>
		<div class="omniprivacy-scan-progress" style="display:none;">
			<div class="omniprivacy-scan-progress-bar"></div>
		</div>
		<div id="omniprivacy-scan-status" style="display:none; margin-top: 10px;"></div>
	</div>

	<form method="get">
		<input type="hidden" name="page" value="omniprivacy-scan" />
		<?php $results_table->display(); ?>
	</form>
</div>
