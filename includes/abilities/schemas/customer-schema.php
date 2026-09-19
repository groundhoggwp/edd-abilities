<?php

namespace EDD_Abilities\Abilities\Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared schema for an EDD customer.
 */
class Customer_Schema extends Schema {

	/**
	 * Optional sections a caller can request via include/expand.
	 */
	public const INCLUDES = [ 'emails', 'recent_orders' ];

	public static function get_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'             => [ 'type' => 'integer' ],
				'name'           => [ 'type' => 'string' ],
				'email'          => [ 'type' => 'string', 'description' => __( 'The primary email address.', 'edd-abilities' ) ],
				'user_id'        => [ 'type' => 'integer', 'description' => __( 'The linked WordPress user, or 0 for a guest customer.', 'edd-abilities' ) ],
				'status'         => [ 'type' => 'string' ],
				'purchase_count' => [ 'type' => 'integer' ],
				'purchase_value' => [ 'type' => 'number', 'description' => __( 'Lifetime value, in the store currency.', 'edd-abilities' ) ],
				'date_created'   => self::datetime_schema(),
				'emails'         => [
					'type'        => 'array',
					'description' => __( 'Every email address attached to the customer. Only present when "emails" is requested.', 'edd-abilities' ),
					'items'       => [ 'type' => 'string' ],
				],
				'recent_orders'  => [
					'type'        => 'array',
					'description' => __( 'The customer\'s 10 most recent orders. Only present when "recent_orders" is requested.', 'edd-abilities' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'           => [ 'type' => 'integer' ],
							'order_number' => [ 'type' => 'string' ],
							'status'       => [ 'type' => 'string' ],
							'total'        => [ 'type' => 'number' ],
							'currency'     => [ 'type' => 'string' ],
							'date_created' => self::datetime_schema(),
						],
					],
				],
			],
		];
	}

	/**
	 * @param \EDD_Customer|int $object a customer, or a customer ID
	 * @param array             $include any of self::INCLUDES
	 *
	 * @return array
	 */
	public static function transform( $object, array $include = [] ): array {

		if ( ! $object instanceof \EDD_Customer ) {
			$object = new \EDD_Customer( $object );
		}

		$data = [
			'id'             => (int) $object->id,
			'name'           => (string) $object->name,
			'email'          => (string) $object->email,
			'user_id'        => (int) $object->user_id,
			'status'         => (string) $object->status,
			'purchase_count' => (int) $object->purchase_count,
			'purchase_value' => self::money( $object->purchase_value ),
			'date_created'   => self::datetime( $object->date_created ),
		];

		if ( in_array( 'emails', $include, true ) ) {
			$data['emails'] = array_values( array_map( 'strval', (array) $object->emails ) );
		}

		if ( in_array( 'recent_orders', $include, true ) ) {
			$orders = edd_get_orders( [
				'customer_id' => $object->id,
				'type'        => 'sale',
				'number'      => 10,
				'orderby'     => 'date_created',
				'order'       => 'DESC',
			] );

			$data['recent_orders'] = array_values( array_map( function ( $order ) {
				return [
					'id'           => (int) $order->id,
					'order_number' => (string) $order->get_number(),
					'status'       => (string) $order->status,
					'total'        => self::money( $order->total ),
					'currency'     => (string) $order->currency,
					'date_created' => self::datetime( $order->date_created ),
				];
			}, $orders ) );
		}

		return $data;
	}
}
