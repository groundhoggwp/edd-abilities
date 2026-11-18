<?php

class Download_Logs_Test extends EDD_Abilities_Test_Case {

	/**
	 * A fixed March 2026 period, so nothing here depends on today's date.
	 * 2026-03-01 is a Sunday, so 2026-03-10 is a Tuesday and falls in ISO week 2026-W11.
	 */
	protected const MARCH = [
		'created_after'  => '2026-03-01',
		'created_before' => '2026-03-31',
	];

	/**
	 * Analysis input that returns only the totals and breakdown.
	 */
	protected function plain( array $input = [] ): array {
		return array_merge( self::MARCH, [ 'trend' => 'none', 'compare_previous' => false, 'include_flags' => false ], $input );
	}

	public function test_totals_and_the_product_breakdown_add_up() {

		$big   = $this->create_product( [ 'post_title' => 'Big seller' ] );
		$small = $this->create_product( [ 'post_title' => 'Small seller' ] );
		$one   = $this->create_customer();
		$two   = $this->create_customer();

		$this->create_download_log( [ 'product_id' => $big, 'customer_id' => $one, 'order_id' => 101, 'ip' => '203.0.113.1', 'date_created' => '2026-03-05 10:00:00' ] );
		$this->create_download_log( [ 'product_id' => $big, 'customer_id' => $one, 'order_id' => 101, 'ip' => '203.0.113.1', 'date_created' => '2026-03-06 10:00:00' ] );
		$this->create_download_log( [ 'product_id' => $big, 'customer_id' => $two, 'order_id' => 102, 'ip' => '203.0.113.2', 'date_created' => '2026-03-07 10:00:00' ] );
		$this->create_download_log( [ 'product_id' => $small, 'customer_id' => $one, 'order_id' => 103, 'ip' => '203.0.113.3', 'date_created' => '2026-03-08 18:30:00' ] );

		$result = $this->run_ok( 'edd-alt/analyze-download-logs', $this->plain() );

		$totals = $result['totals'];
		$this->assertSame( 4, $totals['downloads'] );
		$this->assertSame( 2, $totals['unique_products'] );
		$this->assertSame( 2, $totals['unique_customers'] );
		$this->assertSame( 3, $totals['unique_orders'] );
		$this->assertSame( 3, $totals['unique_ips'] );
		$this->assertSame( '2026-03-05T10:00:00Z', $totals['first_download']['utc'] );
		$this->assertSame( '2026-03-08T18:30:00Z', $totals['last_download']['utc'] );
		$this->assertEqualsWithDelta( 4 / 31, $totals['average_per_day'], 0.01 );

		$this->assertSame( '2026-03-01T00:00:00Z', $result['range']['after']['utc'] );
		$this->assertSame( '2026-03-31T23:59:59Z', $result['range']['before']['utc'], 'A bare end date covers the whole day.' );

		$this->assertSame( 'product', $result['breakdown']['group_by'] );
		$this->assertSame( 2, $result['breakdown']['total_groups'] );

		$rows = $result['breakdown']['rows'];
		$this->assertSame( (string) $big, $rows[0]['key'] );
		$this->assertSame( 'Big seller', $rows[0]['label'] );
		$this->assertSame( 3, $rows[0]['downloads'] );
		$this->assertEqualsWithDelta( 0.75, $rows[0]['share'], 0.0001 );
		$this->assertSame( 2, $rows[0]['unique_customers'] );
		$this->assertSame( '2026-03-05T10:00:00Z', $rows[0]['first_download']['utc'] );
		$this->assertSame( '2026-03-07T10:00:00Z', $rows[0]['last_download']['utc'] );

		$this->assertSame( 'Small seller', $rows[1]['label'] );
		$this->assertSame( 1, $rows[1]['downloads'] );
		$this->assertNull( $result['trend'] );
		$this->assertNull( $result['comparison'] );
		$this->assertSame( [], $result['flags'] );
	}

