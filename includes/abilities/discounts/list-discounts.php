<?php

namespace EDD_Abilities\Abilities\Discounts;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Discount_Schema;
use EDD_Abilities\Abilities\Schemas\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists/searches EDD discount codes (Downloads > Discounts in the admin).
 */
class List_Discounts extends Ability {

	protected const NAME       = 'edd/list-discounts';
	protected const CATEGORY   = 'edd-discounts';
	protected const CAPABILITY = 'manage_shop_discounts';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'List Discounts', 'edd-abilities' ),
			'description' => __( 'List or search Easy Digital Downloads discount codes. Archived discounts are left out unless you ask for that status.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array_merge( [
					'search'  => [ 'type' => 'string', 'description' => __( 'Free-text search against the discount name and code.', 'edd-abilities' ) ],
					'status'  => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string', 'enum' => [ 'active', 'inactive', 'expired', 'archived' ] ],
						'description' => __( 'Only discounts with one of these statuses.', 'edd-abilities' ),
					],
					'orderby' => [
						'type'    => 'string',
						'enum'    => [ 'date_created', 'name', 'use_count', 'id' ],
						'default' => 'date_created',
					],
					'order'   => [
						'type'    => 'string',
						'enum'    => [ 'ASC', 'DESC' ],
						'default' => 'DESC',
					],
				], Schema::pagination_input() ),
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'total_items' => [
						'type'        => 'integer',
						'description' => __( 'Total discounts matching the filters, ignoring limit/offset.', 'edd-abilities' ),
					],
					'discounts'   => [
						'type'  => 'array',
						'items' => Discount_Schema::get_schema(),
					],
				],
			],
		];
	}

	public function __invoke( $input ) {

		$input = (array) $input;

		list( $limit, $offset ) = Schema::pagination( $input );

		$args = [];

		if ( ! empty( $input['search'] ) ) {
			$args['search'] = sanitize_text_field( $input['search'] );
		}

		if ( ! empty( $input['status'] ) ) {
			$args['status__in'] = array_values( array_intersect( (array) $input['status'], [ 'active', 'inactive', 'expired', 'archived' ] ) );
		} else {
			// edd_get_discounts() hides archived discounts by default but edd_get_discount_count()
			// does not, so say it explicitly or total_items would disagree with the list.
			$args['status__not_in'] = [ 'archived' ];
		}

		$total = (int) edd_get_discount_count( $args );

		$orderby = $input['orderby'] ?? 'date_created';

		$discounts = edd_get_discounts( array_merge( $args, [
			'number'  => $limit,
			'offset'  => $offset,
			'orderby' => in_array( $orderby, [ 'name', 'use_count', 'id' ], true ) ? $orderby : 'date_created',
			'order'   => ( $input['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
		] ) );

		return [
			'total_items' => $total,
			'discounts'   => array_map( [ Discount_Schema::class, 'transform' ], (array) $discounts ),
		];
	}
}
