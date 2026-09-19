<?php

use EDD_Abilities\Abilities\Schemas\Schema;
use EDD_Abilities\Autoloader;

/**
 * The plain helpers: autoloader file naming and the shared Schema utilities.
 */
class Helpers_Test extends WP_UnitTestCase {

	/**
	 * @dataProvider class_to_file
	 */
	public function test_autoloader_derives_the_file_path( string $class, string $expected ) {
		$this->assertSame( $expected, Autoloader::relative_path( $class ) );
	}

	public function class_to_file(): array {
		return [
			'plain'              => [ 'Plugin', 'plugin.php' ],
			'underscores'        => [ 'Abilities\Orders\List_Orders', 'abilities/orders/list-orders.php' ],
			'camel case'         => [ 'Abilities\Orders\ListOrders', 'abilities/orders/list-orders.php' ],
			'schema'             => [ 'Abilities\Schemas\Order_Item_Schema', 'abilities/schemas/order-item-schema.php' ],
			'trait'              => [ 'Abilities\Traits\Checks_Customer_Caps', 'abilities/traits/checks-customer-caps.php' ],
		];
	}

	public function test_every_class_in_the_plugin_is_autoloadable() {

		$root  = dirname( __DIR__, 3 ) . '/includes/';
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );

		$checked = 0;

		foreach ( $files as $file ) {

			if ( 'php' !== $file->getExtension() || 'autoloader.php' === $file->getFilename() ) {
				continue;
			}

			$source = file_get_contents( $file->getPathname() );

			if ( ! preg_match( '/^namespace ([\w\\\\]+);/m', $source, $ns ) || ! preg_match( '/^(?:abstract )?(class|trait) (\w+)/m', $source, $decl ) ) {
				continue;
			}

			$name = $ns[1] . '\\' . $decl[2];

			$this->assertTrue( class_exists( $name ) || trait_exists( $name ), "$name did not autoload from {$file->getPathname()}" );
			$checked++;
		}

		$this->assertGreaterThan( 30, $checked );
	}

	public function test_money_is_a_plain_float() {

		$this->assertSame( 12.5, Schema::money( '12.50' ) );
		$this->assertSame( -20.0, Schema::money( '-20' ) );
		$this->assertSame( 0.0, Schema::money( null ) );
		$this->assertSame( 0.0, Schema::money( '' ) );
	}

	public function test_datetime_treats_edd_strings_as_utc_and_renders_both_zones() {

		update_option( 'timezone_string', 'America/New_York' );

		$dt = Schema::datetime( '2030-07-04 16:00:00' );

		$this->assertSame( '2030-07-04T16:00:00Z', $dt['utc'] );
		$this->assertSame( '2030-07-04T12:00:00-04:00', $dt['local'] );
		$this->assertSame( 'America/New_York', $dt['timezone'] );
	}

	public function test_datetime_accepts_timestamps() {

		$dt = Schema::datetime( 1893456000 ); // 2030-01-01 00:00:00 UTC

		$this->assertSame( '2030-01-01T00:00:00Z', $dt['utc'] );
	}

	public function test_datetime_returns_null_for_empty_and_zero_dates() {

		foreach ( [ null, '', 0, '0', '0000-00-00 00:00:00', 'not a date' ] as $value ) {
			$this->assertNull( Schema::datetime( $value ), var_export( $value, true ) );
		}
	}

	public function test_pagination_clamps_to_safe_integers() {

		$this->assertSame( [ 20, 0 ], Schema::pagination( [] ) );
		$this->assertSame( [ 5, 10 ], Schema::pagination( [ 'limit' => 5, 'offset' => 10 ] ) );
		$this->assertSame( [ 100, 0 ], Schema::pagination( [ 'limit' => 100000 ] ) );
		$this->assertSame( [ 1, 0 ], Schema::pagination( [ 'limit' => 0 ] ) );
		$this->assertSame( [ 20, 3 ], Schema::pagination( [ 'offset' => -3 ] ), 'A negative offset is folded to its absolute value, never used as a negative.' );
		$this->assertSame( [ 50, 0 ], Schema::pagination( [], 50, 200 ) );
	}

	public function test_date_range_query() {

		$this->assertSame( [], Schema::date_range_query( [] ) );
		$this->assertSame( [], Schema::date_range_query( [ 'created_after' => 'garbage' ] ) );

		$this->assertSame(
			[ 'after' => '2026-01-01', 'inclusive' => true ],
			Schema::date_range_query( [ 'created_after' => '2026-01-01' ] )
		);

		$this->assertSame(
			[ 'after' => '2026-01-01', 'before' => '2026-02-01 12:00:00', 'inclusive' => true ],
			Schema::date_range_query( [ 'created_after' => '2026-01-01', 'created_before' => '2026-02-01 12:00:00' ] )
		);
	}

	public function test_schema_defaults_advertised_in_input_schemas_match_what_the_code_uses() {

		$input = Schema::pagination_input( 20, 100 );

		$this->assertSame( 20, $input['limit']['default'] );
		$this->assertSame( 100, $input['limit']['maximum'] );
		$this->assertSame( 0, $input['offset']['default'] );
	}
}
