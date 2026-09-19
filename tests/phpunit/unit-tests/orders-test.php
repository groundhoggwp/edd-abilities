<?php

/**
 * Order abilities, including full and partial refunds, run through the real Abilities API.
 */
class Orders_Test extends EDD_Abilities_Test_Case {

	/* ---------------------------------------------------------------------------------------- */
	/* edd/list-orders                                                                          */
	/* ---------------------------------------------------------------------------------------- */

	public function test_list_orders_returns_total_separately_from_the_page() {

		for ( $i = 0; $i < 5; $i++ ) {
			$this->create_order();
		}

		$page = $this->run_ok( 'edd/list-orders', [ 'limit' => 2, 'offset' => 0 ] );
		$this->assertSame( 5, $page['total_items'] );
		$this->assertCount( 2, $page['orders'] );

		$last = $this->run_ok( 'edd/list-orders', [ 'limit' => 2, 'offset' => 4 ] );
		$this->assertSame( 5, $last['total_items'] );
		$this->assertCount( 1, $last['orders'] );
	}

	public function test_list_orders_filters() {

		$customer = $this->create_customer();
		$product  = $this->create_product();

		$mine_complete = $this->create_order( [ [ 'product_id' => $product, 'subtotal' => 20.0 ] ], [ 'customer_id' => $customer ] );
		$mine_pending  = $this->create_order( [], [ 'customer_id' => $customer, 'status' => 'pending' ] );
		$other         = $this->create_order( [], [ 'gateway' => 'stripe' ] );

		$ids = function ( $result ) {
			return wp_list_pluck( $result['orders'], 'id' );
		};

		$by_customer = $this->run_ok( 'edd/list-orders', [ 'customer_id' => $customer ] );
		$this->assertEqualsCanonicalizing( [ $mine_complete, $mine_pending ], $ids( $by_customer ) );

		$by_status = $this->run_ok( 'edd/list-orders', [ 'customer_id' => $customer, 'status' => [ 'pending' ] ] );
		$this->assertSame( [ $mine_pending ], $ids( $by_status ) );

		$by_product = $this->run_ok( 'edd/list-orders', [ 'product_id' => $product ] );
		$this->assertSame( [ $mine_complete ], $ids( $by_product ) );

		$by_gateway = $this->run_ok( 'edd/list-orders', [ 'gateway' => 'stripe' ] );
		$this->assertSame( [ $other ], $ids( $by_gateway ) );
		$this->assertSame( 1, $by_gateway['total_items'] );
	}

	public function test_list_orders_hides_refund_orders_unless_asked() {

		$order_id  = $this->create_order();
		$refund_id = $this->run_ok( 'edd/refund-order', [ 'id' => $order_id ] )['refund_order_id'];

		$sales = $this->run_ok( 'edd/list-orders' );
		$this->assertSame( [ $order_id ], wp_list_pluck( $sales['orders'], 'id' ) );

		$refunds = $this->run_ok( 'edd/list-orders', [ 'type' => 'refund' ] );
		$this->assertSame( [ $refund_id ], wp_list_pluck( $refunds['orders'], 'id' ) );

		$any = $this->run_ok( 'edd/list-orders', [ 'type' => 'any' ] );
		$this->assertSame( 2, $any['total_items'] );
	}

	public function test_list_orders_can_sort_by_total() {

		$small = $this->create_order( [ [ 'product_id' => $this->create_product(), 'subtotal' => 5.0 ] ] );
		$big   = $this->create_order( [ [ 'product_id' => $this->create_product(), 'subtotal' => 50.0 ] ] );

		$desc = $this->run_ok( 'edd/list-orders', [ 'orderby' => 'total', 'order' => 'DESC' ] );
		$this->assertSame( [ $big, $small ], wp_list_pluck( $desc['orders'], 'id' ) );

		$asc = $this->run_ok( 'edd/list-orders', [ 'orderby' => 'total', 'order' => 'ASC' ] );
		$this->assertSame( [ $small, $big ], wp_list_pluck( $asc['orders'], 'id' ) );
	}

	public function test_list_orders_only_includes_expanded_sections_when_asked() {

		$this->create_order();

		$plain = $this->run_ok( 'edd/list-orders' )['orders'][0];
		$this->assertArrayNotHasKey( 'items', $plain );

		$expanded = $this->run_ok( 'edd/list-orders', [ 'expand' => [ 'items' ] ] )['orders'][0];
		$this->assertCount( 1, $expanded['items'] );
	}

