<?php

namespace EDD_Abilities\Abilities\Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared schema for an EDD Software Licensing license.
 */
class License_Schema extends Schema {

	/**
	 * Optional sections a caller can request via include/expand.
	 */
	public const INCLUDES = [ 'activations' ];

	public static function get_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'               => [ 'type' => 'integer' ],
				'license_key'      => [ 'type' => 'string' ],
				'status'           => [ 'type' => 'string', 'description' => __( 'active, inactive, expired or disabled.', 'edd-abilities' ) ],
				'product_id'       => [ 'type' => 'integer' ],
				'product_name'     => [ 'type' => 'string' ],
				'price_id'         => [ 'type' => [ 'integer', 'null' ] ],
				'order_id'         => [ 'type' => 'integer', 'description' => __( 'The order the license was issued for.', 'edd-abilities' ) ],
				'customer_id'      => [ 'type' => 'integer' ],
				'user_id'          => [ 'type' => 'integer' ],
				'parent'           => [ 'type' => 'integer', 'description' => __( 'For a bundle child license, the ID of the parent license. Otherwise 0.', 'edd-abilities' ) ],
				'is_lifetime'      => [ 'type' => 'boolean' ],
				'expiration'       => self::datetime_schema(),
				'activation_limit' => [ 'type' => 'integer', 'description' => __( '0 means unlimited.', 'edd-abilities' ) ],
				'activation_count' => [ 'type' => 'integer' ],
				'date_created'     => self::datetime_schema(),
				'activations'      => [
					'type'        => 'array',
					'description' => __( 'Sites the license is currently activated on. Only present when "activations" is requested.', 'edd-abilities' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'site_id'   => [ 'type' => 'integer' ],
							'site_name' => [ 'type' => 'string' ],
							'is_local'  => [ 'type' => 'boolean' ],
						],
					],
				],
			],
		];
	}

	/**
	 * @param \EDD_SL_License|int $object a license, or a license ID
	 * @param array               $include any of self::INCLUDES
	 *
	 * @return array
	 */
	public static function transform( $object, array $include = [] ): array {

		if ( ! $object instanceof \EDD_SL_License ) {
			$object = edd_software_licensing()->get_license( $object );
		}

		$is_lifetime = (bool) $object->is_lifetime;

		$data = [
			'id'               => (int) $object->ID,
			'license_key'      => (string) $object->key,
			'status'           => (string) $object->status,
			'product_id'       => (int) $object->download_id,
			'product_name'     => (string) get_the_title( $object->download_id ),
			'price_id'         => is_null( $object->price_id ) || '' === $object->price_id ? null : (int) $object->price_id,
			'order_id'         => (int) $object->payment_id,
			'customer_id'      => (int) $object->customer_id,
			'user_id'          => (int) $object->user_id,
			'parent'           => (int) $object->parent,
			'is_lifetime'      => $is_lifetime,
			'expiration'       => $is_lifetime ? null : self::datetime( (int) $object->expiration ),
			'activation_limit' => (int) $object->activation_limit,
			'activation_count' => (int) $object->activation_count,
			'date_created'     => self::datetime( $object->date_created ),
		];

		if ( in_array( 'activations', $include, true ) ) {
			$data['activations'] = array_values( array_map( function ( $activation ) {
				return [
					'site_id'   => (int) $activation->site_id,
					'site_name' => (string) $activation->site_name,
					'is_local'  => (bool) $activation->is_local,
				];
			}, (array) $object->get_activations() ) );
		}

		return $data;
	}
}
