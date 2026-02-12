<?php
/**
 * OmniPrivacy Pro — EXIF Stripper
 *
 * Supprime les métadonnées EXIF (GPS, appareil, etc.) des images
 * lors du téléchargement dans la bibliothèque média.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_Exif_Stripper {

	/**
	 * Filtre wp_handle_upload pour supprimer les EXIF.
	 *
	 * @param array $upload Données du fichier uploadé (file, url, type).
	 * @return array Données du fichier (inchangées ou nettoyées).
	 */
	public function strip_exif( $upload ) {
		if ( ! get_option( 'omniprivacy_exif_strip_enabled', 1 ) ) {
			return $upload;
		}

		if ( empty( $upload['file'] ) || ! file_exists( $upload['file'] ) ) {
			return $upload;
		}

		$mime = $upload['type'] ?? '';

		if ( 'image/jpeg' === $mime || 'image/jpg' === $mime ) {
			$this->strip_jpeg_exif( $upload['file'] );
		} elseif ( 'image/png' === $mime ) {
			$this->strip_png_metadata( $upload['file'] );
		}

		return $upload;
	}

	/**
	 * Supprime les EXIF d'un fichier JPEG en le ré-encodant via GD.
	 *
	 * @param string $file_path Chemin absolu du fichier.
	 */
	private function strip_jpeg_exif( $file_path ) {
		if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
			return;
		}

		$image = @imagecreatefromjpeg( $file_path );
		if ( false === $image ) {
			return;
		}

		// Conserver la qualité originale.
		imagejpeg( $image, $file_path, 92 );
		imagedestroy( $image );
	}

	/**
	 * Supprime les métadonnées d'un fichier PNG en le ré-encodant via GD.
	 *
	 * @param string $file_path Chemin absolu du fichier.
	 */
	private function strip_png_metadata( $file_path ) {
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return;
		}

		$image = @imagecreatefrompng( $file_path );
		if ( false === $image ) {
			return;
		}

		// Préserver la transparence.
		imagesavealpha( $image, true );
		imagepng( $image, $file_path, 9 );
		imagedestroy( $image );
	}
}
