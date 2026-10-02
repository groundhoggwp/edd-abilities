<?php

namespace EDD_Abilities\Abilities\Subscriptions;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Schema;
use EDD_Abilities\Abilities\Schemas\Subscription_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists/searches Recurring Payments subscriptions. Only registered when EDD Recurring is active.
 */
class List_Subscriptions extends Ability {

	protected const NAME       = 'edd-alt/list-subscriptions';
	protected const CATEGORY   = 'edd-alt-subscriptions';
	protected const CAPABILITY = 'edit_shop_payments';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	public static function is_available(): bool {
		return class_exists( 'EDD_Subscriptions_DB' ) && class_exists( 'EDD_Subscription' );
	}

	protected function get_args(): array {

		return [
			'label'       => __( 'List Subscriptions', 'edd-abilities' ),
			'description' => __( 'List or search Easy Digital Downloads Recurring Payments subscriptions. Filter by status, product, customer or the order that started them.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array_merge( [
					'search'      => [ 'type' => 'string', 'description' => __( 'Search by customer email, or prefix with txn:, profile_id:, product_id:, customer_id: or id: to match that field exactly.', 'edd-abilities' ) ],
					'status'      => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string', 'enum' => [ 'pending', 'active', 'trialling', 'cancelled', 'expired', 'failing', 'completed' ] ],
						'description' => __( 'Only subscriptions with one of these statuses.', 'edd-abilities' ),
					],
					'product_id'  => [ 'type' => 'integer' ],
					'customer_id' => [ 'type' => 'integer' ],
					'order_id'    => [ 'type' => 'integer', 'description' => __( 'Only subscriptions started by this order.', 'edd-abilities' ) ],
					'orderby'     => [
						'type'    => 'string',
						'enum'    => [ 'id', 'created', 'expiration' ],
						'default' => 'id',
					],
					'order'       => [
						'type'    => 'string',
						'enum'    => [ 'ASC', 'DESC' ],
						'default' => 'DESC',
					],
				], Schema::pagination_input() ),
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'total_items'   => [
						'type'        => 'integer',
						'description' => __( 'Total subscriptions matching the filters, ignoring limit/offset.', 'edd-abilities' ),
					],
					'subscriptions' => [
						'type'  => 'array',
						'items' => Subscription_Schema::get_schema(),
					],
				],
			],
		];
	}

	public function __invoke( $input ) {

		$input = (array) $input;

		list( $limit, $offset ) = Schema::pagination( $input );

		$args = [];

		foreach ( [ 'product_id', 'customer_id', 'parent_payment_id' => 'order_id' ] as $arg => $key ) {

			$arg = is_int( $arg ) ? $key : $arg;

			if ( ! empty( $input[ $key ] ) ) {
				$args[ $arg ] = absint( $input[ $key ] );
			}
		}

		if ( ! empty( $input['search'] ) ) {
			$args['search'] = sanitize_text_field( $input['search'] );
		}

		$statuses = [ 'pending', 'active', 'trialling', 'cancelled', 'expired', 'failing', 'completed' ];

		if ( ! empty( $input['status'] ) ) {
			$args['status'] = array_values( array_intersect( (array) $input['status'], $statuses ) );
		}

		$db    = new \EDD_Subscriptions_DB();
		$total = (int) $db->count( $args );

		$orderby = $input['orderby'] ?? 'id';

		$subscriptions = $db->get_subscriptions( array_merge( $args, [
			'number'  => $limit,
			'offset'  => $offset,
			'orderby' => in_array( $orderby, [ 'created', 'expiration' ], true ) ? $orderby : 'id',
			'order'   => ( $input['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
		] ) );

		return [
			'total_items'   => $total,
			'subscriptions' => array_map( [ Subscription_Schema::class, 'transform' ], (array) $subscriptions ),
		];
	}
}
