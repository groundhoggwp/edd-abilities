<?php

/**
 * edd-alt/create-order.
 */
class Create_Order_Test extends EDD_Abilities_Test_Case {

	/** @var int[] wp_mail() calls seen during a test */
	private $mails = [];

	public function set_up(): void {
		parent::set_up();

		$this->mails = [];

		// EDD runs its after-payment work (receipts, stats) from cron by default; run it inline so the
		// tests see its effects - without this a "no email was sent" check would pass vacuously.
		add_filter( 'edd_use_after_payment_actions', '__return_false' );

		// Count mail instead of sending it.
		add_filter( 'pre_wp_mail', function ( $short_circuit, $atts ) {
			$this->mails[] = $atts['to'];

			return true;
		}, 10, 2 );
	}

	private function create( array $input ) {
		return $this->run_ability( 'edd-alt/create-order', $input );
	}

	private function create_ok( array $input ): array {
		return $this->run_ok( 'edd-alt/create-order', $input );
	}

	private function variable_product(): int {
		$id = $this->create_product();

		update_post_meta( $id, '_variable_pricing', 1 );
		update_post_meta( $id, 'edd_variable_prices', [
			1 => [ 'index' => 1, 'name' => 'Personal', 'amount' => '49.00' ],
			2 => [ 'index' => 2, 'name' => 'Agency', 'amount' => '149.00' ],
		] );

		return $id;
	}

	private function discount( array $args = [] ): int {
		$id = edd_add_discount( array_merge( [
			'name'        => 'Test',
			'code'        => 'TEST20',
			'status'      => 'active',
			'amount_type' => 'percent',
			'amount'      => 20,
			'type'        => 'discount',
			'scope'       => 'global',
		], $args ) );

		$this->assertNotEmpty( $id );

		return (int) $id;
	}

	/* ---------------------------------------------------------------------------------------- */
	/* the order itself                                                                         */
	/* ---------------------------------------------------------------------------------------- */

	public function test_creates_a_completed_order_for_an_existing_customer() {

		$customer = $this->create_customer( [ 'email' => 'buyer@example.com' ] );
		$product  = $this->create_product( [ 'post_title' => 'Widget', 'price' => '25.00' ] );

		$order = $this->create_ok( [ 'customer_id' => $customer, 'items' => [ [ 'product_id' => $product ] ] ] );

		$this->assertSame( 'complete', $order['status'] );
		$this->assertSame( 'sale', $order['type'] );
		$this->assertSame( $customer, $order['customer_id'] );
		$this->assertSame( 'buyer@example.com', $order['email'] );
		$this->assertSame( 'manual', $order['gateway'] );
		$this->assertSame( 'USD', $order['currency'] );
		$this->assertEqualsWithDelta( 25.0, $order['subtotal'], 0.001 );
		$this->assertEqualsWithDelta( 25.0, $order['total'], 0.001 );
		$this->assertFalse( $order['tax_calculated'] );

		$this->assertCount( 1, $order['items'] );
		$this->assertSame( 'Widget', $order['items'][0]['product_name'] );
		$this->assertSame( 'complete', $order['items'][0]['status'] );

		// And it's a real, readable order.
		$this->assertSame( $order['id'], $this->run_ok( 'edd-alt/get-order', [ 'id' => $order['id'] ] )['id'] );
	}

	public function test_completing_the_order_counts_towards_the_customer_and_the_product() {

		$customer = $this->create_customer();
		$product  = $this->create_product( [ 'price' => '25.00' ] );

		$this->create_ok( [ 'customer_id' => $customer, 'items' => [ [ 'product_id' => $product, 'quantity' => 2 ] ] ] );

		$fresh = edd_get_customer( $customer );
		$this->assertSame( 1, (int) $fresh->purchase_count );
		$this->assertEqualsWithDelta( 50.0, (float) $fresh->purchase_value, 0.001 );

		// Reports read the product's sales from its completed order items.
		$items = edd_get_order_items( [ 'product_id' => $product, 'status' => 'complete' ] );
		$this->assertCount( 1, $items );
		$this->assertSame( 2, (int) $items[0]->quantity );
	}

