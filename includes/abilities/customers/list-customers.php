<?php

namespace EDD_Abilities\Abilities\Customers;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Customer_Schema;
use EDD_Abilities\Abilities\Schemas\Schema;
use EDD_Abilities\Abilities\Traits\Checks_Customer_Caps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists/searches EDD customers (Downloads > Customers in the admin).
 */
class List_Customers extends Ability {

	use Checks_Customer_Caps;

	protected const NAME     = 'edd-alt/list-customers';
	protected const CATEGORY = 'edd-alt-customers';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'List Customers', 'edd-abilities' ),
			'description' => __( 'List or search Easy Digital Downloads customers by name or email, newest first by default.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array_merge( [
					'search'  => [
						'type'        => 'string',
						'description' => __( 'Free-text search against the customer name and email addresses.', 'edd-abilities' ),
					],
					'email'   => [
						'type'        => 'string',
						'description' => __( 'Exact email address match, including a customer\'s secondary addresses.', 'edd-abilities' ),
					],
					'user_id' => [ 'type' => 'integer', 'description' => __( 'Only the customer linked to this WordPress user.', 'edd-abilities' ) ],
					'status'  => [ 'type' => 'string', 'enum' => [ 'active', 'disabled' ] ],
					'orderby' => [
						'type'    => 'string',
						'enum'    => [ 'date_created', 'purchase_value', 'purchase_count', 'name', 'id' ],
						'default' => 'date_created',
					],
					'order'   => [
						'type'    => 'string',
						'enum'    => [ 'ASC', 'DESC' ],
						'default' => 'DESC',
					],
					'expand'  => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string', 'enum' => Customer_Schema::INCLUDES ],
						'default'     => [],
						'description' => __( 'Optional extra sections to include on each customer. Each costs extra queries per customer.', 'edd-abilities' ),
					],
				], Schema::date_range_input(), Schema::pagination_input() ),
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'total_items' => [
						'type'        => 'integer',
						'description' => __( 'Total customers matching the filters, ignoring limit/offset.', 'edd-abilities' ),
					],
					'customers'   => [
						'type'  => 'array',
						'items' => Customer_Schema::get_schema(),
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

		if ( ! empty( $input['email'] ) ) {
			$args['email'] = sanitize_email( $input['email'] );
		}

		if ( ! empty( $input['user_id'] ) ) {
			$args['user_id'] = absint( $input['user_id'] );
		}

		if ( ! empty( $input['status'] ) ) {
			$args['status'] = sanitize_key( $input['status'] );
		}

		$dates = Schema::date_range_query( $input );
		if ( $dates ) {
			$args['date_created_query'] = $dates;
		}

		$total = (int) edd_count_customers( $args );

		$orderby = $input['orderby'] ?? 'date_created';

		$customers = edd_get_customers( array_merge( $args, [
			'number'  => $limit,
			'offset'  => $offset,
			'orderby' => in_array( $orderby, [ 'purchase_value', 'purchase_count', 'name', 'id' ], true ) ? $orderby : 'date_created',
			'order'   => ( $input['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
		] ) );

		$expand = array_values( array_intersect( (array) ( $input['expand'] ?? [] ), Customer_Schema::INCLUDES ) );

		return [
			'total_items' => $total,
			'customers'   => array_map( function ( $customer ) use ( $expand ) {
				return Customer_Schema::transform( $customer, $expand );
			}, $customers ),
		];
	}
}
