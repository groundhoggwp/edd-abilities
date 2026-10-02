<?php

class Customers_Test extends EDD_Abilities_Test_Case {

	public function test_list_customers_by_name_and_exact_email() {

		$alice = $this->create_customer( [ 'name' => 'Alice Zebra', 'email' => 'alice@example.com' ] );
		$bob   = $this->create_customer( [ 'name' => 'Bob Yak', 'email' => 'bob@example.com' ] );

		$by_name = $this->run_ok( 'edd/list-customers', [ 'search' => 'Zebra' ] );
		$this->assertSame( [ $alice ], wp_list_pluck( $by_name['customers'], 'id' ) );
		$this->assertSame( 1, $by_name['total_items'] );

		$by_email = $this->run_ok( 'edd/list-customers', [ 'email' => 'bob@example.com' ] );
		$this->assertSame( [ $bob ], wp_list_pluck( $by_email['customers'], 'id' ) );
	}

	public function test_list_customers_pages_and_reports_the_total() {

		for ( $i = 0; $i < 4; $i++ ) {
			$this->create_customer();
		}

		$page = $this->run_ok( 'edd/list-customers', [ 'limit' => 3 ] );
		$this->assertCount( 3, $page['customers'] );
		$this->assertSame( 4, $page['total_items'] );

		$rest = $this->run_ok( 'edd/list-customers', [ 'limit' => 3, 'offset' => 3 ] );
		$this->assertCount( 1, $rest['customers'] );
	}

	public function test_list_customers_status_filter() {

		$active   = $this->create_customer();
		$disabled = $this->create_customer( [ 'status' => 'disabled' ] );

		$result = $this->run_ok( 'edd/list-customers', [ 'status' => 'disabled' ] );

		$this->assertSame( [ $disabled ], wp_list_pluck( $result['customers'], 'id' ) );
		$this->assertNotContains( $active, wp_list_pluck( $result['customers'], 'id' ) );
	}

	public function test_get_customer_by_id_and_by_email_with_related_data() {

		$id = $this->create_customer( [ 'email' => 'jane@example.com', 'name' => 'Jane' ] );
		edd_get_customer( $id )->add_email( 'jane.work@example.com' );
		$order = $this->create_order( [], [ 'customer_id' => $id ] );

		$by_id = $this->run_ok( 'edd/get-customer', [ 'id' => $id ] );

		$this->assertSame( 'Jane', $by_id['name'] );
		$this->assertSame( 'jane@example.com', $by_id['email'] );
		$this->assertContains( 'jane.work@example.com', $by_id['emails'] );
		$this->assertSame( [ $order ], wp_list_pluck( $by_id['recent_orders'], 'id' ) );

		$by_email = $this->run_ok( 'edd/get-customer', [ 'email' => 'jane@example.com' ] );
		$this->assertSame( $id, $by_email['id'] );

		// Secondary addresses resolve to the same customer.
		$by_secondary = $this->run_ok( 'edd/get-customer', [ 'email' => 'jane.work@example.com' ] );
		$this->assertSame( $id, $by_secondary['id'] );
	}

	public function test_get_customer_needs_an_identifier_and_reports_unknowns() {

		$this->assertAbilityError( 'edd_abilities_missing_identifier', $this->run_ability( 'edd/get-customer' ) );
		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/get-customer', [ 'id' => 999999 ] ) );
		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/get-customer', [ 'email' => 'nobody@example.com' ] ) );
	}

	public function test_create_customer() {

		$user_id = self::factory()->user->create();

		$customer = $this->run_ok( 'edd/create-customer', [ 'email' => 'New.Person@Example.com', 'name' => 'New Person', 'user_id' => $user_id ] );

		$this->assertSame( 'new.person@example.com', strtolower( $customer['email'] ) );
		$this->assertSame( 'New Person', $customer['name'] );
		$this->assertSame( $user_id, $customer['user_id'] );
		$this->assertNotEmpty( edd_get_customer( $customer['id'] ) );
	}

	public function test_create_customer_rejects_duplicates_bad_emails_and_unknown_users() {

		$this->create_customer( [ 'email' => 'taken@example.com' ] );

		$this->assertAbilityError( 'edd_abilities_customer_exists', $this->run_ability( 'edd/create-customer', [ 'email' => 'taken@example.com' ] ) );
		$this->assertWPError( $this->run_ability( 'edd/create-customer', [ 'email' => 'not-an-email' ] ) );
		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/create-customer', [ 'email' => 'x@example.com', 'user_id' => 999999 ] ) );
		$this->assertEmpty( edd_get_customer_by( 'email', 'x@example.com' ) );
	}

	public function test_update_customer_changes_only_supplied_fields() {

		$id = $this->create_customer( [ 'name' => 'Before', 'email' => 'keep@example.com' ] );

		$customer = $this->run_ok( 'edd/update-customer', [ 'id' => $id, 'name' => 'After' ] );

		$this->assertSame( 'After', $customer['name'] );
		$this->assertSame( 'keep@example.com', $customer['email'] );
		$this->assertSame( 'active', $customer['status'] );

		$disabled = $this->run_ok( 'edd/update-customer', [ 'id' => $id, 'status' => 'disabled' ] );
		$this->assertSame( 'disabled', $disabled['status'] );
		$this->assertSame( 'After', $disabled['name'] );
	}

	public function test_update_customer_can_link_and_unlink_a_user() {

		$id      = $this->create_customer();
		$user_id = self::factory()->user->create();

		$this->assertSame( $user_id, $this->run_ok( 'edd/update-customer', [ 'id' => $id, 'user_id' => $user_id ] )['user_id'] );
		$this->assertSame( 0, $this->run_ok( 'edd/update-customer', [ 'id' => $id, 'user_id' => 0 ] )['user_id'] );
		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/update-customer', [ 'id' => $id, 'user_id' => 999999 ] ) );
	}

	public function test_update_customer_adds_a_secondary_email_without_changing_the_primary() {

		$id = $this->create_customer( [ 'email' => 'primary@example.com' ] );

		$customer = $this->run_ok( 'edd/update-customer', [ 'id' => $id, 'add_email' => 'extra@example.com' ] );

		$this->assertSame( 'primary@example.com', $customer['email'] );
		$this->assertContains( 'extra@example.com', $customer['emails'] );
	}

	public function test_update_customer_will_not_steal_another_customers_email() {

		$id = $this->create_customer();
		$this->create_customer( [ 'email' => 'theirs@example.com' ] );

		$this->assertAbilityError( 'edd_abilities_email_in_use', $this->run_ability( 'edd/update-customer', [ 'id' => $id, 'add_email' => 'theirs@example.com' ] ) );
		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/update-customer', [ 'id' => 999999, 'name' => 'x' ] ) );
	}

	public function test_a_reports_only_user_can_read_but_not_change_customers() {

		$id = $this->create_customer();

		wp_set_current_user( $this->create_shop_user( [ 'view_shop_reports' ] ) );

		$this->run_ok( 'edd/get-customer', [ 'id' => $id ] );
		$this->assertAbilityError( 'ability_invalid_permissions', $this->run_ability( 'edd/update-customer', [ 'id' => $id, 'name' => 'Nope' ] ) );
		$this->assertAbilityError( 'ability_invalid_permissions', $this->run_ability( 'edd/create-customer', [ 'email' => 'a@example.com' ] ) );
	}
}
