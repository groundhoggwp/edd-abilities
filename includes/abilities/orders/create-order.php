<?php

namespace EDD_Abilities\Abilities\Orders;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Order_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a manual order, the way the admin's Add Order screen does: the order and its items go in
 * as pending and are then moved to the requested status, so everything hooked to that transition
 * runs - store earnings, the customer's lifetime value, discount usage, and Software Licensing
 * generating licenses.
 */
class Create_Order extends Ability {

	protected const NAME       = 'edd-alt/create-order';
	protected const CATEGORY   = 'edd-alt-orders';
	protected const CAPABILITY = 'edit_shop_payments';

	// Adds to store earnings and the customer's lifetime value, can generate licenses, and can email
	// the customer, so a client should confirm first.
	protected const DESTRUCTIVE = true;

	protected function get_args(): array {

		$gateways = array_values( array_unique( array_merge( [ 'manual' ], array_keys( edd_get_payment_gateways() ) ) ) );

		$output = Order_Schema::get_schema();

		$output['properties']['tax_calculated'] = [
			'type'        => 'boolean',
			'description' => __( 'Always false: this ability does not calculate or record tax.', 'edd-abilities' ),
		];

		return [
			'label'       => __( 'Create Order', 'edd-abilities' ),
			'description' => __( 'Create a manual Easy Digital Downloads order for a customer. Give an existing customer_id, or an email address (a customer is created if the email is new). Item prices are read from the products unless you set a price; an optional discount code is applied to the whole order. The order is created and then moved to the requested status, so completing it adds to store earnings, generates any licenses and so on. Customer emails are NOT sent unless send_emails is true. No payment is collected and tax is not calculated; discount product restrictions are not enforced, and bundles and other non-standard product types are not supported.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'items' ],
				'properties'           => [
					'customer_id'   => [ 'type' => 'integer', 'minimum' => 1, 'description' => __( 'An existing customer ID. Provide this or an email address.', 'edd-abilities' ) ],
					'email'         => [ 'type' => 'string', 'format' => 'email', 'description' => __( 'The customer\'s email address. A new customer record is created if no customer has this email.', 'edd-abilities' ) ],
					'name'          => [ 'type' => 'string', 'description' => __( 'The customer\'s name, used only when a new customer record is created.', 'edd-abilities' ) ],
					'items'         => [
						'type'        => 'array',
						'minItems'    => 1,
						'maxItems'    => 100,
						'description' => __( 'The products to include.', 'edd-abilities' ),
						'items'       => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'required'             => [ 'product_id' ],
							'properties'           => [
								'product_id' => [ 'type' => 'integer', 'minimum' => 1 ],
								'price_id'   => [ 'type' => 'integer', 'minimum' => 0, 'description' => __( 'The price option. Required for a product with variable pricing - find them with edd-alt/get-product.', 'edd-abilities' ) ],
								'quantity'   => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 1 ],
								'price'      => [ 'type' => 'number', 'minimum' => 0, 'description' => __( 'The price of one unit, instead of the product\'s own price. 0 makes the item free.', 'edd-abilities' ) ],
							],
						],
					],
					'status'        => [ 'type' => 'string', 'enum' => [ 'complete', 'pending', 'processing' ], 'default' => 'complete', 'description' => __( 'The status to move the order to.', 'edd-abilities' ) ],
					'gateway'       => [ 'type' => 'string', 'enum' => $gateways, 'default' => 'manual', 'description' => __( 'The gateway to record on the order. Recording one charges nothing; "manual" is right for an order made without payment.', 'edd-abilities' ) ],
					'discount_code' => [ 'type' => 'string', 'description' => __( 'An active discount code to apply to the whole order.', 'edd-abilities' ) ],
					'note'          => [ 'type' => 'string', 'maxLength' => 5000, 'description' => __( 'A private note to record on the order.', 'edd-abilities' ) ],
					'send_emails'   => [ 'type' => 'boolean', 'default' => false, 'description' => __( 'Whether to send the purchase receipt to the customer and the new-sale notice to the store when the order completes. Off unless you set it.', 'edd-abilities' ) ],
				],
			],

