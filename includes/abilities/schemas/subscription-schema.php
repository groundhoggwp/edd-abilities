<?php

namespace EDD_Abilities\Abilities\Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared schema for an EDD Recurring Payments subscription.
 */
class Subscription_Schema extends Schema {

	public static function get_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'               => [ 'type' => 'integer' ],
				'status'           => [ 'type' => 'string', 'description' => __( 'e.g. active, trialling, cancelled, expired, failing, completed, pending.', 'edd-abilities' ) ],
				'customer_id'      => [ 'type' => 'integer' ],
				'product_id'       => [ 'type' => 'integer' ],
				'product_name'     => [ 'type' => 'string' ],
				'price_id'         => [ 'type' => [ 'integer', 'null' ] ],
				'period'           => [ 'type' => 'string', 'description' => __( 'The billing period: day, week, month, quarter or year.', 'edd-abilities' ) ],
				'initial_amount'   => [ 'type' => 'number' ],
				'recurring_amount' => [ 'type' => 'number' ],
				'bill_times'       => [ 'type' => 'integer', 'description' => __( 'Total payments before the subscription completes. 0 means until cancelled.', 'edd-abilities' ) ],
				'times_billed'     => [ 'type' => 'integer' ],
				'trial_period'     => [ 'type' => 'string' ],
				'gateway'          => [ 'type' => 'string' ],
				'transaction_id'   => [ 'type' => 'string' ],
				'profile_id'       => [ 'type' => 'string', 'description' => __( 'The subscription ID at the payment gateway.', 'edd-abilities' ) ],
				'parent_order_id'  => [ 'type' => 'integer', 'description' => __( 'The order that started the subscription.', 'edd-abilities' ) ],
				'created'          => self::datetime_schema(),
				'expiration'       => self::datetime_schema(),
				'can_cancel'       => [ 'type' => 'boolean' ],
			],
		];
	}

	/**
	 * @param \EDD_Subscription|int $object a subscription, or a subscription ID
	 * @param array                 $include unused
	 *
	 * @return array
	 */
	public static function transform( $object, array $include = [] ): array {

		if ( ! $object instanceof \EDD_Subscription ) {
			$object = new \EDD_Subscription( $object );
		}

		return [
			'id'               => (int) $object->id,
			'status'           => (string) $object->get_status(),
			'customer_id'      => (int) $object->customer_id,
			'product_id'       => (int) $object->product_id,
			'product_name'     => (string) get_the_title( $object->product_id ),
			'price_id'         => is_null( $object->price_id ) || '' === $object->price_id ? null : (int) $object->price_id,
			'period'           => (string) $object->period,
			'initial_amount'   => self::money( $object->initial_amount ),
			'recurring_amount' => self::money( $object->recurring_amount ),
			'bill_times'       => (int) $object->bill_times,
			'times_billed'     => (int) $object->get_times_billed(),
			'trial_period'     => (string) $object->trial_period,
			'gateway'          => (string) $object->gateway,
			'transaction_id'   => (string) $object->transaction_id,
			'profile_id'       => (string) $object->profile_id,
			'parent_order_id'  => (int) $object->parent_payment_id,
			'created'          => self::datetime( $object->created ),
			'expiration'       => self::datetime( $object->expiration ),
			'can_cancel'       => (bool) $object->can_cancel(),
		];
	}
}
