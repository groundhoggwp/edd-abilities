<?php

namespace EDD_Abilities\Abilities\Products;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Product_Schema;
use EDD_Abilities\Abilities\Schemas\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists/searches EDD products (Downloads > Downloads in the admin).
 */
class List_Products extends Ability {

	protected const NAME       = 'edd/list-products';
	protected const CATEGORY   = 'edd-products';
	protected const CAPABILITY = 'edit_products';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'List Products', 'edd-abilities' ),
			'description' => __( 'List or search Easy Digital Downloads products. Use the returned id as product_id in edd/list-orders, edd/create-discount and similar.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array_merge( [
					'search'  => [ 'type' => 'string', 'description' => __( 'Free-text search against the product title and content.', 'edd-abilities' ) ],
					'status'  => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string', 'enum' => [ 'publish', 'draft', 'pending', 'private', 'future' ] ],
						'default'     => [ 'publish' ],
						'description' => __( 'Post statuses to include. Defaults to published products only.', 'edd-abilities' ),
					],
					'orderby' => [
						'type'    => 'string',
						'enum'    => [ 'title', 'date', 'ID' ],
						'default' => 'title',
					],
					'order'   => [
						'type'    => 'string',
						'enum'    => [ 'ASC', 'DESC' ],
						'default' => 'ASC',
					],
					'expand'  => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string', 'enum' => Product_Schema::INCLUDES ],
						'default'     => [],
						'description' => __( 'Optional extra sections to include on each product. "stats" is only returned to users who can view shop reports.', 'edd-abilities' ),
					],
				], Schema::pagination_input() ),
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'total_items' => [
						'type'        => 'integer',
						'description' => __( 'Total products matching the filters, ignoring limit/offset.', 'edd-abilities' ),
					],
					'products'    => [
						'type'  => 'array',
						'items' => Product_Schema::get_schema(),
					],
				],
			],
		];
	}

	public function __invoke( $input ) {

		$input = (array) $input;

		list( $limit, $offset ) = Schema::pagination( $input );

		$statuses = array_intersect( (array) ( $input['status'] ?? [ 'publish' ] ), [ 'publish', 'draft', 'pending', 'private', 'future' ] );

		$orderby = $input['orderby'] ?? 'title';

		$args = [
			'post_type'      => 'download',
			'post_status'    => $statuses ?: [ 'publish' ],
			'posts_per_page' => $limit,
			'offset'         => $offset,
			'orderby'        => in_array( $orderby, [ 'date', 'ID' ], true ) ? $orderby : 'title',
			'order'          => ( $input['order'] ?? 'ASC' ) === 'DESC' ? 'DESC' : 'ASC',
		];

		if ( ! empty( $input['search'] ) ) {
			$args['s'] = sanitize_text_field( $input['search'] );
		}

		$query  = new \WP_Query( $args );
		$expand = array_values( array_intersect( (array) ( $input['expand'] ?? [] ), Product_Schema::INCLUDES ) );

		return [
			'total_items' => (int) $query->found_posts,
			'products'    => array_map( function ( $post ) use ( $expand ) {
				return Product_Schema::transform( $post, $expand );
			}, $query->posts ),
		];
	}
}
