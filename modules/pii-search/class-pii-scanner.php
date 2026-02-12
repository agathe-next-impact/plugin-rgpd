<?php
/**
 * OmniPrivacy Pro — PII Scanner
 *
 * Moteur de scan par lots pour détecter les données personnelles
 * dans les contenus, métadonnées, médias et commentaires.
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

	public function __construct() {
		$this->patterns = array(
			'email'    => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
			'phone_fr' => '/(?:\+33|0)\s*[1-9](?:[\s.\-]*\d{2}){4}/',
			'iban'     => '/[A-Z]{2}\d{2}[\s]?[\dA-Z]{4}[\s]?(?:[\dA-Z]{4}[\s]?){2,7}[\dA-Z]{1,4}/',
		);

		// Permettre l'ajout de patterns personnalisés via filtre.
		$this->patterns = apply_filters( 'omniprivacy_pii_patterns', $this->patterns );
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

		// Nettoyer les résultats précédents (sauf ignorés).
		$wpdb->delete(
			$wpdb->prefix . 'omniprivacy_scan_results',
			array( 'status' => 'active' ),
			array( '%s' )
		);

		// Planifier le premier batch.
		$batch_data = array(
			'offset'  => 0,
			'step'    => 'posts',
			'scan_id' => wp_generate_uuid4(),
		);

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'omniprivacy_pii_scan_batch', array( $batch_data ) );
		} else {
			$this->process_batch( $batch_data );
		}

		wp_send_json_success( array(
			'message' => __( 'Scan démarré en arrière-plan.', 'omniprivacy-pro' ),
			'scan_id' => $batch_data['scan_id'],
		) );
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
				if ( ! $has_more ) {
					$step   = 'postmeta';
					$offset = 0;
					$has_more = true;
				}
				break;

			case 'postmeta':
				$has_more = $this->scan_postmeta( $offset, $batch_size );
				if ( ! $has_more ) {
					$step   = 'comments';
					$offset = 0;
					$has_more = true;
				}
				break;

			case 'comments':
				$has_more = $this->scan_comments( $offset, $batch_size );
				if ( ! $has_more ) {
					$step   = 'media';
					$offset = 0;
					$has_more = true;
				}
				break;

			case 'media':
				$has_more = $this->scan_media( $offset, $batch_size );
				break;
		}

		// S'il reste des données, planifier le batch suivant.
		if ( $has_more ) {
			$next_batch = array(
				'offset'  => $offset + $batch_size,
				'step'    => $step,
				'scan_id' => $scan_id,
			);

			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'omniprivacy_pii_scan_batch', array( $next_batch ) );
			} else {
				$this->process_batch( $next_batch );
			}
		} else {
			// Scan terminé.
			update_option( 'omniprivacy_last_scan_date', current_time( 'mysql' ) );
			update_option( 'omniprivacy_last_scan_id', $scan_id );
		}
	}

	/**
	 * Scanne la table wp_posts.
	 *
	 * @param int $offset     Décalage.
	 * @param int $batch_size Taille du lot.
	 * @return bool True s'il reste des éléments.
	 */
	private function scan_posts( $offset, $batch_size ) {
		global $wpdb;

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_content, post_excerpt, post_type
				FROM {$wpdb->posts}
				WHERE post_status != 'auto-draft'
				AND post_type != 'revision'
				AND post_type != 'attachment'
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

		return count( $posts ) >= $batch_size;
	}

	/**
	 * Scanne la table wp_postmeta.
	 *
	 * @param int $offset     Décalage.
	 * @param int $batch_size Taille du lot.
	 * @return bool True s'il reste des éléments.
	 */
	private function scan_postmeta( $offset, $batch_size ) {
		global $wpdb;

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
			$this->scan_text( $meta->meta_value, 'postmeta', $meta->post_id, $meta->meta_key );
		}

		return count( $metas ) >= $batch_size;
	}

	/**
	 * Scanne la table wp_comments.
	 *
	 * @param int $offset     Décalage.
	 * @param int $batch_size Taille du lot.
	 * @return bool True s'il reste des éléments.
	 */
	private function scan_comments( $offset, $batch_size ) {
		global $wpdb;

		$comments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_author, comment_author_email, comment_content
				FROM {$wpdb->comments}
				ORDER BY comment_ID ASC
				LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			)
		);

		foreach ( $comments as $comment ) {
			$this->scan_text( $comment->comment_author, 'comment', $comment->comment_ID, 'comment_author' );
			$this->scan_text( $comment->comment_author_email, 'comment', $comment->comment_ID, 'comment_author_email' );
			$this->scan_text( $comment->comment_content, 'comment', $comment->comment_ID, 'comment_content' );
		}

		return count( $comments ) >= $batch_size;
	}

	/**
	 * Scanne les médias (titres et textes alternatifs).
	 *
	 * @param int $offset     Décalage.
	 * @param int $batch_size Taille du lot.
	 * @return bool True s'il reste des éléments.
	 */
	private function scan_media( $offset, $batch_size ) {
		global $wpdb;

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

		return count( $media ) >= $batch_size;
	}

	/**
	 * Applique les patterns regex à un texte et stocke les résultats.
	 *
	 * @param string $text        Texte à scanner.
	 * @param string $source_type Type de source (post, comment, media, postmeta).
	 * @param int    $source_id   ID de la source.
	 * @param string $field_name  Nom du champ.
	 */
	private function scan_text( $text, $source_type, $source_id, $field_name ) {
		if ( empty( $text ) ) {
			return;
		}

		global $wpdb;

		foreach ( $this->patterns as $pattern_type => $regex ) {
			if ( preg_match_all( $regex, $text, $matches ) ) {
				foreach ( array_unique( $matches[0] ) as $match ) {
					// Vérifier que cet élément n'est pas déjà ignoré.
					$ignored = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$wpdb->prefix}omniprivacy_ignored_items
							WHERE item_type = %s AND item_id = %d AND field_name = %s",
							$source_type,
							$source_id,
							$field_name
						)
					);

					if ( $ignored > 0 ) {
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
