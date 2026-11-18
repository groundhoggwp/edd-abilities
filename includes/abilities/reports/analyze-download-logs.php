<?php

namespace EDD_Abilities\Abilities\Reports;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregates EDD's file download log (Downloads > Reports > File Downloads) so an agent can answer
 * questions like "what was downloaded most last month?" or "is anyone sharing their download link?"
 * without paging through thousands of raw rows.
 *
 * Every figure is computed in SQL over the log table, so it stays cheap on large stores. All times are
 * UTC, because that is how EDD stores them, and so is the grouping by day, week, month and hour.
 */
class Analyze_Download_Logs extends Ability {

	protected const NAME       = 'edd-alt/analyze-download-logs';
	protected const CATEGORY   = 'edd-alt-reports';
	protected const CAPABILITY = 'view_shop_reports';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	/**
	 * What a breakdown can be grouped by.
	 */
	protected const DIMENSIONS = [ 'product', 'file', 'customer', 'order', 'client', 'day', 'week', 'month', 'hour_of_day', 'day_of_week', 'ip' ];

	/**
	 * Dimensions that are a position in time rather than a thing, and so read best in natural order.
	 */
	protected const TIME_DIMENSIONS = [ 'day', 'week', 'month', 'hour_of_day', 'day_of_week' ];

	protected const TRENDS = [ 'auto', 'day', 'week', 'month', 'none' ];

	/**
	 * The most periods the trend will return, newest kept.
	 */
	protected const TREND_MAX_PERIODS = 366;

