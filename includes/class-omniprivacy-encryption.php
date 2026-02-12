<?php
/**
 * OmniPrivacy Pro — Encryption
 *
 * Wrapper AES-256-CBC pour le chiffrement des données sensibles.
 * Utilise AUTH_KEY de wp-config.php + sel dédié pour dériver la clé.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Encryption {

	private const CIPHER = 'aes-256-cbc';

	/**
	 * Dérive une clé de chiffrement à partir des constantes WordPress.
	 *
	 * @return string Clé de 32 octets.
	 */
	private static function get_key() {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'omniprivacy-fallback-key';
		return hash( 'sha256', $salt . 'omniprivacy-encryption-salt', true );
	}

	/**
	 * Chiffre une chaîne.
	 *
	 * @param string $plaintext Données en clair.
	 * @return string Données chiffrées (base64 : IV + ciphertext).
	 */
	public static function encrypt( $plaintext ) {
		$key    = self::get_key();
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = openssl_random_pseudo_bytes( $iv_len );

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return '';
		}

		return base64_encode( $iv . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Déchiffre une chaîne.
	 *
	 * @param string $encrypted Données chiffrées (base64).
	 * @return string Données en clair, ou chaîne vide en cas d'erreur.
	 */
	public static function decrypt( $encrypted ) {
		$key    = self::get_key();
		$data   = base64_decode( $encrypted, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$iv_len = openssl_cipher_iv_length( self::CIPHER );

		if ( false === $data || strlen( $data ) < $iv_len ) {
			return '';
		}

		$iv         = substr( $data, 0, $iv_len );
		$ciphertext = substr( $data, $iv_len );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

		return ( false === $plaintext ) ? '' : $plaintext;
	}

	/**
	 * Génère un hash HMAC pour la signature de données.
	 *
	 * @param string $data Données à signer.
	 * @return string Hash HMAC-SHA256.
	 */
	public static function hmac_sign( $data ) {
		$key = self::get_key();
		return hash_hmac( 'sha256', $data, $key );
	}

	/**
	 * Vérifie une signature HMAC.
	 *
	 * @param string $data      Données originales.
	 * @param string $signature Signature à vérifier.
	 * @return bool
	 */
	public static function hmac_verify( $data, $signature ) {
		return hash_equals( self::hmac_sign( $data ), $signature );
	}
}
