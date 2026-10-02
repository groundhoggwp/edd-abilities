<?php

class Products_Test extends EDD_Abilities_Test_Case {

	public function test_list_products_defaults_to_published_only() {

		$live  = $this->create_product( [ 'post_title' => 'Live one' ] );
		$draft = $this->create_product( [ 'post_title' => 'Draft one', 'post_status' => 'draft' ] );

		$default = $this->run_ok( 'edd-alt/list-products' );
		$this->assertSame( [ $live ], wp_list_pluck( $default['products'], 'id' ) );
		$this->assertSame( 1, $default['total_items'] );

		$both = $this->run_ok( 'edd-alt/list-products', [ 'status' => [ 'publish', 'draft' ] ] );
		$this->assertEqualsCanonicalizing( [ $live, $draft ], wp_list_pluck( $both['products'], 'id' ) );
	}

	public function test_list_products_search_pages_and_sorts() {

		$b = $this->create_product( [ 'post_title' => 'Bravo plugin' ] );
		$a = $this->create_product( [ 'post_title' => 'Alpha plugin' ] );
		$this->create_product( [ 'post_title' => 'Unrelated theme' ] );

		$search = $this->run_ok( 'edd-alt/list-products', [ 'search' => 'plugin' ] );
		$this->assertSame( [ $a, $b ], wp_list_pluck( $search['products'], 'id' ), 'Title order by default.' );
		$this->assertSame( 2, $search['total_items'] );

		$desc = $this->run_ok( 'edd-alt/list-products', [ 'search' => 'plugin', 'order' => 'DESC' ] );
		$this->assertSame( [ $b, $a ], wp_list_pluck( $desc['products'], 'id' ) );

		$page = $this->run_ok( 'edd-alt/list-products', [ 'limit' => 1, 'offset' => 1 ] );
		$this->assertCount( 1, $page['products'] );
		$this->assertSame( 3, $page['total_items'] );
	}

	public function test_get_product_basics() {

		$id = $this->create_product( [ 'post_title' => 'Pro Plugin', 'price' => '49.00' ] );

		$product = $this->run_ok( 'edd-alt/get-product', [ 'id' => $id ] );

		$this->assertSame( $id, $product['id'] );
		$this->assertSame( 'Pro Plugin', $product['title'] );
		$this->assertSame( 'publish', $product['status'] );
		$this->assertSame( 'default', $product['type'] );
		$this->assertEqualsWithDelta( 49.0, $product['price'], 0.001 );
		$this->assertFalse( $product['has_variable_prices'] );
		$this->assertSame( [], $product['prices'] );
	}

	public function test_get_product_lists_variable_price_options() {

		$id = $this->create_product();

		update_post_meta( $id, '_variable_pricing', 1 );
		update_post_meta( $id, 'edd_variable_prices', [
			1 => [ 'index' => 1, 'name' => 'Personal', 'amount' => '49.00' ],
			2 => [ 'index' => 2, 'name' => 'Agency', 'amount' => '149.00' ],
		] );

		$product = $this->run_ok( 'edd-alt/get-product', [ 'id' => $id, 'include' => [ 'prices' ] ] );

		$this->assertTrue( $product['has_variable_prices'] );
		$this->assertSame( [ 1, 2 ], wp_list_pluck( $product['prices'], 'price_id' ) );
		$this->assertSame( [ 'Personal', 'Agency' ], wp_list_pluck( $product['prices'], 'name' ) );
		$this->assertEqualsWithDelta( 149.0, $product['prices'][1]['amount'], 0.001 );
	}

	public function test_product_stats_are_only_shown_to_users_who_can_view_reports() {

		$id = $this->create_product();
		$this->create_order( [ [ 'product_id' => $id, 'subtotal' => 20.0 ] ] );
		edd_recalculate_download_sales_earnings( $id );

		$with = $this->run_ok( 'edd-alt/get-product', [ 'id' => $id, 'include' => [ 'stats' ] ] );
		$this->assertArrayHasKey( 'sales', $with );
		$this->assertArrayHasKey( 'earnings', $with );

		// Can see products, but not the money.
		wp_set_current_user( $this->create_shop_user( [ 'edit_products' ] ) );

		$without = $this->run_ok( 'edd-alt/get-product', [ 'id' => $id, 'include' => [ 'stats' ] ] );
		$this->assertArrayNotHasKey( 'sales', $without );
		$this->assertArrayNotHasKey( 'earnings', $without );
	}

	public function test_get_product_for_an_unknown_id_is_a_clean_error() {

		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd-alt/get-product', [ 'id' => 999999 ] ) );

		// A non-download post must not be mistaken for a product.
		$post = self::factory()->post->create();
		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd-alt/get-product', [ 'id' => $post ] ) );
	}
}
