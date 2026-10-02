<?php

namespace EDD_Abilities\Abilities\Orders;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Order_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refunds an order, in full or in part, using EDD's refund flow: creates a refund order, restores
 * stock and revokes access for refunded items. EDD's own validator rejects amounts that exceed
 * what is still refundable. Whether the money is returned at the gateway depends on the gateway.
 */
class Refund_Order extends Ability {

	protected const NAME       = 'edd-alt/refund-order';
	protected const CATEGORY   = 'edd-alt-orders';
	protected const CAPABILITY = 'edit_shop_payments';

	protected const DESTRUCTIVE = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Refund Order', 'edd-abilities' ),
			'description' => __( 'Refund an Easy Digital Downloads order, fully or partially. Omit "items" to refund the whole order (including fees and credits). To refund part of it, list the order items to refund with a quantity and amount for each - get the order_item ids and amounts from edd-alt/get-order with items included. A partial refund does not refund fees or credits. Orders outside the refund window or already fully refunded are rejected (see is_refundable on edd-alt/get-order). This cannot be undone.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id' ],
				'properties'           => [
					'id'    => [ 'type' => 'integer', 'description' => __( 'The ID of the sale order to refund.', 'edd-abilities' ) ],
					'items' => [
						'type'        => 'array',
						'minItems'    => 1,
						'description' => __( 'For a partial refund: the order items to refund. Omit to refund the entire order.', 'edd-abilities' ),
						'items'       => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => [ 'order_item_id', 'quantity', 'subtotal' ],
							'properties'           => [
								'order_item_id' => [ 'type' => 'integer', 'description' => __( 'The id of the item on the original order.', 'edd-abilities' ) ],
								'quantity'      => [ 'type' => 'integer', 'minimum' => 1, 'description' => __( 'How many units to refund.', 'edd-abilities' ) ],
								'subtotal'      => [ 'type' => 'number', 'minimum' => 0, 'description' => __( 'Amount to refund for this item, excluding tax. Cannot exceed what remains unrefunded.', 'edd-abilities' ) ],
								'tax'           => [ 'type' => 'number', 'minimum' => 0, 'default' => 0, 'description' => __( 'Tax to refund for this item.', 'edd-abilities' ) ],
							],
						],
					],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'refund_order_id' => [ 'type' => 'integer', 'description' => __( 'The ID of the newly created refund order.', 'edd-abilities' ) ],
					'refund'          => Order_Schema::get_schema(),
					'order'           => Order_Schema::get_schema(),
				],
			],
		];
	}

	public function __invoke( $input ) {

		$id    = absint( $input['id'] ?? 0 );
		$order = edd_get_order( $id );

		if ( ! $order ) {
			return $this->not_found( 'order', $id );
		}

		if ( 'sale' !== $order->type ) {
			return new \WP_Error( 'edd_abilities_invalid_order', __( 'Only sale orders can be refunded.', 'edd-abilities' ) );
		}

		if ( isset( $input['items'] ) ) {

			$items = $this->normalize_items( $order, (array) $input['items'] );

			if ( is_wp_error( $items ) ) {
				return $items;
			}

			// A partial refund only refunds the listed items - never fees or credits.
			$refund_id = edd_refund_order( $id, $items, [] );

		} else {
			$refund_id = edd_refund_order( $id );
		}

		if ( is_wp_error( $refund_id ) ) {
			return $refund_id;
		}

		return [
			'refund_order_id' => (int) $refund_id,
			'refund'          => Order_Schema::transform( edd_get_order( $refund_id ), [ 'items' ] ),
			'order'           => Order_Schema::transform( edd_get_order( $id ), [ 'items' ] ),
		];
	}

	/**
	 * Validate and shape the partial-refund items into what edd_refund_order() expects. Checks the
	 * items belong to the order and aren't listed twice; EDD's own validator enforces the amounts.
	 *
	 * @param \EDD\Orders\Order $order
	 * @param array             $items
	 *
	 * @return array|\WP_Error
	 */
	protected function normalize_items( $order, array $items ) {

		$valid_ids = array_map( 'intval', wp_list_pluck( $order->get_items(), 'id' ) );
		$normal    = [];
		$seen      = [];

		foreach ( $items as $item ) {

			$item_id = absint( $item['order_item_id'] ?? 0 );

			if ( ! in_array( $item_id, $valid_ids, true ) ) {
				/* translators: %d: an order item ID */
				return new \WP_Error( 'edd_abilities_invalid_item', sprintf( __( 'Order item %d does not belong to this order.', 'edd-abilities' ), $item_id ) );
			}

			if ( isset( $seen[ $item_id ] ) ) {
				/* translators: %d: an order item ID */
				return new \WP_Error( 'edd_abilities_duplicate_item', sprintf( __( 'Order item %d is listed more than once.', 'edd-abilities' ), $item_id ) );
			}

			$seen[ $item_id ] = true;

			$quantity = absint( $item['quantity'] ?? 0 );
			$subtotal = (float) ( $item['subtotal'] ?? 0 );
			$tax      = (float) ( $item['tax'] ?? 0 );

			if ( $quantity < 1 || $subtotal < 0 || $tax < 0 ) {
				return new \WP_Error( 'edd_abilities_invalid_amount', __( 'Each item needs a quantity of at least 1 and non-negative amounts.', 'edd-abilities' ) );
			}

			$normal[] = [
				'order_item_id' => $item_id,
				'quantity'      => $quantity,
				'subtotal'      => $subtotal,
				'tax'           => $tax,
			];
		}

		return $normal;
	}
}
