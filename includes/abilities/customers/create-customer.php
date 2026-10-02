<?php

namespace EDD_Abilities\Abilities\Customers;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Customer_Schema;
use EDD_Abilities\Abilities\Traits\Checks_Customer_Caps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates an EDD customer record without placing an order.
 */
class Create_Customer extends Ability {

	use Checks_Customer_Caps;

	protected const NAME     = 'edd-alt/create-customer';
	protected const CATEGORY = 'edd-alt-customers';

	protected function get_args(): array {

		return [
			'label'       => __( 'Create Customer', 'edd-abilities' ),
			'description' => __( 'Create an Easy Digital Downloads customer. Fails if a customer with that email address already exists.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'email' ],
				'properties'           => [
					'email'   => [ 'type' => 'string', 'format' => 'email' ],
					'name'    => [ 'type' => 'string' ],
					'user_id' => [ 'type' => 'integer', 'description' => __( 'Link the customer to this WordPress user.', 'edd-abilities' ) ],
				],
			],

			'output_schema' => Customer_Schema::get_schema(),
		];
	}

	public function __invoke( $input ) {

		$email = sanitize_email( $input['email'] ?? '' );

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'edd_abilities_invalid_email', __( 'A valid email address is required.', 'edd-abilities' ) );
		}

		if ( edd_get_customer_by( 'email', $email ) ) {
			return new \WP_Error( 'edd_abilities_customer_exists', __( 'A customer with that email address already exists.', 'edd-abilities' ) );
		}

		$user_id = absint( $input['user_id'] ?? 0 );

		if ( $user_id && ! get_userdata( $user_id ) ) {
			return $this->not_found( 'user', $user_id );
		}

		$id = edd_add_customer( [
			'email'   => $email,
			'name'    => sanitize_text_field( $input['name'] ?? '' ),
			'user_id' => $user_id,
		] );

		if ( ! $id ) {
			return new \WP_Error( 'edd_abilities_create_failed', __( 'EDD could not create the customer.', 'edd-abilities' ) );
		}

		return Customer_Schema::transform( $id );
	}
}
