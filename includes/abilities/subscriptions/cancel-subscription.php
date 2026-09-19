<?php

namespace EDD_Abilities\Abilities\Subscriptions;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Subscription_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cancels a subscription, including at the payment gateway where the gateway supports it. Only
 * registered when EDD Recurring is active.
 */
class Cancel_Subscription extends Ability {

	protected const NAME       = 'edd/cancel-subscription';
	protected const CATEGORY   = 'edd-subscriptions';
	protected const CAPABILITY = 'edit_shop_payments';

	protected const DESTRUCTIVE = true;
	protected const IDEMPOTENT  = true;

	public static function is_available(): bool {
		return class_exists( 'EDD_Subscription' );
	}

	protected function get_args(): array {

		return [
			'label'       => __( 'Cancel Subscription', 'edd-abilities' ),
			'description' => __( 'Cancel an Easy Digital Downloads Recurring Payments subscription so it stops billing. Where the gateway supports it, the subscription is cancelled at the gateway too. Cancelling cannot be undone from here. Already-cancelled subscriptions are returned unchanged; check can_cancel on edd/get-subscription for gateways that don\'t allow it.', 'edd-abilities' ),

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

		if ( 'cancelled' !== $subscription->status ) {

			if ( ! $subscription->can_cancel() ) {
				return new \WP_Error( 'edd_abilities_cannot_cancel', __( 'This subscription cannot be cancelled through its payment gateway.', 'edd-abilities' ) );
			}

			$subscription->cancel();
			$subscription = new \EDD_Subscription( $id );

			if ( 'cancelled' !== $subscription->status ) {
				return new \WP_Error( 'edd_abilities_cancel_failed', __( 'The subscription could not be cancelled.', 'edd-abilities' ) );
			}
		}

		return Subscription_Schema::transform( $subscription );
	}
}
