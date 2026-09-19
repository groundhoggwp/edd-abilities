<?php

namespace EDD_Abilities\Abilities\Orders;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Order_Schema;
use EDD_Abilities\Abilities\Schemas\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists/searches EDD orders (Downloads > Payments in the admin).
 */
class List_Orders extends Ability {

	protected const NAME       = 'edd/list-orders';
	protected const CATEGORY   = 'edd-orders';
	protected const CAPABILITY = 'edit_shop_payments';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'List Orders', 'edd-abilities' ),
			'description' => __( 'List or search Easy Digital Downloads orders, newest first by default. Filter by status, customer, product, gateway or date. Use edd/get-order for a single order in full.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array_merge( [
					'search'      => [
						'type'        => 'string',
						'description' => __( 'Free-text search: order ID, order number, email or payment key.', 'edd-abilities' ),
					],
					'status'      => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string' ],
						'description' => __( 'Only orders with one of these statuses, e.g. complete, pending, refunded, partially_refunded, failed, abandoned. Omit for every status.', 'edd-abilities' ),
					],
					'type'        => [
						'type'        => 'string',
						'enum'        => [ 'sale', 'refund', 'any' ],
						'default'     => 'sale',
						'description' => __( 'Refunds are stored as their own orders. Defaults to real sales only.', 'edd-abilities' ),
					],
					'customer_id' => [ 'type' => 'integer' ],
					'email'       => [ 'type' => 'string', 'description' => __( 'Only orders placed with this exact email address.', 'edd-abilities' ) ],
					'product_id'  => [ 'type' => 'integer', 'description' => __( 'Only orders containing this product.', 'edd-abilities' ) ],
					'gateway'     => [ 'type' => 'string', 'description' => __( 'Gateway key, e.g. stripe, paypal_commerce, manual.', 'edd-abilities' ) ],
					'orderby'     => [
						'type'    => 'string',
						'enum'    => [ 'date_created', 'total', 'id' ],
						'default' => 'date_created',
					],
					'order'       => [
						'type'    => 'string',
						'enum'    => [ 'ASC', 'DESC' ],
						'default' => 'DESC',
					],
					'expand'      => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string', 'enum' => Order_Schema::INCLUDES ],
						'default'     => [],
						'description' => __( 'Optional extra sections to include on each order. Each costs extra queries per order - only request what you need.', 'edd-abilities' ),
					],
				], Schema::date_range_input(), Schema::pagination_input() ),
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'total_items' => [
						'type'        => 'integer',
						'description' => __( 'Total orders matching the filters, ignoring limit/offset.', 'edd-abilities' ),
					],
					'orders'      => [
						'type'  => 'array',
						'items' => Order_Schema::get_schema(),
					],
				],
			],
		];
	}

	public function __invoke( $input ) {

		$input = (array) $input;

		list( $limit, $offset ) = Schema::pagination( $input );

		$args = [];

		$type = $input['type'] ?? 'sale';
		if ( $type !== 'any' ) {
			$args['type'] = sanitize_key( $type );
		}

		if ( ! empty( $input['status'] ) ) {
			$args['status__in'] = array_map( 'sanitize_key', (array) $input['status'] );
		}

		foreach ( [ 'customer_id', 'product_id' ] as $key ) {
			if ( ! empty( $input[ $key ] ) ) {
				$args[ $key ] = absint( $input[ $key ] );
			}
		}

		if ( ! empty( $input['email'] ) ) {
			$args['email'] = sanitize_email( $input['email'] );
		}

		if ( ! empty( $input['gateway'] ) ) {
			$args['gateway'] = sanitize_key( $input['gateway'] );
		}

		if ( ! empty( $input['search'] ) ) {
			$args['search'] = sanitize_text_field( $input['search'] );
		}

		$dates = Schema::date_range_query( $input );
		if ( $dates ) {
			$args['date_created_query'] = $dates;
		}

		$total = (int) edd_count_orders( $args );

		$orders = edd_get_orders( array_merge( $args, [
			'number'  => $limit,
			'offset'  => $offset,
			'orderby' => in_array( $input['orderby'] ?? '', [ 'total', 'id' ], true ) ? $input['orderby'] : 'date_created',
			'order'   => ( $input['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
		] ) );

		$expand = array_values( array_intersect( (array) ( $input['expand'] ?? [] ), Order_Schema::INCLUDES ) );

		return [
			'total_items' => $total,
			'orders'      => array_map( function ( $order ) use ( $expand ) {
				return Order_Schema::transform( $order, $expand );
			}, $orders ),
		];
	}
}
