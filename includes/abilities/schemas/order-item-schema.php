<?php

namespace EDD_Abilities\Abilities\Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared schema for an EDD order item (one purchased product line).
 */
class Order_Item_Schema extends Schema {

	public static function get_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'           => [ 'type' => 'integer' ],
				'product_id'   => [ 'type' => 'integer' ],
				'product_name' => [ 'type' => 'string' ],
				'price_id'     => [ 'type' => [ 'integer', 'null' ], 'description' => __( 'The variable price option, if the product has them.', 'edd-abilities' ) ],
				'status'       => [ 'type' => 'string' ],
				'quantity'     => [ 'type' => 'integer' ],
				'subtotal'     => [ 'type' => 'number' ],
				'discount'     => [ 'type' => 'number' ],
				'tax'          => [ 'type' => 'number' ],
				'total'        => [ 'type' => 'number' ],
			],
		];
	}

	/**
	 * @param \EDD\Orders\Order_Item $object
	 * @param array                  $include unused
	 *
	 * @return array
	 */
	public static function transform( $object, array $include = [] ): array {
		return [
			'id'           => (int) $object->id,
			'product_id'   => (int) $object->product_id,
			'product_name' => (string) $object->product_name,
			'price_id'     => is_null( $object->price_id ) ? null : (int) $object->price_id,
			'status'       => (string) $object->status,
			'quantity'     => (int) $object->quantity,
			'subtotal'     => self::money( $object->subtotal ),
			'discount'     => self::money( $object->discount ),
			'tax'          => self::money( $object->tax ),
			'total'        => self::money( $object->total ),
		];
	}
}
