<?php

class Discounts_Test extends EDD_Abilities_Test_Case {

	protected function create_discount( array $input = [] ): array {
		return $this->run_ok( 'edd/create-discount', array_merge( [
			'name'        => 'Test discount',
			'code'        => 'SAVE20',
			'amount_type' => 'percent',
			'amount'      => 20,
		], $input ) );
	}

	public function test_create_percent_discount_with_defaults() {

		$discount = $this->create_discount();

		$this->assertSame( 'SAVE20', $discount['code'] );
		$this->assertSame( 'percent', $discount['amount_type'] );
		$this->assertEqualsWithDelta( 20.0, $discount['amount'], 0.001 );
		$this->assertSame( 'active', $discount['status'] );
		$this->assertSame( 0, $discount['max_uses'] );
		$this->assertSame( 'global', $discount['scope'] );
		$this->assertNull( $discount['start_date'] );
		$this->assertNull( $discount['end_date'] );
	}

	public function test_create_discount_normalises_the_code() {

		$discount = $this->create_discount( [ 'code' => 'spring sale-10!' ] );

		$this->assertSame( 'SPRINGSALE-10', $discount['code'] );
	}

	public function test_create_flat_discount_with_restrictions() {

		$product  = $this->create_product();
		$excluded = $this->create_product();

		$discount = $this->create_discount( [
			'code'              => 'FIVEOFF',
			'amount_type'       => 'flat',
			'amount'            => 5,
			'max_uses'          => 10,
			'min_charge_amount' => 25,
			'once_per_customer' => true,
			'product_reqs'      => [ $product ],
			'excluded_products' => [ $excluded ],
			'product_condition' => 'any',
			'scope'             => 'not_global',
		] );

		$this->assertSame( 'flat', $discount['amount_type'] );
		$this->assertSame( 10, $discount['max_uses'] );
		$this->assertEqualsWithDelta( 25.0, $discount['min_charge_amount'], 0.001 );
		$this->assertTrue( $discount['once_per_customer'] );
		$this->assertSame( [ $product ], $discount['product_reqs'] );
		$this->assertSame( [ $excluded ], $discount['excluded_products'] );
		$this->assertSame( 'any', $discount['product_condition'] );
		$this->assertSame( 'not_global', $discount['scope'] );
	}

	public function test_create_discount_rejects_bad_amounts_and_duplicate_codes() {

		$this->create_discount();

		$this->assertAbilityError( 'edd_abilities_code_exists', $this->run_ability( 'edd/create-discount', [ 'name' => 'Dupe', 'code' => 'save20', 'amount_type' => 'flat', 'amount' => 1 ] ) );
		$this->assertAbilityError( 'edd_abilities_invalid_amount', $this->run_ability( 'edd/create-discount', [ 'name' => 'Too much', 'code' => 'OVER', 'amount_type' => 'percent', 'amount' => 150 ] ) );
		$this->assertAbilityError( 'ability_invalid_input', $this->run_ability( 'edd/create-discount', [ 'name' => 'Zero', 'code' => 'ZERO', 'amount_type' => 'flat', 'amount' => 0 ] ) );
		$this->assertAbilityError( 'ability_invalid_input', $this->run_ability( 'edd/create-discount', [ 'name' => 'Bad type', 'code' => 'BAD', 'amount_type' => 'bogus', 'amount' => 5 ] ) );

		$this->assertEmpty( edd_get_discount_by_code( 'OVER' ) );
	}

	public function test_a_date_without_a_time_covers_the_whole_day() {

		$discount = $this->create_discount( [ 'start_date' => '2030-10-01', 'end_date' => '2030-10-31' ] );

		// The test site runs in UTC, so local and UTC agree.
		$this->assertSame( '2030-10-01T00:00:00Z', $discount['start_date']['utc'] );
		$this->assertSame( '2030-10-31T23:59:59Z', $discount['end_date']['utc'] );
	}

	public function test_dates_are_read_in_the_site_timezone_and_stored_as_utc() {

		update_option( 'timezone_string', 'America/New_York' );

		$discount = $this->create_discount( [ 'start_date' => '2030-10-01', 'end_date' => '2030-10-02' ] );

		// New York is UTC-4 in early October, so local midnight is 04:00 UTC and local 23:59:59 is 03:59:59 UTC the next day.
		$this->assertSame( '2030-10-01T04:00:00Z', $discount['start_date']['utc'] );
		$this->assertSame( '2030-10-03T03:59:59Z', $discount['end_date']['utc'] );
		$this->assertSame( '2030-10-01T00:00:00-04:00', $discount['start_date']['local'] );

		$this->assertSame( '2030-10-01 04:00:00', edd_get_discount( $discount['id'] )->start_date );
	}

