<?php

/**
 * Recurring Payments abilities. Skipped unless EDD Recurring is loaded.
 */
class Subscriptions_Test extends EDD_Abilities_Recurring_Test_Case {

	public function test_the_subscription_abilities_are_registered() {

		foreach ( [ 'edd/list-subscriptions', 'edd/get-subscription', 'edd/cancel-subscription' ] as $name ) {
			$this->assertTrue( wp_has_ability( $name ), "$name should be registered when Recurring is active" );
		}
	}

	/* ---------------------------------------------------------------------------------------- */
	/* edd/get-subscription                                                                     */
	/* ---------------------------------------------------------------------------------------- */

	public function test_get_subscription() {

		$product = $this->create_product( [ 'post_title' => 'Monthly Membership' ] );

		$sub = $this->create_subscription( [
			'product_id'       => $product,
			'period'           => 'year',
			'initial_amount'   => '99.00',
			'recurring_amount' => '79.50',
			'bill_times'       => 12,
			'profile_id'       => 'sub_abc123',
		] );

		$result = $this->run_ok( 'edd/get-subscription', [ 'id' => $sub->id ] );

		$this->assertSame( (int) $sub->id, $result['id'] );
		$this->assertSame( 'active', $result['status'] );
		$this->assertSame( $product, $result['product_id'] );
		$this->assertSame( 'Monthly Membership', $result['product_name'] );
		$this->assertSame( 'year', $result['period'] );
		$this->assertEqualsWithDelta( 99.0, $result['initial_amount'], 0.001 );
		$this->assertEqualsWithDelta( 79.5, $result['recurring_amount'], 0.001 );
		$this->assertSame( 12, $result['bill_times'] );
		$this->assertSame( 'sub_abc123', $result['profile_id'] );
		$this->assertSame( (int) $sub->parent_payment_id, $result['parent_order_id'] );
		$this->assertSame( (int) $sub->customer_id, $result['customer_id'] );
		$this->assertNull( $result['price_id'] );
		$this->assertSame( (bool) $sub->can_cancel(), $result['can_cancel'], 'can_cancel must report what EDD Recurring itself says.' );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $result['created']['utc'] );
		$this->assertNotNull( $result['expiration'] );
	}

	public function test_get_subscription_reports_the_variable_price_option() {

		$sub = $this->create_subscription( [ 'price_id' => 2 ] );

		$this->assertSame( 2, $this->run_ok( 'edd/get-subscription', [ 'id' => $sub->id ] )['price_id'] );
	}

	public function test_get_subscription_for_an_unknown_id_is_a_clean_error() {

		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/get-subscription', [ 'id' => 999999 ] ) );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* edd/list-subscriptions                                                                   */
	/* ---------------------------------------------------------------------------------------- */

	public function test_list_subscriptions_returns_total_separately_from_the_page() {

		for ( $i = 0; $i < 4; $i++ ) {
			$this->create_subscription();
		}

		$page = $this->run_ok( 'edd/list-subscriptions', [ 'limit' => 3 ] );
		$this->assertCount( 3, $page['subscriptions'] );
		$this->assertSame( 4, $page['total_items'] );

		$rest = $this->run_ok( 'edd/list-subscriptions', [ 'limit' => 3, 'offset' => 3 ] );
		$this->assertCount( 1, $rest['subscriptions'] );
		$this->assertSame( 4, $rest['total_items'] );
	}

	public function test_list_subscriptions_filters_and_the_total_follows_the_filter() {

		$product  = $this->create_product();
		$customer = $this->create_customer();

		$mine  = $this->create_subscription( [ 'product_id' => $product, 'customer_id' => $customer ] );
		$other = $this->create_subscription();

		$ids = function ( $result ) {
			return wp_list_pluck( $result['subscriptions'], 'id' );
		};

		$by_product = $this->run_ok( 'edd/list-subscriptions', [ 'product_id' => $product ] );
		$this->assertSame( [ (int) $mine->id ], $ids( $by_product ) );
		$this->assertSame( 1, $by_product['total_items'] );

		$by_customer = $this->run_ok( 'edd/list-subscriptions', [ 'customer_id' => $customer ] );
		$this->assertSame( [ (int) $mine->id ], $ids( $by_customer ) );
		$this->assertSame( 1, $by_customer['total_items'] );

		$by_order = $this->run_ok( 'edd/list-subscriptions', [ 'order_id' => $other->parent_payment_id ] );
		$this->assertSame( [ (int) $other->id ], $ids( $by_order ) );
		$this->assertSame( 1, $by_order['total_items'] );
	}

	public function test_list_subscriptions_filters_by_status() {

		$active    = $this->create_subscription();
		$cancelled = $this->create_subscription( [ 'status' => 'cancelled' ] );
		$expired   = $this->create_subscription( [ 'status' => 'expired', 'expiration' => '2020-01-01 00:00:00' ] );

		$ids = function ( $statuses ) {
			$result = $this->run_ok( 'edd/list-subscriptions', [ 'status' => $statuses ] );
			$this->assertSame( count( $result['subscriptions'] ), $result['total_items'], 'total_items must agree with the list for ' . implode( ',', $statuses ) );
			return wp_list_pluck( $result['subscriptions'], 'id' );
		};

		$this->assertSame( [ (int) $active->id ], $ids( [ 'active' ] ) );
		$this->assertSame( [ (int) $cancelled->id ], $ids( [ 'cancelled' ] ) );
		$this->assertEqualsCanonicalizing( [ (int) $cancelled->id, (int) $expired->id ], $ids( [ 'cancelled', 'expired' ] ) );
	}

	public function test_list_subscriptions_search_by_prefixed_field_and_email() {

		$one = $this->create_subscription( [ 'profile_id' => 'sub_findme' ] );
		$two = $this->create_subscription( [ 'profile_id' => 'sub_other' ] );

		$by_profile = $this->run_ok( 'edd/list-subscriptions', [ 'search' => 'profile_id:sub_findme' ] );
		$this->assertSame( [ (int) $one->id ], wp_list_pluck( $by_profile['subscriptions'], 'id' ) );
		$this->assertSame( 1, $by_profile['total_items'] );

		$email    = edd_get_customer( $two->customer_id )->email;
		$by_email = $this->run_ok( 'edd/list-subscriptions', [ 'search' => $email ] );
		$this->assertSame( [ (int) $two->id ], wp_list_pluck( $by_email['subscriptions'], 'id' ) );
	}

	public function test_list_subscriptions_orders_newest_first_by_default() {

		$first  = $this->create_subscription();
		$second = $this->create_subscription();

		$this->assertSame( [ (int) $second->id, (int) $first->id ], wp_list_pluck( $this->run_ok( 'edd/list-subscriptions' )['subscriptions'], 'id' ) );
		$this->assertSame( [ (int) $first->id, (int) $second->id ], wp_list_pluck( $this->run_ok( 'edd/list-subscriptions', [ 'order' => 'ASC' ] )['subscriptions'], 'id' ) );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* edd/cancel-subscription                                                                  */
	/* ---------------------------------------------------------------------------------------- */

	public function test_cancel_is_refused_when_the_gateway_cannot_cancel() {

		// Whether a gateway can cancel is up to it (via this filter). Force "no" whatever the default is.
		add_filter( 'edd_subscription_can_cancel', '__return_false', 99 );

		$sub = $this->create_subscription();

		$this->assertFalse( $this->run_ok( 'edd/get-subscription', [ 'id' => $sub->id ] )['can_cancel'] );

		$this->assertAbilityError( 'edd_abilities_cannot_cancel', $this->run_ability( 'edd/cancel-subscription', [ 'id' => $sub->id ] ) );

		$this->assertSame( 'active', ( new EDD_Subscription( $sub->id ) )->status, 'A refused cancel must not change anything.' );
	}

	public function test_cancel_cancels_the_subscription_and_fires_edds_hook() {

		add_filter( 'edd_subscription_can_cancel', '__return_true', 99 );

		$sub    = $this->create_subscription();
		$fired  = 0;
		add_action( 'edd_subscription_cancelled', function () use ( &$fired ) {
			$fired++;
		} );

		$result = $this->run_ok( 'edd/cancel-subscription', [ 'id' => $sub->id ] );

		$this->assertSame( 'cancelled', $result['status'] );
		$this->assertSame( 'cancelled', ( new EDD_Subscription( $sub->id ) )->status );
		$this->assertSame( 1, $fired, 'EDD Recurring\'s own cancellation hook must run so gateways and emails react.' );
	}

	public function test_cancelling_twice_does_not_run_the_cancellation_again() {

		add_filter( 'edd_subscription_can_cancel', '__return_true', 99 );

		$sub   = $this->create_subscription();
		$fired = 0;
		add_action( 'edd_subscription_cancelled', function () use ( &$fired ) {
			$fired++;
		} );

		$this->run_ok( 'edd/cancel-subscription', [ 'id' => $sub->id ] );
		$again = $this->run_ok( 'edd/cancel-subscription', [ 'id' => $sub->id ] );

		$this->assertSame( 'cancelled', $again['status'] );
		$this->assertSame( 1, $fired );
	}

	public function test_an_already_cancelled_subscription_is_returned_even_if_its_gateway_cannot_cancel() {

		$sub = $this->create_subscription( [ 'status' => 'cancelled' ] );

		$this->assertSame( 'cancelled', $this->run_ok( 'edd/cancel-subscription', [ 'id' => $sub->id ] )['status'] );
	}

	public function test_cancel_rejects_unknown_ids() {

		add_filter( 'edd_subscription_can_cancel', '__return_true', 99 );

		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/cancel-subscription', [ 'id' => 999999 ] ) );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* permissions                                                                              */
	/* ---------------------------------------------------------------------------------------- */

	public function test_subscriptions_need_edit_shop_payments() {

		add_filter( 'edd_subscription_can_cancel', '__return_true', 99 );

		$sub = $this->create_subscription();

		wp_set_current_user( $this->create_shop_user( [ 'view_shop_reports', 'manage_licenses' ] ) );

		foreach ( [
			[ 'edd/list-subscriptions', [] ],
			[ 'edd/get-subscription', [ 'id' => $sub->id ] ],
			[ 'edd/cancel-subscription', [ 'id' => $sub->id ] ],
		] as $call ) {
			$this->assertAbilityError( 'ability_invalid_permissions', $this->run_ability( $call[0], $call[1] ) );
		}

		$this->assertSame( 'active', ( new EDD_Subscription( $sub->id ) )->status );
	}
}
