<?php

namespace EDD_Abilities\Abilities\Reports;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Download_Log_Schema;
use EDD_Abilities\Abilities\Schemas\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the individual entries of EDD's file download log (Downloads > Reports > File Downloads).
 * Pairs with edd-alt/analyze-download-logs: analyze to find something worth a look, list to see the
 * downloads behind it.
 */
class List_Download_Logs extends Ability {

	protected const NAME       = 'edd-alt/list-download-logs';
	protected const CATEGORY   = 'edd-alt-reports';
	protected const CAPABILITY = 'view_shop_reports';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'List Download Logs', 'edd-abilities' ),
			'description' => __( 'List the individual file download log entries of Easy Digital Downloads, newest first by default. Filter by product, file, customer, order or date. IP addresses are only included for users who hold view_shop_sensitive_data. To count or summarize downloads use edd-alt/analyze-download-logs instead.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array_merge( [
					'product_id'  => [ 'type' => 'integer', 'minimum' => 1 ],
					'file_id'     => [ 'type' => 'integer', 'minimum' => 0, 'description' => __( 'The file\'s key within the product\'s file list. Use with product_id.', 'edd-abilities' ) ],
					'customer_id' => [ 'type' => 'integer', 'minimum' => 1 ],
					'order_id'    => [ 'type' => 'integer', 'minimum' => 1 ],
					'orderby'     => [
						'type'    => 'string',
						'enum'    => [ 'date_created', 'id' ],
						'default' => 'date_created',
					],
					'order'       => [
						'type'    => 'string',
						'enum'    => [ 'ASC', 'DESC' ],
						'default' => 'DESC',
					],
				], Schema::date_range_input(), Schema::pagination_input() ),
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'total_items' => [
						'type'        => 'integer',
						'description' => __( 'Total log entries matching the filters, ignoring limit/offset.', 'edd-abilities' ),
					],
					'logs'        => [
						'type'  => 'array',
						'items' => Download_Log_Schema::get_schema(),
					],
				],
			],
		];
	}

	public function __invoke( $input ) {

		$input = (array) $input;

		list( $limit, $offset ) = Schema::pagination( $input );

		$args = [];

		foreach ( [ 'product_id', 'file_id', 'customer_id', 'order_id' ] as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$args[ $key ] = absint( $input[ $key ] );
			}
		}

		$dates = Schema::date_range_query( $input );
		if ( $dates ) {
			$args['date_created_query'] = $dates;
		}

		$total = (int) edd_count_file_download_logs( $args );

		$logs = edd_get_file_download_logs( array_merge( $args, [
			'number'  => $limit,
			'offset'  => $offset,
			'orderby' => 'id' === ( $input['orderby'] ?? '' ) ? 'id' : 'date_created',
			'order'   => 'ASC' === ( $input['order'] ?? 'DESC' ) ? 'ASC' : 'DESC',
		] ) );

		$include = current_user_can( 'view_shop_sensitive_data' ) ? [ 'ip' ] : [];

		return [
			'total_items' => $total,
			'logs'        => array_map( function ( $log ) use ( $include ) {
				return Download_Log_Schema::transform( $log, $include );
			}, $logs ),
		];
	}
}
