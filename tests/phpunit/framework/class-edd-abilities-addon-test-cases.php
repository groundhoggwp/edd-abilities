<?php
/**
 * Base test cases for abilities that only exist when an EDD add-on is active. Each one skips
 * itself when its add-on wasn't loaded by the bootstrap, so the suite still runs on a machine
 * that only has EDD.
 *
 * @package EDD_Abilities
 */

/**
 * Software Licensing.
 */
abstract class EDD_Abilities_Licensing_Test_Case extends EDD_Abilities_Test_Case {

	public function set_up(): void {

		if ( ! function_exists( 'edd_software_licensing' ) ) {
			$this->markTestSkipped( 'EDD Software Licensing is not loaded (set EDD_SL_DIR).' );
		}

		parent::set_up();
	}

	/**
	 * A product with licensing switched on.
	 *
	 * @param array $args post fields, plus 'limit' (activation limit)
	 *
	 * @return int
	 */
	protected function create_licensed_product( array $args = [] ): int {

		$limit = $args['limit'] ?? 2;
		unset( $args['limit'] );

		$id = $this->create_product( $args );

		update_post_meta( $id, '_edd_sl_enabled', 1 );
		update_post_meta( $id, '_edd_sl_limit', $limit );
		update_post_meta( $id, '_edd_sl_exp_length', 1 );
		update_post_meta( $id, '_edd_sl_exp_unit', 'years' );

		return $id;
	}

	/**
	 * Issue a license the way a purchase does, through SL's own create().
	 *
	 * @param int   $product_id a licensed product
	 * @param int   $order_id   the order it was bought on; created if 0
	 * @param array $options    SL creation options: activation_limit, is_lifetime, expiration_date, ...
	 *
	 * @return int license id
	 */
	protected function create_license( int $product_id, int $order_id = 0, array $options = [] ): int {

		if ( ! $order_id ) {
			$order_id = $this->create_order( [ [ 'product_id' => $product_id, 'subtotal' => 20.0 ] ] );
		}

		$ids = ( new EDD_SL_License() )->create( $product_id, $order_id, false, 0, $options );

		$this->assertNotEmpty( $ids, 'Software Licensing did not issue a license.' );

		return (int) $ids[0];
	}
}

/**
 * Recurring Payments.
 */
abstract class EDD_Abilities_Recurring_Test_Case extends EDD_Abilities_Test_Case {

	public function set_up(): void {

		if ( ! class_exists( 'EDD_Subscription' ) || ! class_exists( 'EDD_Subscriptions_DB' ) ) {
			$this->markTestSkipped( 'EDD Recurring Payments is not loaded (set EDD_RECURRING_DIR).' );
		}

		parent::set_up();
	}

	/**
	 * Create a subscription, with its parent order, through EDD Recurring's own create().
	 *
	 * @param array $args subscription fields; product_id / customer_id / parent_payment_id are
	 *                    created when not given
	 *
	 * @return EDD_Subscription
	 */
	protected function create_subscription( array $args = [] ): EDD_Subscription {

		$product_id  = $args['product_id'] ?? $this->create_product( [ 'price' => '10.00' ] );
		$customer_id = $args['customer_id'] ?? $this->create_customer();

		$order_id = $args['parent_payment_id'] ?? $this->create_order(
			[ [ 'product_id' => $product_id, 'subtotal' => 10.0 ] ],
			[ 'customer_id' => $customer_id ]
		);

		$subscription = new EDD_Subscription();
		$subscription->create( array_merge( [
			'customer_id'        => $customer_id,
			'period'             => 'month',
			'initial_amount'     => '10.00',
			'recurring_amount'   => '10.00',
			'bill_times'         => 0,
			'parent_payment_id'  => $order_id,
			'product_id'         => $product_id,
			'price_id'           => null,
			'created'            => gmdate( 'Y-m-d H:i:s' ),
			'expiration'         => gmdate( 'Y-m-d 23:59:59', strtotime( '+1 month' ) ),
			'status'             => 'active',
			'profile_id'         => 'profile_' . wp_generate_password( 8, false ),
		], $args, [
			'product_id'        => $product_id,
			'customer_id'       => $customer_id,
			'parent_payment_id' => $order_id,
		] ) );

		$this->assertNotEmpty( $subscription->id, 'EDD Recurring did not create the subscription.' );

		return $subscription;
	}
}
