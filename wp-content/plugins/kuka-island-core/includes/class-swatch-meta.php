<?php
/**
 * Color swatch values live on pa_renk terms, never in theme CSS.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Swatch_Meta {
	private const META_KEY = 'kuka_swatch_hex';

	public function register(): void {
		add_action( 'pa_renk_add_form_fields', array( $this, 'render_add_field' ) );
		add_action( 'pa_renk_edit_form_fields', array( $this, 'render_edit_field' ) );
		add_action( 'created_pa_renk', array( $this, 'save_field' ) );
		add_action( 'edited_pa_renk', array( $this, 'save_field' ) );
	}

	public function render_add_field(): void {
		wp_nonce_field( 'kuka_swatch_term', 'kuka_swatch_nonce' );
		?>
		<div class="form-field">
			<label for="kuka_swatch_hex"><?php echo esc_html__( 'Swatch rengi', 'kuka-island-core' ); ?></label>
			<input name="kuka_swatch_hex" id="kuka_swatch_hex" type="color" value="#3c2a12">
		</div>
		<?php
	}

	public function render_edit_field( WP_Term $term ): void {
		$value = (string) get_term_meta( $term->term_id, self::META_KEY, true );
		wp_nonce_field( 'kuka_swatch_term', 'kuka_swatch_nonce' );
		?>
		<tr class="form-field">
			<th scope="row"><label for="kuka_swatch_hex"><?php echo esc_html__( 'Swatch rengi', 'kuka-island-core' ); ?></label></th>
			<td><input name="kuka_swatch_hex" id="kuka_swatch_hex" type="color" value="<?php echo esc_attr( $value ?: '#3c2a12' ); ?>"></td>
		</tr>
		<?php
	}

	public function save_field( int $term_id ): void {
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}
		if ( ! isset( $_POST['kuka_swatch_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kuka_swatch_nonce'] ) ), 'kuka_swatch_term' ) ) {
			return;
		}

		$value = isset( $_POST['kuka_swatch_hex'] ) ? sanitize_hex_color( wp_unslash( $_POST['kuka_swatch_hex'] ) ) : '';
		if ( $value ) {
			update_term_meta( $term_id, self::META_KEY, $value );
		}
	}
}

