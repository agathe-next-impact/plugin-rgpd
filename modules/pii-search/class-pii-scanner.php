<?php
/**
 * OmniPrivacy Pro — PII Scanner
 *
 * Moteur de scan par lots pour détecter les données personnelles
 * dans les contenus, métadonnées, médias et commentaires.
 * Suivi de progression via transients. Support des patterns custom.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OmniPrivacy_PII_Scanner {

	/**
	 * Patterns regex pour la détection de PII.
	 *
	 * @var array
	 */
	private $patterns = array();

	/**
	 * Étapes de base du scan.
	 *
	 * @var array
	 */
	private const BASE_STEPS = array( 'posts', 'postmeta', 'comments', 'media' );

	/**
	 * Callbacks des étapes d'extension (clé = nom step, valeur = callable).
	 *
	 * @var array
	 */
	private $extension_steps = array();

	/**
	 * Liste ordonnée de toutes les étapes (base + extensions).
	 *
	 * @var array
	 */
	private $all_steps = array();

	/**
	 * Cache mémoire des éléments ignorés (clé = "source_id|field_name").
	 *
	 * @var array|null
	 */
	private $ignored_cache = null;

	/**
	 * Type de source du cache ignoré courant.
	 *
	 * @var string
	 */
	private $ignored_cache_type = '';

	/**
	 * Buffer des résultats PII à insérer en batch.
	 *
	 * @var array
	 */
	private $pending_results = array();

	public function __construct() {
		$this->patterns = array(
			'email'    => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
			'phone_fr' => '/(?:\+33|0)\s*[1-9](?:[\s.\-]*\d{2}){4}/',
			'iban'     => '/[A-Z]{2}\d{2}[\s]?[\dA-Z]{4}[\s]?(?:[\dA-Z]{4}[\s]?){2,7}[\dA-Z]{1,4}/',
		);

		// Charger les patterns personnalisés depuis les réglages.
		$custom = get_option( 'omniprivacy_custom_patterns', '' );
		if ( ! empty( $custom ) ) {
			$lines = explode( "\n", $custom );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( empty( $line ) || strpos( $line, '|' ) === false ) {
					continue;
				}
				list( $name, $regex ) = explode( '|', $line, 2 );
				$name  = sanitize_key( trim( $name ) );
				$regex = trim( $regex );
				// Vérifier que le regex est valide.
				if ( $name && $regex && @preg_match( $regex, '' ) !== false ) {
					$this->patterns[ 'custom_' . $name ] = $regex;
				}
			}
		}

		// Permettre l'ajout de patterns via filtre.
		$this->patterns = apply_filters( 'omniprivacy_pii_patterns', $this->patterns );

		// Charger les étapes d'extension (WooCommerce, CF7, WPForms, etc.).
		$this->extension_steps = apply_filters( 'omniprivacy_pii_scan_steps', array() );
		$this->all_steps       = array_merge( self::BASE_STEPS, array_keys( $this->extension_steps ) );
	}

	/**
	 * Lance un scan complet via AJAX.
	 */
	public function ajax_start_scan() {
		check_ajax_referer( 'omniprivacy_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_omniprivacy' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès non autorisé.', 'omniprivacy-pro' ) ) );
		}

		global $wpdb;

		// Nettoyer les résultats précédents (sauf ignorés et anonymisés).
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}omniprivacy_scan_results WHERE status = %s",
				'active'
			)
		);

		// Invalider les caches transient.
		delete_transient( 'omniprivacy_scan_results_cache' );
		delete_transient( 'omniprivacy_dashboard_stats' );

		$scan_id = wp_generate_uuid4();

		// Initialiser la progression.
		$total_items = $this->count_total_items();
		set_transient( 'omniprivacy_scan_progress_' . $scan_id, array(
			'status'         => 'running',
			'step'           => 'posts',
			'processed'      => 0,
			'total'          => $total_items,
			'found'          => 0,
			'started_at'     => current_time( 'mysql' ),
		), HOUR_IN_SECONDS );

		// Planifier le premier batch.
		$batch_data = array(
			'offset'  => 0,
			'step'    => 'posts',
			'scan_id' => $scan_id,
		);

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'omniprivacy_pii_scan_batch', array( $batch_data ) );
		} else {
			$this->process_batch( $batch_data );
		}

		wp_send_json_success( array(
			'message' => __( 'Scan démarré en arrière-plan.', 'omniprivacy-pro' ),
			'scan_id' => $scan_id,
		) );
	}

	/**
	 * Retourne la progression d'un scan en cours via AJAX.
	 */
	public function ajax_scan_progress() {
		check_ajax_referer( 'omniprivacy_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_omniprivacy' ) ) {
			wp_send_json_error( array( 'message' => __( 'Accès non autorisé.', 'omniprivacy-pro' ) ) );
		}

		$scan_id  = isset( $_POST['scan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_id'] ) ) : '';
		$progress = get_transient( 'omniprivacy_scan_progress_' . $scan_id );

		if ( ! $progress ) {
			wp_send_json_success( array(
				'status'    => 'complete',
				'processed' => 0,
				'total'     => 0,
				'found'     => 0,
			) );
		}

		wp_send_json_success( $progress );
	}

	/**
	 * Traite un lot du scan.
	 *
	 * @param array $batch_data Données du batch (offset, step, scan_id).
	 */
	public function process_batch( $batch_data ) {
		$offset     = absint( $batch_data['offset'] ?? 0 );
		$step       = sanitize_text_field( $batch_data['step'] ?? 'posts' );
		$scan_id    = sanitize_text_field( $batch_data['scan_id'] ?? '' );
		$batch_size = absint( get_option( 'omniprivacy_scan_batch_size', 100 ) );
		$has_more   = false;

		switch ( $step ) {
			case 'posts':
				$has_more = $this->scan_posts( $offset, $batch_size );
				break;
			case 'postmeta':
				$has_more = $this->scan_postmeta( $offset, $batch_size );
				break;
			case 'comments':
				$has_more = $this->scan_comments( $offset, $batch_size );
				break;
			case 'media':
				$has_more = $this->scan_media( $offset, $batch_size );
				break;
			default:
				// Étape d'extension (WooCommerce, CF7, WPForms, etc.).
				if ( isset( $this->extension_steps[ $step ] ) && is_callable( $this->extension_steps[ $step ] ) ) {
					$has_more = call_user_func( $this->extension_steps[ $step ], $offset, $batch_size, $this->patterns );
				}
				break;
		}

		// Mettre à jour la progression.
		$this->update_progress( $scan_id, $step, $offset + $batch_size );

		if ( $has_more ) {
			// Même step, offset suivant.
			$next_batch = array(
				'offset'  => $offset + $batch_size,
				'step'    => $step,
				'scan_id' => $scan_id,
			);
		} else {
			// Passer à l'étape suivante.
			$next_step = $this->get_next_step( $step );
			if ( $next_step ) {
				$next_batch = array(
					'offset'  => 0,
					'step'    => $next_step,
					'scan_id' => $scan_id,
				);
			} else {
				$next_batch = null;
			}
		}

		if ( $next_batch ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'omniprivacy_pii_scan_batch', array( $next_batch ) );
			} else {
				$this->process_batch( $next_batch );
			}
		} else {
			// Scan terminé.
			$this->finalize_scan( $scan_id );
		}
	}

	/**
	 * Retourne l'étape suivante ou null si c'est la dernière.
	 *
	 * @param string $current_step Étape courante.
	 * @return string|null
	 */
	private function get_next_step( $current_step ) {
		$index = array_search( $current_step, $this->all_steps, true );
		if ( false === $index || $index >= count( $this->all_steps ) - 1 ) {
			return null;
		}
		return $this->all_steps[ $index + 1 ];
	}

	/**
	 * Met à jour le transient de progression.
	 *
	 * @param string $scan_id  ID du scan.
	 * @param string $step     Étape courante.
	 * @param int    $processed Nombre d'éléments traités dans cette étape.
	 */
	private function update_progress( $scan_id, $step, $processed ) {
		if ( empty( $scan_id ) ) {
			return;
		}

		$progress = get_transient( 'omniprivacy_scan_progress_' . $scan_id );
		if ( ! is_array( $progress ) ) {
			return;
		}

		global $wpdb;
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}omniprivacy_scan_results WHERE status = %s",
				'active'
			)
		);

		$progress['step']      = $step;
		$progress['processed'] = $processed;
		$progress['found']     = $found;

		set_transient( 'omniprivacy_scan_progress_' . $scan_id, $progress, HOUR_IN_SECONDS );
	}

	/**
	 * Finalise un scan : met à jour les options et la progression.
	 *
	 * @param string $scan_id ID du scan.
	 */
	private function finalize_scan( $scan_id ) {
		update_option( 'omniprivacy_last_scan_date', current_time( 'mysql' ) );
		update_option( 'omniprivacy_last_scan_id', $scan_id );

		// Invalider les caches.
		delete_transient( 'omniprivacy_scan_results_cache' );
		delete_transient( 'omniprivacy_dashboard_stats' );

		// Marquer la progression comme terminée.
		$progress = get_transient( 'omniprivacy_scan_progress_' . $scan_id );
		if ( is_array( $progress ) ) {
			global $wpdb;
			$progress['status']       = 'complete';
			$progress['completed_at'] = current_time( 'mysql' );
			$progress['found']        = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}omniprivacy_scan_results WHERE status = %s",
					'active'
				)
			);
			set_transient( 'omniprivacy_scan_progress_' . $scan_id, $progress, HOUR_IN_SECONDS );
		}

		OmniPrivacy_Audit_Logger::log_system(
			'pii_scan_complete',
			'scan',
			array( 'scan_id' => $scan_id, 'found' => $progress['found'] ?? 0 )
		);
	}

	/**
	 * Compte le nombre total approximatif d'éléments à scanner.
	 *
	 * @return int
	 */
	private function count_total_items() {
		global $wpdb;

		$posts    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status != %s AND post_type NOT IN (%s, %s)",
				'auto-draft',
				'revision',
				'attachment'
			)
		);
		$postmeta = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value != '' AND meta_value IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static query, no user input.
		$comments = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- static query, no user input.
		$media    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				'attachment'
			)
		);

		$total = $posts + $postmeta + $comments + $media;

		// Ajouter les éléments des extensions (WooCommerce, CF7, WPForms, etc.).
		return (int) apply_filters( 'omniprivacy_pii_scan_total', $total );
	}

	/**
	 * Scanne la table wp_posts.
	 */
	private function scan_posts( $offset, $batch_size ) {
		global $wpdb;

		$this->preload_ignored( 'post' );

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content, post_excerpt, post_type
				FROM {$wpdb->posts}
				WHERE post_status != 'auto-draft'
				AND post_type NOT IN ('revision', 'attachment')
				ORDER BY ID ASC
				LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			)
		);

		foreach ( $posts as $post ) {
			$this->scan_text( $post->post_title, 'post', $post->ID, 'post_title' );
			$this->scan_text( $post->post_content, 'post', $post->ID, 'post_content' );
			$this->scan_text( $post->post_excerpt, 'post', $post->ID, 'post_excerpt' );
		}

		$this->flush_pending_results();

		return count( $posts ) >= $batch_size;
	}

	/**
	 * Scanne la table wp_postmeta.
	 */
	private function scan_postmeta( $offset, $batch_size ) {
		global $wpdb;

		$this->preload_ignored( 'postmeta' );

		$metas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, post_id, meta_key, meta_value
				FROM {$wpdb->postmeta}
				WHERE meta_value != ''
				AND meta_value IS NOT NULL
				ORDER BY meta_id ASC
				LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			)
		);

		foreach ( $metas as $meta ) {
			// Ne scanner que les valeurs textuelles (pas les sérialisées complexes).
			if ( is_serialized( $meta->meta_value ) ) {
				$unserialized = maybe_unserialize( $meta->meta_value );
				if ( is_string( $unserialized ) ) {
					$this->scan_text( $unserialized, 'postmeta', $meta->post_id, $meta->meta_key );
				}
			} else {
				$this->scan_text( $meta->meta_value, 'postmeta', $meta->post_id, $meta->meta_key );
			}
		}

		$this->flush_pending_results();

		return count( $metas ) >= $batch_size;
	}

	/**
	 * Scanne la table wp_comments.
	 */
	private function scan_comments( $offset, $batch_size ) {
		global $wpdb;

		$this->preload_ignored( 'comment' );

		$comments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_author, comment_author_email, comment_content
				FROM {$wpdb->comments}
				WHERE comment_author_email NOT LIKE %s
				ORDER BY comment_ID ASC
				LIMIT %d OFFSET %d",
				'%@anonymized.local',
				$batch_size,
				$offset
			)
		);

		foreach ( $comments as $comment ) {
			$this->scan_text( $comment->comment_author, 'comment', $comment->comment_ID, 'comment_author' );
			$this->scan_text( $comment->comment_author_email, 'comment', $comment->comment_ID, 'comment_author_email' );
			$this->scan_text( $comment->comment_content, 'comment', $comment->comment_ID, 'comment_content' );
		}

		$this->flush_pending_results();

		return count( $comments ) >= $batch_size;
	}

	/**
	 * Scanne les médias (titres et textes alternatifs).
	 */
	private function scan_media( $offset, $batch_size ) {
		global $wpdb;

		$this->preload_ignored( 'media' );

		$media = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_excerpt, pm.meta_value AS alt_text
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
				WHERE p.post_type = 'attachment'
				ORDER BY p.ID ASC
				LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			)
		);

		foreach ( $media as $item ) {
			$this->scan_text( $item->post_title, 'media', $item->ID, 'title' );
			$this->scan_text( $item->post_excerpt, 'media', $item->ID, 'caption' );
			if ( ! empty( $item->alt_text ) ) {
				$this->scan_text( $item->alt_text, 'media', $item->ID, 'alt_text' );
			}
		}

		$this->flush_pending_results();

		return count( $media ) >= $batch_size;
	}

	/**
	 * Pré-charge les éléments ignorés pour un type de source en une seule requête.
	 *
	 * @param string $source_type Type de source (post, comment, etc.).
	 */
	private function preload_ignored( $source_type ) {
		if ( $this->ignored_cache_type === $source_type && null !== $this->ignored_cache ) {
			return;
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT item_id, field_name FROM {$wpdb->prefix}omniprivacy_ignored_items WHERE item_type = %s",
				$source_type
			)
		);

		$this->ignored_cache = array();
		foreach ( $rows as $row ) {
			$this->ignored_cache[ $row->item_id . '|' . $row->field_name ] = true;
		}
		$this->ignored_cache_type = $source_type;
	}

	/**
	 * Insère les résultats en attente en batch, en évitant les doublons.
	 * Remplace les INSERT individuels + SELECT de vérification par
	 * une seule lecture groupée + un INSERT multi-valeurs.
	 */
	private function flush_pending_results() {
		if ( empty( $this->pending_results ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'omniprivacy_scan_results';

		// Collecter les source_ids uniques pour charger les résultats existants.
		$ids_by_type = array();
		foreach ( $this->pending_results as $r ) {
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
		foreach ( $this->pending_results as $r ) {
			$key = $r['source_type'] . '|' . $r['source_id'] . '|' . $r['field_name'] . '|' . $r['matched_value'] . '|' . $r['pattern_type'];
			if ( ! isset( $existing_set[ $key ] ) ) {
				$to_insert[]          = $r;
				$existing_set[ $key ] = true; // Éviter les doublons intra-batch.
			}
		}

		// Insérer en chunks de 50 pour limiter la taille de la requête.
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

		$this->pending_results = array();
	}

	/**
	 * Applique les patterns regex à un texte et bufferise les résultats.
	 *
	 * @param string $text        Texte à scanner.
	 * @param string $source_type Type de source.
	 * @param int    $source_id   ID de la source.
	 * @param string $field_name  Nom du champ.
	 */
	private function scan_text( $text, $source_type, $source_id, $field_name ) {
		if ( empty( $text ) || ! is_string( $text ) ) {
			return;
		}

		// Vérifier dans le cache mémoire des éléments ignorés (pré-chargé par preload_ignored).
		$ignore_key = $source_id . '|' . $field_name;
		if ( isset( $this->ignored_cache[ $ignore_key ] ) ) {
			return;
		}

		foreach ( $this->patterns as $pattern_type => $regex ) {
			if ( preg_match_all( $regex, $text, $matches ) ) {
				foreach ( array_unique( $matches[0] ) as $match ) {
					$this->pending_results[] = array(
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
}
