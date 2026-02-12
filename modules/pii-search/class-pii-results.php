<?php
/**
 * OmniPrivacy Pro — PII Results
 *
 * Affichage des résultats de scan via WP_List_Table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class OmniPrivacy_PII_Results extends WP_List_Table {

	public function __construct() {
		parent::__construct( array(
			'singular' => __( 'Résultat PII', 'omniprivacy-pro' ),
			'plural'   => __( 'Résultats PII', 'omniprivacy-pro' ),
			'ajax'     => false,
		) );
	}

	/**
	 * Colonnes du tableau.
	 */
	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox" />',
			'pattern_type'  => __( 'Type', 'omniprivacy-pro' ),
			'source_type'   => __( 'Source', 'omniprivacy-pro' ),
			'field_name'    => __( 'Champ', 'omniprivacy-pro' ),
			'matched_value' => __( 'Valeur détectée', 'omniprivacy-pro' ),
			'status'        => __( 'Statut', 'omniprivacy-pro' ),
			'actions'       => __( 'Actions', 'omniprivacy-pro' ),
		);
	}

	/**
	 * Colonnes triables.
	 */
	public function get_sortable_columns() {
		return array(
			'pattern_type' => array( 'pattern_type', false ),
			'source_type'  => array( 'source_type', false ),
			'status'       => array( 'status', false ),
		);
	}

	/**
	 * Actions groupées.
	 */
	public function get_bulk_actions() {
		return array(
			'anonymize' => __( 'Anonymiser', 'omniprivacy-pro' ),
			'ignore'    => __( 'Ignorer', 'omniprivacy-pro' ),
		);
	}

	/**
	 * Prépare les items pour l'affichage.
	 */
	public function prepare_items() {
		global $wpdb;

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		// Filtres.
		$where = array( '1=1' );
		$args  = array();

		$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'active';
		if ( $status_filter && 'all' !== $status_filter ) {
			$where[] = 'status = %s';
			$args[]  = $status_filter;
		}

		$type_filter = isset( $_GET['pattern_type'] ) ? sanitize_text_field( wp_unslash( $_GET['pattern_type'] ) ) : '';
		if ( $type_filter ) {
			$where[] = 'pattern_type = %s';
			$args[]  = $type_filter;
		}

		$where_sql = implode( ' AND ', $where );

		// Tri (whitelist strict pour éviter l'injection SQL).
		$allowed_orderby = array( 'pattern_type', 'source_type', 'status', 'scan_date' );
		$raw_orderby     = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : '';
		$order_col       = in_array( $raw_orderby, $allowed_orderby, true ) ? $raw_orderby : 'scan_date';
		$raw_order       = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( $_GET['order'] ) ) : '';
		$order_dir       = 'DESC' === strtoupper( $raw_order ) ? 'DESC' : 'ASC';
		$orderby         = $order_col . ' ' . $order_dir;

		// Compter le total.
		$count_query = "SELECT COUNT(*) FROM {$wpdb->prefix}omniprivacy_scan_results WHERE {$where_sql}";
		$total_items = $wpdb->get_var( empty( $args ) ? $count_query : $wpdb->prepare( $count_query, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Récupérer les items.
		$query = "SELECT * FROM {$wpdb->prefix}omniprivacy_scan_results WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$query_args   = array_merge( $args, array( $per_page, $offset ) );
		$this->items  = $wpdb->get_results( $wpdb->prepare( $query, $query_args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total_items / $per_page ),
		) );

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);
	}

	/**
	 * Colonne checkbox.
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="items[]" value="%d" />', absint( $item->id ) );
	}

	/**
	 * Colonne par défaut.
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'pattern_type':
				return esc_html( ucfirst( $item->pattern_type ) );
			case 'source_type':
				return esc_html( ucfirst( $item->source_type ) ) . ' #' . absint( $item->source_id );
			case 'field_name':
				return esc_html( $item->field_name );
			case 'matched_value':
				return '<code>' . esc_html( $item->matched_value ) . '</code>';
			case 'status':
				$labels = array(
					'active'     => __( 'Actif', 'omniprivacy-pro' ),
					'anonymized' => __( 'Anonymisé', 'omniprivacy-pro' ),
					'ignored'    => __( 'Ignoré', 'omniprivacy-pro' ),
				);
				return esc_html( $labels[ $item->status ] ?? $item->status );
			case 'actions':
				return $this->render_actions( $item );
			default:
				return '';
		}
	}

	/**
	 * Boutons d'action pour chaque ligne.
	 */
	private function render_actions( $item ) {
		if ( 'active' !== $item->status ) {
			return '—';
		}

		$edit_url = $this->get_edit_url( $item );

		$actions  = '';
		if ( $edit_url ) {
			$actions .= sprintf(
				'<a href="%s" target="_blank" class="button button-small">%s</a> ',
				esc_url( $edit_url ),
				esc_html__( 'Éditer', 'omniprivacy-pro' )
			);
		}

		$actions .= sprintf(
			'<button class="button button-small omniprivacy-anonymize" data-id="%d">%s</button> ',
			absint( $item->id ),
			esc_html__( 'Anonymiser', 'omniprivacy-pro' )
		);

		$actions .= sprintf(
			'<button class="button button-small omniprivacy-ignore" data-id="%d">%s</button>',
			absint( $item->id ),
			esc_html__( 'Ignorer', 'omniprivacy-pro' )
		);

		return $actions;
	}

	/**
	 * Génère l'URL d'édition selon le type de source.
	 */
	private function get_edit_url( $item ) {
		switch ( $item->source_type ) {
			case 'post':
			case 'media':
				return get_edit_post_link( $item->source_id, 'raw' );
			case 'comment':
				return admin_url( 'comment.php?action=editcomment&c=' . absint( $item->source_id ) );
			default:
				return '';
		}
	}
}
