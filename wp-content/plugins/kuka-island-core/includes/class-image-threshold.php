<?php
/**
 * Measured source-media threshold.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Image_Threshold {
	public const THRESHOLD = 2000;

	public function register(): void {
		add_filter( 'big_image_size_threshold', array( $this, 'filter_threshold' ) );
	}

	public function filter_threshold(): int {
		return self::THRESHOLD;
	}
}

