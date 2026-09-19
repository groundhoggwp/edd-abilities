<?php

/**
 * Software Licensing abilities. Skipped unless EDD Software Licensing is loaded.
 */
class Licenses_Test extends EDD_Abilities_Licensing_Test_Case {

	public function test_the_license_abilities_are_registered() {

		foreach ( [ 'edd/list-licenses', 'edd/get-license', 'edd/update-license-status' ] as $name ) {
			$this->assertTrue( wp_has_ability( $name ), "$name should be registered when Software Licensing is active" );
		}
	}

	/* ---------------------------------------------------------------------------------------- */
	/* edd/get-license                                                                          */
	/* ---------------------------------------------------------------------------------------- */

	public function test_get_license_by_id_and_by_key() {

		$product = $this->create_licensed_product( [ 'post_title' => 'Licensed Plugin', 'limit' => 3 ] );
		$order   = $this->create_order( [ [ 'product_id' => $product, 'subtotal' => 20.0 ] ] );
		$id      = $this->create_license( $product, $order, [ 'activation_limit' => 3 ] );

		$by_id = $this->run_ok( 'edd/get-license', [ 'id' => $id ] );

		$this->assertSame( $id, $by_id['id'] );
		$this->assertNotEmpty( $by_id['license_key'] );
		$this->assertSame( 'inactive', $by_id['status'] );
		$this->assertSame( $product, $by_id['product_id'] );
		$this->assertSame( 'Licensed Plugin', $by_id['product_name'] );
		$this->assertSame( $order, $by_id['order_id'] );
		$this->assertSame( (int) edd_get_order( $order )->customer_id, $by_id['customer_id'] );
		$this->assertSame( 3, $by_id['activation_limit'] );
		$this->assertSame( 0, $by_id['activation_count'] );
		$this->assertSame( [], $by_id['activations'] );

		$by_key = $this->run_ok( 'edd/get-license', [ 'license_key' => $by_id['license_key'] ] );
		$this->assertSame( $id, $by_key['id'] );
	}

	public function test_a_dated_license_reports_its_expiration_and_a_lifetime_license_reports_none() {

		$product = $this->create_licensed_product();

		$dated    = $this->run_ok( 'edd/get-license', [ 'id' => $this->create_license( $product ) ] );
		$lifetime = $this->run_ok( 'edd/get-license', [ 'id' => $this->create_license( $product, 0, [ 'is_lifetime' => true ] ) ] );

		$this->assertFalse( $dated['is_lifetime'] );
		$this->assertNotNull( $dated['expiration'], 'A one-year license has an expiration.' );
		$this->assertGreaterThan( time(), strtotime( $dated['expiration']['utc'] ) );

		$this->assertTrue( $lifetime['is_lifetime'] );
		$this->assertNull( $lifetime['expiration'] );
	}

	public function test_get_license_lists_site_activations() {

		$product = $this->create_licensed_product();
		$id      = $this->create_license( $product );

		$license = edd_software_licensing()->get_license( $id );
		$this->assertTrue( (bool) $license->add_site( 'https://example.com' ), 'Fixture: activation should succeed.' );

		$result = $this->run_ok( 'edd/get-license', [ 'id' => $id ] );

		$this->assertSame( 1, $result['activation_count'] );
		$this->assertCount( 1, $result['activations'] );
		$this->assertStringContainsString( 'example.com', $result['activations'][0]['site_name'] );
		$this->assertFalse( $result['activations'][0]['is_local'] );

		// Activations are optional: leave them out and they are not queried or returned.
		$without = $this->run_ok( 'edd/get-license', [ 'id' => $id, 'include' => [] ] );
		$this->assertArrayNotHasKey( 'activations', $without );
		$this->assertSame( 1, $without['activation_count'] );
	}

