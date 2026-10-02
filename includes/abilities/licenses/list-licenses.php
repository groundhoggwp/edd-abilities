<?php

namespace EDD_Abilities\Abilities\Licenses;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\License_Schema;
use EDD_Abilities\Abilities\Schemas\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists/searches Software Licensing licenses. Only registered when EDD Software Licensing is active.
 */
class List_Licenses extends Ability {

	protected const NAME       = 'edd-alt/list-licenses';
	protected const CATEGORY   = 'edd-alt-licenses';
	protected const CAPABILITY = 'manage_licenses';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	public static function is_available(): bool {
		return function_exists( 'edd_software_licensing' );
	}

	protected function get_args(): array {

		return [
			'label'       => __( 'List Licenses', 'edd-abilities' ),
			'description' => __( 'List or search Easy Digital Downloads Software Licensing licenses. Filter by status, product, customer or order.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array_merge( [
					'search'      => [ 'type' => 'string', 'description' => __( 'Free-text search: license key, customer email or name.', 'edd-abilities' ) ],
					'license_key' => [ 'type' => 'string', 'description' => __( 'Exact license key match.', 'edd-abilities' ) ],
					'status'      => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string', 'enum' => [ 'active', 'inactive', 'expired', 'disabled' ] ],
						'description' => __( 'Only licenses with one of these statuses.', 'edd-abilities' ),
					],
					'product_id'  => [ 'type' => 'integer', 'description' => __( 'Only licenses for this product.', 'edd-abilities' ) ],
					'customer_id' => [ 'type' => 'integer' ],
					'order_id'    => [ 'type' => 'integer', 'description' => __( 'Only licenses issued for this order.', 'edd-abilities' ) ],
					'orderby'     => [
						'type'    => 'string',
						'enum'    => [ 'id', 'expiration', 'date_created' ],
						'default' => 'id',
					],
					'order'       => [
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
						'description' => __( 'Total licenses matching the filters, ignoring limit/offset.', 'edd-abilities' ),
					],
					'licenses'    => [
						'type'  => 'array',
						'items' => License_Schema::get_schema(),
					],
				],
			],
		];
	}

	public function __invoke( $input ) {

		$input = (array) $input;

		list( $limit, $offset ) = Schema::pagination( $input );

		$args = [];

		foreach ( [ 'customer_id', 'download_id' => 'product_id', 'payment_id' => 'order_id' ] as $arg => $key ) {

			$arg = is_int( $arg ) ? $key : $arg;

			if ( ! empty( $input[ $key ] ) ) {
				$args[ $arg ] = absint( $input[ $key ] );
			}
		}

		if ( ! empty( $input['search'] ) ) {
			$args['search'] = sanitize_text_field( $input['search'] );
		}

		if ( ! empty( $input['license_key'] ) ) {
			$args['license_key'] = sanitize_text_field( $input['license_key'] );
		}

		if ( ! empty( $input['status'] ) ) {
			$args['status'] = array_values( array_intersect( (array) $input['status'], [ 'active', 'inactive', 'expired', 'disabled' ] ) );
		}

		$db    = edd_software_licensing()->licenses_db;
		$total = (int) $db->count( $args );

		$orderby = $input['orderby'] ?? 'id';

		$licenses = $db->get_licenses( array_merge( $args, [
			'number'  => $limit,
			'offset'  => $offset,
			'orderby' => in_array( $orderby, [ 'expiration', 'date_created' ], true ) ? $orderby : 'id',
			'order'   => ( $input['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
		] ) );

		return [
			'total_items' => $total,
			'licenses'    => array_map( [ License_Schema::class, 'transform' ], (array) $licenses ),
		];
	}
}
