<?php

namespace EDD_Abilities\Abilities\Subscriptions;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Subscription_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches one subscription. Only registered when EDD Recurring is active.
 */
class Get_Subscription extends Ability {

	protected const NAME       = 'edd-alt/get-subscription';
	protected const CATEGORY   = 'edd-alt-subscriptions';
	protected const CAPABILITY = 'edit_shop_payments';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	public static function is_available(): bool {
		return class_exists( 'EDD_Subscription' );
	}

	protected function get_args(): array {

		return [
			'label'       => __( 'Get Subscription', 'edd-abilities' ),
			'description' => __( 'Get one Easy Digital Downloads Recurring Payments subscription by ID.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id' ],
				'properties'           => [
					'id' => [ 'type' => 'integer', 'description' => __( 'The subscription ID.', 'edd-abilities' ) ],
				],
			],

			'output_schema' => Subscription_Schema::get_schema(),
		];
	}

	public function __invoke( $input ) {

		$id           = absint( $input['id'] ?? 0 );
		$subscription = new \EDD_Subscription( $id );

		if ( ! $subscription->id ) {
			return $this->not_found( 'subscription', $id );
		}

		return Subscription_Schema::transform( $subscription );
	}
}