	public function test_list_orders_rejects_a_limit_over_the_schema_maximum() {
		$this->assertAbilityError( 'ability_invalid_input', $this->run_ability( 'edd/list-orders', [ 'limit' => 5000 ] ) );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* edd/get-order                                                                            */
	/* ---------------------------------------------------------------------------------------- */

	public function test_get_order_returns_everything_by_default() {

		$product  = $this->create_product( [ 'post_title' => 'Widget' ] );
		$order_id = $this->create_order( [ [ 'product_id' => $product, 'subtotal' => 20.0, 'tax' => 2.0 ] ] );

		edd_add_note( [ 'object_id' => $order_id, 'object_type' => 'order', 'content' => 'Hello there', 'user_id' => 0 ] );

		$order = $this->run_ok( 'edd/get-order', [ 'id' => $order_id ] );

		$this->assertSame( $order_id, $order['id'] );
		$this->assertSame( 'complete', $order['status'] );
		$this->assertSame( 'sale', $order['type'] );
		$this->assertSame( 'USD', $order['currency'] );
		$this->assertEqualsWithDelta( 22.0, $order['total'], 0.001 );
		$this->assertEqualsWithDelta( 2.0, $order['tax'], 0.001 );
		$this->assertTrue( $order['is_refundable'] );

		$this->assertCount( 1, $order['items'] );
		$this->assertSame( 'Widget', $order['items'][0]['product_name'] );
		$this->assertSame( $product, $order['items'][0]['product_id'] );
		$this->assertEqualsWithDelta( 22.0, $order['items'][0]['total'], 0.001 );

		// EDD also writes its own system notes, so look for ours rather than assuming its position.
		$this->assertContains( 'Hello there', wp_list_pluck( $order['notes'], 'content' ) );
		$this->assertNull( $order['address'], 'An order with no stored address reports null, not an empty object.' );

		// Dates are reported as both UTC and the site timezone.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $order['date_created']['utc'] );
		$this->assertArrayHasKey( 'local', $order['date_created'] );
	}

	public function test_the_order_schema_describes_every_key_the_order_transform_returns() {

		$id = $this->create_order();
		edd_add_order_address( [ 'order_id' => $id, 'type' => 'billing', 'name' => 'Jane', 'country' => 'US' ] );

		$order     = $this->run_ok( 'edd/get-order', [ 'id' => $id ] );
		$described = array_keys( \EDD_Abilities\Abilities\Schemas\Order_Schema::get_schema()['properties'] );

		foreach ( array_keys( $order ) as $key ) {
			$this->assertContains( $key, $described, "Order_Schema::transform() returns '$key' but get_schema() does not describe it." );
		}

		$item_described = array_keys( \EDD_Abilities\Abilities\Schemas\Order_Item_Schema::get_schema()['properties'] );

		foreach ( array_keys( $order['items'][0] ) as $key ) {
			$this->assertContains( $key, $item_described, "Order_Item_Schema returns '$key' but does not describe it." );
		}
	}

	public function test_get_order_returns_the_billing_address_when_there_is_one() {

		$id = $this->create_order();

		edd_add_order_address( [
			'order_id'    => $id,
			'type'        => 'billing',
			'name'        => 'Jane Doe',
			'address'     => '1 Main St',
			'city'        => 'Austin',
			'region'      => 'TX',
			'postal_code' => '78701',
			'country'     => 'US',
		] );

		$order = $this->run_ok( 'edd/get-order', [ 'id' => $id, 'include' => [ 'address' ] ] );

		$this->assertSame( 'Jane Doe', $order['address']['name'] );
		$this->assertSame( 'Austin', $order['address']['city'] );
		$this->assertSame( 'US', $order['address']['country'] );
	}

	public function test_get_order_include_limits_the_sections() {

		$order = $this->run_ok( 'edd/get-order', [ 'id' => $this->create_order(), 'include' => [ 'items' ] ] );

		$this->assertArrayHasKey( 'items', $order );
		$this->assertArrayNotHasKey( 'notes', $order );
		$this->assertArrayNotHasKey( 'discounts', $order );
	}

	public function test_get_order_for_an_unknown_id_is_a_clean_error() {
		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/get-order', [ 'id' => 999999 ] ) );
	}

	public function test_a_pending_order_is_not_refundable() {

		$order = $this->run_ok( 'edd/get-order', [ 'id' => $this->create_order( [], [ 'status' => 'pending' ] ) ] );

		$this->assertFalse( $order['is_refundable'] );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* edd/update-order-status                                                                  */
	/* ---------------------------------------------------------------------------------------- */

	public function test_update_order_status_changes_status() {

		$id = $this->create_order( [], [ 'status' => 'pending' ] );

		$order = $this->run_ok( 'edd/update-order-status', [ 'id' => $id, 'status' => 'failed' ] );

		$this->assertSame( 'failed', $order['status'] );
		$this->assertSame( 'failed', edd_get_order( $id )->status );
	}

	public function test_update_order_status_to_the_current_status_is_a_no_op() {

		$id = $this->create_order();

		$order = $this->run_ok( 'edd/update-order-status', [ 'id' => $id, 'status' => 'complete' ] );

		$this->assertSame( 'complete', $order['status'] );
	}

	public function test_update_order_status_cannot_be_used_to_refund() {

		$id = $this->create_order();

		foreach ( [ 'refunded', 'partially_refunded' ] as $status ) {
			$this->assertAbilityError( 'ability_invalid_input', $this->run_ability( 'edd/update-order-status', [ 'id' => $id, 'status' => $status ] ) );
		}

		$this->assertSame( 'complete', edd_get_order( $id )->status );
	}

	public function test_update_order_status_refuses_refund_orders() {

		$id        = $this->create_order();
		$refund_id = $this->run_ok( 'edd/refund-order', [ 'id' => $id ] )['refund_order_id'];

		$this->assertAbilityError( 'edd_abilities_invalid_order', $this->run_ability( 'edd/update-order-status', [ 'id' => $refund_id, 'status' => 'pending' ] ) );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* edd/add-order-note                                                                       */
	/* ---------------------------------------------------------------------------------------- */

	public function test_add_order_note_attributes_the_note_to_the_current_user() {

		$id = $this->create_order();

		$note = $this->run_ok( 'edd/add-order-note', [ 'id' => $id, 'note' => "Called the customer\n<script>alert(1)</script>" ] );

		$this->assertSame( $id, $note['order_id'] );
		$this->assertStringNotContainsString( '<script>', $note['content'] );

		$stored = edd_get_note( $note['id'] );
		$this->assertSame( 'order', $stored->object_type );
		$this->assertSame( $id, (int) $stored->object_id );
		$this->assertSame( $this->admin_id, (int) $stored->user_id );
	}

	public function test_add_order_note_rejects_blank_notes_and_unknown_orders() {

		$id = $this->create_order();

		$this->assertAbilityError( 'ability_invalid_input', $this->run_ability( 'edd/add-order-note', [ 'id' => $id, 'note' => '' ] ) );
		$this->assertAbilityError( 'edd_abilities_empty_note', $this->run_ability( 'edd/add-order-note', [ 'id' => $id, 'note' => '   ' ] ) );
		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/add-order-note', [ 'id' => 999999, 'note' => 'x' ] ) );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* edd/refund-order - full                                                                  */
	/* ---------------------------------------------------------------------------------------- */

	public function test_full_refund_creates_a_negative_refund_order_and_marks_the_original_refunded() {

		$id = $this->create_order( [
			[ 'product_id' => $this->create_product(), 'subtotal' => 10.0 ],
			[ 'product_id' => $this->create_product(), 'subtotal' => 20.0 ],
		] );

		$result = $this->run_ok( 'edd/refund-order', [ 'id' => $id ] );

		$this->assertGreaterThan( 0, $result['refund_order_id'] );
		$this->assertSame( 'refund', $result['refund']['type'] );
		$this->assertSame( $id, $result['refund']['parent'] );
		$this->assertEqualsWithDelta( -30.0, $result['refund']['total'], 0.001 );
		$this->assertCount( 2, $result['refund']['items'] );

		$this->assertSame( 'refunded', $result['order']['status'] );
		$this->assertFalse( $result['order']['is_refundable'] );
	}

	public function test_a_fully_refunded_order_cannot_be_refunded_again() {

		$id = $this->create_order();

		$this->run_ok( 'edd/refund-order', [ 'id' => $id ] );

		$this->assertAbilityError( 'not_refundable', $this->run_ability( 'edd/refund-order', [ 'id' => $id ] ) );
		$this->assertCount( 1, edd_get_order_refunds( $id ) );
	}

	public function test_refund_rejects_unknown_orders_pending_orders_and_refund_orders() {

		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/refund-order', [ 'id' => 999999 ] ) );

		$pending = $this->create_order( [], [ 'status' => 'pending' ] );
		$this->assertAbilityError( 'not_refundable', $this->run_ability( 'edd/refund-order', [ 'id' => $pending ] ) );

		$refund_id = $this->run_ok( 'edd/refund-order', [ 'id' => $this->create_order() ] )['refund_order_id'];
		$this->assertAbilityError( 'edd_abilities_invalid_order', $this->run_ability( 'edd/refund-order', [ 'id' => $refund_id ] ) );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* edd/refund-order - partial                                                               */
	/* ---------------------------------------------------------------------------------------- */

	public function test_partial_refund_refunds_only_the_listed_item() {

		$id                = $this->create_order( [
			[ 'product_id' => $this->create_product(), 'subtotal' => 10.0 ],
			[ 'product_id' => $this->create_product(), 'subtotal' => 20.0 ],
		] );
		list( $a, $b )     = $this->order_item_ids( $id );

		$result = $this->run_ok( 'edd/refund-order', [
			'id'    => $id,
			'items' => [ [ 'order_item_id' => $b, 'quantity' => 1, 'subtotal' => 20.0 ] ],
		] );

		$this->assertEqualsWithDelta( -20.0, $result['refund']['total'], 0.001 );
		$this->assertCount( 1, $result['refund']['items'] );
		$this->assertSame( 'partially_refunded', $result['order']['status'] );

		$statuses = wp_list_pluck( $result['order']['items'], 'status', 'id' );
		$this->assertSame( 'complete', $statuses[ $a ], 'The item that was not refunded must stay complete.' );
		$this->assertSame( 'refunded', $statuses[ $b ] );

		// Still refundable: there is money left on it.
		$this->assertTrue( $result['order']['is_refundable'] );
	}

	public function test_refunding_the_remaining_items_in_a_second_partial_completes_the_refund() {

		$id            = $this->create_order( [
			[ 'product_id' => $this->create_product(), 'subtotal' => 10.0 ],
			[ 'product_id' => $this->create_product(), 'subtotal' => 20.0 ],
		] );
		list( $a, $b ) = $this->order_item_ids( $id );

		$this->run_ok( 'edd/refund-order', [ 'id' => $id, 'items' => [ [ 'order_item_id' => $b, 'quantity' => 1, 'subtotal' => 20.0 ] ] ] );
		$second = $this->run_ok( 'edd/refund-order', [ 'id' => $id, 'items' => [ [ 'order_item_id' => $a, 'quantity' => 1, 'subtotal' => 10.0 ] ] ] );

		$this->assertSame( 'refunded', $second['order']['status'] );
		$this->assertCount( 2, edd_get_order_refunds( $id ) );
		$this->assertEqualsWithDelta( 0.0, edd_get_order_total( $id ), 0.001, 'Sale plus its refunds should net to zero.' );
	}

	public function test_partial_refund_can_refund_part_of_an_items_amount_and_its_tax() {

		$id      = $this->create_order( [ [ 'product_id' => $this->create_product(), 'subtotal' => 20.0, 'tax' => 2.0 ] ] );
		list( $item ) = $this->order_item_ids( $id );

		$result = $this->run_ok( 'edd/refund-order', [
			'id'    => $id,
			'items' => [ [ 'order_item_id' => $item, 'quantity' => 1, 'subtotal' => 10.0, 'tax' => 1.0 ] ],
		] );

		$this->assertEqualsWithDelta( -11.0, $result['refund']['total'], 0.001 );
		$this->assertEqualsWithDelta( -1.0, $result['refund']['tax'], 0.001 );
		$this->assertSame( 'partially_refunded', $result['order']['status'] );
		$this->assertEqualsWithDelta( 11.0, edd_get_order_total( $id ), 0.001, 'The original total less the refund.' );
	}

	public function test_partial_refund_never_refunds_more_than_is_left() {

		$id      = $this->create_order( [ [ 'product_id' => $this->create_product(), 'subtotal' => 20.0 ] ] );
		list( $item ) = $this->order_item_ids( $id );

		$this->run_ok( 'edd/refund-order', [ 'id' => $id, 'items' => [ [ 'order_item_id' => $item, 'quantity' => 1, 'subtotal' => 15.0 ] ] ] );

		// $5 is left; asking for $10 must be rejected by EDD's validator and change nothing.
		$result = $this->run_ability( 'edd/refund-order', [ 'id' => $id, 'items' => [ [ 'order_item_id' => $item, 'quantity' => 1, 'subtotal' => 10.0 ] ] ] );

		$this->assertAbilityError( 'refund_validation_error', $result );
		$this->assertCount( 1, edd_get_order_refunds( $id ) );
		$this->assertEqualsWithDelta( 5.0, edd_get_order_total( $id ), 0.001 );
	}

	public function test_partial_refund_rejects_an_amount_above_the_item_price_and_changes_nothing() {

		$id      = $this->create_order( [ [ 'product_id' => $this->create_product(), 'subtotal' => 20.0 ] ] );
		list( $item ) = $this->order_item_ids( $id );

		$result = $this->run_ability( 'edd/refund-order', [ 'id' => $id, 'items' => [ [ 'order_item_id' => $item, 'quantity' => 1, 'subtotal' => 25.0 ] ] ] );

		$this->assertAbilityError( 'refund_validation_error', $result );
		$this->assertCount( 0, edd_get_order_refunds( $id ) );
		$this->assertSame( 'complete', edd_get_order( $id )->status );
	}

	public function test_partial_refund_rejects_items_from_another_order() {

		$id      = $this->create_order();
		$other   = $this->create_order();
		list( $foreign ) = $this->order_item_ids( $other );

		$result = $this->run_ability( 'edd/refund-order', [ 'id' => $id, 'items' => [ [ 'order_item_id' => $foreign, 'quantity' => 1, 'subtotal' => 20.0 ] ] ] );

		$this->assertAbilityError( 'edd_abilities_invalid_item', $result );
		$this->assertCount( 0, edd_get_order_refunds( $id ) );
		$this->assertCount( 0, edd_get_order_refunds( $other ) );
	}

	public function test_partial_refund_rejects_an_item_listed_twice() {

		$id      = $this->create_order();
		list( $item ) = $this->order_item_ids( $id );
		$line    = [ 'order_item_id' => $item, 'quantity' => 1, 'subtotal' => 5.0 ];

		$this->assertAbilityError( 'edd_abilities_duplicate_item', $this->run_ability( 'edd/refund-order', [ 'id' => $id, 'items' => [ $line, $line ] ] ) );
		$this->assertCount( 0, edd_get_order_refunds( $id ) );
	}

	public function test_partial_refund_input_is_schema_validated() {

		$id      = $this->create_order();
		list( $item ) = $this->order_item_ids( $id );

		foreach ( [
			[],                                                                       // empty list
			[ [ 'order_item_id' => $item, 'quantity' => 1 ] ],                        // no subtotal
			[ [ 'order_item_id' => $item, 'quantity' => 0, 'subtotal' => 5.0 ] ],     // zero quantity
			[ [ 'order_item_id' => $item, 'quantity' => 1, 'subtotal' => -5.0 ] ],    // negative amount
			[ [ 'order_item_id' => $item, 'quantity' => 1, 'subtotal' => 5.0, 'bogus' => 1 ] ],
		] as $items ) {
			$this->assertAbilityError( 'ability_invalid_input', $this->run_ability( 'edd/refund-order', [ 'id' => $id, 'items' => $items ] ) );
		}

		$this->assertCount( 0, edd_get_order_refunds( $id ) );
	}

	public function test_a_partial_refund_does_not_touch_fees_or_credits() {

		$id      = $this->create_order( [ [ 'product_id' => $this->create_product(), 'subtotal' => 20.0 ] ] );
		list( $item ) = $this->order_item_ids( $id );

		$fee_id = edd_add_order_adjustment( [
			'object_id'   => $id,
			'object_type' => 'order',
			'type'        => 'fee',
			'description' => 'Handling',
			'subtotal'    => 3.0,
			'total'       => 3.0,
		] );
		$this->assertNotEmpty( $fee_id );

		$result = $this->run_ok( 'edd/refund-order', [ 'id' => $id, 'items' => [ [ 'order_item_id' => $item, 'quantity' => 1, 'subtotal' => 5.0 ] ] ] );

		$refund_adjustments = edd_get_order_adjustments( [ 'object_id' => $result['refund_order_id'], 'object_type' => 'order' ] );
		$this->assertCount( 0, $refund_adjustments, 'Fees must only be refunded by a full refund.' );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* permissions                                                                              */
	/* ---------------------------------------------------------------------------------------- */

	public function test_orders_cannot_be_read_or_refunded_without_edit_shop_payments() {

		$id = $this->create_order();

		// Reports access alone is not enough to see or change orders.
		wp_set_current_user( $this->create_shop_user( [ 'view_shop_reports' ] ) );

		foreach ( [
			[ 'edd/list-orders', [] ],
			[ 'edd/get-order', [ 'id' => $id ] ],
			[ 'edd/refund-order', [ 'id' => $id ] ],
			[ 'edd/update-order-status', [ 'id' => $id, 'status' => 'failed' ] ],
			[ 'edd/add-order-note', [ 'id' => $id, 'note' => 'x' ] ],
		] as $call ) {
			$this->assertAbilityError( 'ability_invalid_permissions', $this->run_ability( $call[0], $call[1] ) );
		}

		$this->assertSame( 'complete', edd_get_order( $id )->status );
		$this->assertCount( 0, edd_get_order_refunds( $id ) );
	}
}