	public function test_create_discount_rejects_an_unparseable_date() {

		$this->assertAbilityError( 'edd_abilities_invalid_date', $this->run_ability( 'edd/create-discount', [
			'name'        => 'Bad date',
			'code'        => 'BADDATE',
			'amount_type' => 'flat',
			'amount'      => 5,
			'start_date'  => 'when the moon is full',
		] ) );

		$this->assertEmpty( edd_get_discount_by_code( 'BADDATE' ) );
	}

	public function test_get_discount_by_id_and_by_code() {

		$created = $this->create_discount();

		$this->assertSame( $created['id'], $this->run_ok( 'edd/get-discount', [ 'id' => $created['id'] ] )['id'] );
		$this->assertSame( $created['id'], $this->run_ok( 'edd/get-discount', [ 'code' => 'SAVE20' ] )['id'] );

		$this->assertAbilityError( 'edd_abilities_missing_identifier', $this->run_ability( 'edd/get-discount' ) );
		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/get-discount', [ 'code' => 'NOPE' ] ) );
		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/get-discount', [ 'id' => 999999 ] ) );
	}

	public function test_update_discount_status_and_list_filters() {

		$one = $this->create_discount( [ 'code' => 'ONE' ] );
		$two = $this->create_discount( [ 'code' => 'TWO' ] );

		$this->assertSame( 'inactive', $this->run_ok( 'edd/update-discount-status', [ 'id' => $one['id'], 'status' => 'inactive' ] )['status'] );

		$inactive = $this->run_ok( 'edd/list-discounts', [ 'status' => [ 'inactive' ] ] );
		$this->assertSame( [ $one['id'] ], wp_list_pluck( $inactive['discounts'], 'id' ) );
		$this->assertSame( 1, $inactive['total_items'] );

		$active = $this->run_ok( 'edd/list-discounts', [ 'status' => [ 'active' ] ] );
		$this->assertSame( [ $two['id'] ], wp_list_pluck( $active['discounts'], 'id' ) );
	}

	public function test_archived_discounts_are_hidden_unless_requested() {

		$keep     = $this->create_discount( [ 'code' => 'KEEP' ] );
		$archived = $this->create_discount( [ 'code' => 'GONE' ] );

		$this->run_ok( 'edd/update-discount-status', [ 'id' => $archived['id'], 'status' => 'archived' ] );

		$default = $this->run_ok( 'edd/list-discounts' );
		$this->assertSame( [ $keep['id'] ], wp_list_pluck( $default['discounts'], 'id' ) );
		$this->assertSame( 1, $default['total_items'] );

		$asked = $this->run_ok( 'edd/list-discounts', [ 'status' => [ 'archived' ] ] );
		$this->assertSame( [ $archived['id'] ], wp_list_pluck( $asked['discounts'], 'id' ) );
	}

	public function test_update_discount_status_rejects_unknown_ids_and_bad_statuses() {

		$discount = $this->create_discount();

		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/update-discount-status', [ 'id' => 999999, 'status' => 'active' ] ) );
		$this->assertAbilityError( 'ability_invalid_input', $this->run_ability( 'edd/update-discount-status', [ 'id' => $discount['id'], 'status' => 'expired' ] ) );
	}

	public function test_discounts_need_manage_shop_discounts() {

		$discount = $this->create_discount();

		wp_set_current_user( $this->create_shop_user( [ 'edit_shop_payments', 'view_shop_reports' ] ) );

		foreach ( [
			[ 'edd/list-discounts', [] ],
			[ 'edd/get-discount', [ 'id' => $discount['id'] ] ],
			[ 'edd/create-discount', [ 'name' => 'x', 'code' => 'X', 'amount_type' => 'flat', 'amount' => 1 ] ],
			[ 'edd/update-discount-status', [ 'id' => $discount['id'], 'status' => 'inactive' ] ],
		] as $call ) {
			$this->assertAbilityError( 'ability_invalid_permissions', $this->run_ability( $call[0], $call[1] ) );
		}

		$this->assertSame( 'active', edd_get_discount( $discount['id'] )->status );
	}
}
