<?php

namespace EDD_Abilities\Abilities\Licenses;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\License_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enables or disables a license. Only registered when EDD Software Licensing is active.
 */
class Update_License_Status extends Ability {

	protected const NAME       = 'edd-alt/update-license-status';
	protected const CATEGORY   = 'edd-alt-licenses';
	protected const CAPABILITY = 'manage_licenses';

	protected const IDEMPOTENT = true;

	public static function is_available(): bool {
		return function_exists( 'edd_software_licensing' );
	}

	protected function get_args(): array {

		return [
			'label'       => __( 'Update License Status', 'edd-abilities' ),
			'description' => __( 'Disable a Software Licensing license (it stops validating and receiving updates) or re-enable it. Enabling restores "active" if the license has site activations, otherwise "inactive". Child licenses of a bundle follow their parent.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id', 'action' ],
				'properties'           => [
					'id'     => [ 'type' => 'integer', 'description' => __( 'The license ID.', 'edd-abilities' ) ],
					'action' => [ 'type' => 'string', 'enum' => [ 'enable', 'disable' ] ],
				],
			],

			'output_schema' => License_Schema::get_schema(),
		];
	}

	public function __invoke( $input ) {

		$id      = absint( $input['id'] ?? 0 );
		$action  = $input['action'] ?? '';
		$license = edd_software_licensing()->get_license( $id );

		if ( ! $license || ! $license->ID ) {
			return $this->not_found( 'license', $id );
		}

		if ( ! in_array( $action, [ 'enable', 'disable' ], true ) ) {
			return new \WP_Error( 'edd_abilities_invalid_action', __( 'Action must be enable or disable.', 'edd-abilities' ) );
		}

		$disabled = 'disabled' === $license->status;

		// Only touch the license when the requested state differs from the current one.
		if ( ( 'disable' === $action && ! $disabled ) || ( 'enable' === $action && $disabled ) ) {

			if ( ! $license->$action() ) {
				return new \WP_Error( 'edd_abilities_status_failed', __( 'Software Licensing could not update the license.', 'edd-abilities' ) );
			}
		}

		return License_Schema::transform( edd_software_licensing()->get_license( $id ) );
	}
}
