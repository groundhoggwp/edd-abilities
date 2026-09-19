<?php

namespace EDD_Abilities\Abilities\Licenses;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\License_Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches one license by ID or key, with its site activations. Only registered when EDD Software
 * Licensing is active.
 */
class Get_License extends Ability {

	protected const NAME       = 'edd/get-license';
	protected const CATEGORY   = 'edd-licenses';
	protected const CAPABILITY = 'manage_licenses';

	protected const READONLY   = true;
	protected const IDEMPOTENT = true;

	public static function is_available(): bool {
		return function_exists( 'edd_software_licensing' );
	}

	protected function get_args(): array {

		return [
			'label'       => __( 'Get License', 'edd-abilities' ),
			'description' => __( 'Get one Software Licensing license by ID or license key, including the sites it is activated on.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'id'          => [ 'type' => 'integer', 'description' => __( 'The license ID. Provide this or license_key.', 'edd-abilities' ) ],
					'license_key' => [ 'type' => 'string', 'description' => __( 'The license key. Provide this or id.', 'edd-abilities' ) ],
					'include'     => [
						'type'        => 'array',
						'items'       => [ 'type' => 'string', 'enum' => License_Schema::INCLUDES ],
						'default'     => License_Schema::INCLUDES,
						'description' => __( 'Sections to include. Defaults to all of them.', 'edd-abilities' ),
					],
				],
			],

			'output_schema' => License_Schema::get_schema(),
		];
	}

	public function __invoke( $input ) {

		$input = (array) $input;

		if ( ! empty( $input['id'] ) ) {
			$license = edd_software_licensing()->get_license( absint( $input['id'] ) );
		} elseif ( ! empty( $input['license_key'] ) ) {
			$license = edd_software_licensing()->get_license( sanitize_text_field( $input['license_key'] ), true );
		} else {
			return new \WP_Error( 'edd_abilities_missing_identifier', __( 'Provide either id or license_key.', 'edd-abilities' ) );
		}

		if ( ! $license || ! $license->ID ) {
			return $this->not_found( 'license', $input['id'] ?? $input['license_key'] );
		}

		$include = isset( $input['include'] ) ? array_intersect( (array) $input['include'], License_Schema::INCLUDES ) : License_Schema::INCLUDES;

		return License_Schema::transform( $license, array_values( $include ) );
	}
}
