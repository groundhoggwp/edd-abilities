<?php

namespace EDD_Abilities\Abilities\Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared schema for an EDD order.
 */
class Order_Schema extends Schema {

	/**
	 * Optional sections a caller can request via include/expand.
	 */
	public const INCLUDES = [ 'items', 'address', 'discounts', 'notes' ];

	public static function get_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'             => [ 'type' => 'integer' ],
				'order_number'   => [ 'type' => 'string' ],
				'type'           => [ 'type' => 'string', 'description' => __( '"sale" or "refund". A refund is its own order that references the original as its parent.', 'edd-abilities' ) ],
				'status'         => [ 'type' => 'string' ],
				'status_label'   => [ 'type' => 'string' ],
				'parent'         => [ 'type' => 'integer', 'description' => __( 'For a refund order, the ID of the order it refunds. Otherwise 0.', 'edd-abilities' ) ],
				'customer_id'    => [ 'type' => 'integer' ],
				'customer_name'  => [ 'type' => 'string' ],
				'user_id'        => [ 'type' => 'integer', 'description' => __( 'The linked WordPress user, or 0 for a guest.', 'edd-abilities' ) ],
				'email'          => [ 'type' => 'string' ],
				'gateway'        => [ 'type' => 'string' ],
				'mode'           => [ 'type' => 'string', 'description' => __( '"live" or "test".', 'edd-abilities' ) ],
				'currency'       => [ 'type' => 'string' ],
				'subtotal'       => [ 'type' => 'number' ],
				'discount'       => [ 'type' => 'number' ],
				'tax'            => [ 'type' => 'number' ],
				'total'          => [ 'type' => 'number' ],
				'transaction_id' => [ 'type' => 'string', 'description' => __( 'The payment gateway\'s transaction ID.', 'edd-abilities' ) ],
				'is_refundable'  => [ 'type' => 'boolean', 'description' => __( 'Whether edd-alt/refund-order would currently accept this order.', 'edd-abilities' ) ],
				'date_created'   => self::datetime_schema(),
				'date_completed' => self::datetime_schema(),
				'items'          => [
					'type'        => 'array',
					'description' => __( 'Only present when "items" is requested.', 'edd-abilities' ),
					'items'       => Order_Item_Schema::get_schema(),
				],
				'address'        => [
					'type'        => [ 'object', 'null' ],
					'description' => __( 'The billing address. Only present when "address" is requested.', 'edd-abilities' ),
					'properties'  => [
						'name'        => [ 'type' => 'string' ],
						'address'     => [ 'type' => 'string' ],
						'address2'    => [ 'type' => 'string' ],
						'city'        => [ 'type' => 'string' ],
						'region'      => [ 'type' => 'string' ],
						'postal_code' => [ 'type' => 'string' ],
						'country'     => [ 'type' => 'string' ],
					],
				],
				'discounts'      => [
					'type'        => 'array',
					'description' => __( 'Discount codes applied to the order. Only present when "discounts" is requested.', 'edd-abilities' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'code'   => [ 'type' => 'string' ],
							'amount' => [ 'type' => 'number' ],
						],
					],
				],
				'notes'          => [
					'type'        => 'array',
					'description' => __( 'Only present when "notes" is requested.', 'edd-abilities' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'           => [ 'type' => 'integer' ],
							'content'      => [ 'type' => 'string' ],
							'user_id'      => [ 'type' => 'integer', 'description' => __( '0 for system notes.', 'edd-abilities' ) ],
							'date_created' => self::datetime_schema(),
						],
					],
				],
			],
		];
	}

	/**
	 * @param \EDD\Orders\Order|int $object an Order, or an order ID
	 * @param array                 $include any of self::INCLUDES
	 *
	 * @return array
	 */
	public static function transform( $object, array $include = [] ): array {

		if ( ! $object instanceof \EDD\Orders\Order ) {
			$object = edd_get_order( $object );
		}

		$statuses = function_exists( 'edd_get_payment_statuses' ) ? edd_get_payment_statuses() : [];
		$customer = $object->customer_id ? edd_get_customer( $object->customer_id ) : null;

		$data = [
			'id'             => (int) $object->id,
			'order_number'   => (string) $object->get_number(),
			'type'           => (string) $object->type,
			'status'         => (string) $object->status,
			'status_label'   => (string) ( $statuses[ $object->status ] ?? $object->status ),
			'parent'         => (int) $object->parent,
			'customer_id'    => (int) $object->customer_id,
			'customer_name'  => $customer ? (string) $customer->name : '',
			'user_id'        => (int) $object->user_id,
			'email'          => (string) $object->email,
			'gateway'        => (string) $object->gateway,
			'mode'           => (string) $object->mode,
			'currency'       => (string) $object->currency,
			'subtotal'       => self::money( $object->subtotal ),
			'discount'       => self::money( $object->discount ),
			'tax'            => self::money( $object->tax ),
			'total'          => self::money( $object->total ),
			'transaction_id' => (string) $object->get_transaction_id(),
			'is_refundable'  => 'sale' === $object->type && (bool) edd_is_order_refundable( $object->id ),
			'date_created'   => self::datetime( $object->date_created ),
			'date_completed' => self::datetime( $object->date_completed ),
		];

		if ( in_array( 'items', $include, true ) ) {
			$data['items'] = array_values( array_map( [ Order_Item_Schema::class, 'transform' ], $object->get_items() ) );
		}

		if ( in_array( 'address', $include, true ) ) {
			// With no stored address EDD hands back an empty stdClass, not false.
			$address         = $object->get_address();
			$data['address'] = $address instanceof \EDD\Orders\Order_Address ? [
				'name'        => (string) $address->name,
				'address'     => (string) $address->address,
				'address2'    => (string) $address->address2,
				'city'        => (string) $address->city,
				'region'      => (string) $address->region,
				'postal_code' => (string) $address->postal_code,
				'country'     => (string) $address->country,
			] : null;
		}

		if ( in_array( 'discounts', $include, true ) ) {
			$data['discounts'] = array_values( array_map( function ( $adjustment ) {
				return [
					'code'   => (string) $adjustment->description,
					'amount' => self::money( $adjustment->subtotal ),
				];
			}, $object->get_discounts() ) );
		}

		if ( in_array( 'notes', $include, true ) ) {
			$data['notes'] = array_values( array_map( function ( $note ) {
				return [
					'id'           => (int) $note->id,
					'content'      => wp_strip_all_tags( $note->content ),
					'user_id'      => (int) $note->user_id,
					'date_created' => self::datetime( $note->date_created ),
				];
			}, $object->get_notes() ) );
		}

		return $data;
	}
}
