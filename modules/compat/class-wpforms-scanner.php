<?php
/**
 * OmniPrivacy Pro — WPForms Compatibility
 *
 * Scan des soumissions WPForms (table wpforms_entries),
 * récupération et suppression pour le portail visiteur.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_WPForms_Scanner {

	/**
	 * Initialise les hooks de compatibilité WPForms.
	 */
	public function register_hooks() {
		if ( ! function_exists( 'wpforms' ) ) {
			return;
		}

		add_filter( 'omniprivacy_pii_scan_steps', array( $this, 'add_scan_steps' ) );
		add_filter( 'omniprivacy_pii_scan_total', array( $this, 'add_scan_total' ) );
		add_filter( 'omniprivacy_user_data', array( $this, 'add_user_data' ), 10, 2 );
		add_filter( 'omniprivacy_deletion_handlers', array( $this, 'register_deletion_handler' ) );
	}

	/**
	 * Ajoute l'étape de scan WPForms.
	 *
	 * @param array $steps Étapes existantes.
	 * @return array
	 */
	public function add_scan_steps( $steps ) {
		$steps['wpforms'] = array( $this, 'scan_entries' );
		return $steps;
	}

	/**
	 * Ajoute le nombre d'entrées WPForms au total du scan.
	 *
	 * @param int $total Total courant.
	 * @return int
	 */
	public function add_scan_total( $total ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wpforms_entries';
		if ( ! $this->table_exists( $table ) ) {
			return $total;
		}

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $total + $count;
	}

	/**
	 * Scanne les entrées WPForms.
	 *
	 * @param int   $offset     Décalage.
	 * @param int   $batch_size Taille du lot.
	 * @param array $patterns   Patterns regex.
	 * @return bool True s'il reste des éléments.
	 */
	public function scan_entries( $offset, $batch_size, $patterns ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wpforms_entries';
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entry_id, form_id, fields, ip_address, user_agent
				FROM {$table}
				ORDER BY entry_id ASC
				LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$batch_size,
				$offset
			)
		);

		foreach ( $entries as $entry ) {
			// Scanner l'IP.
			$this->scan_field( $entry->ip_address, 'wpforms', $entry->entry_id, 'ip_address', $patterns );

			// Décoder et scanner les champs du formulaire.
			$fields = json_decode( $entry->fields, true );
			if ( is_array( $fields ) ) {
				foreach ( $fields as $field_id => $field_data ) {
					$value = $field_data['value'] ?? '';
					if ( ! empty( $value ) && is_string( $value ) ) {
						$field_name = 'field_' . absint( $field_id );
						$this->scan_field( $value, 'wpforms', $entry->entry_id, $field_name, $patterns );
					}
				}
			}
		}

		return count( $entries ) >= $batch_size;
	}

	/**
	 * Ajoute les soumissions WPForms aux données utilisateur du portail.
	 *
	 * @param array  $data  Données existantes.
	 * @param string $email Email du visiteur.
	 * @return array
	 */
	public function add_user_data( $data, $email ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wpforms_entries';
		if ( ! $this->table_exists( $table ) ) {
			return $data;
		}

		// WPForms stocke les champs en JSON — rechercher l'email dans la colonne fields.
		$entries = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entry_id, form_id, fields, date AS entry_date
				FROM {$table}
				WHERE fields LIKE %s
				ORDER BY date DESC
				LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'%' . $wpdb->esc_like( $email ) . '%'
			)
		);

		$submissions = array();
		foreach ( $entries as $entry ) {
			// Vérifier que l'email est bien dans les champs (pas un faux positif LIKE).
			$fields = json_decode( $entry->fields, true );
			if ( ! $this->fields_contain_email( $fields, $email ) ) {
				continue;
			}

			$form_title = get_the_title( $entry->form_id );
			$submissions[] = array(
				'id'        => $entry->entry_id,
				'form_name' => $form_title ?: sprintf( 'Form #%d', $entry->form_id ),
				'date'      => $entry->entry_date,
				'type'      => 'wpforms_entry',
			);
		}

		if ( ! empty( $submissions ) ) {
			$data['wpforms_entries'] = $submissions;
		}

		return $data;
	}

	/**
	 * Enregistre le handler de suppression WPForms.
	 *
	 * @param array $handlers Handlers existants.
	 * @return array
	 */
	public function register_deletion_handler( $handlers ) {
		$handlers['wpforms_entry'] = array( $this, 'delete_entry' );
		return $handlers;
	}

	/**
	 * Supprime une entrée WPForms.
	 *
	 * @param int $entry_id ID de l'entrée.
	 * @return bool Succès.
	 */
	public function delete_entry( $entry_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wpforms_entries';
		if ( ! $this->table_exists( $table ) ) {
			return false;
		}

		$deleted = $wpdb->delete(
			$table,
			array( 'entry_id' => $entry_id ),
			array( '%d' )
		);

		// Supprimer aussi les métadonnées associées.
		$meta_table = $wpdb->prefix . 'wpforms_entry_meta';
		if ( $this->table_exists( $meta_table ) ) {
			$wpdb->delete(
				$meta_table,
				array( 'entry_id' => $entry_id ),
				array( '%d' )
			);
		}

		if ( $deleted ) {
			OmniPrivacy_Audit_Logger::log_system(
				'wpforms_entry_deleted',
				'wpforms',
				array( 'entry_id' => $entry_id )
			);
		}

		return (bool) $deleted;
	}

	/**
	 * Vérifie si un tableau de champs contient l'email recherché.
	 *
	 * @param array|null $fields Champs du formulaire.
	 * @param string     $email  Email à chercher.
	 * @return bool
	 */
	private function fields_contain_email( $fields, $email ) {
		if ( ! is_array( $fields ) ) {
			return false;
		}

		foreach ( $fields as $field ) {
			$value = $field['value'] ?? '';
			if ( is_string( $value ) && stripos( $value, $email ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Vérifie si une table existe en base.
	 *
	 * @param string $table_name Nom complet de la table.
	 * @return bool
	 */
	private function table_exists( $table_name ) {
		global $wpdb;

		static $cache = array();
		if ( isset( $cache[ $table_name ] ) ) {
			return $cache[ $table_name ];
		}

		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table_name
			)
		);

		$cache[ $table_name ] = ! empty( $result );
		return $cache[ $table_name ];
	}

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
