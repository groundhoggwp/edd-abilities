<?php

namespace EDD_Abilities\Abilities\Discounts;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Discount_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates an EDD discount code.
 */
class Create_Discount extends Ability {

	protected const NAME       = 'edd-alt/create-discount';
	protected const CATEGORY   = 'edd-alt-discounts';
	protected const CAPABILITY = 'manage_shop_discounts';

	protected function get_args(): array {

		return [
			'label'       => __( 'Create Discount', 'edd-abilities' ),
			'description' => __( 'Create an Easy Digital Downloads discount code. Fails if the code already exists. Dates are in the site timezone; a date without a time starts at 00:00:00 and ends at 23:59:59.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'name', 'code', 'amount_type', 'amount' ],
				'properties'           => [
					'name'              => [ 'type' => 'string', 'minLength' => 1, 'description' => __( 'The internal, admin-facing name.', 'edd-abilities' ) ],
					'code'              => [ 'type' => 'string', 'minLength' => 1, 'description' => __( 'The code customers enter at checkout. Letters, numbers, dashes and underscores.', 'edd-abilities' ) ],
					'amount_type'       => [ 'type' => 'string', 'enum' => [ 'percent', 'flat' ] ],
					'amount'            => [ 'type' => 'number', 'minimum' => 0, 'exclusiveMinimum' => true, 'description' => __( 'A percentage (max 100) when amount_type is "percent", otherwise a flat amount in the store currency.', 'edd-abilities' ) ],
					'status'            => [ 'type' => 'string', 'enum' => [ 'active', 'inactive' ], 'default' => 'active' ],
					'start_date'        => [ 'type' => 'string', 'description' => __( 'When the code becomes usable, e.g. 2026-10-01 or 2026-10-01 09:00:00.', 'edd-abilities' ) ],
					'end_date'          => [ 'type' => 'string', 'description' => __( 'When the code expires.', 'edd-abilities' ) ],
					'max_uses'          => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => __( '0 means unlimited.', 'edd-abilities' ) ],
					'min_charge_amount' => [ 'type' => 'number', 'minimum' => 0, 'default' => 0 ],
					'once_per_customer' => [ 'type' => 'boolean', 'default' => false ],
					'product_reqs'      => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => __( 'Product IDs the cart must contain.', 'edd-abilities' ) ],
					'excluded_products' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => __( 'Product IDs the discount never applies to.', 'edd-abilities' ) ],
					'product_condition' => [ 'type' => 'string', 'enum' => [ 'all', 'any' ], 'default' => 'all' ],
					'scope'             => [ 'type' => 'string', 'enum' => [ 'global', 'not_global' ], 'default' => 'global', 'description' => __( '"global" discounts the whole cart; "not_global" only the products in product_reqs.', 'edd-abilities' ) ],
				],
			],

			'output_schema' => Discount_Schema::get_schema(),
		];
	}

	public function __invoke( $input ) {

		$code = strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $input['code'] ?? '' ) ) );
		$name = sanitize_text_field( $input['name'] ?? '' );

		if ( '' === $code || '' === $name ) {
			return new \WP_Error( 'edd_abilities_missing_fields', __( 'A name and a code are required.', 'edd-abilities' ) );
		}

		if ( edd_get_discount_by_code( $code ) ) {
			return new \WP_Error( 'edd_abilities_code_exists', __( 'A discount with that code already exists.', 'edd-abilities' ) );
		}

		$amount_type = ( $input['amount_type'] ?? '' ) === 'flat' ? 'flat' : 'percent';
		$amount      = (float) ( $input['amount'] ?? 0 );

		if ( $amount <= 0 || ( 'percent' === $amount_type && $amount > 100 ) ) {
			return new \WP_Error( 'edd_abilities_invalid_amount', __( 'The amount must be greater than 0, and at most 100 for a percentage.', 'edd-abilities' ) );
		}

		$data = [
			'name'              => $name,
			'code'              => $code,
			'status'            => ( $input['status'] ?? 'active' ) === 'inactive' ? 'inactive' : 'active',
			'amount_type'       => $amount_type,
			'amount'            => $amount,
			'max_uses'          => absint( $input['max_uses'] ?? 0 ),
			'min_charge_amount' => max( 0, (float) ( $input['min_charge_amount'] ?? 0 ) ),
			'once_per_customer' => ! empty( $input['once_per_customer'] ),
			'product_reqs'      => array_map( 'absint', (array) ( $input['product_reqs'] ?? [] ) ),
			'excluded_products' => array_map( 'absint', (array) ( $input['excluded_products'] ?? [] ) ),
			'product_condition' => ( $input['product_condition'] ?? 'all' ) === 'any' ? 'any' : 'all',
			'scope'             => ( $input['scope'] ?? 'global' ) === 'not_global' ? 'not_global' : 'global',
		];

		foreach ( [ 'start_date' => '00:00:00', 'end_date' => '23:59:59' ] as $key => $default_time ) {

			if ( empty( $input[ $key ] ) ) {
				continue;
			}

			$local = $this->to_local_datetime( (string) $input[ $key ], $default_time );

			if ( ! $local ) {
				/* translators: %s: the input field name */
				return new \WP_Error( 'edd_abilities_invalid_date', sprintf( __( '%s is not a valid date.', 'edd-abilities' ), $key ) );
			}

			// EDD stores discount dates in UTC.
			$data[ $key ] = get_gmt_from_date( $local );
		}

		$id = edd_add_discount( $data );

		if ( ! $id ) {
			return new \WP_Error( 'edd_abilities_create_failed', __( 'EDD could not create the discount.', 'edd-abilities' ) );
		}

		return Discount_Schema::transform( $id );
	}

	/**
	 * Normalize a user-supplied date into a site-local 'Y-m-d H:i:s', appending a default time
	 * when only a date was given.
	 *
	 * @param string $value
	 * @param string $default_time
	 *
	 * @return string|false false if unparseable
	 */
	protected function to_local_datetime( string $value, string $default_time ) {

		$value = trim( $value );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$value .= ' ' . $default_time;
		}

		$timestamp = strtotime( $value );

		return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : false;
	}
}