	public function test_a_pending_order_does_not_count_until_it_is_completed() {

		$customer = $this->create_customer();
		$product  = $this->create_product( [ 'price' => '25.00' ] );

		$order = $this->create_ok( [ 'customer_id' => $customer, 'status' => 'pending', 'items' => [ [ 'product_id' => $product ] ] ] );

		$this->assertSame( 'pending', $order['status'] );
		$this->assertSame( 'pending', $order['items'][0]['status'] );
		$this->assertSame( 0, (int) edd_get_customer( $customer )->purchase_count );

		// Completing it later does count it - the order was built the way a real one is.
		$this->run_ok( 'edd-alt/update-order-status', [ 'id' => $order['id'], 'status' => 'complete' ] );
		$this->assertSame( 1, (int) edd_get_customer( $customer )->purchase_count );
	}

	public function test_several_items_quantities_and_a_price_override() {

		$customer = $this->create_customer();
		$a        = $this->create_product( [ 'price' => '10.00' ] );
		$b        = $this->create_product( [ 'price' => '20.00' ] );

		$order = $this->create_ok( [
			'customer_id' => $customer,
			'items'       => [
				[ 'product_id' => $a, 'quantity' => 3 ],
				[ 'product_id' => $b, 'price' => 5.5 ],
			],
		] );

		$this->assertEqualsWithDelta( 35.5, $order['total'], 0.001 ); // 3 x 10 + 1 x 5.50, not the product's own 20
		$this->assertSame( [ 3, 1 ], wp_list_pluck( $order['items'], 'quantity' ) );
		$this->assertEqualsWithDelta( 5.5, $order['items'][1]['total'], 0.001 );
	}

	public function test_a_price_of_zero_makes_the_item_free() {

		$order = $this->create_ok( [
			'customer_id' => $this->create_customer(),
			'items'       => [ [ 'product_id' => $this->create_product( [ 'price' => '99.00' ] ), 'price' => 0 ] ],
		] );

		$this->assertEqualsWithDelta( 0.0, $order['total'], 0.001 );
		$this->assertSame( 'complete', $order['status'] );
	}

	public function test_a_variable_priced_product_uses_the_chosen_price_option() {

		$product = $this->variable_product();

		$order = $this->create_ok( [ 'customer_id' => $this->create_customer(), 'items' => [ [ 'product_id' => $product, 'price_id' => 2 ] ] ] );

		$this->assertEqualsWithDelta( 149.0, $order['total'], 0.001 );
		$this->assertSame( 2, $order['items'][0]['price_id'] );
	}

