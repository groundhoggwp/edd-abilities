<?php

namespace EDD_Abilities\Abilities\Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared schema for an EDD discount code.
 */
class Discount_Schema extends Schema {

	public static function get_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'                => [ 'type' => 'integer' ],
				'name'              => [ 'type' => 'string' ],
				'code'              => [ 'type' => 'string' ],
				'status'            => [ 'type' => 'string', 'description' => __( 'active, inactive, expired or archived.', 'edd-abilities' ) ],
				'amount_type'       => [ 'type' => 'string', 'description' => __( '"percent" or "flat".', 'edd-abilities' ) ],
				'amount'            => [ 'type' => 'number', 'description' => __( 'A percentage when amount_type is "percent", otherwise a flat amount in the store currency.', 'edd-abilities' ) ],
				'use_count'         => [ 'type' => 'integer' ],
				'max_uses'          => [ 'type' => 'integer', 'description' => __( '0 means unlimited.', 'edd-abilities' ) ],
				'min_charge_amount' => [ 'type' => 'number', 'description' => __( 'Minimum cart subtotal required. 0 means none.', 'edd-abilities' ) ],
				'once_per_customer' => [ 'type' => 'boolean' ],
				'scope'             => [ 'type' => 'string', 'description' => __( '"global" applies to the whole cart, "not_global" only to the required products.', 'edd-abilities' ) ],
				'product_reqs'      => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => __( 'IDs of products the cart must contain.', 'edd-abilities' ) ],
				'excluded_products' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ],
				'product_condition' => [ 'type' => 'string', 'description' => __( '"all" or "any" of product_reqs must be in the cart.', 'edd-abilities' ) ],
				'start_date'        => self::datetime_schema(),
				'end_date'          => self::datetime_schema(),
			],
		];
	}

	/**
	 * @param \EDD_Discount|int $object a discount, or a discount ID
	 * @param array             $include unused
	 *
	 * @return array
	 */
	public static function transform( $object, array $include = [] ): array {

		if ( ! $object instanceof \EDD_Discount ) {
			$object = edd_get_discount( $object );
		}

		return [
			'id'                => (int) $object->id,
			'name'              => (string) $object->name,
			'code'              => (string) $object->code,
			'status'            => (string) $object->status,
			'amount_type'       => (string) $object->amount_type,
			'amount'            => self::money( $object->amount ),
			'use_count'         => (int) $object->use_count,
			'max_uses'          => (int) $object->max_uses,
			'min_charge_amount' => self::money( $object->min_charge_amount ),
			'once_per_customer' => (bool) $object->once_per_customer,
			'scope'             => (string) $object->get_scope(),
			'product_reqs'      => array_values( array_map( 'intval', (array) $object->get_product_reqs() ) ),
			'excluded_products' => array_values( array_map( 'intval', (array) $object->get_excluded_products() ) ),
			'product_condition' => (string) $object->get_product_condition(),
			'start_date'        => self::datetime( $object->start_date ),
			'end_date'          => self::datetime( $object->end_date ),
		];
	}
}
