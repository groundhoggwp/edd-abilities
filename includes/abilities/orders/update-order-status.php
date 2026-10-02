<?php

namespace EDD_Abilities\Abilities\Orders;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Order_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Changes an order's status (e.g. pending -> complete). Runs EDD's normal status-change hooks,
 * so completing an order delivers purchase receipts, licenses and so on.
 */
class Update_Order_Status extends Ability {

	protected const NAME       = 'edd-alt/update-order-status';
	protected const CATEGORY   = 'edd-alt-orders';
	protected const CAPABILITY = 'edit_shop_payments';

	protected const IDEMPOTENT = true;

	/**
	 * Statuses that must go through edd-alt/refund-order so a refund order and the gateway refund
	 * are handled properly, rather than just flipping a column.
	 */
	protected const REFUND_STATUSES = [ 'refunded', 'partially_refunded' ];

	protected function get_args(): array {

		$statuses = array_diff( array_keys( edd_get_payment_statuses() ), self::REFUND_STATUSES );

		return [
			'label'       => __( 'Update Order Status', 'edd-abilities' ),
			'description' => __( 'Change an order\'s status. This fires EDD\'s normal status hooks, so completing an order sends receipts and generates licenses. To refund an order use edd-alt/refund-order instead.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id', 'status' ],
				'properties'           => [
					'id'     => [ 'type' => 'integer', 'description' => __( 'The order ID.', 'edd-abilities' ) ],
					'status' => [ 'type' => 'string', 'enum' => array_values( $statuses ) ],
				],
			],

			'output_schema' => Order_Schema::get_schema(),
		];
	}

	public function __invoke( $input ) {

		$id     = absint( $input['id'] ?? 0 );
		$status = sanitize_key( $input['status'] ?? '' );
		$order  = edd_get_order( $id );

		if ( ! $order ) {
			return $this->not_found( 'order', $id );
		}

		if ( 'sale' !== $order->type ) {
			return new \WP_Error( 'edd_abilities_invalid_order', __( 'Only sale orders can have their status changed.', 'edd-abilities' ) );
		}

		if ( $order->status !== $status && false === edd_update_order_status( $id, $status ) ) {
			return new \WP_Error( 'edd_abilities_status_failed', __( 'EDD could not update the order status.', 'edd-abilities' ) );
		}

		return Order_Schema::transform( edd_get_order( $id ) );
	}
}
