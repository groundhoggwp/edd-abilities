<?php

namespace EDD_Abilities\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for every ability. Instantiating one registers it with the WP Abilities API;
 * the instance itself is the execute callback (see __invoke()).
 */
abstract class Ability {

	protected const NAME       = '';
	protected const CATEGORY   = '';
	protected const CAPABILITY = '';

	protected const PUBLIC      = true;
	protected const READONLY    = false;
	protected const DESTRUCTIVE = false;
	protected const IDEMPOTENT  = false;

	/**
	 * Whether the plugin this ability wraps is present. Abilities for optional add-ons
	 * override this so they simply don't register when the add-on is inactive.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return true;
	}

	public function __construct() {

		$args = $this->get_args();

		$args['category']            = static::CATEGORY;
		$args['execute_callback']    = $this;
		$args['permission_callback'] = $args['permission_callback'] ?? [ $this, 'can_execute' ];

		$args['meta'] = array_merge( [ 'public' => static::PUBLIC ], $args['meta'] ?? [] );

		$args['meta']['annotations'] = array_merge(
			[
				'readonly'    => static::READONLY,
				'destructive' => static::DESTRUCTIVE,
				'idempotent'  => static::IDEMPOTENT,
			],
			$args['meta']['annotations'] ?? []
		);

		wp_register_ability( static::NAME, $args );
	}

	/**
	 * Ability registration arguments: label, description, input_schema, output_schema.
	 *
	 * @return array
	 */
	abstract protected function get_args(): array;

	/**
	 * Execute the ability.
	 *
	 * @param mixed $input
	 *
	 * @return mixed
	 */
	abstract public function __invoke( $input );

	/**
	 * Whether the current user may execute the ability.
	 *
	 * @param mixed $input
	 *
	 * @return bool|\WP_Error
	 */
	public function can_execute( $input = null ) {

		if ( ! static::CAPABILITY ) {
			return true;
		}

		return current_user_can( static::CAPABILITY );
	}

	/**
	 * Standard WP_Error for an id that doesn't resolve to anything.
	 *
	 * @param string $what e.g. "order"
	 * @param int    $id
	 *
	 * @return \WP_Error
	 */
	protected function not_found( string $what, $id ): \WP_Error {
		return new \WP_Error(
			'edd_abilities_not_found',
			/* translators: 1: the kind of thing, e.g. "order", 2: its id */
			sprintf( __( 'No %1$s found with ID %2$s.', 'edd-abilities' ), $what, $id )
		);
	}
}
