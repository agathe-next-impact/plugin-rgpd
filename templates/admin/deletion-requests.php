<?php
/**
 * OmniPrivacy Pro — Deletion Requests Template
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// Whitelist strict des statuts autorisés.
$allowed_statuses = array( 'pending', 'approved', 'rejected' );
$status_filter    = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
if ( $status_filter && ! in_array( $status_filter, $allowed_statuses, true ) ) {
	$status_filter = '';
}

if ( $status_filter ) {
	$requests = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}omniprivacy_deletion_requests WHERE status = %s ORDER BY created_at DESC LIMIT 50",
			$status_filter
		)
	);
} else {
	$requests = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}omniprivacy_deletion_requests ORDER BY created_at DESC LIMIT %d",
			50
		)
	);
}
?>

<div class="wrap omniprivacy-wrap">
	<h1><?php esc_html_e( 'Demandes de suppression', 'omniprivacy-pro' ); ?></h1>

	<ul class="subsubsub">
		<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=omniprivacy-requests' ) ); ?>"
			   <?php echo ! $status_filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'Toutes', 'omniprivacy-pro' ); ?></a> |</li>
		<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=omniprivacy-requests&status=pending' ) ); ?>"
			   <?php echo 'pending' === $status_filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'En attente', 'omniprivacy-pro' ); ?></a> |</li>
		<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=omniprivacy-requests&status=approved' ) ); ?>"
			   <?php echo 'approved' === $status_filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'Approuvées', 'omniprivacy-pro' ); ?></a> |</li>
		<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=omniprivacy-requests&status=rejected' ) ); ?>"
			   <?php echo 'rejected' === $status_filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'Rejetées', 'omniprivacy-pro' ); ?></a></li>
	</ul>

	<table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
		<thead>
			<tr>
				<th><?php esc_html_e( '#', 'omniprivacy-pro' ); ?></th>
				<th><?php esc_html_e( 'Email', 'omniprivacy-pro' ); ?></th>
				<th><?php esc_html_e( 'Statut', 'omniprivacy-pro' ); ?></th>
				<th><?php esc_html_e( 'Date', 'omniprivacy-pro' ); ?></th>
				<th><?php esc_html_e( 'Traitée', 'omniprivacy-pro' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'omniprivacy-pro' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $requests ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'Aucune demande.', 'omniprivacy-pro' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $requests as $request ) : ?>
					<tr>
						<td><?php echo absint( $request->id ); ?></td>
						<td><?php echo esc_html( $request->user_email ); ?></td>
						<td>
							<span class="omniprivacy-status-badge <?php echo esc_attr( $request->status ); ?>">
								<?php echo esc_html( ucfirst( $request->status ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( $request->created_at ); ?></td>
						<td><?php echo esc_html( $request->processed_at ?: '—' ); ?></td>
						<td>
							<?php if ( 'pending' === $request->status ) : ?>
								<button class="button button-small omniprivacy-approve-request"
										data-id="<?php echo absint( $request->id ); ?>">
									<?php esc_html_e( 'Approuver', 'omniprivacy-pro' ); ?>
								</button>
								<button class="button button-small omniprivacy-reject-request"
										data-id="<?php echo absint( $request->id ); ?>">
									<?php esc_html_e( 'Rejeter', 'omniprivacy-pro' ); ?>
								</button>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
