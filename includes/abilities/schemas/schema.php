<?php

namespace EDD_Abilities\Abilities\Schemas;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for reusable ability schemas.
 *
 * A Schema knows two things about one "shape" of data (an Order, a Customer, ...):
 *
 * 1. How to describe it as a JSON schema, for an ability's output_schema or nested inside another.
 * 2. How to transform a real EDD object (or raw row) into the plain array matching that schema.
 *
 * Both are static, so a Schema is used directly by class name:
 *
 *     Order_Schema::get_schema();
 *     Order_Schema::transform( $order, [ 'items' ] );
 *
 * $include lists optional, more expensive sections (extra queries) the caller can opt into.
 * Cheap standard fields are always returned.
 */
abstract class Schema {

	/**
	 * @return array JSON schema
	 */
	abstract public static function get_schema(): array;

	/**
	 * @param mixed $object
	 * @param array $include optional section names
	 *
	 * @return array
	 */
	abstract public static function transform( $object, array $include = [] ): array;

	/**
	 * The schema fragment for a moment in time rendered by datetime().
	 *
	 * @return array
	 */
	public static function datetime_schema(): array {
		return [
			'type'        => [ 'object', 'null' ],
			'description' => __( 'A moment in time, given as both UTC and the site timezone. Null if not set.', 'edd-abilities' ),
			'properties'  => [
				'utc'      => [
					'type'        => 'string',
					'description' => __( 'ISO 8601, UTC (e.g. 2026-07-10T14:00:00Z).', 'edd-abilities' ),
				],
				'local'    => [
					'type'        => 'string',
					'description' => __( 'ISO 8601 in the site timezone, with offset.', 'edd-abilities' ),
				],
				'timezone' => [
					'type'        => 'string',
					'description' => __( 'The site timezone name or offset.', 'edd-abilities' ),
				],
			],
		];
	}

	/**
	 * Render a moment in time as both UTC and the site's timezone.
	 *
	 * EDD stores every datetime column as a UTC 'Y-m-d H:i:s' string, so a string without an
	 * explicit offset is read as UTC. Numeric values are unix timestamps.
	 *
	 * @param int|string|null $when
	 *
	 * @return array{utc: string, local: string, timezone: string}|null null when empty/zero/unparseable
	 */
	public static function datetime( $when ): ?array {

		if ( empty( $when ) || ( is_string( $when ) && strpos( $when, '0000-00-00' ) === 0 ) ) {
			return null;
		}

		try {
			$utc = new DateTimeZone( 'UTC' );
			$dt  = is_numeric( $when )
				? ( new DateTimeImmutable( '@' . (int) $when ) )
				: new DateTimeImmutable( (string) $when, $utc );
		} catch ( Throwable $e ) {
			return null;
		}

		return [
			'utc'      => $dt->setTimezone( $utc )->format( 'Y-m-d\TH:i:s\Z' ),
			'local'    => $dt->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i:sP' ),
			'timezone' => wp_timezone()->getName(),
		];
	}

	/**
	 * Money is returned as a plain float in the order's own currency; EDD may hand back strings.
	 *
	 * @param mixed $amount
	 *
	 * @return float
	 */
	public static function money( $amount ): float {
		return round( (float) $amount, 6 );
	}

	/**
	 * Standard limit/offset input properties for list abilities.
	 *
	 * @param int $default
	 * @param int $max
	 *
	 * @return array
	 */
	public static function pagination_input( int $default = 20, int $max = 100 ): array {
		return [
			'limit'  => [
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => $max,
				'default'     => $default,
				'description' => __( 'Maximum number of items to return.', 'edd-abilities' ),
			],
			'offset' => [
				'type'        => 'integer',
				'minimum'     => 0,
				'default'     => 0,
				'description' => __( 'Number of items to skip, for paging.', 'edd-abilities' ),
			],
		];
	}

	/**
	 * Clamp limit/offset input into safe integers.
	 *
	 * @param array $input
	 * @param int   $default
	 * @param int   $max
	 *
	 * @return array{0:int,1:int} [ limit, offset ]
	 */
	public static function pagination( array $input, int $default = 20, int $max = 100 ): array {
		$limit  = isset( $input['limit'] ) ? min( max( 1, absint( $input['limit'] ) ), $max ) : $default;
		$offset = isset( $input['offset'] ) ? absint( $input['offset'] ) : 0;

		return [ $limit, $offset ];
	}

	/**
	 * A "created after / before" input pair used by several list abilities.
	 *
	 * @return array
	 */
	public static function date_range_input(): array {
		return [
			'created_after'  => [
				'type'        => 'string',
				'description' => __( 'Only items created on or after this date/time (UTC). Accepts YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.', 'edd-abilities' ),
			],
			'created_before' => [
				'type'        => 'string',
				'description' => __( 'Only items created on or before this date/time (UTC). Accepts YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.', 'edd-abilities' ),
			],
		];
	}

	/**
	 * Build a WP_Date_Query compatible clause from created_after/created_before input.
	 *
	 * @param array $input
	 *
	 * @return array empty if neither bound was given
	 */
	public static function date_range_query( array $input ): array {

		$query = [];

		foreach ( [ 'created_after' => 'after', 'created_before' => 'before' ] as $key => $bound ) {
			if ( ! empty( $input[ $key ] ) && strtotime( (string) $input[ $key ] ) ) {
				$query[ $bound ] = sanitize_text_field( $input[ $key ] );
			}
		}

		if ( $query ) {
			$query['inclusive'] = true;
		}

		return $query;
	}
}
