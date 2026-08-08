<?php
/** English display names for product terms, stored on the original term. */

defined( 'ABSPATH' ) || exit;

function kuka_island_term_name( WP_Term $term ): string {
	if ( function_exists( 'kuka_island_is_english' ) && kuka_island_is_english() ) {
		$english = trim( (string) get_term_meta( $term->term_id, '_kuka_name_en', true ) );
		if ( '' !== $english ) { return $english; }
	}
	return $term->name;
}

final class Kuka_Island_Core_Taxonomy_Translations {
	private const TAXONOMIES = array( 'product_cat', 'pa_renk', 'pa_kesim', 'pa_beden' );

	public function register(): void {
		foreach ( self::TAXONOMIES as $taxonomy ) {
			add_action( $taxonomy . '_add_form_fields', array( $this, 'add_field' ) );
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'edit_field' ) );
			add_action( 'created_' . $taxonomy, array( $this, 'save' ) );
			add_action( 'edited_' . $taxonomy, array( $this, 'save' ) );
		}
		add_filter( 'get_terms', array( $this, 'translate_terms' ), 20, 4 );
		add_filter( 'get_term', array( $this, 'translate_term' ), 20, 2 );
		add_filter( 'woocommerce_attribute_label', array( $this, 'attribute_label' ), 20, 3 );
	}

	public function add_field(): void {
		wp_nonce_field( 'kuka_term_english', 'kuka_term_english_nonce' );
		echo '<div class="form-field"><label for="kuka_name_en">' . esc_html__( 'English name', 'kuka-island-core' ) . '</label><input name="kuka_name_en" id="kuka_name_en" type="text"><p>' . esc_html__( 'Leave empty to show the Turkish term name.', 'kuka-island-core' ) . '</p></div>';
	}

	public function edit_field( WP_Term $term ): void {
		$value = (string) get_term_meta( $term->term_id, '_kuka_name_en', true );
		wp_nonce_field( 'kuka_term_english', 'kuka_term_english_nonce' );
		echo '<tr class="form-field"><th scope="row"><label for="kuka_name_en">' . esc_html__( 'English name', 'kuka-island-core' ) . '</label></th><td><input name="kuka_name_en" id="kuka_name_en" type="text" value="' . esc_attr( $value ) . '"><p class="description">' . esc_html__( 'Leave empty to show the Turkish term name.', 'kuka-island-core' ) . '</p></td></tr>';
	}

	public function save( int $term_id ): void {
		if ( ! current_user_can( 'manage_product_terms' ) ) { return; }
		if ( ! isset( $_POST['kuka_term_english_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kuka_term_english_nonce'] ) ), 'kuka_term_english' ) ) { return; }
		update_term_meta( $term_id, '_kuka_name_en', sanitize_text_field( wp_unslash( $_POST['kuka_name_en'] ?? '' ) ) );
	}

	public function translate_terms( array $terms, array $taxonomies, array $args, WP_Term_Query $query ): array {
		unset( $args, $query );
		if ( ! function_exists( 'kuka_island_is_english' ) || ! kuka_island_is_english() || ! array_intersect( self::TAXONOMIES, $taxonomies ) ) { return $terms; }
		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term && in_array( $term->taxonomy, self::TAXONOMIES, true ) ) {
				$term->name = kuka_island_term_name( $term );
			}
		}
		return $terms;
	}

	public function translate_term( WP_Term $term, string $taxonomy ): WP_Term {
		if ( in_array( $taxonomy, self::TAXONOMIES, true ) && function_exists( 'kuka_island_is_english' ) && kuka_island_is_english() ) {
			$term->name = kuka_island_term_name( $term );
		}
		return $term;
	}

	public function attribute_label( string $label, string $name, $product ): string {
		unset( $product );
		if ( ! function_exists( 'kuka_island_is_english' ) || ! kuka_island_is_english() ) { return $label; }
		return array( 'pa_renk' => 'Color', 'pa_kesim' => 'Cut', 'pa_beden' => 'Size' )[ $name ] ?? $label;
	}
}