	public function test_nothing_logged_gives_zeroes_not_errors() {

		$result = $this->run_ok( 'edd-alt/analyze-download-logs', self::MARCH );

		$this->assertSame( 0, $result['totals']['downloads'] );
		$this->assertSame( 0, $result['totals']['unique_customers'] );
		$this->assertEqualsWithDelta( 0.0, $result['totals']['average_per_day'], 0.0001 );
		$this->assertNull( $result['totals']['first_download'] );
		$this->assertNull( $result['totals']['last_download'] );
		$this->assertSame( [], $result['breakdown']['rows'] );
		$this->assertSame( 0, $result['breakdown']['total_groups'] );
		$this->assertSame( [], $result['trend']['periods'] );
		$this->assertNull( $result['trend']['peak'] );
		$this->assertNull( $result['comparison']['change_percent'], 'No division by zero when the previous period was empty.' );
		$this->assertSame( [], $result['flags'] );
	}

	public function test_it_looks_at_the_last_30_days_by_default() {

		$this->create_download_log( [ 'date_created' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ] );
		$this->create_download_log( [ 'date_created' => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) ] );
		$this->create_download_log( [ 'date_created' => gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ] );

		$result = $this->run_ok( 'edd-alt/analyze-download-logs' );

		$this->assertSame( 2, $result['totals']['downloads'] );
		$this->assertEqualsWithDelta( 30.0, $result['range']['days'], 0.01 );
		$this->assertSame( 'day', $result['trend']['interval'] );
	}

	public function test_the_end_date_is_inclusive_of_the_whole_day_and_a_time_is_honoured() {

		$this->create_download_log( [ 'date_created' => '2026-03-31 23:30:00' ] );
		$this->create_download_log( [ 'date_created' => '2026-04-01 00:00:00' ] );

		$this->assertSame( 1, $this->run_ok( 'edd-alt/analyze-download-logs', $this->plain() )['totals']['downloads'] );

		$timed = $this->run_ok( 'edd-alt/analyze-download-logs', $this->plain( [ 'created_before' => '2026-03-31 12:00:00' ] ) );
		$this->assertSame( 0, $timed['totals']['downloads'] );
	}

	public function test_it_compares_with_the_previous_period_of_the_same_length() {

		// Current period: 11-20 March (10 days). The 10 days before it are 1-10 March.
		$this->create_download_log( [ 'date_created' => '2026-03-03 12:00:00' ] );
		$this->create_download_log( [ 'date_created' => '2026-03-10 23:59:59' ] );
		$this->create_download_log( [ 'date_created' => '2026-03-11 00:00:00' ] );
		$this->create_download_log( [ 'date_created' => '2026-03-15 12:00:00' ] );
		$this->create_download_log( [ 'date_created' => '2026-03-20 23:59:59' ] );
		$this->create_download_log( [ 'date_created' => '2026-03-21 00:00:00' ] );

		$result = $this->run_ok( 'edd-alt/analyze-download-logs', [ 'created_after' => '2026-03-11', 'created_before' => '2026-03-20', 'include_flags' => false ] );

		$this->assertSame( 3, $result['totals']['downloads'] );

		$comparison = $result['comparison'];
		$this->assertSame( '2026-03-01T00:00:00Z', $comparison['previous_range']['after']['utc'] );
		$this->assertSame( '2026-03-10T23:59:59Z', $comparison['previous_range']['before']['utc'] );
		$this->assertSame( 2, $comparison['previous_downloads'] );
		$this->assertSame( 1, $comparison['change'] );
		$this->assertEqualsWithDelta( 50.0, $comparison['change_percent'], 0.01 );

		$off = $this->run_ok( 'edd-alt/analyze-download-logs', [ 'created_after' => '2026-03-11', 'created_before' => '2026-03-20', 'compare_previous' => false ] );
		$this->assertNull( $off['comparison'] );
	}

	public function test_the_comparison_respects_the_filters() {

		$mine  = $this->create_product();
		$other = $this->create_product();

		$this->create_download_log( [ 'product_id' => $mine, 'date_created' => '2026-03-03 12:00:00' ] );
		$this->create_download_log( [ 'product_id' => $other, 'date_created' => '2026-03-04 12:00:00' ] );
		$this->create_download_log( [ 'product_id' => $mine, 'date_created' => '2026-03-15 12:00:00' ] );

		$result = $this->run_ok( 'edd-alt/analyze-download-logs', [ 'created_after' => '2026-03-11', 'created_before' => '2026-03-20', 'product_id' => $mine ] );

		$this->assertSame( 1, $result['comparison']['previous_downloads'] );
	}

	public function test_filters_narrow_every_figure() {

		$product  = $this->create_product();
		$customer = $this->create_customer();

		$this->create_download_log( [ 'product_id' => $product, 'file_id' => 0, 'customer_id' => $customer, 'order_id' => 201, 'date_created' => '2026-03-05 10:00:00' ] );
		$this->create_download_log( [ 'product_id' => $product, 'file_id' => 1, 'customer_id' => $customer, 'order_id' => 201, 'date_created' => '2026-03-06 10:00:00' ] );
		$this->create_download_log( [ 'product_id' => $product, 'file_id' => 1, 'customer_id' => 0, 'order_id' => 202, 'date_created' => '2026-03-07 10:00:00' ] );
		$this->create_download_log( [ 'date_created' => '2026-03-08 10:00:00' ] );

		$count = function ( array $filter ): int {
			return $this->run_ok( 'edd-alt/analyze-download-logs', $this->plain( $filter ) )['totals']['downloads'];
		};

		$this->assertSame( 4, $count( [] ) );
		$this->assertSame( 3, $count( [ 'product_id' => $product ] ) );
		$this->assertSame( 2, $count( [ 'product_id' => $product, 'file_id' => 1 ] ) );
		$this->assertSame( 1, $count( [ 'product_id' => $product, 'file_id' => 0 ] ), 'file_id 0 is the first file, not "any file".' );
		$this->assertSame( 2, $count( [ 'customer_id' => $customer ] ) );
		$this->assertSame( 2, $count( [ 'order_id' => 201 ] ) );
		$this->assertSame( 0, $count( [ 'order_id' => 999 ] ) );
	}

	public function test_it_groups_by_file_customer_and_order_with_readable_labels() {

		$product  = $this->create_product( [ 'post_title' => 'Plugin' ] );
		$customer = $this->create_customer( [ 'name' => 'Ada Lovelace' ] );

		$this->create_download_log( [ 'product_id' => $product, 'file_id' => 0, 'customer_id' => $customer, 'order_id' => 301, 'date_created' => '2026-03-05 10:00:00' ] );
		$this->create_download_log( [ 'product_id' => $product, 'file_id' => 0, 'customer_id' => $customer, 'order_id' => 301, 'date_created' => '2026-03-06 10:00:00' ] );
		$this->create_download_log( [ 'product_id' => $product, 'file_id' => 0, 'customer_id' => 0, 'order_id' => 302, 'date_created' => '2026-03-07 10:00:00' ] );

		$files = $this->run_ok( 'edd-alt/analyze-download-logs', $this->plain( [ 'group_by' => 'file' ] ) )['breakdown']['rows'];
		$this->assertSame( "{$product}:0", $files[0]['key'] );
		$this->assertStringStartsWith( 'Plugin', $files[0]['label'] );
		$this->assertSame( 3, $files[0]['downloads'] );

		$customers = $this->run_ok( 'edd-alt/analyze-download-logs', $this->plain( [ 'group_by' => 'customer' ] ) )['breakdown']['rows'];
		$this->assertSame( (string) $customer, $customers[0]['key'] );
		$this->assertSame( 'Ada Lovelace', $customers[0]['label'] );
		$this->assertSame( '0', $customers[1]['key'] );
		$this->assertSame( 'Not tied to a customer', $customers[1]['label'] );

		$orders = $this->run_ok( 'edd-alt/analyze-download-logs', $this->plain( [ 'group_by' => 'order' ] ) )['breakdown']['rows'];
		$this->assertSame( '301', $orders[0]['key'] );
		$this->assertSame( 'Order #301', $orders[0]['label'] );
		$this->assertSame( 2, $orders[0]['downloads'] );
	}

	public function test_it_sorts_pages_and_counts_groups() {

		$products = [];
		foreach ( [ 3, 2, 1 ] as $n => $downloads ) {
			$products[ $n ] = $this->create_product();
			for ( $i = 0; $i < $downloads; $i++ ) {
				$this->create_download_log( [ 'product_id' => $products[ $n ], 'date_created' => '2026-03-05 10:00:00' ] );
			}
		}

		$page = $this->run_ok( 'edd-alt/analyze-download-logs', $this->plain( [ 'limit' => 1, 'offset' => 1 ] ) )['breakdown'];
		$this->assertSame( 3, $page['total_groups'], 'total_groups ignores the page.' );
		$this->assertCount( 1, $page['rows'] );
		$this->assertSame( 2, $page['rows'][0]['downloads'] );

		$least = $this->run_ok( 'edd-alt/analyze-download-logs', $this->plain( [ 'order' => 'ASC' ] ) )['breakdown']['rows'];
		$this->assertSame( [ 1, 2, 3 ], wp_list_pluck( $least, 'downloads' ) );

		$by_key = $this->run_ok( 'edd-alt/analyze-download-logs', $this->plain( [ 'sort' => 'key' ] ) )['breakdown']['rows'];
		$this->assertSame( array_map( 'strval', $products ), wp_list_pluck( $by_key, 'key' ) );
	}

	public function test_it_groups_by_time_in_natural_order() {

		$this->create_download_log( [ 'date_created' => '2026-03-10 14:30:00' ] ); // Tuesday
		$this->create_download_log( [ 'date_created' => '2026-03-10 14:45:00' ] );
		$this->create_download_log( [ 'date_created' => '2026-03-15 03:00:00' ] ); // Sunday
		$this->create_download_log( [ 'date_created' => '2026-04-02 03:00:00' ] );

		$rows = function ( string $group_by ) {
			return $this->run_ok( 'edd-alt/analyze-download-logs', $this->plain( [ 'group_by' => $group_by, 'created_before' => '2026-04-30' ] ) )['breakdown']['rows'];
		};

		$this->assertSame( [ '2026-03-10', '2026-03-15', '2026-04-02' ], wp_list_pluck( $rows( 'day' ), 'key' ) );

		// ISO weeks run Monday to Sunday: the Tuesday and the Sunday share a week.
		$weeks = $rows( 'week' );
		$this->assertSame( [ '2026-W11', '2026-W14' ], wp_list_pluck( $weeks, 'key' ) );
		$this->assertSame( [ 3, 1 ], wp_list_pluck( $weeks, 'downloads' ) );

		$months = $rows( 'month' );
		$this->assertSame( [ '2026-03', '2026-04' ], wp_list_pluck( $months, 'key' ) );
		$this->assertSame( [ 3, 1 ], wp_list_pluck( $months, 'downloads' ) );

		$hours = $rows( 'hour_of_day' );
		$this->assertSame( [ '3', '14' ], wp_list_pluck( $hours, 'key' ) );
		$this->assertSame( '14:00 UTC', $hours[1]['label'] );
		$this->assertSame( 2, $hours[1]['downloads'] );

		$days = $rows( 'day_of_week' );
		$this->assertSame( [ 'Sunday', 'Tuesday', 'Thursday' ], wp_list_pluck( $days, 'label' ) );
		$this->assertSame( [ 1, 2, 1 ], wp_list_pluck( $days, 'downloads' ) );
	}

	public function test_the_trend_picks_an_interval_to_suit_the_period() {

		$this->create_download_log( [ 'date_created' => '2026-03-10 09:00:00' ] );
		$this->create_download_log( [ 'date_created' => '2026-03-10 17:00:00' ] );
		$this->create_download_log( [ 'date_created' => '2026-03-12 17:00:00' ] );

		$trend = $this->run_ok( 'edd-alt/analyze-download-logs', array_merge( self::MARCH, [ 'compare_previous' => false ] ) )['trend'];

		$this->assertSame( 'day', $trend['interval'] );
		$this->assertFalse( $trend['truncated'] );
		$this->assertSame(
			[ [ 'period' => '2026-03-10', 'downloads' => 2 ], [ 'period' => '2026-03-12', 'downloads' => 1 ] ],
			$trend['periods'],
			'Oldest first, and days with no downloads are left out.'
		);
		$this->assertSame( [ 'period' => '2026-03-10', 'downloads' => 2 ], $trend['peak'] );

		$week = $this->run_ok( 'edd-alt/analyze-download-logs', array_merge( self::MARCH, [ 'trend' => 'week' ] ) )['trend'];
		$this->assertSame( 'week', $week['interval'] );
		$this->assertSame( [ [ 'period' => '2026-W11', 'downloads' => 3 ] ], $week['periods'] );

		// A long period is bucketed by week, and a very long one by month.
		$year = $this->run_ok( 'edd-alt/analyze-download-logs', [ 'created_after' => '2026-01-01', 'created_before' => '2026-12-31' ] )['trend'];
		$this->assertSame( 'week', $year['interval'] );

		$years = $this->run_ok( 'edd-alt/analyze-download-logs', [ 'created_after' => '2025-01-01', 'created_before' => '2026-12-31' ] )['trend'];
		$this->assertSame( 'month', $years['interval'] );
		$this->assertSame( [ [ 'period' => '2026-03', 'downloads' => 3 ] ], $years['periods'] );
	}

	public function test_it_sorts_clients_into_families() {

		$agents = [
			'chrome'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
			'edge'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 Edg/120.0',
			'safari'    => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15',
			'firefox'   => 'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
			'wordpress' => 'WordPress/6.8; https://example.com',
			'script'    => 'curl/8.4.0',
			'bot'       => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
			'unknown'   => '',
			'other'     => 'SomeApp/1.0',
		];

		foreach ( $agents as $agent ) {
			$this->create_download_log( [ 'user_agent' => $agent, 'date_created' => '2026-03-05 10:00:00' ] );
		}

		$rows = $this->run_ok( 'edd-alt/analyze-download-logs', $this->plain( [ 'group_by' => 'client', 'limit' => 20 ] ) )['breakdown']['rows'];

		$this->assertSame( 9, count( $rows ) );

		foreach ( $rows as $row ) {
			$this->assertSame( 1, $row['downloads'], "{$row['key']} should hold exactly one of the sample user agents." );
		}

		$this->assertEqualsCanonicalizing( array_keys( $agents ), wp_list_pluck( $rows, 'key' ) );
		$this->assertSame( 'Microsoft Edge', wp_list_pluck( $rows, 'label', 'key' )['edge'] );
	}

	public function test_it_flags_orders_whose_link_looks_shared_or_hammered() {

		// 5 downloads from 5 addresses: looks shared.
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->create_download_log( [ 'order_id' => 501, 'customer_id' => 7, 'ip' => "203.0.113.1{$i}", 'date_created' => '2026-03-05 10:00:00' ] );
		}

		// 20 downloads from one address: a script, or a customer stuck in a retry loop.
		for ( $i = 0; $i < 20; $i++ ) {
			$this->create_download_log( [ 'order_id' => 502, 'ip' => '198.51.100.1', 'date_created' => '2026-03-05 10:00:00' ] );
		}

		// Ordinary use.
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->create_download_log( [ 'order_id' => 503, 'ip' => "192.0.2.{$i}", 'date_created' => '2026-03-05 10:00:00' ] );
		}

		// Outside the period.
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->create_download_log( [ 'order_id' => 504, 'ip' => "192.0.2.1{$i}", 'date_created' => '2026-02-05 10:00:00' ] );
		}

		// A download that was never tied to an order can't be attributed. EDD's own helper refuses to
		// write one, so put it straight into the table, as an old or imported row might be.
		global $wpdb;

		for ( $i = 1; $i <= 6; $i++ ) {
			$wpdb->insert( $wpdb->edd_logs_file_downloads, [
				'product_id'    => $this->create_product(),
				'order_id'      => 0,
				'ip'            => "192.0.2.9{$i}",
				'date_created'  => '2026-03-05 10:00:00',
				'date_modified' => '2026-03-05 10:00:00',
			] );
		}

		$flags = $this->run_ok( 'edd-alt/analyze-download-logs', array_merge( self::MARCH, [ 'compare_previous' => false ] ) )['flags'];

		$this->assertCount( 2, $flags );

		$this->assertSame( 'many_ips_per_order', $flags[0]['type'] );
		$this->assertSame( 501, $flags[0]['order_id'] );
		$this->assertSame( 7, $flags[0]['customer_id'] );
		$this->assertSame( 5, $flags[0]['downloads'] );
		$this->assertSame( 5, $flags[0]['unique_ips'] );
		$this->assertStringContainsString( '501', $flags[0]['message'] );

		$this->assertSame( 'many_downloads_per_order', $flags[1]['type'] );
		$this->assertSame( 502, $flags[1]['order_id'] );
		$this->assertSame( 20, $flags[1]['downloads'] );
		$this->assertSame( 1, $flags[1]['unique_ips'] );

		// The thresholds are the caller's to move.
		$strict = $this->run_ok( 'edd-alt/analyze-download-logs', array_merge( self::MARCH, [ 'flag_min_ips' => 3, 'flag_min_downloads' => 100 ] ) )['flags'];
		$this->assertSame( [ 501, 503 ], wp_list_pluck( $strict, 'order_id' ) );

		$none = $this->run_ok( 'edd-alt/analyze-download-logs', array_merge( self::MARCH, [ 'include_flags' => false ] ) )['flags'];
		$this->assertSame( [], $none );
	}

	public function test_bad_input_is_rejected() {

		$this->assertAbilityError( 'edd_abilities_invalid_range', $this->run_ability( 'edd-alt/analyze-download-logs', [ 'created_after' => '2026-04-01', 'created_before' => '2026-03-01' ] ) );
		$this->assertAbilityError( 'edd_abilities_invalid_range', $this->run_ability( 'edd-alt/analyze-download-logs', [ 'created_after' => '2026-02-30' ] ) );
		$this->assertAbilityError( 'edd_abilities_invalid_range', $this->run_ability( 'edd-alt/analyze-download-logs', [ 'created_before' => 'last tuesday' ] ) );
		$this->assertAbilityError( 'edd_abilities_invalid_range', $this->run_ability( 'edd-alt/analyze-download-logs', [ 'created_before' => '2026-03-01 25:00:00' ] ) );
		$this->assertAbilityError( 'ability_invalid_input', $this->run_ability( 'edd-alt/analyze-download-logs', [ 'group_by' => 'country' ] ) );
		$this->assertAbilityError( 'ability_invalid_input', $this->run_ability( 'edd-alt/analyze-download-logs', [ 'limit' => 1000 ] ) );
		$this->assertAbilityError( 'ability_invalid_input', $this->run_ability( 'edd-alt/analyze-download-logs', [ 'group_by' => 'day; DROP TABLE x' ] ) );
	}

	public function test_a_time_range_with_only_a_start_runs_to_now() {

		$this->create_download_log( [ 'date_created' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ] );

		$result = $this->run_ok( 'edd-alt/analyze-download-logs', [ 'created_after' => gmdate( 'Y-m-d', time() - 3 * DAY_IN_SECONDS ) ] );

		$this->assertSame( 1, $result['totals']['downloads'] );
	}

	public function test_grouping_by_ip_needs_the_sensitive_data_capability() {

		$this->create_download_log( [ 'ip' => '203.0.113.50', 'date_created' => '2026-03-05 10:00:00' ] );

		$rows = $this->run_ok( 'edd-alt/analyze-download-logs', $this->plain( [ 'group_by' => 'ip' ] ) )['breakdown']['rows'];
		$this->assertSame( '203.0.113.50', $rows[0]['key'] );

		wp_set_current_user( $this->create_shop_user( [ 'view_shop_reports' ] ) );

		$this->assertAbilityError( 'edd_abilities_sensitive_data', $this->run_ability( 'edd-alt/analyze-download-logs', $this->plain( [ 'group_by' => 'ip' ] ) ) );

		// Everything else is open to a report viewer, and the IP counts do not reveal an address.
		$result = $this->run_ok( 'edd-alt/analyze-download-logs', $this->plain() );
		$this->assertSame( 1, $result['totals']['downloads'] );
		$this->assertSame( 1, $result['totals']['unique_ips'] );
	}

	public function test_both_abilities_need_view_shop_reports() {

		wp_set_current_user( $this->create_shop_user( [ 'edit_shop_payments' ] ) );

		$this->assertAbilityError( 'ability_invalid_permissions', $this->run_ability( 'edd-alt/analyze-download-logs' ) );
		$this->assertAbilityError( 'ability_invalid_permissions', $this->run_ability( 'edd-alt/list-download-logs' ) );
	}

	/** list-download-logs *****************************************************/

	public function test_list_returns_entries_newest_first_with_a_total() {

		$product  = $this->create_product( [ 'post_title' => 'Plugin' ] );
		$customer = $this->create_customer();

		$old = $this->create_download_log( [ 'product_id' => $product, 'customer_id' => $customer, 'order_id' => 601, 'user_agent' => 'curl/8.4.0', 'date_created' => '2026-03-05 10:00:00' ] );
		$new = $this->create_download_log( [ 'product_id' => $product, 'customer_id' => $customer, 'order_id' => 601, 'date_created' => '2026-03-09 10:00:00' ] );
		$this->create_download_log( [ 'date_created' => '2026-03-07 10:00:00' ] );

		$result = $this->run_ok( 'edd-alt/list-download-logs', [ 'product_id' => $product ] );

		$this->assertSame( 2, $result['total_items'] );
		$this->assertSame( [ $new, $old ], wp_list_pluck( $result['logs'], 'id' ) );

		$log = $result['logs'][1];
		$this->assertSame( $product, $log['product_id'] );
		$this->assertSame( 'Plugin', $log['product_title'] );
		$this->assertSame( $customer, $log['customer_id'] );
		$this->assertSame( 601, $log['order_id'] );
		$this->assertSame( 'curl/8.4.0', $log['user_agent'] );
		$this->assertSame( '2026-03-05T10:00:00Z', $log['date_created']['utc'] );
		$this->assertNull( $log['price_id'], 'A product without price options has no price id.' );

		$asc = $this->run_ok( 'edd-alt/list-download-logs', [ 'product_id' => $product, 'order' => 'ASC' ] );
		$this->assertSame( [ $old, $new ], wp_list_pluck( $asc['logs'], 'id' ) );
	}

	public function test_list_filters_and_pages() {

		$customer = $this->create_customer();

		for ( $day = 1; $day <= 5; $day++ ) {
			$this->create_download_log( [ 'customer_id' => $customer, 'order_id' => 700 + $day, 'date_created' => sprintf( '2026-03-%02d 10:00:00', $day ) ] );
		}
		$this->create_download_log( [ 'date_created' => '2026-03-03 11:00:00' ] );

		$this->assertSame( 5, $this->run_ok( 'edd-alt/list-download-logs', [ 'customer_id' => $customer ] )['total_items'] );
		$this->assertSame( 1, $this->run_ok( 'edd-alt/list-download-logs', [ 'order_id' => 703 ] )['total_items'] );

		$dated = $this->run_ok( 'edd-alt/list-download-logs', [ 'created_after' => '2026-03-02', 'created_before' => '2026-03-04 23:59:59' ] );
		$this->assertSame( 4, $dated['total_items'] );

		$page = $this->run_ok( 'edd-alt/list-download-logs', [ 'customer_id' => $customer, 'limit' => 2, 'offset' => 2 ] );
		$this->assertSame( 5, $page['total_items'], 'total_items ignores the page.' );
		$this->assertSame( [ 703, 702 ], wp_list_pluck( $page['logs'], 'order_id' ) );

		$this->assertSame( [], $this->run_ok( 'edd-alt/list-download-logs', [ 'order_id' => 99999 ] )['logs'] );
	}

	public function test_list_only_shows_ip_addresses_to_users_who_may_see_them() {

		$this->create_download_log( [ 'ip' => '203.0.113.77', 'order_id' => 801 ] );

		$admin = $this->run_ok( 'edd-alt/list-download-logs', [ 'order_id' => 801 ] );
		$this->assertSame( '203.0.113.77', $admin['logs'][0]['ip'] );

		wp_set_current_user( $this->create_shop_user( [ 'view_shop_reports' ] ) );

		$analyst = $this->run_ok( 'edd-alt/list-download-logs', [ 'order_id' => 801 ] );
		$this->assertCount( 1, $analyst['logs'] );
		$this->assertArrayNotHasKey( 'ip', $analyst['logs'][0] );
		$this->assertStringNotContainsString( '203.0.113.77', wp_json_encode( $analyst ) );
	}
}
