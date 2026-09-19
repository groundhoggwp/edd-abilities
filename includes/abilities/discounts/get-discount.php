<?php

namespace EDD_Abilities\Abilities\Discounts;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Discount_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches one discount by ID or code.
 */
class Get_Discount extends Ability {

	protected const NAME       = 'edd/get-discount';
	protected const CATEGORY   = 'edd-discounts';
	protected const CAPABILITY = 'manage_shop_discounts';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Get Discount', 'edd-abilities' ),
			'description' => __( 'Get one Easy Digital Downloads discount by ID or by its code.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'id'   => [ 'type' => 'integer', 'description' => __( 'The discount ID. Provide this or code.', 'edd-abilities' ) ],
					'code' => [ 'type' => 'string', 'description' => __( 'The discount code, e.g. SAVE20. Provide this or id.', 'edd-abilities' ) ],
				],
			],

			'output_schema' => Discount_Schema::get_schema(),
		];
	}

	public function __invoke( $input ) {

		$input = (array) $input;

		if ( ! empty( $input['id'] ) ) {
			$discount = edd_get_discount( absint( $input['id'] ) );
		} elseif ( ! empty( $input['code'] ) ) {
			$discount = edd_get_discount_by_code( sanitize_text_field( $input['code'] ) );
		} else {
			return new \WP_Error( 'edd_abilities_missing_identifier', __( 'Provide either id or code.', 'edd-abilities' ) );
		}

		if ( ! $discount ) {
			return $this->not_found( 'discount', $input['id'] ?? $input['code'] );
		}

		return Discount_Schema::transform( $discount );
	}
}
