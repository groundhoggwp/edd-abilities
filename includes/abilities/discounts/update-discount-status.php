<?php

namespace EDD_Abilities\Abilities\Discounts;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Discount_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activates, deactivates or archives a discount code.
 */
class Update_Discount_Status extends Ability {

	protected const NAME       = 'edd/update-discount-status';
	protected const CATEGORY   = 'edd-discounts';
	protected const CAPABILITY = 'manage_shop_discounts';

	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Update Discount Status', 'edd-abilities' ),
			'description' => __( 'Activate, deactivate or archive an Easy Digital Downloads discount code. Archived discounts are hidden from the admin list and can no longer be used.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id', 'status' ],
				'properties'           => [
					'id'     => [ 'type' => 'integer', 'description' => __( 'The discount ID.', 'edd-abilities' ) ],
					'status' => [ 'type' => 'string', 'enum' => [ 'active', 'inactive', 'archived' ] ],
				],
			],

			'output_schema' => Discount_Schema::get_schema(),
		];
	}

	public function __invoke( $input ) {

		$id     = absint( $input['id'] ?? 0 );
		$status = $input['status'] ?? '';

		if ( ! edd_get_discount( $id ) ) {
			return $this->not_found( 'discount', $id );
		}

		if ( ! in_array( $status, [ 'active', 'inactive', 'archived' ], true ) ) {
			return new \WP_Error( 'edd_abilities_invalid_status', __( 'Status must be active, inactive or archived.', 'edd-abilities' ) );
		}

		if ( ! edd_update_discount_status( $id, $status ) ) {
			return new \WP_Error( 'edd_abilities_status_failed', __( 'EDD could not update the discount status.', 'edd-abilities' ) );
		}

		return Discount_Schema::transform( edd_get_discount( $id ) );
	}
}
