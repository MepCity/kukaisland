<?php
/**
 * Production test-fixture guard.
 *
 * Single source of truth for the "is this order a synthetic test fixture?"
 * decision. Both Kuka_Island_Core_Invoice_Queue and
 * Kuka_Island_Core_Invoice_Manager consult this class, so the queue no longer
 * has to reach into a protected manager method (which produced a fatal error
 * on the automatic send path).
 *
 * Design constraints:
 * - The class is final and the decision method is static, so no subclass can
 *   weaken the guard by overriding it.
 * - There is deliberately NO toggle, filter, option or constant that turns the
 *   guard off. A fixture-marked order can never be invoiced for real.
 *
 * @package Kuka_Island_EDM
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Invoice_Fixture_Guard {
	/**
	 * Order meta key that permanently marks an order as a synthetic fixture.
	 */
	public const META_FIXTURE = '_kuka_test_fixture';

	/**
	 * Safe error code emitted when a fixture order reaches the invoice pipeline.
	 */
	public const ERROR_CODE = 'test_fixture_rejected';

	/**
	 * Is the given order a synthetic test/sandbox fixture?
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public static function is_test_fixture_order( WC_Order $order ): bool {
		$flag = $order->get_meta( self::META_FIXTURE, true );

		return '1' === (string) $flag || 1 === $flag || true === $flag;
	}

	/**
	 * Throw the canonical permanent rejection for a fixture order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @throws Kuka_Island_Core_Invoice_Permanent_Exception Always.
	 */
	public static function assert_not_fixture( WC_Order $order ): void {
		if ( ! self::is_test_fixture_order( $order ) ) {
			return;
		}

		throw new Kuka_Island_Core_Invoice_Permanent_Exception(
			'Test fixture orders cannot be sent for production invoicing.',
			self::ERROR_CODE,
			__( 'Test veya sandbox siparişleri için gerçek fatura kesilemez.', 'kuka-island-edm' )
		);
	}
}