	public function test_get_license_needs_an_identifier_and_reports_unknowns() {

		$this->assertAbilityError( 'edd_abilities_missing_identifier', $this->run_ability( 'edd/get-license' ) );
		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/get-license', [ 'id' => 999999 ] ) );
		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/get-license', [ 'license_key' => 'nope-not-a-key' ] ) );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* edd/list-licenses                                                                        */
	/* ---------------------------------------------------------------------------------------- */

	public function test_list_licenses_returns_total_separately_from_the_page() {

		$product = $this->create_licensed_product();

		for ( $i = 0; $i < 4; $i++ ) {
			$this->create_license( $product );
		}

		$page = $this->run_ok( 'edd/list-licenses', [ 'limit' => 3 ] );
		$this->assertCount( 3, $page['licenses'] );
		$this->assertSame( 4, $page['total_items'] );

		$rest = $this->run_ok( 'edd/list-licenses', [ 'limit' => 3, 'offset' => 3 ] );
		$this->assertCount( 1, $rest['licenses'] );
		$this->assertSame( 4, $rest['total_items'] );
	}

	public function test_list_licenses_filters_and_the_total_follows_the_filter() {

		$alpha = $this->create_licensed_product();
		$beta  = $this->create_licensed_product();

		$order   = $this->create_order( [ [ 'product_id' => $alpha, 'subtotal' => 20.0 ] ] );
		$a_one   = $this->create_license( $alpha, $order );
		$a_two   = $this->create_license( $alpha );
		$b_one   = $this->create_license( $beta );

		$ids = function ( $result ) {
			return wp_list_pluck( $result['licenses'], 'id' );
		};

		$by_product = $this->run_ok( 'edd/list-licenses', [ 'product_id' => $alpha ] );
		$this->assertEqualsCanonicalizing( [ $a_one, $a_two ], $ids( $by_product ) );
		$this->assertSame( 2, $by_product['total_items'] );

		$by_order = $this->run_ok( 'edd/list-licenses', [ 'order_id' => $order ] );
		$this->assertSame( [ $a_one ], $ids( $by_order ) );
		$this->assertSame( 1, $by_order['total_items'] );

		$customer = edd_get_order( $order )->customer_id;
		$by_customer = $this->run_ok( 'edd/list-licenses', [ 'customer_id' => $customer ] );
		$this->assertSame( [ $a_one ], $ids( $by_customer ) );

		$key    = $this->run_ok( 'edd/get-license', [ 'id' => $b_one ] )['license_key'];
		$by_key = $this->run_ok( 'edd/list-licenses', [ 'license_key' => $key ] );
		$this->assertSame( [ $b_one ], $ids( $by_key ) );
		$this->assertSame( 1, $by_key['total_items'] );
	}

	public function test_list_licenses_filters_by_status() {

		$product  = $this->create_licensed_product();
		$inactive = $this->create_license( $product );
		$disabled = $this->create_license( $product );

		$this->run_ok( 'edd/update-license-status', [ 'id' => $disabled, 'action' => 'disable' ] );

		$only_disabled = $this->run_ok( 'edd/list-licenses', [ 'status' => [ 'disabled' ] ] );
		$this->assertSame( [ $disabled ], wp_list_pluck( $only_disabled['licenses'], 'id' ) );
		$this->assertSame( 1, $only_disabled['total_items'] );

		$only_inactive = $this->run_ok( 'edd/list-licenses', [ 'status' => [ 'inactive' ] ] );
		$this->assertSame( [ $inactive ], wp_list_pluck( $only_inactive['licenses'], 'id' ) );

		$both = $this->run_ok( 'edd/list-licenses', [ 'status' => [ 'inactive', 'disabled' ] ] );
		$this->assertSame( 2, $both['total_items'] );
	}

	public function test_list_licenses_orders_by_id() {

		$product = $this->create_licensed_product();
		$first   = $this->create_license( $product );
		$second  = $this->create_license( $product );

		$this->assertSame( [ $second, $first ], wp_list_pluck( $this->run_ok( 'edd/list-licenses' )['licenses'], 'id' ), 'Newest first by default.' );
		$this->assertSame( [ $first, $second ], wp_list_pluck( $this->run_ok( 'edd/list-licenses', [ 'order' => 'ASC' ] )['licenses'], 'id' ) );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* edd/update-license-status                                                                */
	/* ---------------------------------------------------------------------------------------- */

	public function test_disable_then_enable_a_license() {

		$id = $this->create_license( $this->create_licensed_product() );

		$disabled = $this->run_ok( 'edd/update-license-status', [ 'id' => $id, 'action' => 'disable' ] );
		$this->assertSame( 'disabled', $disabled['status'] );

		$enabled = $this->run_ok( 'edd/update-license-status', [ 'id' => $id, 'action' => 'enable' ] );
		$this->assertSame( 'inactive', $enabled['status'], 'With no site activations, enabling restores inactive.' );
	}

	public function test_enabling_a_license_with_activations_restores_active() {

		$id = $this->create_license( $this->create_licensed_product() );
		edd_software_licensing()->get_license( $id )->add_site( 'https://example.com' );

		$this->run_ok( 'edd/update-license-status', [ 'id' => $id, 'action' => 'disable' ] );
		$enabled = $this->run_ok( 'edd/update-license-status', [ 'id' => $id, 'action' => 'enable' ] );

		$this->assertSame( 'active', $enabled['status'] );
	}

	public function test_repeating_the_same_action_is_a_harmless_no_op() {

		$id = $this->create_license( $this->create_licensed_product() );

		$this->assertSame( 'inactive', $this->run_ok( 'edd/update-license-status', [ 'id' => $id, 'action' => 'enable' ] )['status'] );

		$this->run_ok( 'edd/update-license-status', [ 'id' => $id, 'action' => 'disable' ] );
		$this->assertSame( 'disabled', $this->run_ok( 'edd/update-license-status', [ 'id' => $id, 'action' => 'disable' ] )['status'] );
	}

	public function test_update_license_status_rejects_unknown_licenses_and_bad_actions() {

		$id = $this->create_license( $this->create_licensed_product() );

		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/update-license-status', [ 'id' => 999999, 'action' => 'disable' ] ) );
		$this->assertAbilityError( 'ability_invalid_input', $this->run_ability( 'edd/update-license-status', [ 'id' => $id, 'action' => 'delete' ] ) );
		$this->assertSame( 'inactive', edd_software_licensing()->get_license( $id )->status );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* permissions                                                                              */
	/* ---------------------------------------------------------------------------------------- */

	public function test_licenses_need_manage_licenses() {

		$id = $this->create_license( $this->create_licensed_product() );

		// Everything else a shop worker can do, but not manage_licenses.
		wp_set_current_user( $this->create_shop_user( [ 'edit_shop_payments', 'view_shop_reports', 'edit_products', 'manage_shop_discounts' ] ) );

		foreach ( [
			[ 'edd/list-licenses', [] ],
			[ 'edd/get-license', [ 'id' => $id ] ],
			[ 'edd/update-license-status', [ 'id' => $id, 'action' => 'disable' ] ],
		] as $call ) {
			$this->assertAbilityError( 'ability_invalid_permissions', $this->run_ability( $call[0], $call[1] ) );
		}

		$this->assertSame( 'inactive', edd_software_licensing()->get_license( $id )->status );
	}
}
