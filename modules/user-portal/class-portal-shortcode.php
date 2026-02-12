<?php
/**
 * OmniPrivacy Pro — Portal Shortcode
 *
 * Shortcode [omniprivacy_portal] pour afficher le portail de transparence.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Portal_Shortcode {

	/**
	 * Enregistre le shortcode.
	 */
	public function register_shortcode() {
		add_shortcode( 'omniprivacy_portal', array( $this, 'render' ) );
	}

	/**
	 * Rendu du shortcode.
	 *
	 * @param array $atts Attributs du shortcode.
	 * @return string HTML du portail.
	 */
	public function render( $atts ) {
		$token = isset( $_GET['omniprivacy_token'] ) ? sanitize_text_field( wp_unslash( $_GET['omniprivacy_token'] ) ) : '';

		if ( $token ) {
			return $this->render_data_view( $token );
		}

		return $this->render_login_form();
	}

	/**
	 * Affiche le formulaire de demande de magic link.
	 *
	 * @return string HTML du formulaire.
	 */
	private function render_login_form() {
		ob_start();
		$template = OMNIPRIVACY_PLUGIN_DIR . 'templates/front/portal-login.php';
		if ( file_exists( $template ) ) {
			include $template;
		} else {
			?>
			<div class="omniprivacy-portal-login">
				<h2><?php esc_html_e( 'Accéder à mes données personnelles', 'omniprivacy-pro' ); ?></h2>
				<p><?php esc_html_e( 'Entrez votre adresse email pour recevoir un lien d\'accès sécurisé.', 'omniprivacy-pro' ); ?></p>
				<form id="omniprivacy-magic-link-form" method="post">
					<label for="omniprivacy-email"><?php esc_html_e( 'Email', 'omniprivacy-pro' ); ?></label>
					<input type="email" id="omniprivacy-email" name="email" required />
					<button type="submit"><?php esc_html_e( 'Recevoir mon lien d\'accès', 'omniprivacy-pro' ); ?></button>
				</form>
				<div id="omniprivacy-message" style="display:none;"></div>
			</div>
			<?php
		}
		return ob_get_clean();
	}

	/**
	 * Affiche les données de l'utilisateur après validation du token.
	 *
	 * @param string $token Token d'accès.
	 * @return string HTML des données.
	 */
	private function render_data_view( $token ) {
		$magic_link = new OmniPrivacy_Magic_Link();
		$email      = $magic_link->validate_token( $token );

		if ( ! $email ) {
			return '<div class="omniprivacy-error">' .
				esc_html__( 'Lien invalide ou expiré. Veuillez demander un nouveau lien d\'accès.', 'omniprivacy-pro' ) .
				'</div>';
		}

		$viewer = new OmniPrivacy_Data_Viewer();
		$data   = $viewer->get_user_data( $email );

		ob_start();
		$template = OMNIPRIVACY_PLUGIN_DIR . 'templates/front/portal-data.php';
		if ( file_exists( $template ) ) {
			include $template;
		} else {
			$this->render_default_data_view( $data, $email );
		}
		return ob_get_clean();
	}

	/**
	 * Vue par défaut des données (fallback si pas de template).
	 *
	 * @param array  $data  Données utilisateur.
	 * @param string $email Email du visiteur.
	 */
	private function render_default_data_view( $data, $email ) {
		?>
		<div class="omniprivacy-portal-data">
			<h2><?php esc_html_e( 'Vos données personnelles', 'omniprivacy-pro' ); ?></h2>
			<p><?php printf( esc_html__( 'Données associées à : %s', 'omniprivacy-pro' ), esc_html( $email ) ); ?></p>

			<form id="omniprivacy-deletion-form" method="post">
				<input type="hidden" name="email" value="<?php echo esc_attr( $email ); ?>" />

				<?php if ( ! empty( $data['comments'] ) ) : ?>
					<h3><?php esc_html_e( 'Commentaires', 'omniprivacy-pro' ); ?></h3>
					<ul>
						<?php foreach ( $data['comments'] as $comment ) : ?>
							<li>
								<label>
									<input type="checkbox" name="items[]" value='<?php echo esc_attr( wp_json_encode( $comment ) ); ?>' />
									<?php printf(
										esc_html__( '%1$s — %2$s', 'omniprivacy-pro' ),
										esc_html( $comment['date'] ),
										esc_html( $comment['content'] )
									); ?>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<?php if ( ! empty( $data['account'] ) ) : ?>
					<h3><?php esc_html_e( 'Compte', 'omniprivacy-pro' ); ?></h3>
					<label>
						<input type="checkbox" name="items[]" value='<?php echo esc_attr( wp_json_encode( $data['account'] ) ); ?>' />
						<?php printf(
							esc_html__( 'Compte %s (inscrit le %s)', 'omniprivacy-pro' ),
							esc_html( $data['account']['display_name'] ),
							esc_html( $data['account']['registered'] )
						); ?>
					</label>
				<?php endif; ?>

				<?php if ( ! empty( $data['orders'] ) ) : ?>
					<h3><?php esc_html_e( 'Commandes', 'omniprivacy-pro' ); ?></h3>
					<ul>
						<?php foreach ( $data['orders'] as $order ) : ?>
							<li>
								<?php if ( $order['locked'] ) : ?>
									<span class="omniprivacy-locked" title="<?php esc_attr_e( 'Conservation obligatoire — obligation fiscale', 'omniprivacy-pro' ); ?>">
										&#128274; <?php printf( esc_html__( 'Commande #%1$s — %2$s', 'omniprivacy-pro' ), esc_html( $order['number'] ), esc_html( $order['date'] ) ); ?>
									</span>
								<?php else : ?>
									<label>
										<input type="checkbox" name="items[]" value='<?php echo esc_attr( wp_json_encode( $order ) ); ?>' />
										<?php printf( esc_html__( 'Commande #%1$s — %2$s', 'omniprivacy-pro' ), esc_html( $order['number'] ), esc_html( $order['date'] ) ); ?>
									</label>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>

				<button type="submit"><?php esc_html_e( 'Demander la suppression des éléments sélectionnés', 'omniprivacy-pro' ); ?></button>
			</form>
		</div>
		<?php
	}
}