	public function test_the_gateway_and_a_private_note_are_recorded() {

		$order = $this->create_ok( [
			'customer_id' => $this->create_customer(),
			'items'       => [ [ 'product_id' => $this->create_product() ] ],
			'gateway'     => 'paypal_commerce',
			'note'        => 'Paid by cheque, ref 123',
		] );

		$this->assertSame( 'paypal_commerce', $order['gateway'] );

		$notes = wp_list_pluck( $this->run_ok( 'edd-alt/get-order', [ 'id' => $order['id'], 'include' => [ 'notes' ] ] )['notes'], 'content' );
		$this->assertContains( 'Paid by cheque, ref 123', $notes );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* discounts                                                                                */
	/* ---------------------------------------------------------------------------------------- */

	public function test_a_percentage_discount_is_shared_across_the_items_and_counted_as_used() {

		$this->discount();
		$a = $this->create_product( [ 'price' => '30.00' ] );
		$b = $this->create_product( [ 'price' => '70.00' ] );

		$order = $this->create_ok( [ 'customer_id' => $this->create_customer(), 'discount_code' => 'test20', 'items' => [ [ 'product_id' => $a ], [ 'product_id' => $b ] ] ] );

		$this->assertEqualsWithDelta( 100.0, $order['subtotal'], 0.001 );
		$this->assertEqualsWithDelta( 20.0, $order['discount'], 0.001 );
		$this->assertEqualsWithDelta( 80.0, $order['total'], 0.001 );

		$this->assertEqualsWithDelta( 20.0, array_sum( wp_list_pluck( $order['items'], 'discount' ) ), 0.001, 'The item rows add up to the order discount.' );
		$this->assertEqualsWithDelta( 6.0, $order['items'][0]['discount'], 0.001 );
		$this->assertSame( [ 'TEST20' ], wp_list_pluck( $order['discounts'], 'code' ) );

		$this->assertSame( 1, (int) edd_get_discount_by_code( 'TEST20' )->use_count );
	}

	public function test_a_flat_discount_bigger_than_the_order_cannot_make_it_negative() {

		$this->discount( [ 'code' => 'BIG', 'amount_type' => 'flat', 'amount' => 500 ] );

		$order = $this->create_ok( [ 'customer_id' => $this->create_customer(), 'discount_code' => 'BIG', 'items' => [ [ 'product_id' => $this->create_product( [ 'price' => '40.00' ] ) ] ] ] );

		$this->assertEqualsWithDelta( 40.0, $order['discount'], 0.001 );
		$this->assertEqualsWithDelta( 0.0, $order['total'], 0.001 );
	}

	/**
	 * @dataProvider unusable_discounts
	 */
	public function test_an_unusable_discount_is_refused_and_creates_nothing( array $args, string $code, string $error ) {

		$this->discount( $args );
		$orders = edd_count_orders();

		$result = $this->create( [ 'email' => 'nobody@example.com', 'discount_code' => $code, 'items' => [ [ 'product_id' => $this->create_product( [ 'price' => '10.00' ] ) ] ] ] );

		$this->assertAbilityError( $error, $result );
		$this->assertSame( $orders, edd_count_orders() );
		$this->assertEmpty( edd_get_customer_by( 'email', 'nobody@example.com' ), 'A refused order must not leave a new customer behind.' );
	}

	public function unusable_discounts(): array {
		return [
			'unknown code'      => [ [ 'code' => 'REAL' ], 'NOPE', 'edd_abilities_invalid_discount' ],
			'inactive code'     => [ [ 'code' => 'OFF', 'status' => 'inactive' ], 'OFF', 'edd_abilities_invalid_discount' ],
			'used up'           => [ [ 'code' => 'ONCE', 'max_uses' => 1, 'use_count' => 1 ], 'ONCE', 'edd_abilities_discount_maxed_out' ],
			'minimum not met'   => [ [ 'code' => 'BIGSPEND', 'min_charge_amount' => 50 ], 'BIGSPEND', 'edd_abilities_discount_minimum_not_met' ],
		];
	}

	/* ---------------------------------------------------------------------------------------- */
	/* customers                                                                                */
	/* ---------------------------------------------------------------------------------------- */

	public function test_an_unknown_email_creates_a_customer_with_the_given_name() {

		$order = $this->create_ok( [ 'email' => 'New.Buyer@Example.com', 'name' => 'New Buyer', 'items' => [ [ 'product_id' => $this->create_product() ] ] ] );

		$customer = edd_get_customer( $order['customer_id'] );
		$this->assertSame( 'new.buyer@example.com', strtolower( $customer->email ) );
		$this->assertSame( 'New Buyer', $customer->name );
	}

	public function test_a_known_email_reuses_the_customer_and_a_matching_wordpress_user_is_linked() {

		$user_id  = self::factory()->user->create( [ 'user_email' => 'member@example.com' ] );
		$existing = $this->create_customer( [ 'email' => 'known@example.com' ] );

		$again = $this->create_ok( [ 'email' => 'known@example.com', 'items' => [ [ 'product_id' => $this->create_product() ] ] ] );
		$this->assertSame( $existing, $again['customer_id'] );

		$linked = $this->create_ok( [ 'email' => 'member@example.com', 'items' => [ [ 'product_id' => $this->create_product() ] ] ] );
		$this->assertSame( $user_id, $linked['user_id'] );
	}

	public function test_without_a_usable_customer_nothing_is_created() {

		$product = $this->create_product();
		$orders  = edd_count_orders();

		$this->assertAbilityError( 'edd_abilities_customer_required', $this->create( [ 'items' => [ [ 'product_id' => $product ] ] ] ) );
		$this->assertAbilityError( 'edd_abilities_not_found', $this->create( [ 'customer_id' => 999999, 'items' => [ [ 'product_id' => $product ] ] ] ) );

		$this->assertSame( $orders, edd_count_orders() );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* products                                                                                 */
	/* ---------------------------------------------------------------------------------------- */

	public function test_products_it_cannot_sell_correctly_are_refused_and_create_nothing() {

		$customer = $this->create_customer();
		$orders   = edd_count_orders();
		$bundle   = $this->create_product();
		update_post_meta( $bundle, '_edd_product_type', 'bundle' );

		$trashed = $this->create_product( [ 'post_status' => 'trash' ] );

		foreach ( [
			[ 'edd_abilities_not_found', 999999 ],
			[ 'edd_abilities_not_found', $trashed ],
			[ 'edd_abilities_unsupported_product', $bundle ],
		] as list( $error, $product_id ) ) {
			$this->assertAbilityError( $error, $this->create( [ 'customer_id' => $customer, 'items' => [ [ 'product_id' => $product_id ] ] ] ) );
		}

		$this->assertSame( $orders, edd_count_orders() );
	}

	public function test_a_variable_priced_product_needs_a_valid_price_option() {

		$customer = $this->create_customer();
		$product  = $this->variable_product();

		$this->assertAbilityError( 'edd_abilities_price_id_required', $this->create( [ 'customer_id' => $customer, 'items' => [ [ 'product_id' => $product ] ] ] ) );
		$this->assertAbilityError( 'edd_abilities_invalid_price_id', $this->create( [ 'customer_id' => $customer, 'items' => [ [ 'product_id' => $product, 'price_id' => 9 ] ] ] ) );
	}

	public function test_one_bad_item_stops_the_whole_order() {

		$customer = $this->create_customer();
		$orders   = edd_count_orders();

		$result = $this->create( [ 'customer_id' => $customer, 'items' => [ [ 'product_id' => $this->create_product() ], [ 'product_id' => 999999 ] ] ] );

		$this->assertAbilityError( 'edd_abilities_not_found', $result );
		$this->assertSame( $orders, edd_count_orders() );
		$this->assertSame( 0, (int) edd_get_customer( $customer )->purchase_count );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* emails                                                                                   */
	/* ---------------------------------------------------------------------------------------- */

	/**
	 * Capture the email flags as EDD's own mailer would see them when the order completes, then stop
	 * it there. (EDD's receipt template can't be installed in the test database, so a real send isn't
	 * possible here - what matters is what this ability hands to EDD.)
	 *
	 * Fills $this->flags_seen with one [ receipt, admin_notice, flags_exist ] entry per completed order.
	 */
	private function capture_email_flags(): void {

		$seen = new \ArrayObject();

		add_action( 'edd_after_order_actions', function ( $order_id ) use ( $seen ) {
			$seen[] = [
				edd_get_order_meta( $order_id, '_edd_should_send_order_receipt', true ),
				edd_get_order_meta( $order_id, '_edd_should_send_admin_order_notice', true ),
				metadata_exists( 'edd_order', $order_id, '_edd_should_send_order_receipt' ) && metadata_exists( 'edd_order', $order_id, '_edd_should_send_admin_order_notice' ),
			];

			remove_all_actions( 'edd_after_order_actions' );
		}, 1, 3 );

		$this->flags_seen = $seen;
	}

	/** @var \ArrayObject|null */
	private $flags_seen;

	public function test_no_email_is_sent_unless_asked_for() {

		$this->capture_email_flags();

		$this->create_ok( [ 'email' => 'quiet@example.com', 'items' => [ [ 'product_id' => $this->create_product() ] ] ] );

		$this->assertCount( 1, $this->flags_seen, 'EDD should have reached its after-order step.' );
		list( $receipt, $notice, $exist ) = $this->flags_seen[0];

		// The flags must exist (EDD treats a missing flag as "use the legacy path") and be falsy.
		$this->assertTrue( $exist );
		$this->assertEmpty( $receipt, 'The default must not email the customer.' );
		$this->assertEmpty( $notice, 'The default must not email the store.' );
		$this->assertSame( [], $this->mails );
	}

	public function test_send_emails_false_is_the_same_as_leaving_it_out() {

		$this->capture_email_flags();

		$this->create_ok( [ 'email' => 'quiet@example.com', 'send_emails' => false, 'items' => [ [ 'product_id' => $this->create_product() ] ] ] );

		list( $receipt, $notice, $exist ) = $this->flags_seen[0];

		$this->assertTrue( $exist );
		$this->assertEmpty( $receipt );
		$this->assertEmpty( $notice );
	}

	public function test_emails_are_requested_when_asked_for() {

		$this->capture_email_flags();

		$this->create_ok( [ 'email' => 'loud@example.com', 'send_emails' => true, 'items' => [ [ 'product_id' => $this->create_product() ] ] ] );

		list( $receipt, $notice, $exist ) = $this->flags_seen[0];

		$this->assertTrue( $exist );
		$this->assertNotEmpty( $receipt, 'send_emails => true must ask EDD to send the purchase receipt.' );
		$this->assertNotEmpty( $notice, 'send_emails => true must ask EDD to send the new-sale notice.' );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* input and permissions                                                                    */
	/* ---------------------------------------------------------------------------------------- */

	public function test_the_schema_rejects_malformed_input() {

		$customer = $this->create_customer();
		$product  = $this->create_product();

		foreach ( [
			[ 'customer_id' => $customer ],                                                                  // no items
			[ 'customer_id' => $customer, 'items' => [] ],                                                   // empty items
			[ 'customer_id' => $customer, 'items' => [ [ 'quantity' => 1 ] ] ],                              // no product
			[ 'customer_id' => $customer, 'items' => [ [ 'product_id' => $product, 'quantity' => 0 ] ] ],    // zero quantity
			[ 'customer_id' => $customer, 'items' => [ [ 'product_id' => $product, 'price' => -1 ] ] ],      // negative price
			[ 'customer_id' => $customer, 'items' => [ [ 'product_id' => $product ] ], 'status' => 'refunded' ],
			[ 'customer_id' => $customer, 'items' => [ [ 'product_id' => $product ] ], 'bogus' => 1 ],
			[ 'email' => 'not-an-email', 'items' => [ [ 'product_id' => $product ] ] ],                      // malformed email
		] as $input ) {
			$this->assertAbilityError( 'ability_invalid_input', $this->create( $input ) );
		}
	}

	public function test_it_needs_edit_shop_payments() {

		$orders = edd_count_orders();

		wp_set_current_user( $this->create_shop_user( [ 'view_shop_reports', 'edit_products', 'manage_shop_discounts' ] ) );

		$this->assertAbilityError( 'ability_invalid_permissions', $this->create( [ 'customer_id' => $this->create_customer(), 'items' => [ [ 'product_id' => $this->create_product() ] ] ] ) );
		$this->assertSame( $orders, edd_count_orders() );
	}
}
