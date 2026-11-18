<?php

namespace EDD_Abilities\Abilities\Schemas;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared schema for one row of EDD's file download log.
 *
 * The IP address is personal data, so it is only part of the result when the caller asks for it by
 * passing 'ip' in $include (the list ability does that for users who hold view_shop_sensitive_data).
 */
class Download_Log_Schema extends Schema {

	public const INCLUDES = [ 'ip' ];

	public static function get_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'            => [ 'type' => 'integer' ],
				'product_id'    => [ 'type' => 'integer' ],
				'product_title' => [ 'type' => 'string' ],
				'file_id'       => [ 'type' => 'integer', 'description' => __( 'The file\'s key within the product\'s file list (0 is the first file).', 'edd-abilities' ) ],
				'price_id'      => [ 'type' => [ 'integer', 'null' ], 'description' => __( 'The variable price option that was downloaded. Null for a product without price options.', 'edd-abilities' ) ],
				'order_id'      => [ 'type' => 'integer' ],
				'customer_id'   => [ 'type' => 'integer', 'description' => __( '0 when the download could not be tied to a customer.', 'edd-abilities' ) ],
				'user_agent'    => [ 'type' => 'string' ],
				'ip'            => [ 'type' => 'string', 'description' => __( 'Only present for users who hold view_shop_sensitive_data.', 'edd-abilities' ) ],
				'date_created'  => self::datetime_schema(),
			],
		];
	}

	/**
	 * @param \EDD\Logs\File_Download_Log|object $object a log row
	 * @param array                              $include optional sections: 'ip'
	 *
	 * @return array
	 */
	public static function transform( $object, array $include = [] ): array {

		$title = get_the_title( (int) $object->product_id );

		$log = [
			'id'            => (int) $object->id,
			'product_id'    => (int) $object->product_id,
			'product_title' => '' !== $title ? (string) $title : sprintf( '#%d', (int) $object->product_id ),
			'file_id'       => (int) $object->file_id,
			// EDD stores 0 for "no price option", which is also a valid option id, so only report it for
			// variable-priced products.
			'price_id'      => edd_has_variable_prices( (int) $object->product_id ) ? (int) $object->price_id : null,
			'order_id'      => (int) $object->order_id,
			'customer_id'   => (int) $object->customer_id,
			'user_agent'    => (string) $object->user_agent,
			'date_created'  => self::datetime( $object->date_created ),
		];

		if ( in_array( 'ip', $include, true ) ) {
			$log['ip'] = (string) $object->ip;
		}

		return $log;
	}
}
