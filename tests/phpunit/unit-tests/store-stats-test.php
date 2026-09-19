<?php

class Store_Stats_Test extends EDD_Abilities_Test_Case {

	public function test_todays_stats_add_up() {

		$big   = $this->create_product( [ 'post_title' => 'Big seller' ] );
		$small = $this->create_product( [ 'post_title' => 'Small seller' ] );

		$this->create_order( [ [ 'product_id' => $big, 'subtotal' => 30.0 ] ] );
		$this->create_order( [ [ 'product_id' => $big, 'subtotal' => 30.0 ] ] );
		$this->create_order( [ [ 'product_id' => $small, 'subtotal' => 10.0 ] ] );

		$stats = $this->run_ok( 'edd/get-store-stats', [ 'range' => 'today' ] );

		$this->assertSame( 'today', $stats['range'] );
		$this->assertSame( 'USD', $stats['currency'] );
		$this->assertSame( 3, $stats['order_count'] );
		$this->assertEqualsWithDelta( 70.0, $stats['earnings'], 0.001 );
		$this->assertEqualsWithDelta( 70.0 / 3, $stats['average_order_value'], 0.01 );
		$this->assertSame( 0, $stats['refund_count'] );
		$this->assertEqualsWithDelta( 0.0, $stats['refund_amount'], 0.001 );

		$this->assertSame( $big, $stats['top_products'][0]['product_id'] );
		$this->assertSame( 'Big seller', $stats['top_products'][0]['title'] );
		$this->assertEqualsWithDelta( 60.0, $stats['top_products'][0]['earnings'], 0.001 );
	}

	public function test_top_products_respects_the_limit() {

		for ( $i = 0; $i < 3; $i++ ) {
			$this->create_order();
		}

		$this->assertCount( 2, $this->run_ok( 'edd/get-store-stats', [ 'range' => 'today', 'top_products' => 2 ] )['top_products'] );
		$this->assertSame( [], $this->run_ok( 'edd/get-store-stats', [ 'range' => 'today', 'top_products' => 0 ] )['top_products'] );
	}

	public function test_tax_and_exclude_taxes() {

		$this->create_order( [ [ 'product_id' => $this->create_product(), 'subtotal' => 20.0, 'tax' => 2.0 ] ] );

		$with    = $this->run_ok( 'edd/get-store-stats', [ 'range' => 'today' ] );
		$without = $this->run_ok( 'edd/get-store-stats', [ 'range' => 'today', 'exclude_taxes' => true ] );

		$this->assertEqualsWithDelta( 2.0, $with['tax'], 0.001 );
		$this->assertGreaterThan( $without['earnings'], $with['earnings'], 'Excluding tax must lower earnings.' );
		$this->assertEqualsWithDelta( 20.0, $without['earnings'], 0.001 );
	}

	public function test_refunds_are_reported() {

		$order = $this->create_order( [ [ 'product_id' => $this->create_product(), 'subtotal' => 40.0 ] ] );

		$this->run_ok( 'edd/refund-order', [ 'id' => $order ] );

		$stats = $this->run_ok( 'edd/get-store-stats', [ 'range' => 'today' ] );

		$this->assertSame( 1, $stats['refund_count'] );
		$this->assertEqualsWithDelta( 40.0, abs( $stats['refund_amount'] ), 0.001 );
	}

	public function test_a_custom_range_only_counts_orders_inside_it() {

		$this->create_order( [], [ 'date_created' => '2020-03-15 12:00:00' ] );
		$this->create_order( [], [ 'date_created' => '2020-06-15 12:00:00' ] );

		$march = $this->run_ok( 'edd/get-store-stats', [ 'start' => '2020-03-01', 'end' => '2020-03-31' ] );

		$this->assertSame( 'custom', $march['range'] );
		$this->assertSame( 1, $march['order_count'] );

		$none = $this->run_ok( 'edd/get-store-stats', [ 'start' => '2019-01-01', 'end' => '2019-12-31' ] );
		$this->assertSame( 0, $none['order_count'] );
		$this->assertSame( [], $none['top_products'] );
	}

	public function test_a_custom_range_wins_over_a_named_range_and_is_validated() {

		$this->create_order( [], [ 'date_created' => '2020-03-15 12:00:00' ] );

		$stats = $this->run_ok( 'edd/get-store-stats', [ 'range' => 'today', 'start' => '2020-03-01', 'end' => '2020-03-31' ] );
		$this->assertSame( 1, $stats['order_count'] );

		$this->assertAbilityError( 'edd_abilities_invalid_range', $this->run_ability( 'edd/get-store-stats', [ 'start' => '2020-04-01', 'end' => '2020-03-01' ] ) );
		$this->assertAbilityError( 'ability_invalid_input', $this->run_ability( 'edd/get-store-stats', [ 'range' => 'next_century' ] ) );

		// A half-specified or impossible custom range falls back to the named range rather than guessing.
		$this->assertSame( 'last_30_days', $this->run_ok( 'edd/get-store-stats', [ 'start' => '2020-02-30', 'end' => '2020-03-31' ] )['range'] );
		$this->assertSame( 'last_30_days', $this->run_ok( 'edd/get-store-stats', [ 'start' => '2020-03-01' ] )['range'] );
	}

	public function test_stats_need_view_shop_reports() {

		wp_set_current_user( $this->create_shop_user( [ 'edit_shop_payments' ] ) );

		$this->assertAbilityError( 'ability_invalid_permissions', $this->run_ability( 'edd/get-store-stats' ) );
	}
}
