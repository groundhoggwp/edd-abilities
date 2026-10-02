<?php

namespace EDD_Abilities\Abilities\Customers;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Customer_Schema;
use EDD_Abilities\Abilities\Traits\Checks_Customer_Caps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches one customer by ID or email.
 */
class Get_Customer extends Ability {

	use Checks_Customer_Caps;

	protected const NAME     = 'edd-alt/get-customer';
	protected const CATEGORY = 'edd-alt-customers';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Get Customer', 'edd-abilities' ),
			'description' => __( 'Get one Easy Digital Downloads customer by ID or email address, including all their email addresses and recent orders.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'id'      => [ 'type' => 'integer', 'description' => __( 'The customer ID. Provide this or email.', 'edd-abilities' ) ],
					'email'   => [ 'type' => 'string', 'description' => __( 'Any email address attached to the customer. Provide this or id.', 'edd-abilities' ) ],
					'include' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string', 'enum' => Customer_Schema::INCLUDES ],
						'default'     => Customer_Schema::INCLUDES,
						'description' => __( 'Sections to include. Defaults to all of them.', 'edd-abilities' ),
					],
				],
			],

			'output_schema' => Customer_Schema::get_schema(),
		];
	}

	public function __invoke( $input ) {

		$input = (array) $input;

		if ( ! empty( $input['id'] ) ) {
			$customer = edd_get_customer( absint( $input['id'] ) );
		} elseif ( ! empty( $input['email'] ) ) {
			$customer = edd_get_customer_by( 'email', sanitize_email( $input['email'] ) );
		} else {
			return new \WP_Error( 'edd_abilities_missing_identifier', __( 'Provide either id or email.', 'edd-abilities' ) );
		}

		if ( ! $customer ) {
			return $this->not_found( 'customer', $input['id'] ?? $input['email'] );
		}

		$include = isset( $input['include'] ) ? array_intersect( (array) $input['include'], Customer_Schema::INCLUDES ) : Customer_Schema::INCLUDES;

		return Customer_Schema::transform( $customer, array_values( $include ) );
	}
}
