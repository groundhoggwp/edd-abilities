<?php

namespace EDD_Abilities\Abilities\Customers;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Customer_Schema;
use EDD_Abilities\Abilities\Traits\Checks_Customer_Caps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates a customer's name, status, linked user, or attaches an extra email address.
 */
class Update_Customer extends Ability {

	use Checks_Customer_Caps;

	protected const NAME     = 'edd-alt/update-customer';
	protected const CATEGORY = 'edd-alt-customers';

	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Update Customer', 'edd-abilities' ),
			'description' => __( 'Update an Easy Digital Downloads customer\'s name, status or linked WordPress user, or attach an additional email address. Only the fields you provide are changed.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id' ],
				'properties'           => [
					'id'        => [ 'type' => 'integer', 'description' => __( 'The customer ID.', 'edd-abilities' ) ],
					'name'      => [ 'type' => 'string' ],
					'status'    => [ 'type' => 'string', 'enum' => [ 'active', 'disabled' ] ],
					'user_id'   => [ 'type' => 'integer', 'description' => __( 'The WordPress user to link. 0 unlinks the customer.', 'edd-abilities' ) ],
					'add_email' => [ 'type' => 'string', 'format' => 'email', 'description' => __( 'An additional email address to attach to the customer. It becomes a secondary address, not the primary.', 'edd-abilities' ) ],
				],
			],

			'output_schema' => Customer_Schema::get_schema(),
		];
	}

	public function __invoke( $input ) {

		$id       = absint( $input['id'] ?? 0 );
		$customer = edd_get_customer( $id );

		if ( ! $customer ) {
			return $this->not_found( 'customer', $id );
		}

		$data = [];

		if ( isset( $input['name'] ) ) {
			$data['name'] = sanitize_text_field( $input['name'] );
		}

		if ( isset( $input['status'] ) && in_array( $input['status'], [ 'active', 'disabled' ], true ) ) {
			$data['status'] = $input['status'];
		}

		if ( isset( $input['user_id'] ) ) {

			$user_id = absint( $input['user_id'] );

			if ( $user_id && ! get_userdata( $user_id ) ) {
				return $this->not_found( 'user', $user_id );
			}

			$data['user_id'] = $user_id;
		}

		if ( $data ) {
			$customer->update( $data );
		}

		if ( ! empty( $input['add_email'] ) ) {

			$email = sanitize_email( $input['add_email'] );

			if ( ! is_email( $email ) ) {
				return new \WP_Error( 'edd_abilities_invalid_email', __( 'A valid email address is required.', 'edd-abilities' ) );
			}

			$existing = edd_get_customer_by( 'email', $email );

			if ( $existing && (int) $existing->id !== $id ) {
				return new \WP_Error( 'edd_abilities_email_in_use', __( 'That email address already belongs to another customer.', 'edd-abilities' ) );
			}

			if ( ! $existing ) {
				$customer->add_email( $email );
			}
		}

		return Customer_Schema::transform( $id, [ 'emails' ] );
	}
}
