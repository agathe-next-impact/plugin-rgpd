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
	 * Buffer des résultats PII à insérer en batch.
	 *
	 * @var array
	 */
	private $pii_pending_results = array();

	/**
	 * Scanne un champ texte et bufferise les PII trouvées.
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

		foreach ( $patterns as $pattern_type => $regex ) {
			if ( preg_match_all( $regex, $text, $matches ) ) {
				foreach ( array_unique( $matches[0] ) as $match ) {
					$this->pii_pending_results[] = array(
						'source_type'   => $source_type,
						'source_id'     => $source_id,
						'field_name'    => $field_name,
						'matched_value' => $match,
						'pattern_type'  => $pattern_type,
					);
				}
			}
		}
	}

	/**
	 * Insère les résultats bufferisés en batch, en évitant les doublons.
	 */
	private function flush_scan_results() {
		if ( empty( $this->pii_pending_results ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'omniprivacy_scan_results';

		// Collecter les source_ids uniques pour charger les résultats existants.
		$ids_by_type = array();
		foreach ( $this->pii_pending_results as $r ) {
			$ids_by_type[ $r['source_type'] ][ $r['source_id'] ] = true;
		}

		// Charger les résultats existants en une requête par source_type.
		$existing_set = array();
		foreach ( $ids_by_type as $src_type => $ids_map ) {
			$source_ids   = array_keys( $ids_map );
			$placeholders = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );
			$query_args   = array_merge( array( $src_type ), $source_ids );

			$existing_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT source_type, source_id, field_name, matched_value, pattern_type
					FROM {$table}
					WHERE source_type = %s AND source_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					$query_args
				)
			);

			foreach ( $existing_rows as $row ) {
				$key = $row->source_type . '|' . $row->source_id . '|' . $row->field_name . '|' . $row->matched_value . '|' . $row->pattern_type;
				$existing_set[ $key ] = true;
			}
		}

		// Filtrer les doublons et préparer l'insertion.
		$to_insert = array();
		$now       = current_time( 'mysql' );
		foreach ( $this->pii_pending_results as $r ) {
			$key = $r['source_type'] . '|' . $r['source_id'] . '|' . $r['field_name'] . '|' . $r['matched_value'] . '|' . $r['pattern_type'];
			if ( ! isset( $existing_set[ $key ] ) ) {
				$to_insert[]          = $r;
				$existing_set[ $key ] = true;
			}
		}

		// Insérer en chunks de 50.
		if ( ! empty( $to_insert ) ) {
			foreach ( array_chunk( $to_insert, 50 ) as $chunk ) {
				$values_list      = array();
				$placeholders_sql = array();
				foreach ( $chunk as $r ) {
					$placeholders_sql[] = '(%s, %d, %s, %s, %s, %s, %s)';
					array_push( $values_list, $r['source_type'], $r['source_id'], $r['field_name'], $r['matched_value'], $r['pattern_type'], 'active', $now );
				}

				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$table} (source_type, source_id, field_name, matched_value, pattern_type, status, scan_date)
						VALUES " . implode( ', ', $placeholders_sql ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						$values_list
					)
				);
			}
		}

		$this->pii_pending_results = array();
	}
}