	protected function get_args(): array {

		return [
			'label'       => __( 'Analyze Download Logs', 'edd-abilities' ),
			'description' => __( 'Analyze Easy Digital Downloads file download logs. Returns totals, a change against the previous period of the same length, a breakdown (by product, file, customer, order, client type, day, week, month, hour of day, day of week, or IP address), a download trend over time, and flags for orders whose download link looks shared or abused. Defaults to the last 30 days. All times are UTC. Use edd-alt/list-download-logs to see the individual log entries behind a figure.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array_merge( [
					'created_after'      => [
						'type'        => 'string',
						'description' => __( 'Start of the period (UTC), inclusive. YYYY-MM-DD (from the start of that day) or YYYY-MM-DD HH:MM:SS. Defaults to 30 days before created_before.', 'edd-abilities' ),
					],
					'created_before'     => [
						'type'        => 'string',
						'description' => __( 'End of the period (UTC), inclusive. YYYY-MM-DD (to the end of that day) or YYYY-MM-DD HH:MM:SS. Defaults to now.', 'edd-abilities' ),
					],
					'product_id'         => [ 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Only downloads of this product.', 'edd-abilities' ) ],
					'file_id'            => [ 'type' => 'integer', 'minimum' => 0, 'description' => __( 'Only downloads of this file, as its key in the product\'s file list. Use with product_id.', 'edd-abilities' ) ],
					'customer_id'        => [ 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Only downloads by this customer.', 'edd-abilities' ) ],
					'order_id'           => [ 'type' => 'integer', 'minimum' => 1, 'description' => __( 'Only downloads made with this order\'s download link.', 'edd-abilities' ) ],
					'group_by'           => [
						'type'        => 'string',
						'enum'        => self::DIMENSIONS,
						'default'     => 'product',
						'description' => __( 'What to break the downloads down by. "client" sorts the user agent into browser families, wordpress (plugin updates), script (curl, Python and the like) and bot. "ip" needs the view_shop_sensitive_data capability.', 'edd-abilities' ),
					],
					'sort'               => [
						'type'        => 'string',
						'enum'        => [ 'downloads', 'key' ],
						'description' => __( 'Sort the breakdown by download count or by its key. Defaults to downloads, or to key when grouping by a time period.', 'edd-abilities' ),
					],
					'order'              => [
						'type'        => 'string',
						'enum'        => [ 'ASC', 'DESC' ],
						'description' => __( 'Sort direction. Defaults to DESC for downloads and ASC for key.', 'edd-abilities' ),
					],
					'trend'              => [
						'type'        => 'string',
						'enum'        => self::TRENDS,
						'default'     => 'auto',
						'description' => __( 'Downloads over time. "auto" picks day, week or month to suit the length of the period; "none" leaves the trend out. Periods with no downloads are not listed.', 'edd-abilities' ),
					],
					'compare_previous'   => [
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'Compare the total with the previous period of the same length, ending just before this one starts.', 'edd-abilities' ),
					],
					'include_flags'      => [
						'type'        => 'boolean',
						'default'     => true,
						'description' => __( 'Flag orders in this period whose download link was used from many different IP addresses or downloaded an unusual number of times.', 'edd-abilities' ),
					],
					'flag_min_ips'       => [ 'type' => 'integer', 'minimum' => 2, 'default' => 5, 'description' => __( 'Flag an order downloaded from at least this many different IP addresses.', 'edd-abilities' ) ],
					'flag_min_downloads' => [ 'type' => 'integer', 'minimum' => 2, 'default' => 20, 'description' => __( 'Flag an order downloaded at least this many times.', 'edd-abilities' ) ],
				], Schema::pagination_input( 10, 100 ) ),
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'range'      => [
						'type'       => 'object',
						'properties' => [
							'after'  => Schema::datetime_schema(),
							'before' => Schema::datetime_schema(),
							'days'   => [ 'type' => 'number', 'description' => __( 'Length of the period in days.', 'edd-abilities' ) ],
						],
					],
					'totals'     => [
						'type'       => 'object',
						'properties' => [
							'downloads'        => [ 'type' => 'integer' ],
							'unique_products'  => [ 'type' => 'integer' ],
							'unique_customers' => [ 'type' => 'integer', 'description' => __( 'Distinct customers; downloads not tied to a customer are not counted.', 'edd-abilities' ) ],
							'unique_orders'    => [ 'type' => 'integer' ],
							'unique_ips'       => [ 'type' => 'integer' ],
							'average_per_day'  => [ 'type' => 'number' ],
							'first_download'   => Schema::datetime_schema(),
							'last_download'    => Schema::datetime_schema(),
						],
					],
					'comparison' => [
						'type'        => [ 'object', 'null' ],
						'description' => __( 'The same total for the previous period. Null when compare_previous is false.', 'edd-abilities' ),
						'properties'  => [
							'previous_range'     => [
								'type'       => 'object',
								'properties' => [
									'after'  => Schema::datetime_schema(),
									'before' => Schema::datetime_schema(),
								],
							],
							'previous_downloads' => [ 'type' => 'integer' ],
							'change'             => [ 'type' => 'integer', 'description' => __( 'This period\'s downloads minus the previous period\'s.', 'edd-abilities' ) ],
							'change_percent'     => [ 'type' => [ 'number', 'null' ], 'description' => __( 'Null when the previous period had no downloads.', 'edd-abilities' ) ],
						],
					],
					'breakdown'  => [
						'type'       => 'object',
						'properties' => [
							'group_by'     => [ 'type' => 'string' ],
							'total_groups' => [ 'type' => 'integer', 'description' => __( 'How many groups there are in all, ignoring limit/offset.', 'edd-abilities' ) ],
							'rows'         => [
								'type'  => 'array',
								'items' => [
									'type'       => 'object',
									'properties' => [
										'key'              => [ 'type' => 'string', 'description' => __( 'The id or value that was grouped on, e.g. a product ID. For "file" it is product_id:file_id.', 'edd-abilities' ) ],
										'label'            => [ 'type' => 'string' ],
										'downloads'        => [ 'type' => 'integer' ],
										'share'            => [ 'type' => 'number', 'description' => __( 'Fraction of all downloads in the period, 0 to 1.', 'edd-abilities' ) ],
										'unique_customers' => [ 'type' => 'integer' ],
										'first_download'   => Schema::datetime_schema(),
										'last_download'    => Schema::datetime_schema(),
									],
								],
							],
						],
					],
					'trend'      => [
						'type'        => [ 'object', 'null' ],
						'description' => __( 'Downloads per period, oldest first. Null when trend is "none".', 'edd-abilities' ),
						'properties'  => [
							'interval'  => [ 'type' => 'string', 'description' => __( 'day, week (ISO, e.g. 2026-W07) or month.', 'edd-abilities' ) ],
							'truncated' => [ 'type' => 'boolean', 'description' => __( 'True when older periods were left out to keep the result small.', 'edd-abilities' ) ],
							'periods'   => [
								'type'  => 'array',
								'items' => [
									'type'       => 'object',
									'properties' => [
										'period'    => [ 'type' => 'string' ],
										'downloads' => [ 'type' => 'integer' ],
									],
								],
							],
							'peak'      => [
								'type'       => [ 'object', 'null' ],
								'properties' => [
									'period'    => [ 'type' => 'string' ],
									'downloads' => [ 'type' => 'integer' ],
								],
							],
						],
					],
					'flags'      => [
						'type'        => 'array',
						'description' => __( 'Orders worth a closer look, most suspicious first. Empty when there is nothing unusual or include_flags is false.', 'edd-abilities' ),
						'items'       => [
							'type'       => 'object',
							'properties' => [
								'type'        => [ 'type' => 'string', 'enum' => [ 'many_ips_per_order', 'many_downloads_per_order' ] ],
								'order_id'    => [ 'type' => 'integer' ],
								'customer_id' => [ 'type' => 'integer' ],
								'downloads'   => [ 'type' => 'integer' ],
								'unique_ips'  => [ 'type' => 'integer' ],
								'message'     => [ 'type' => 'string' ],
							],
						],
					],
				],
			],
		];
	}

	public function __invoke( $input ) {

		global $wpdb;

		$input = (array) $input;

		$group_by = in_array( $input['group_by'] ?? '', self::DIMENSIONS, true ) ? $input['group_by'] : 'product';

		if ( 'ip' === $group_by && ! current_user_can( 'view_shop_sensitive_data' ) ) {
			return new \WP_Error( 'edd_abilities_sensitive_data', __( 'Grouping by IP address needs the view_shop_sensitive_data capability.', 'edd-abilities' ) );
		}

		$range = $this->resolve_range( $input );

		if ( is_wp_error( $range ) ) {
			return $range;
		}

		list( $after, $before ) = $range;

		$span_seconds = $before - $after + 1;
		$span_days    = round( $span_seconds / DAY_IN_SECONDS, 2 );

		$table   = $wpdb->edd_logs_file_downloads;
		$filters = $this->filters( $input );

		try {

			$where = $this->where( $filters, $after, $before );

			// Totals.
			$row = $this->rows(
				"SELECT COUNT(*) AS downloads,
					COUNT(DISTINCT NULLIF(product_id, 0)) AS products,
					COUNT(DISTINCT NULLIF(customer_id, 0)) AS customers,
					COUNT(DISTINCT NULLIF(order_id, 0)) AS orders,
					COUNT(DISTINCT NULLIF(ip, '')) AS ips,
					MIN(date_created) AS first_download,
					MAX(date_created) AS last_download
				FROM {$table} WHERE {$where}"
			)[0];

			$downloads = (int) $row['downloads'];

			$totals = [
				'downloads'        => $downloads,
				'unique_products'  => (int) $row['products'],
				'unique_customers' => (int) $row['customers'],
				'unique_orders'    => (int) $row['orders'],
				'unique_ips'       => (int) $row['ips'],
				'average_per_day'  => $span_days > 0 ? round( $downloads / max( $span_days, 1 ), 2 ) : 0.0,
				'first_download'   => Schema::datetime( $row['first_download'] ),
				'last_download'    => Schema::datetime( $row['last_download'] ),
			];

			return [
				'range'      => [
					'after'  => Schema::datetime( gmdate( 'Y-m-d H:i:s', $after ) ),
					'before' => Schema::datetime( gmdate( 'Y-m-d H:i:s', $before ) ),
					'days'   => $span_days,
				],
				'totals'     => $totals,
				'comparison' => ! array_key_exists( 'compare_previous', $input ) || $input['compare_previous']
					? $this->compare( $filters, $after, $before, $span_seconds, $downloads )
					: null,
				'breakdown'  => $this->breakdown( $group_by, $input, $where, $downloads ),
				'trend'      => $this->trend( $input, $where, $span_days ),
				'flags'      => ! array_key_exists( 'include_flags', $input ) || $input['include_flags']
					? $this->flags( $input, $where )
					: [],
			];

		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'edd_abilities_db_error', $e->getMessage() );
		}
	}

	/**
	 * The period to analyse, as unix timestamps.
	 *
	 * @param array $input
	 *
	 * @return array{0:int,1:int}|\WP_Error [ after, before ], both inclusive
	 */
	protected function resolve_range( array $input ) {

		$before = $this->parse_bound( $input['created_before'] ?? '', true );
		$after  = $this->parse_bound( $input['created_after'] ?? '', false );

		if ( false === $before || false === $after ) {
			return new \WP_Error( 'edd_abilities_invalid_range', __( 'Dates must be real dates in the form YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.', 'edd-abilities' ) );
		}

		$before = $before ?? time();
		$after  = $after ?? $before - 30 * DAY_IN_SECONDS;

		if ( $after > $before ) {
			return new \WP_Error( 'edd_abilities_invalid_range', __( 'created_after must not be after created_before.', 'edd-abilities' ) );
		}

		return [ $after, $before ];
	}

	/**
	 * @param mixed $value       YYYY-MM-DD or YYYY-MM-DD HH:MM:SS (UTC)
	 * @param bool  $end_of_day  where a bare date lands: the last second of the day instead of the first
	 *
	 * @return int|false|null a unix timestamp, null when nothing was given, false when it is not a real date
	 */
	protected function parse_bound( $value, bool $end_of_day ) {

		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return false;
		}

		if ( isset( $m[4] ) ) {

			if ( (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59 ) {
				return false;
			}

			return gmmktime( (int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1] );
		}

		return $end_of_day
			? gmmktime( 23, 59, 59, (int) $m[2], (int) $m[3], (int) $m[1] )
			: gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * The column filters the caller asked for.
	 *
	 * @param array $input
	 *
	 * @return array<string,int> column => value
	 */
	protected function filters( array $input ): array {

		$filters = [];

		foreach ( [ 'product_id', 'file_id', 'customer_id', 'order_id' ] as $column ) {
			if ( isset( $input[ $column ] ) ) {
				$filters[ $column ] = absint( $input[ $column ] );
			}
		}

		return $filters;
	}

	/**
	 * A prepared WHERE clause for the period and filters. Nothing but integers and dates from
	 * resolve_range() goes into it.
	 *
	 * @param array<string,int> $filters
	 * @param int               $after
	 * @param int               $before
	 *
	 * @return string
	 */
	protected function where( array $filters, int $after, int $before ): string {

		global $wpdb;

		$clauses = [ 'date_created >= %s', 'date_created <= %s' ];
		$args    = [ gmdate( 'Y-m-d H:i:s', $after ), gmdate( 'Y-m-d H:i:s', $before ) ];

		foreach ( $filters as $column => $value ) {
			// $column is one of the four keys filters() allows, never caller text.
			$clauses[] = "{$column} = %d";
			$args[]    = $value;
		}

		return $wpdb->prepare( implode( ' AND ', $clauses ), $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Run a query built only from this class's own SQL and an already prepared WHERE clause.
	 *
	 * @param string $sql
	 *
	 * @return array[]
	 * @throws \RuntimeException when the database reports an error
	 */
	protected function rows( string $sql ): array {

		global $wpdb;

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( $wpdb->last_error ) {
			throw new \RuntimeException( __( 'The download log could not be read.', 'edd-abilities' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return (array) $rows;
	}

	/**
	 * The SQL that yields a row's group for a dimension. Fixed strings only: the caller picks a name
	 * from DIMENSIONS and never supplies any SQL.
	 *
	 * @param string $dimension
	 *
	 * @return string
	 */
	protected function expression( string $dimension ): string {

		switch ( $dimension ) {
			case 'file':
				return "CONCAT( product_id, ':', file_id )";
			case 'customer':
				return 'customer_id';
			case 'order':
				return 'order_id';
			case 'ip':
				return 'ip';
			case 'client':
				return "CASE
					WHEN user_agent = '' THEN 'unknown'
					WHEN user_agent LIKE '%bot%' OR user_agent LIKE '%spider%' OR user_agent LIKE '%crawl%' OR user_agent LIKE '%slurp%' THEN 'bot'
					WHEN user_agent LIKE 'curl/%' OR user_agent LIKE 'Wget/%' OR user_agent LIKE '%python%' OR user_agent LIKE '%Go-http-client%' OR user_agent LIKE 'Java/%' OR user_agent LIKE '%axios%' OR user_agent LIKE 'node%' THEN 'script'
					WHEN user_agent LIKE 'WordPress/%' THEN 'wordpress'
					WHEN user_agent LIKE '%Edg/%' OR user_agent LIKE '%Edge/%' THEN 'edge'
					WHEN user_agent LIKE '%Firefox/%' THEN 'firefox'
					WHEN user_agent LIKE '%Chrome/%' OR user_agent LIKE '%CriOS/%' THEN 'chrome'
					WHEN user_agent LIKE '%Safari/%' THEN 'safari'
					ELSE 'other'
				END";
			case 'day':
				return 'DATE( date_created )';
			case 'week':
				return "DATE_FORMAT( date_created, '%x-W%v' )";
			case 'month':
				return "DATE_FORMAT( date_created, '%Y-%m' )";
			case 'hour_of_day':
				return 'HOUR( date_created )';
			case 'day_of_week':
				return 'DAYOFWEEK( date_created )';
			case 'product':
			default:
				return 'product_id';
		}
	}

	/**
	 * @param array<string,int> $filters
	 * @param int               $after
	 * @param int               $before
	 * @param int               $span_seconds
	 * @param int               $downloads    this period's total
	 *
	 * @return array
	 */
	protected function compare( array $filters, int $after, int $before, int $span_seconds, int $downloads ): array {

		global $wpdb;

		$previous_before = $after - 1;
		$previous_after  = $after - $span_seconds;

		$where = $this->where( $filters, $previous_after, $previous_before );

		$previous = (int) $this->rows( "SELECT COUNT(*) AS downloads FROM {$wpdb->edd_logs_file_downloads} WHERE {$where}" )[0]['downloads']; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return [
			'previous_range'     => [
				'after'  => Schema::datetime( gmdate( 'Y-m-d H:i:s', $previous_after ) ),
				'before' => Schema::datetime( gmdate( 'Y-m-d H:i:s', $previous_before ) ),
			],
			'previous_downloads' => $previous,
			'change'             => $downloads - $previous,
			'change_percent'     => $previous > 0 ? round( ( $downloads - $previous ) / $previous * 100, 1 ) : null,
		];
	}

	/**
	 * @param string $group_by
	 * @param array  $input
	 * @param string $where     prepared WHERE clause
	 * @param int    $downloads this period's total, for the share column
	 *
	 * @return array
	 */
	protected function breakdown( string $group_by, array $input, string $where, int $downloads ): array {

		global $wpdb;

		$table = $wpdb->edd_logs_file_downloads;
		$expr  = $this->expression( $group_by );

		list( $limit, $offset ) = Schema::pagination( $input, 10, 100 );

		$is_time = in_array( $group_by, self::TIME_DIMENSIONS, true );
		$sort    = in_array( $input['sort'] ?? '', [ 'downloads', 'key' ], true ) ? $input['sort'] : ( $is_time ? 'key' : 'downloads' );
		$default = 'downloads' === $sort ? 'DESC' : 'ASC';
		$order   = in_array( $input['order'] ?? '', [ 'ASC', 'DESC' ], true ) ? $input['order'] : $default;

		$order_by = 'downloads' === $sort ? "downloads {$order}, k ASC" : "k {$order}";

		$total_groups = (int) $this->rows( "SELECT COUNT(*) AS n FROM ( SELECT {$expr} AS k FROM {$table} WHERE {$where} GROUP BY k ) AS g" )[0]['n']; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows = $this->rows(
			"SELECT {$expr} AS k,
				COUNT(*) AS downloads,
				COUNT(DISTINCT NULLIF(customer_id, 0)) AS customers,
				MIN(date_created) AS first_download,
				MAX(date_created) AS last_download
			FROM {$table} WHERE {$where}
			GROUP BY k ORDER BY {$order_by} LIMIT {$limit} OFFSET {$offset}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$out = [];

		foreach ( $rows as $row ) {
			$out[] = [
				'key'              => (string) $row['k'],
				'label'            => $this->label( $group_by, (string) $row['k'] ),
				'downloads'        => (int) $row['downloads'],
				'share'            => $downloads > 0 ? round( (int) $row['downloads'] / $downloads, 4 ) : 0.0,
				'unique_customers' => (int) $row['customers'],
				'first_download'   => Schema::datetime( $row['first_download'] ),
				'last_download'    => Schema::datetime( $row['last_download'] ),
			];
		}

		return [
			'group_by'     => $group_by,
			'total_groups' => $total_groups,
			'rows'         => $out,
		];
	}

	/**
	 * A human-readable name for a group.
	 *
	 * @param string $dimension
	 * @param string $key
	 *
	 * @return string
	 */
	protected function label( string $dimension, string $key ): string {

		global $wp_locale;

		switch ( $dimension ) {

			case 'product':
				$title = get_the_title( (int) $key );

				return '' !== $title ? $title : sprintf( '#%d', (int) $key );

			case 'file':
				list( $product_id, $file_id ) = array_map( 'intval', explode( ':', $key . ':0' ) );

				$title = get_the_title( $product_id );
				$title = '' !== $title ? $title : sprintf( '#%d', $product_id );
				$files = function_exists( 'edd_get_download_files' ) ? (array) edd_get_download_files( $product_id ) : [];
				$name  = isset( $files[ $file_id ]['name'] ) ? (string) $files[ $file_id ]['name'] : '';

				/* translators: 1: product title, 2: file name or number */
				return sprintf( __( '%1$s - %2$s', 'edd-abilities' ), $title, '' !== $name ? $name : sprintf( '#%d', $file_id ) );

			case 'customer':
				if ( (int) $key < 1 ) {
					return __( 'Not tied to a customer', 'edd-abilities' );
				}

				$customer = edd_get_customer( (int) $key );

				if ( ! $customer ) {
					return sprintf( '#%d', (int) $key );
				}

				return '' !== (string) $customer->name ? (string) $customer->name : (string) $customer->email;

			case 'order':
				/* translators: %d: an order ID */
				return (int) $key > 0 ? sprintf( __( 'Order #%d', 'edd-abilities' ), (int) $key ) : __( 'Not tied to an order', 'edd-abilities' );

			case 'ip':
				return '' !== $key ? $key : __( 'Unknown', 'edd-abilities' );

			case 'client':
				$names = [
					'unknown'   => __( 'Unknown', 'edd-abilities' ),
					'bot'       => __( 'Bot or crawler', 'edd-abilities' ),
					'script'    => __( 'Script or command-line tool', 'edd-abilities' ),
					'wordpress' => __( 'WordPress (plugin update or install)', 'edd-abilities' ),
					'edge'      => __( 'Microsoft Edge', 'edd-abilities' ),
					'firefox'   => __( 'Firefox', 'edd-abilities' ),
					'chrome'    => __( 'Chrome', 'edd-abilities' ),
					'safari'    => __( 'Safari', 'edd-abilities' ),
					'other'     => __( 'Other', 'edd-abilities' ),
				];

				return $names[ $key ] ?? $key;

			case 'hour_of_day':
				return sprintf( '%02d:00 UTC', (int) $key );

			case 'day_of_week':
				// MySQL's DAYOFWEEK() is 1 for Sunday, which is WordPress's 0.
				return (string) $wp_locale->get_weekday( max( 0, min( 6, (int) $key - 1 ) ) );

			default:
				return $key;
		}
	}

	/**
	 * @param array  $input
	 * @param string $where     prepared WHERE clause
	 * @param float  $span_days
	 *
	 * @return array|null
	 */
	protected function trend( array $input, string $where, float $span_days ): ?array {

		global $wpdb;

		$interval = in_array( $input['trend'] ?? '', self::TRENDS, true ) ? $input['trend'] : 'auto';

		if ( 'none' === $interval ) {
			return null;
		}

		if ( 'auto' === $interval ) {
			$interval = $span_days <= 62 ? 'day' : ( $span_days <= 400 ? 'week' : 'month' );
		}

		$expr  = $this->expression( $interval );
		$fetch = self::TREND_MAX_PERIODS + 1;

		// Newest first so the cap drops the oldest periods, then put them back in order.
		$rows = $this->rows( "SELECT {$expr} AS k, COUNT(*) AS downloads FROM {$wpdb->edd_logs_file_downloads} WHERE {$where} GROUP BY k ORDER BY k DESC LIMIT {$fetch}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$truncated = count( $rows ) > self::TREND_MAX_PERIODS;
		$rows      = array_reverse( array_slice( $rows, 0, self::TREND_MAX_PERIODS ) );

		$periods = [];
		$peak    = null;

		foreach ( $rows as $row ) {

			$period = [
				'period'    => (string) $row['k'],
				'downloads' => (int) $row['downloads'],
			];

			if ( null === $peak || $period['downloads'] > $peak['downloads'] ) {
				$peak = $period;
			}

			$periods[] = $period;
		}

		return [
			'interval'  => $interval,
			'truncated' => $truncated,
			'periods'   => $periods,
			'peak'      => $peak,
		];
	}

	/**
	 * Orders whose download link was used from many places or many times: the usual signs of a link
	 * that has been shared, or of a script hammering it.
	 *
	 * @param array  $input
	 * @param string $where prepared WHERE clause
	 *
	 * @return array[]
	 */
	protected function flags( array $input, string $where ): array {

		global $wpdb;

		$min_ips       = isset( $input['flag_min_ips'] ) ? max( 2, absint( $input['flag_min_ips'] ) ) : 5;
		$min_downloads = isset( $input['flag_min_downloads'] ) ? max( 2, absint( $input['flag_min_downloads'] ) ) : 20;

		$rows = $this->rows(
			"SELECT order_id,
				MAX(customer_id) AS customer_id,
				COUNT(*) AS downloads,
				COUNT(DISTINCT NULLIF(ip, '')) AS ips
			FROM {$wpdb->edd_logs_file_downloads}
			WHERE {$where} AND order_id > 0
			GROUP BY order_id
			HAVING ips >= {$min_ips} OR downloads >= {$min_downloads}
			ORDER BY ips DESC, downloads DESC, order_id ASC
			LIMIT 10" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$flags = [];

		foreach ( $rows as $row ) {

			$ips       = (int) $row['ips'];
			$downloads = (int) $row['downloads'];
			$many_ips  = $ips >= $min_ips;

			$flags[] = [
				'type'        => $many_ips ? 'many_ips_per_order' : 'many_downloads_per_order',
				'order_id'    => (int) $row['order_id'],
				'customer_id' => (int) $row['customer_id'],
				'downloads'   => $downloads,
				'unique_ips'  => $ips,
				'message'     => $many_ips
					/* translators: 1: an order ID, 2: number of downloads, 3: number of IP addresses */
					? sprintf( __( 'Order #%1$d was downloaded %2$d times from %3$d different IP addresses. Its download link may have been shared.', 'edd-abilities' ), (int) $row['order_id'], $downloads, $ips )
					/* translators: 1: an order ID, 2: number of downloads */
					: sprintf( __( 'Order #%1$d was downloaded %2$d times.', 'edd-abilities' ), (int) $row['order_id'], $downloads ),
			];
		}

		return $flags;
	}
}
