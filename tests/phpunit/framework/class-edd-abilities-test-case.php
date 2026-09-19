<?php
/**
 * Base test case: fixtures for EDD data and a helper that runs abilities through the real
 * Abilities API.
 *
 * @package EDD_Abilities
 */

abstract class EDD_Abilities_Test_Case extends WP_UnitTestCase {

	/**
	 * Every capability the abilities in this plugin can require.
	 */
	protected const SHOP_CAPS = [
		'view_shop_reports',
		'view_shop_sensitive_data',
		'manage_shop_discounts',
		'edit_shop_payments',
		'edit_products',
		'manage_licenses',
	];

	/**
	 * @var int
	 */
	protected $admin_id;

	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->create_shop_user( self::SHOP_CAPS );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * A user with exactly these capabilities on top of the subscriber role.
	 *
	 * @param string[] $caps
	 *
	 * @return int
	 */
	protected function create_shop_user( array $caps = [] ): int {

		$id   = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$user = get_userdata( $id );

		foreach ( $caps as $cap ) {
			$user->add_cap( $cap );
		}

		return $id;
	}

	/**
	 * Run an ability the way an MCP client would: schema validation, permission check, execution,
	 * output validation. Returns whatever the API returns, which may be a WP_Error.
	 *
	 * @param string $name
	 * @param array  $input
	 *
	 * @return mixed
	 */
	protected function run_ability( string $name, array $input = [] ) {

		$ability = wp_get_ability( $name );

		$this->assertNotNull( $ability, "Ability $name is not registered." );

		return $ability->execute( $input );
	}

	/**
	 * Like run_ability() but fails the test if the result is a WP_Error.
	 *
	 * @param string $name
	 * @param array  $input
	 *
	 * @return mixed
	 */
	protected function run_ok( string $name, array $input = [] ) {

		$result = $this->run_ability( $name, $input );

		if ( is_wp_error( $result ) ) {
			$this->fail( "$name returned a WP_Error: " . $result->get_error_code() . ' - ' . $result->get_error_message() );
		}

		return $result;
	}

	protected function assertAbilityError( string $code, $result ): void {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
	}

	/**
	 * @param array $args
	 *
	 * @return int customer id
	 */
	protected function create_customer( array $args = [] ): int {

		static $n = 0;
		$n++;

		$id = edd_add_customer( array_merge( [
			'email' => "customer{$n}@example.com",
			'name'  => "Customer {$n}",
		], $args ) );

		$this->assertNotEmpty( $id );

		return (int) $id;
	}

	/**
	 * @param array $args post fields; 'price' sets the EDD price
	 *
	 * @return int product id
	 */
	protected function create_product( array $args = [] ): int {

		static $n = 0;
		$n++;

		$price = $args['price'] ?? '20.00';
		unset( $args['price'] );

		$id = wp_insert_post( array_merge( [
			'post_type'   => 'download',
			'post_status' => 'publish',
			'post_title'  => "Product {$n}",
		], $args ) );

		update_post_meta( $id, 'edd_price', $price );

		return (int) $id;
	}

	/**
	 * Create a completed sale with the given line items, without going through checkout.
	 *
	 * @param array $items list of [ 'product_id' => int, 'subtotal' => float, 'tax' => float, 'quantity' => int ];
	 *                     defaults to one new $20 product
	 * @param array $args  order overrides (status, customer_id, gateway, date_created, ...)
	 *
	 * @return int order id
	 */
	protected function create_order( array $items = [], array $args = [] ): int {

		if ( ! $items ) {
			$items = [ [ 'product_id' => $this->create_product(), 'subtotal' => 20.0 ] ];
		}

		$customer_id = $args['customer_id'] ?? $this->create_customer();
		$customer    = edd_get_customer( $customer_id );

		$subtotal = 0.0;
		$tax      = 0.0;

		foreach ( $items as $item ) {
			$subtotal += (float) $item['subtotal'];
			$tax      += (float) ( $item['tax'] ?? 0 );
		}

		$order_id = edd_add_order( array_merge( [
			'status'         => 'complete',
			'type'           => 'sale',
			'customer_id'    => $customer_id,
			'email'          => $customer->email,
			'currency'       => 'USD',
			'gateway'        => 'manual',
			'mode'           => 'live',
			'payment_key'    => strtolower( md5( uniqid( '', true ) ) ),
			'subtotal'       => $subtotal,
			'tax'            => $tax,
			'total'          => $subtotal + $tax,
			'date_completed' => gmdate( 'Y-m-d H:i:s' ),
		], $args ) );

		$this->assertNotEmpty( $order_id );

		foreach ( array_values( $items ) as $index => $item ) {

			$quantity = $item['quantity'] ?? 1;

			edd_add_order_item( [
				'order_id'     => $order_id,
				'product_id'   => $item['product_id'],
				'product_name' => get_the_title( $item['product_id'] ),
				'cart_index'   => $index,
				'type'         => 'download',
				'status'       => 'complete',
				'quantity'     => $quantity,
				'amount'       => (float) $item['subtotal'] / $quantity,
				'subtotal'     => (float) $item['subtotal'],
				'tax'          => (float) ( $item['tax'] ?? 0 ),
				'total'        => (float) $item['subtotal'] + (float) ( $item['tax'] ?? 0 ),
			] );
		}

		return (int) $order_id;
	}

	/**
	 * @param int $order_id
	 *
	 * @return int[] the order's item ids, in cart order
	 */
	protected function order_item_ids( int $order_id ): array {
		return array_map( 'intval', wp_list_pluck( edd_get_order( $order_id )->get_items(), 'id' ) );
	}
}