			'output_schema' => $output,
		];
	}

	public function __invoke( $input ) {

		$input = (array) $input;

		// Everything that can be refused is checked before anything is written, and the customer - the
		// only thing created before the order itself - is resolved last, so a refused request leaves
		// nothing behind.
		$items = $this->build_items( (array) ( $input['items'] ?? [] ) );

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$subtotal = (float) array_sum( wp_list_pluck( $items, 'subtotal' ) );
		$applied  = $this->resolve_discount( $input, $subtotal );

		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$customer = $this->resolve_customer( $input );

		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$discount_amount = $applied['amount'];
		$status          = sanitize_key( $input['status'] ?? 'complete' );

		$order_id = edd_add_order( [
			'status'       => 'pending',
			'type'         => 'sale',
			'user_id'      => (int) $customer->user_id,
			'customer_id'  => (int) $customer->id,
			'email'        => $customer->email,
			'gateway'      => sanitize_key( $input['gateway'] ?? 'manual' ),
			'mode'         => edd_is_test_mode() ? 'test' : 'live',
			'currency'     => edd_get_currency(),
			'payment_key'  => edd_generate_order_payment_key( $customer->email ),
			'order_number' => edd_set_order_number(),
			'subtotal'     => $subtotal,
			'discount'     => $discount_amount,
			'tax'          => 0.00,
			'total'        => max( 0, $subtotal - $discount_amount ),
		] );

		if ( ! $order_id ) {
			return new \WP_Error( 'edd_abilities_order_not_created', __( 'EDD could not create the order.', 'edd-abilities' ) );
		}

		$customer->attach_payment( $order_id, false );

		if ( ! $this->add_items( $order_id, $items, $subtotal, $discount_amount ) ) {
			edd_delete_order( $order_id );

			return new \WP_Error( 'edd_abilities_order_items_failed', __( 'EDD could not add the items to the order, so the order was removed.', 'edd-abilities' ) );
		}

		if ( $applied['discount'] && $discount_amount > 0 ) {
			edd_add_order_adjustment( [
				'object_id'   => $order_id,
				'object_type' => 'order',
				'type_id'     => (int) $applied['discount']->id,
				'type'        => 'discount',
				'description' => $applied['discount']->code,
				'subtotal'    => $discount_amount,
				'total'       => $discount_amount,
			] );
		}

		// Derive the order's figures from the rows just written, before the status change reads them.
		$order = edd_get_order( $order_id );

		if ( $order && method_exists( $order, 'recalculate' ) ) {
			$order->recalculate();
		}

		if ( ! empty( $input['note'] ) ) {
			edd_add_note( [
				'object_id'   => $order_id,
				'object_type' => 'order',
				'content'     => wp_kses( trim( (string) $input['note'] ), edd_get_allowed_tags() ),
				'user_id'     => get_current_user_id(),
			] );
		}

		// When the order completes EDD only emails if these flags exist and are truthy (checkout sets
		// them to 1), so they have to be written either way, before the status change that triggers it.
		$send_emails = ! empty( $input['send_emails'] );

		edd_update_order_meta( $order_id, '_edd_should_send_order_receipt', $send_emails ? '1' : false );
		edd_update_order_meta( $order_id, '_edd_should_send_admin_order_notice', $send_emails ? '1' : false );

		if ( 'pending' !== $status ) {
			edd_update_order_status( $order_id, $status );
		}

		$order = edd_get_order( $order_id );

		if ( ! $order ) {
			return new \WP_Error( 'edd_abilities_order_unreadable', __( 'The order was created but could not be read back.', 'edd-abilities' ), [ 'order_id' => $order_id ] );
		}

		return array_merge( Order_Schema::transform( $order, [ 'items', 'discounts' ] ), [ 'tax_calculated' => false ] );
	}

	/**
	 * Check the requested items against the store and work out what each costs.
	 *
	 * @param array $items
	 *
	 * @return array|\WP_Error
	 */
	protected function build_items( array $items ) {

		$decimals = edd_currency_decimal_filter();
		$built    = [];

		foreach ( $items as $item ) {

			$product_id = absint( $item['product_id'] ?? 0 );
			$download   = $product_id ? edd_get_download( $product_id ) : false;

			if ( ! $download || 'trash' === get_post_status( $product_id ) ) {
				return $this->not_found( 'product', $product_id );
			}

			// Bundles, All Access passes and the like carry extra rows and behaviour of their own that a
			// plain order item doesn't, so refuse rather than write a half-correct order.
			if ( 'default' !== $download->get_type() ) {
				/* translators: %d: a product ID */
				return new \WP_Error( 'edd_abilities_unsupported_product', sprintf( __( 'Product %d is a bundle or another special product type, which this ability does not support.', 'edd-abilities' ), $product_id ) );
			}

			$price_id = null;

			if ( $download->has_variable_prices() ) {

				if ( ! isset( $item['price_id'] ) ) {
					/* translators: %d: a product ID */
					return new \WP_Error( 'edd_abilities_price_id_required', sprintf( __( 'Product %d has variable pricing, so a price_id is required.', 'edd-abilities' ), $product_id ) );
				}

				$price_id = absint( $item['price_id'] );

				if ( ! isset( $download->get_prices()[ $price_id ] ) ) {
					/* translators: 1: a price ID, 2: a product ID */
					return new \WP_Error( 'edd_abilities_invalid_price_id', sprintf( __( 'Price option %1$d does not exist on product %2$d.', 'edd-abilities' ), $price_id, $product_id ) );
				}

				$amount = (float) edd_get_price_option_amount( $product_id, $price_id );

			} else {
				$amount = (float) edd_get_download_price( $product_id );
			}

			if ( isset( $item['price'] ) ) {
				$amount = max( 0, (float) $item['price'] );
			}

			$quantity = max( 1, absint( $item['quantity'] ?? 1 ) );

			$built[] = [
				'product_id'   => $product_id,
				'product_name' => edd_get_download_name( $product_id, $price_id ),
				'price_id'     => $price_id,
				'quantity'     => $quantity,
				'amount'       => $amount,
				'subtotal'     => round( $amount * $quantity, $decimals ),
			];
		}

		return $built;
	}

	/**
	 * Validate the discount code and work out how much it takes off the whole order.
	 *
	 * @param array $input
	 * @param float $subtotal
	 *
	 * @return array{discount: ?\EDD_Discount, amount: float}|\WP_Error
	 */
	protected function resolve_discount( array $input, float $subtotal ) {

		if ( empty( $input['discount_code'] ) ) {
			return [ 'discount' => null, 'amount' => 0.0 ];
		}

		$discount = edd_get_discount_by_code( sanitize_text_field( $input['discount_code'] ) );

		if ( ! $discount || ! $discount->is_active( false, false ) ) {
			return new \WP_Error( 'edd_abilities_invalid_discount', __( 'That discount code does not exist or is not active.', 'edd-abilities' ) );
		}

		if ( ! empty( $discount->max_uses ) && (int) $discount->use_count >= (int) $discount->max_uses ) {
			return new \WP_Error( 'edd_abilities_discount_maxed_out', __( 'That discount code has reached its maximum number of uses.', 'edd-abilities' ) );
		}

		if ( ! empty( $discount->min_charge_amount ) && $subtotal < (float) $discount->min_charge_amount ) {
			return new \WP_Error( 'edd_abilities_discount_minimum_not_met', __( 'The order does not reach the minimum amount that discount code requires.', 'edd-abilities' ) );
		}

		$amount = round( $subtotal - $discount->get_discounted_amount( $subtotal ), edd_currency_decimal_filter() );

		return [ 'discount' => $discount, 'amount' => min( $subtotal, max( 0.0, $amount ) ) ];
	}

	/**
	 * Find the customer for the order, creating one from an email address if it's new.
	 *
	 * @param array $input
	 *
	 * @return \EDD_Customer|\WP_Error
	 */
	protected function resolve_customer( array $input ) {

		if ( ! empty( $input['customer_id'] ) ) {

			$customer = edd_get_customer( absint( $input['customer_id'] ) );

			return $customer ?: $this->not_found( 'customer', $input['customer_id'] );
		}

		$email = sanitize_email( $input['email'] ?? '' );

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'edd_abilities_customer_required', __( 'Provide a customer_id, or a valid email address.', 'edd-abilities' ) );
		}

		$customer = edd_get_customer_by( 'email', $email );

		if ( $customer ) {
			return $customer;
		}

		$user = get_user_by( 'email', $email );
		$id   = edd_add_customer( [
			'email'   => $email,
			'name'    => ! empty( $input['name'] ) ? sanitize_text_field( $input['name'] ) : $email,
			'user_id' => $user ? $user->ID : 0,
		] );

		$customer = $id ? edd_get_customer( $id ) : false;

		return $customer ?: new \WP_Error( 'edd_abilities_customer_not_created', __( 'A customer could not be created for that email address.', 'edd-abilities' ) );
	}

	/**
	 * Write the order's item rows, sharing the order-level discount between them in proportion to
	 * their price. They go in as pending whatever the order's final status is, so the status change
	 * is what moves them - that's what makes EDD update each product's sales figures.
	 *
	 * @param int   $order_id
	 * @param array $items
	 * @param float $subtotal
	 * @param float $discount_amount
	 *
	 * @return bool false if any row couldn't be written
	 */
	protected function add_items( int $order_id, array $items, float $subtotal, float $discount_amount ): bool {

		$decimals   = edd_currency_decimal_filter();
		$remaining  = $discount_amount;
		$last_index = count( $items ) - 1;

		foreach ( $items as $index => $item ) {

			$item_discount = 0.0;

			if ( $discount_amount > 0 && $subtotal > 0 ) {
				// The last item takes what's left, so the rows always add up to the order's discount.
				$item_discount = $index === $last_index
					? round( $remaining, $decimals )
					: round( $discount_amount * ( $item['subtotal'] / $subtotal ), $decimals );

				$remaining = round( $remaining - $item_discount, $decimals );
			}

			$added = edd_add_order_item( [
				'order_id'     => $order_id,
				'product_id'   => $item['product_id'],
				'product_name' => $item['product_name'],
				'price_id'     => $item['price_id'],
				'cart_index'   => $index,
				'type'         => 'download',
				'status'       => 'pending',
				'quantity'     => $item['quantity'],
				'amount'       => $item['amount'],
				'subtotal'     => $item['subtotal'],
				'discount'     => $item_discount,
				'tax'          => 0.00,
				'total'        => max( 0, $item['subtotal'] - $item_discount ),
			] );

			if ( ! $added ) {
				return false;
			}
		}

		return true;
	}
}
