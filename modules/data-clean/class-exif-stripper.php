<?php
/**
 * OmniPrivacy Pro — EXIF Stripper
 *
 * Supprime les métadonnées EXIF (GPS, appareil, etc.) des images
 * lors du téléchargement dans la bibliothèque média.
 * Préserve l'orientation EXIF avant la réécriture.
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

		$mime    = $upload['type'] ?? '';
		$stripped = false;

		if ( in_array( $mime, array( 'image/jpeg', 'image/jpg' ), true ) ) {
			$stripped = $this->strip_jpeg_exif( $upload['file'] );
		} elseif ( 'image/png' === $mime ) {
			$stripped = $this->strip_png_metadata( $upload['file'] );
		}

		if ( $stripped ) {
			OmniPrivacy_Audit_Logger::log_system(
				'exif_stripped',
				'media',
				array(
					'file' => basename( $upload['file'] ),
					'mime' => $mime,
				)
			);
		}

		return $upload;
	}

	/**
	 * Supprime les EXIF d'un fichier JPEG en le ré-encodant via GD.
	 * Préserve l'orientation en lisant le tag EXIF Orientation avant la réécriture.
	 *
	 * @param string $file_path Chemin absolu du fichier.
	 * @return bool True si le fichier a été nettoyé.
	 */
	private function strip_jpeg_exif( $file_path ) {
		if ( ! function_exists( 'imagecreatefromjpeg' ) ) {
			return false;
		}

		// Lire l'orientation EXIF avant de détruire les métadonnées.
		$orientation = 0;
		if ( function_exists( 'exif_read_data' ) ) {
			$exif = @exif_read_data( $file_path );
			if ( $exif && isset( $exif['Orientation'] ) ) {
				$orientation = (int) $exif['Orientation'];
			}
		}

		$image = @imagecreatefromjpeg( $file_path );
		if ( false === $image ) {
			return false;
		}

		// Appliquer la rotation correcte selon l'orientation EXIF.
		$image = $this->apply_orientation( $image, $orientation );

		$result = imagejpeg( $image, $file_path, 92 );
		imagedestroy( $image );

		return $result;
	}

	/**
	 * Supprime les métadonnées d'un fichier PNG en le ré-encodant via GD.
	 *
	 * @param string $file_path Chemin absolu du fichier.
	 * @return bool True si le fichier a été nettoyé.
	 */
	private function strip_png_metadata( $file_path ) {
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return false;
		}

		$image = @imagecreatefrompng( $file_path );
		if ( false === $image ) {
			return false;
		}

		// Préserver la transparence.
		imagesavealpha( $image, true );
		$result = imagepng( $image, $file_path, 9 );
		imagedestroy( $image );

		return $result;
	}

	/**
	 * Applique la rotation/miroir selon le tag EXIF Orientation.
	 *
	 * @param resource $image       Ressource image GD.
	 * @param int      $orientation Valeur EXIF Orientation (1-8).
	 * @return resource Image corrigée.
	 */
	private function apply_orientation( $image, $orientation ) {
		switch ( $orientation ) {
			case 2:
				imageflip( $image, IMG_FLIP_HORIZONTAL );
				break;
			case 3:
				$image = imagerotate( $image, 180, 0 );
				break;
			case 4:
				imageflip( $image, IMG_FLIP_VERTICAL );
				break;
			case 5:
				$image = imagerotate( $image, -90, 0 );
				imageflip( $image, IMG_FLIP_HORIZONTAL );
				break;
			case 6:
				$image = imagerotate( $image, -90, 0 );
				break;
			case 7:
				$image = imagerotate( $image, 90, 0 );
				imageflip( $image, IMG_FLIP_HORIZONTAL );
				break;
			case 8:
				$image = imagerotate( $image, 90, 0 );
				break;
		}

		return $image;
	}
}
