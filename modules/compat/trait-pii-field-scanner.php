<?php
/**
 * OmniPrivacy Pro — PII Field Scanner Trait
 *
 * Logique partagée de scan PII pour les modules de compatibilité.
 * Évite la duplication du code de détection dans chaque scanner.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait OmniPrivacy_PII_Field_Scanner {

	/**
	 * Scanne un champ texte et enregistre les PII trouvées.
	 *
	 * @param string $text        Texte à scanner.
	 * @param string $source_type Type de source.
	 * @param int    $source_id   ID source.
	 * @param string $field_name  Nom du champ.
	 * @param array  $patterns    Patterns regex.
	 */
	private function scan_field( $text, $source_type, $source_id, $field_name, $patterns ) {
		if ( empty( $text ) || ! is_string( $text ) ) {
			return;
		}

		global $wpdb;

		foreach ( $patterns as $pattern_type => $regex ) {
			if ( preg_match_all( $regex, $text, $matches ) ) {
				foreach ( array_unique( $matches[0] ) as $match ) {
					$exists = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}omniprivacy_scan_results
							WHERE source_type = %s AND source_id = %d AND field_name = %s
							AND matched_value = %s AND pattern_type = %s",
							$source_type,
							$source_id,
							$field_name,
							$match,
							$pattern_type
						)
					);

					if ( $exists > 0 ) {
						continue;
					}

					$wpdb->insert(
						$wpdb->prefix . 'omniprivacy_scan_results',
						array(
							'source_type'   => $source_type,
							'source_id'     => $source_id,
							'field_name'    => $field_name,
							'matched_value' => $match,
							'pattern_type'  => $pattern_type,
							'status'        => 'active',
							'scan_date'     => current_time( 'mysql' ),
						),
						array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
					);
				}
			}
		}
	}
}
