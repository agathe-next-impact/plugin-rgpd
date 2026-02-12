<?php
/**
 * OmniPrivacy Pro — Magic Link
 *
 * Génération et validation de tokens d'accès temporaires
 * pour le portail de transparence visiteur.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Magic_Link {

	/**
	 * Gère la demande de magic link via AJAX.
	 */
	public function ajax_send_link() {
		check_ajax_referer( 'omniprivacy_portal_nonce', 'nonce' );

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Adresse email invalide.', 'omniprivacy-pro' ) ) );
		}

		// Rate limiting.
		if ( $this->is_rate_limited( $email ) ) {
			wp_send_json_error( array(
				'message' => __( 'Trop de demandes. Veuillez réessayer plus tard.', 'omniprivacy-pro' ),
			) );
		}

		// Générer le token.
		$token      = bin2hex( random_bytes( 32 ) );
		$token_hash = hash( 'sha256', $token );
		$expiry     = absint( get_option( 'omniprivacy_magic_link_expiry', 3600 ) );

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'omniprivacy_magic_tokens',
			array(
				'token_hash' => $token_hash,
				'email'      => $email,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + $expiry ),
				'used'       => 0,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);

		// Construire le lien.
		$portal_page = get_option( 'omniprivacy_portal_page_id', 0 );
		$base_url    = $portal_page ? get_permalink( $portal_page ) : home_url( '/' );
		$link        = add_query_arg( 'omniprivacy_token', $token, $base_url );

		// Envoyer l'email.
		$subject = __( 'Votre lien d\'accès sécurisé — OmniPrivacy', 'omniprivacy-pro' );
		$message = $this->get_email_body( $link, $expiry );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sent = wp_mail( $email, $subject, $message, $headers );

		if ( $sent ) {
			wp_send_json_success( array(
				'message' => __( 'Un lien d\'accès vous a été envoyé par email.', 'omniprivacy-pro' ),
			) );
		} else {
			wp_send_json_error( array(
				'message' => __( 'Impossible d\'envoyer l\'email. Contactez l\'administrateur.', 'omniprivacy-pro' ),
			) );
		}
	}

	/**
	 * Valide un token et retourne l'email associé.
	 *
	 * @param string $token Token en clair.
	 * @return string|false Email associé ou false si invalide.
	 */
	public function validate_token( $token ) {
		global $wpdb;

		$token_hash = hash( 'sha256', $token );

		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}omniprivacy_magic_tokens
				WHERE token_hash = %s AND used = 0 AND expires_at > %s",
				$token_hash,
				current_time( 'mysql' )
			)
		);

		if ( ! $record ) {
			return false;
		}

		// Marquer comme utilisé.
		$wpdb->update(
			$wpdb->prefix . 'omniprivacy_magic_tokens',
			array( 'used' => 1 ),
			array( 'id' => $record->id ),
			array( '%d' ),
			array( '%d' )
		);

		return $record->email;
	}

	/**
	 * Vérifie le rate limiting pour un email.
	 *
	 * @param string $email Adresse email.
	 * @return bool True si limité.
	 */
	private function is_rate_limited( $email ) {
		global $wpdb;

		$max_requests = absint( get_option( 'omniprivacy_magic_link_rate_limit', 3 ) );
		$one_hour_ago = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}omniprivacy_magic_tokens
				WHERE email = %s AND created_at > %s",
				$email,
				$one_hour_ago
			)
		);

		return $count >= $max_requests;
	}

	/**
	 * Génère le corps de l'email du magic link.
	 *
	 * @param string $link   URL du magic link.
	 * @param int    $expiry Durée de validité en secondes.
	 * @return string HTML de l'email.
	 */
	private function get_email_body( $link, $expiry ) {
		$expiry_minutes = round( $expiry / 60 );
		$site_name      = get_bloginfo( 'name' );

		ob_start();
		$template = OMNIPRIVACY_PLUGIN_DIR . 'templates/emails/magic-link.php';
		if ( file_exists( $template ) ) {
			include $template;
		} else {
			printf(
				'<p>%s</p><p><a href="%s">%s</a></p><p>%s</p>',
				esc_html__( 'Vous avez demandé l\'accès à vos données personnelles.', 'omniprivacy-pro' ),
				esc_url( $link ),
				esc_html__( 'Accéder à mes données', 'omniprivacy-pro' ),
				/* translators: %d: minutes de validité */
				sprintf( esc_html__( 'Ce lien expire dans %d minutes.', 'omniprivacy-pro' ), $expiry_minutes )
			);
		}
		return ob_get_clean();
	}
}
