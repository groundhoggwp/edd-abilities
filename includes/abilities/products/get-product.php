<?php

namespace EDD_Abilities\Abilities\Products;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Product_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches one product with its price options and sales stats.
 */
class Get_Product extends Ability {

	protected const NAME       = 'edd-alt/get-product';
	protected const CATEGORY   = 'edd-alt-products';
	protected const CAPABILITY = 'edit_products';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	protected function get_args(): array {

		return [
			'label'       => __( 'Get Product', 'edd-abilities' ),
			'description' => __( 'Get one Easy Digital Downloads product by ID, including its variable price options and, for users who can view shop reports, its net sales and earnings.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id' ],
				'properties'           => [
					'id'      => [ 'type' => 'integer', 'description' => __( 'The product (download) ID.', 'edd-abilities' ) ],
					'include' => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string', 'enum' => Product_Schema::INCLUDES ],
						'default'     => Product_Schema::INCLUDES,
						'description' => __( 'Sections to include. Defaults to all of them.', 'edd-abilities' ),
					],
				],
			],

			'output_schema' => Product_Schema::get_schema(),
		];
	}

	public function __invoke( $input ) {

		$id      = absint( $input['id'] ?? 0 );
		$product = edd_get_download( $id );

		if ( ! $product ) {
			return $this->not_found( 'product', $id );
		}

		$include = isset( $input['include'] ) ? array_intersect( (array) $input['include'], Product_Schema::INCLUDES ) : Product_Schema::INCLUDES;

		return Product_Schema::transform( $product, array_values( $include ) );
	}
}
