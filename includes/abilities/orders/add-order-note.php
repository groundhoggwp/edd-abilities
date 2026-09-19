<?php

namespace EDD_Abilities\Abilities\Orders;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a private note to an order, visible to store admins only.
 */
class Add_Order_Note extends Ability {

	protected const NAME       = 'edd/add-order-note';
	protected const CATEGORY   = 'edd-orders';
	protected const CAPABILITY = 'edit_shop_payments';

	protected function get_args(): array {

		return [
			'label'       => __( 'Add Order Note', 'edd-abilities' ),
			'description' => __( 'Add a private note to an Easy Digital Downloads order. Notes are only visible to store admins, never to the customer.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id', 'note' ],
				'properties'           => [
					'id'   => [ 'type' => 'integer', 'description' => __( 'The order ID.', 'edd-abilities' ) ],
					'note' => [ 'type' => 'string', 'minLength' => 1, 'description' => __( 'The note text.', 'edd-abilities' ) ],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'id'           => [ 'type' => 'integer', 'description' => __( 'The new note ID.', 'edd-abilities' ) ],
					'order_id'     => [ 'type' => 'integer' ],
					'content'      => [ 'type' => 'string' ],
					'date_created' => Schema::datetime_schema(),
				],
			],
		];
	}

	public function __invoke( $input ) {

		$id      = absint( $input['id'] ?? 0 );
		$content = sanitize_textarea_field( $input['note'] ?? '' );

		if ( ! edd_get_order( $id ) ) {
			return $this->not_found( 'order', $id );
		}

		if ( '' === $content ) {
			return new \WP_Error( 'edd_abilities_empty_note', __( 'The note cannot be empty.', 'edd-abilities' ) );
		}

		$note_id = edd_add_note( [
			'object_id'   => $id,
			'object_type' => 'order',
			'content'     => $content,
			'user_id'     => get_current_user_id(),
		] );

		if ( ! $note_id ) {
			return new \WP_Error( 'edd_abilities_note_failed', __( 'EDD could not add the note.', 'edd-abilities' ) );
		}

		$note = edd_get_note( $note_id );

		return [
			'id'           => (int) $note_id,
			'order_id'     => $id,
			'content'      => $content,
			'date_created' => Schema::datetime( $note ? $note->date_created : null ),
		];
	}
}
