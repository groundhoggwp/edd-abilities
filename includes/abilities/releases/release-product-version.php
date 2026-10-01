<?php

namespace EDD_Abilities\Abilities\Releases;

use EDD_Abilities\Abilities\Ability;
use EDD_Abilities\Abilities\Schemas\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ships a new version of a product whose files are pulled from GitHub or Bitbucket by the EDD
 * Git Download Updater add-on: fetches the given tag, repackages it the same way the admin "Update
 * File" button does, and prepends a caller-supplied changelog entry to the product's Software
 * Licensing changelog.
 *
 * Only registered when both the Git Download Updater and Software Licensing add-ons are active -
 * the whole point of this ability is bridging the two.
 */
class Release_Product_Version extends Ability {

	protected const NAME       = 'edd/release-product-version';
	protected const CATEGORY   = 'edd-releases';
	protected const CAPABILITY = 'edit_products';

	protected const DESTRUCTIVE = true;

	public static function is_available(): bool {
		return function_exists( 'edd_git_download_updater' )
			&& function_exists( 'edd_software_licensing' )
			&& null !== edd_git_download_updater()->process_file;
	}

	protected function get_args(): array {

		return [
			'label'       => __( 'Release Product Version', 'edd-abilities' ),
			'description' => __( 'Ship a new version of a product that pulls its files from a connected GitHub or Bitbucket repository (the EDD Git Download Updater add-on): fetches the given tag, repackages it as the download, bumps the Software Licensing version, and prepends your changelog entry to the product\'s changelog. The product must already have a repository connected on its Files tab - this does not connect one. Not idempotent: calling it twice with the same version re-fetches the tag and prepends the changelog entry again.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id', 'version', 'changelog' ],
				'properties'           => [
					'id'        => [ 'type' => 'integer', 'description' => __( 'The product (download) ID. It must already have a git repository connected.', 'edd-abilities' ) ],
					'version'   => [ 'type' => 'string', 'minLength' => 1, 'description' => __( 'The exact tag to pull from the connected repository, e.g. "v1.4.0". Must already exist there - this does not create a tag.', 'edd-abilities' ) ],
					'changelog' => [ 'type' => 'string', 'minLength' => 1, 'description' => __( 'The complete HTML for this release\'s changelog entry, including its own heading - e.g. "<h4>1.4.0</h4><ul><li>...</li></ul>". Prepended as-is above the product\'s existing changelog.', 'edd-abilities' ) ],
				],
			],

			'output_schema' => [
				'type'       => 'object',
				'properties' => [
					'id'           => [ 'type' => 'integer' ],
					'product_name' => [ 'type' => 'string' ],
					'version'      => [ 'type' => 'string', 'description' => __( 'The tag that was pulled.', 'edd-abilities' ) ],
					'sl_version'   => [ 'type' => 'string', 'description' => __( 'The version now set in Software Licensing (the tag with a leading "v" stripped, if it had one).', 'edd-abilities' ) ],
					'provider'     => [ 'type' => 'string', 'enum' => [ 'github', 'bitbucket' ] ],
					'repo'         => [ 'type' => 'string', 'description' => __( 'The repository, as owner/name.', 'edd-abilities' ) ],
					'file'         => [ 'type' => 'string', 'description' => __( 'URL of the newly packaged download file.', 'edd-abilities' ) ],
					'file_name'    => [ 'type' => 'string' ],
					'readme_url'   => [ 'type' => [ 'string', 'null' ], 'description' => __( 'URL of the repo\'s readme.txt, if one was found and readme parsing is enabled. Not the source of the changelog - that always comes from the "changelog" input.', 'edd-abilities' ) ],
					'changelog'    => [ 'type' => 'string', 'description' => __( 'The product\'s full changelog after prepending, i.e. what customers and license checks now see.', 'edd-abilities' ) ],
					'date_shipped' => Schema::datetime_schema(),
				],
			],
		];
	}

	// Uses the flat `edit_products` capability (the base class default), the same one EDD's own
	// repo/tag-listing ajax actions require. The admin's final per-download fetch action actually
	// checks the narrower, per-object `edit_product`, but nothing else in this plugin does
	// object-scoped checks, and `edit_products` is already a privileged, non-default capability.

	public function __invoke( $input ) {

		$id      = absint( $input['id'] ?? 0 );
		$version = sanitize_text_field( $input['version'] ?? '' );

		if ( ! edd_get_download( $id ) ) {
			return $this->not_found( 'product', $id );
		}

		if ( empty( get_post_meta( $id, '_edd_download_use_git', true ) ) ) {
			return new \WP_Error( 'edd_abilities_not_git_enabled', __( 'This product is not set up to pull updates from git. Enable "Use Git" on its Files tab first.', 'edd-abilities' ) );
		}

		$files = get_post_meta( $id, 'edd_download_files', true );
		$files = is_array( $files ) ? $files : [];

		$configured = array_filter( $files, static function ( $file ) {
			return ! empty( $file['git_url'] );
		} );

		if ( ! $configured ) {
			return new \WP_Error( 'edd_abilities_no_git_repo', __( 'This product has no repository connected. Connect one on its Files tab first.', 'edd-abilities' ) );
		}

		// The git updater's own update routine replaces the WHOLE edd_download_files array with
		// just the one file entry it updates - a second, unrelated file would be silently deleted.
		// Refuse rather than risk that; this only ever updates a product with exactly one file.
		if ( count( $files ) > 1 ) {
			return new \WP_Error( 'edd_abilities_multiple_files', __( 'This product has more than one file. The git updater replaces the entire file list with just the one being updated, which would delete the others, so this has been refused. Remove the extra files, or update it from Downloads > Edit > Files instead.', 'edd-abilities' ) );
		}

		$file_key = array_key_first( $configured );
		$file     = $configured[ $file_key ];
		$repo_url = (string) $file['git_url'];

		$path     = wp_parse_url( $repo_url, PHP_URL_PATH );
		$segments = $path ? array_values( array_filter( explode( '/', $path ) ) ) : [];

		if ( count( $segments ) < 2 ) {
			return new \WP_Error( 'edd_abilities_invalid_repo', __( 'The connected repository URL is not valid.', 'edd-abilities' ) );
		}

		list( $repo_owner, $repo_name ) = $segments;

		$provider_id = false !== strpos( $repo_url, 'bitbucket.org' ) ? 'bitbucket' : 'github';

		$connected = 'bitbucket' === $provider_id
			? ( ( edd_get_option( 'bb_username' ) && edd_get_option( 'bb_app_password' ) ) || ( defined( 'EDD_GIT_BB_USER' ) && defined( 'EDD_GIT_BB_PASSWORD' ) ) )
			: (bool) edd_get_option( 'gh_access_token' );

		if ( ! $connected ) {
			/* translators: %s: "GitHub" or "Bitbucket" */
			return new \WP_Error( 'edd_abilities_git_not_connected', sprintf( __( 'No %s account is connected. Connect one under Downloads > Settings > Extensions > Git Download Updater first.', 'edd-abilities' ), 'bitbucket' === $provider_id ? 'Bitbucket' : 'GitHub' ) );
		}

		$entry = trim( wp_kses_post( $input['changelog'] ?? '' ) );

		if ( '' === $entry ) {
			return new \WP_Error( 'edd_abilities_empty_changelog', __( 'The changelog entry cannot be empty.', 'edd-abilities' ) );
		}

		try {
			$provider = edd_git_download_updater()->providerRegistry->getProvider( $provider_id );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'edd_abilities_invalid_provider', $e->getMessage() );
		}

		$updater = edd_git_download_updater();

		// Always pull the tag's own source archive - never a previously hand-picked release asset,
		// and never carry errors over from an earlier call in the same request.
		$updater->process_file->url    = null;
		$updater->process_file->errors = [];
		$updater->process_file->condition = $file['condition'] ?? 'all';

		try {
			$new_zip = $updater->process_file->process(
				$id,
				$version,
				$repo_url,
				$file_key,
				$file['git_folder_name'] ?? '',
				$file['name'] ?? '',
				$repo_owner,
				$repo_name,
				$provider
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'edd_abilities_release_failed',
				$e->getMessage() ?: __( 'The update could not be fetched.', 'edd-abilities' ),
				$updater->process_file->errors
			);
		}

		$existing          = stripslashes( (string) get_post_meta( $id, '_edd_sl_changelog', true ) );
		$updated_changelog = '' !== $existing ? $entry . "\n" . $existing : $entry;

		update_post_meta( $id, '_edd_sl_changelog', addslashes( $updated_changelog ) );

		return [
			'id'           => $id,
			'product_name' => get_the_title( $id ),
			'version'      => $version,
			'sl_version'   => (string) $updater->process_file->sl_version,
			'provider'     => $provider_id,
			'repo'         => $repo_owner . '/' . $repo_name,
			'file'         => (string) $new_zip['url'],
			'file_name'    => basename( $new_zip['path'] ),
			'readme_url'   => ! empty( $new_zip['readme'] ) ? $new_zip['readme'] : null,
			'changelog'    => $updated_changelog,
			'date_shipped' => Schema::datetime( current_time( 'mysql', true ) ),
		];
	}
}
