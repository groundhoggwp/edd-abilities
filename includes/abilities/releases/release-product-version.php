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
 * File" button does, and - if a changelog entry is given - prepends it to the product's Software
 * Licensing changelog. The changelog is optional, so this also doubles as "re-fetch and repackage
 * this product's tag" - e.g. to force-regenerate the file after fixing how it gets named, or to
 * pick up an amended tag - without touching the changelog at all.
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
			'description' => __( 'Ship a new version of a product that pulls its files from a connected GitHub or Bitbucket repository (the EDD Git Download Updater add-on): fetches the given tag, repackages it as the download, bumps the Software Licensing version, and - if given a changelog entry - prepends it to the product\'s changelog. Omit changelog (or leave it empty) to just re-fetch and repackage the tag without touching the changelog, e.g. to force-regenerate the file for a version already shipped. The product must already have a repository connected on its Files tab - this does not connect one. Not idempotent: calling it again always re-fetches and repackages, and if a changelog entry is given, prepends it again too.', 'edd-abilities' ),

			'input_schema' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => [ 'id', 'version' ],
				'properties'           => [
					'id'        => [ 'type' => 'integer', 'description' => __( 'The product (download) ID. It must already have a git repository connected.', 'edd-abilities' ) ],
					'version'   => [ 'type' => 'string', 'minLength' => 1, 'description' => __( 'The exact tag to pull from the connected repository, e.g. "v1.4.0". Must already exist there - this does not create a tag. May be the same tag already shipped, to force-regenerate the file.', 'edd-abilities' ) ],
					'changelog' => [ 'type' => 'string', 'default' => '', 'description' => __( 'The complete HTML for this release\'s changelog entry, including its own heading - e.g. "<h4>1.4.0</h4><ul><li>...</li></ul>". Prepended as-is above the product\'s existing changelog. Omit or leave empty to leave the changelog untouched - useful when re-fetching a tag just to regenerate the file.', 'edd-abilities' ) ],
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
					'changelog'    => [ 'type' => 'string', 'description' => __( 'The product\'s full changelog, i.e. what customers and license checks now see. Unchanged from before the call if no changelog entry was given.', 'edd-abilities' ) ],
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

		// Empty/omitted means "don't touch the changelog" - e.g. a plain re-fetch of an already
		// shipped tag - not an error.
		$entry = trim( wp_kses_post( $input['changelog'] ?? '' ) );

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

		// Always derive a fresh, version-stamped file name rather than reusing whatever name is
		// already stored. The git updater only auto-names the file when given an empty string - if
		// it's handed a name it uses it verbatim, version or no version - so blindly passing through
		// $file['name'] would keep re-saving the *previous* release's name forever (confirmed live:
		// shipping 1.3 over a product whose stored name was "repo-1.2.zip" produced a correctly
		// updated version, changelog and file *content*, but the output file was still named and
		// linked as "repo-1.2.zip").
		$file_name = sanitize_file_name( $repo_name . '-' . $version . '.zip' );

		try {
			$new_zip = $updater->process_file->process(
				$id,
				$version,
				$repo_url,
				$file_key,
				$file['git_folder_name'] ?? '',
				$file_name,
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

		// Save the new file and version ourselves. Git Download Updater 1.3.5+ no longer does: its
		// process() fetches and repackages the zip but only hands the result back, leaving the admin
		// form's Update button to save it (confirmed live: the zip was written and the changelog
		// saved, but the product still said 1.2). Older versions saved it themselves; writing the
		// same values again is harmless, so this works with either.
		$sl_version = (string) $updater->process_file->sl_version;

		update_post_meta( $id, 'edd_download_files', [
			$file_key => [
				'git_version'     => $version,
				'git_url'         => $repo_url,
				'git_folder_name' => $file['git_folder_name'] ?? '',
				'git_file_asset'  => (string) $updater->process_file->url,
				'file'            => (string) $new_zip['url'],
				'name'            => $updater->process_file->file_name ?: basename( $new_zip['path'] ),
				'condition'       => $file['condition'] ?? 'all',
				'attachment_id'   => 0,
			],
		] );

		update_post_meta( $id, '_edd_sl_version', $sl_version );

		// Read it back. Something else on a site can block or undo meta writes, and reporting success
		// for a release that didn't take is worse than an error - and we stop before touching the
		// changelog so it can't announce a version the product doesn't have.
		$saved_files = get_post_meta( $id, 'edd_download_files', true );

		if ( ( $saved_files[ $file_key ]['file'] ?? '' ) !== (string) $new_zip['url'] || (string) get_post_meta( $id, '_edd_sl_version', true ) !== $sl_version ) {
			return new \WP_Error( 'edd_abilities_not_saved', __( 'The new version was fetched and packaged, but the product\'s file and version did not save - something on this site is blocking or reverting changes to them. The changelog was not changed.', 'edd-abilities' ), [ 'file' => $new_zip['url'] ] );
		}

		$existing          = stripslashes( (string) get_post_meta( $id, '_edd_sl_changelog', true ) );
		$updated_changelog = $existing;

		if ( '' !== $entry ) {
			$updated_changelog = '' !== $existing ? $entry . "\n" . $existing : $entry;

			update_post_meta( $id, '_edd_sl_changelog', addslashes( $updated_changelog ) );
		}

		return [
			'id'           => $id,
			'product_name' => get_the_title( $id ),
			'version'      => $version,
			'sl_version'   => $sl_version,
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
