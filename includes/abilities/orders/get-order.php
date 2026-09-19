<?php

namespace EDD_Abilities\Abilities\Orders;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Order_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches a single order in full: items, billing address, discounts and notes.
 */
class Get_Order extends Ability {

	protected const NAME       = 'edd/get-order';
	protected const CATEGORY   = 'edd-orders';
	protected const CAPABILITY = 'edit_shop_payments';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Get Order', 'edd-abilities' ),
			'description' => __( 'Get one Easy Digital Downloads order by ID, including its line items, billing address, applied discounts and notes.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id' ],
				'properties'           => [
					'id'      => [ 'type' => 'integer', 'description' => __( 'The order ID.', 'edd-abilities' ) ],
					'include' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string', 'enum' => Order_Schema::INCLUDES ],
						'default'     => Order_Schema::INCLUDES,
						'description' => __( 'Sections to include. Defaults to all of them.', 'edd-abilities' ),
					],
				],
			],

			'output_schema' => Order_Schema::get_schema(),
		];
	}

	public function __invoke( $input ) {

		$order = edd_get_order( absint( $input['id'] ?? 0 ) );

		if ( ! $order ) {
			return $this->not_found( 'order', $input['id'] ?? 0 );
		}

		$include = isset( $input['include'] ) ? array_intersect( (array) $input['include'], Order_Schema::INCLUDES ) : Order_Schema::INCLUDES;

		return Order_Schema::transform( $order, array_values( $include ) );
	}
}
