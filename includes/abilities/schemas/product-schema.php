<?php

namespace EDD_Abilities\Abilities\Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared schema for an EDD product (a "download").
 */
class Product_Schema extends Schema {

	/**
	 * Optional sections a caller can request via include/expand.
	 */
	public const INCLUDES = [ 'prices', 'stats' ];

	public static function get_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'                  => [ 'type' => 'integer' ],
				'title'               => [ 'type' => 'string' ],
				'slug'                => [ 'type' => 'string' ],
				'status'              => [ 'type' => 'string', 'description' => __( 'The post status, e.g. publish, draft.', 'edd-abilities' ) ],
				'type'                => [ 'type' => 'string', 'description' => __( '"default" or "bundle".', 'edd-abilities' ) ],
				'url'                 => [ 'type' => 'string' ],
				'sku'                 => [ 'type' => 'string' ],
				'price'               => [ 'type' => 'number', 'description' => __( 'The base price. For variable-priced products, the default price option.', 'edd-abilities' ) ],
				'has_variable_prices' => [ 'type' => 'boolean' ],
				'date_created'        => self::datetime_schema(),
				'prices'              => [
					'type'        => 'array',
					'description' => __( 'Variable price options. Only present when "prices" is requested.', 'edd-abilities' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'price_id' => [ 'type' => 'integer' ],
							'name'     => [ 'type' => 'string' ],
							'amount'   => [ 'type' => 'number' ],
						],
					],
				],
				'sales'               => [ 'type' => 'integer', 'description' => __( 'Net sales. Only present when "stats" is requested and the user can view shop reports.', 'edd-abilities' ) ],
				'earnings'            => [ 'type' => 'number', 'description' => __( 'Net earnings. Only present when "stats" is requested and the user can view shop reports.', 'edd-abilities' ) ],
			],
		];
	}

	/**
	 * @param \EDD_Download|\WP_Post|int $object
	 * @param array                      $include any of self::INCLUDES
	 *
	 * @return array
	 */
	public static function transform( $object, array $include = [] ): array {

		if ( ! $object instanceof \EDD_Download ) {
			$object = edd_get_download( $object instanceof \WP_Post ? $object->ID : $object );
		}

		$id = (int) $object->ID;

		$data = [
			'id'                  => $id,
			'title'               => (string) $object->get_name(),
			'slug'                => (string) $object->post_name,
			'status'              => (string) $object->post_status,
			'type'                => (string) $object->get_type(),
			'url'                 => (string) get_permalink( $id ),
			'sku'                 => (string) $object->get_sku(),
			'price'               => self::money( $object->get_price() ),
			'has_variable_prices' => (bool) $object->has_variable_prices(),
			'date_created'        => self::datetime( get_post_time( 'Y-m-d H:i:s', true, $id ) ),
		];

		if ( in_array( 'prices', $include, true ) ) {
			$prices = [];

			foreach ( (array) $object->get_prices() as $price_id => $option ) {
				$prices[] = [
					'price_id' => (int) $price_id,
					'name'     => (string) ( $option['name'] ?? '' ),
					'amount'   => self::money( $option['amount'] ?? 0 ),
				];
			}

			$data['prices'] = $prices;
		}

		if ( in_array( 'stats', $include, true ) && current_user_can( 'view_shop_reports' ) ) {
			$data['sales']    = (int) $object->get_sales();
			$data['earnings'] = self::money( $object->get_earnings() );
		}

		return $data;
	}
}
