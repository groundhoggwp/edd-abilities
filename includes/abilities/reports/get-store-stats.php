<?php

namespace EDD_Abilities\Abilities\Reports;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Store-wide sales statistics for a date range, computed by EDD's own Stats class so the numbers
 * match Downloads > Reports.
 */
class Get_Store_Stats extends Ability {

	protected const NAME       = 'edd-alt/get-store-stats';
	protected const CATEGORY   = 'edd-alt-reports';
	protected const CAPABILITY = 'view_shop_reports';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected const RANGES = [
		'today',
		'yesterday',
		'this_week',
		'last_week',
		'last_30_days',
		'this_month',
		'last_month',
		'this_quarter',
		'last_quarter',
		'this_year',
		'last_year',
	];

	protected function get_args(): array {

		return [
			'label'       => __( 'Get Store Stats', 'edd-abilities' ),
			'description' => __( 'Get Easy Digital Downloads sales statistics for a date range: earnings, order count, refunds, tax, discount savings, new customers and top products. Numbers come from EDD\'s own reports so they match the admin.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'range'         => [
						'type'        => 'string',
						'enum'        => self::RANGES,
						'default'     => 'last_30_days',
						'description' => __( 'A relative date range. Ignored when start and end are both given.', 'edd-abilities' ),
					],
					'start'         => [ 'type' => 'string', 'description' => __( 'Custom range start date, YYYY-MM-DD (site timezone). Use with end.', 'edd-abilities' ) ],
					'end'           => [ 'type' => 'string', 'description' => __( 'Custom range end date, YYYY-MM-DD (site timezone). Use with start.', 'edd-abilities' ) ],
					'exclude_taxes' => [ 'type' => 'boolean', 'default' => false, 'description' => __( 'Leave tax out of the earnings figures.', 'edd-abilities' ) ],
					'top_products'  => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 25, 'default' => 5, 'description' => __( 'How many top-earning products to list. 0 skips it.', 'edd-abilities' ) ],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'range'                => [ 'type' => 'string', 'description' => __( 'The range that was used: a range name, or "custom".', 'edd-abilities' ) ],
					'currency'             => [ 'type' => 'string' ],
					'earnings'             => [ 'type' => 'number', 'description' => __( 'Total earnings from sales, net of refunds.', 'edd-abilities' ) ],
					'order_count'          => [ 'type' => 'integer' ],
					'average_order_value'  => [ 'type' => 'number' ],
					'refund_count'         => [ 'type' => 'integer' ],
					'refund_amount'        => [ 'type' => 'number' ],
					'tax'                  => [ 'type' => 'number' ],
					'discount_savings'     => [ 'type' => 'number', 'description' => __( 'Total value customers saved with discount codes.', 'edd-abilities' ) ],
					'new_customers'        => [ 'type' => 'integer', 'description' => __( 'Customers created in the range.', 'edd-abilities' ) ],
					'top_products'         => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'product_id' => [ 'type' => 'integer' ],
								'title'      => [ 'type' => 'string' ],
								'price_id'   => [ 'type' => [ 'integer', 'null' ] ],
								'earnings'   => [ 'type' => 'number' ],
							],
						],
					],
				],
			],
		];
	}

	public function __invoke( $input ) {

		$input = (array) $input;

		$query = [ 'exclude_taxes' => ! empty( $input['exclude_taxes'] ) ];
		$range = 'custom';

		$start = $this->clean_date( $input['start'] ?? '' );
		$end   = $this->clean_date( $input['end'] ?? '' );

		if ( $start && $end ) {

			if ( $start > $end ) {
				return new \WP_Error( 'edd_abilities_invalid_range', __( 'start must not be after end.', 'edd-abilities' ) );
			}

			$query['start'] = $start;
			$query['end']   = $end;

		} else {
			$range          = in_array( $input['range'] ?? '', self::RANGES, true ) ? $input['range'] : 'last_30_days';
			$query['range'] = $range;
		}

		$stats = new \EDD\Stats( $query );

		$earnings = $stats->get_order_earnings();
		$count    = $stats->get_order_count();
		$average  = $stats->get_order_earnings( [ 'function' => 'AVG' ] );
		$refunds  = $stats->get_order_refund_count();
		$refunded = $stats->get_order_refund_amount();
		$tax      = $stats->get_tax();
		$savings  = $stats->get_discount_savings();
		$new      = $stats->get_customer_count();

		foreach ( [ $earnings, $count, $average, $refunds, $refunded, $tax, $savings, $new ] as $value ) {
			if ( is_wp_error( $value ) ) {
				return $value;
			}
		}

		$top   = [];
		$limit = isset( $input['top_products'] ) ? min( absint( $input['top_products'] ), 25 ) : 5;

		if ( $limit > 0 ) {
			foreach ( (array) $stats->get_most_valuable_order_items( [ 'number' => $limit ] ) as $row ) {
				$top[] = [
					'product_id' => (int) $row->product_id,
					'title'      => (string) get_the_title( $row->product_id ),
					'price_id'   => isset( $row->price_id ) && '' !== $row->price_id && null !== $row->price_id ? (int) $row->price_id : null,
					'earnings'   => Schema::money( $row->total ),
				];
			}
		}

		return [
			'range'               => $range,
			'currency'            => (string) edd_get_currency(),
			'earnings'            => Schema::money( $earnings ),
			'order_count'         => (int) $count,
			'average_order_value' => Schema::money( $average ),
			'refund_count'        => (int) $refunds,
			'refund_amount'       => Schema::money( $refunded ),
			'tax'                 => Schema::money( $tax ),
			'discount_savings'    => Schema::money( $savings ),
			'new_customers'       => (int) $new,
			'top_products'        => $top,
		];
	}

	/**
	 * @param string $value
	 *
	 * @return string YYYY-MM-DD, or '' if not a real date
	 */
	protected function clean_date( $value ): string {

		$value = trim( (string) $value );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}

		return $value;
	}
}
