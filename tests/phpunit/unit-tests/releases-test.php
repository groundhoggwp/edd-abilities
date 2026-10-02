<?php

/**
 * edd-alt/release-product-version. Skipped unless both EDD Software Licensing and the EDD Git Download
 * Updater are loaded. Never makes a real request to GitHub or Bitbucket - see mock_zipball() in
 * EDD_Abilities_Release_Test_Case.
 */
class Releases_Test extends EDD_Abilities_Release_Test_Case {

	public function test_the_release_ability_is_registered() {
		$this->assertTrue( wp_has_ability( 'edd-alt/release-product-version' ) );
		$this->assertSame( 'edd-alt-releases', wp_get_ability( 'edd-alt/release-product-version' )->get_category() );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* happy path                                                                               */
	/* ---------------------------------------------------------------------------------------- */

	public function test_releasing_a_version_fetches_the_tag_and_prepends_the_changelog() {

		$id = $this->create_git_product( [ 'post_title' => 'Widget', 'repo' => 'acme/widget' ] );

		update_post_meta( $id, '_edd_sl_changelog', addslashes( '<h4>1.0.0</h4><ul><li>First release.</li></ul>' ) );

		$this->mock_zipball( 'widget' );

		$result = $this->run_ok( 'edd-alt/release-product-version', [
			'id'        => $id,
			'version'   => 'v1.1.0',
			'changelog' => '<h4>1.1.0</h4><ul><li>Fixed a bug.</li></ul>',
		] );

		$this->assertSame( $id, $result['id'] );
		$this->assertSame( 'Widget', $result['product_name'] );
		$this->assertSame( 'v1.1.0', $result['version'] );
		$this->assertSame( '1.1.0', $result['sl_version'], 'A leading "v" is stripped for the Software Licensing version.' );
		$this->assertSame( 'github', $result['provider'] );
		$this->assertSame( 'acme/widget', $result['repo'] );
		$this->assertNotEmpty( $result['file'] );
		$this->assertStringEndsWith( '.zip', $result['file_name'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T/', $result['date_shipped']['utc'] );

		// The new entry is prepended above the old one, both present, new one first.
		$this->assertSame( 0, strpos( $result['changelog'], '<h4>1.1.0</h4>' ) );
		$this->assertStringContainsString( '<h4>1.0.0</h4>', $result['changelog'] );
		$this->assertLessThan( strpos( $result['changelog'], '1.0.0' ), strpos( $result['changelog'], '1.1.0' ) );

		// Persisted the same way Software Licensing's own metabox does (addslashes on save).
		$stored = get_post_meta( $id, '_edd_sl_changelog', true );
		$this->assertSame( $result['changelog'], stripslashes( $stored ) );

		// The download file meta and SL version were actually updated, not just reported.
		$this->assertSame( '1.1.0', get_post_meta( $id, '_edd_sl_version', true ) );
		$files = get_post_meta( $id, 'edd_download_files', true );
		$this->assertSame( 'v1.1.0', $files[0]['git_version'] );
		$this->assertNotEmpty( $files[0]['file'] );
	}

	public function test_releasing_a_version_never_reuses_a_stale_version_baked_into_the_stored_file_name() {

		// Reproduces a live bug: a product whose file name was last set to "repo-1.2.zip" (either by
		// a prior release or by hand) kept being re-saved under that exact name forever, because the
		// git updater only auto-names the file when given an empty string - handed a name, even a
		// stale one, it uses it verbatim. The version, changelog and fetched *content* all updated
		// correctly; only the file name (and so its URL) stayed wrong.
		$id = $this->create_git_product( [ 'repo' => 'acme/widget' ] );

		$files              = get_post_meta( $id, 'edd_download_files', true );
		$files[0]['name']   = 'widget-1.2.zip';
		update_post_meta( $id, 'edd_download_files', $files );

		$this->mock_zipball( 'widget' );

		$result = $this->run_ok( 'edd-alt/release-product-version', [
			'id'        => $id,
			'version'   => '1.3',
			'changelog' => '<h4>1.3</h4>',
		] );

		$this->assertSame( 'widget-1.3.zip', $result['file_name'] );
		$this->assertStringContainsString( 'widget-1.3.zip', $result['file'] );
		$this->assertStringNotContainsString( '1.2', $result['file_name'] );

		$stored_files = get_post_meta( $id, 'edd_download_files', true );
		$this->assertSame( 'widget-1.3.zip', $stored_files[0]['name'], 'The stale name must not be carried forward to the next release either.' );
	}

	public function test_the_new_file_and_version_are_actually_saved_to_the_product() {

		// Git Download Updater 1.3.5+ fetches and repackages the zip but no longer saves it to the
		// product (the admin form's Update button does that), so the ability has to. Reading these back
		// through the database, not from the ability's own response, is the point.
		$id = $this->create_git_product( [ 'repo' => 'acme/widget' ] );
		$this->mock_zipball( 'widget' );

		$result = $this->run_ok( 'edd-alt/release-product-version', [ 'id' => $id, 'version' => 'v2.0.0', 'changelog' => '<h4>2.0.0</h4>' ] );

		wp_cache_flush();

		$files = get_post_meta( $id, 'edd_download_files', true );

		$this->assertSame( '2.0.0', get_post_meta( $id, '_edd_sl_version', true ) );
		$this->assertSame( 'v2.0.0', $files[0]['git_version'] );
		$this->assertSame( $result['file'], $files[0]['file'] );
		$this->assertSame( 'widget-v2.0.0.zip', $files[0]['name'] );
		$this->assertSame( 'https://github.com/acme/widget', $files[0]['git_url'] );
		$this->assertSame( 'all', $files[0]['condition'] );
	}

	/**
	 * @dataProvider blockable_meta_keys
	 */
	public function test_a_release_that_cannot_be_saved_is_an_error_and_leaves_the_changelog_alone( string $blocked_key ) {

		$id = $this->create_git_product( [ 'repo' => 'acme/widget' ] );
		update_post_meta( $id, '_edd_sl_changelog', addslashes( '<h4>1.0.0</h4>' ) );
		$this->mock_zipball( 'widget' );

		// Something on the site (a security plugin, a snippet) refuses writes to this key.
		add_filter( 'update_post_metadata', function ( $check, $object_id, $meta_key ) use ( $blocked_key ) {
			return $meta_key === $blocked_key ? false : $check;
		}, 10, 3 );

		$result = $this->run_ability( 'edd-alt/release-product-version', [ 'id' => $id, 'version' => '1.1.0', 'changelog' => '<h4>1.1.0</h4>' ] );

		$this->assertAbilityError( 'edd_abilities_not_saved', $result );
		$this->assertSame( '<h4>1.0.0</h4>', stripslashes( get_post_meta( $id, '_edd_sl_changelog', true ) ), 'The changelog must not announce a version that did not save.' );
	}

	public function blockable_meta_keys(): array {
		return [
			'the version'  => [ '_edd_sl_version' ],
			'the file'     => [ 'edd_download_files' ],
		];
	}

	public function test_releasing_the_first_version_needs_no_existing_changelog() {

		$id = $this->create_git_product();
		$this->mock_zipball( 'widget' );

		$result = $this->run_ok( 'edd-alt/release-product-version', [
			'id'        => $id,
			'version'   => '1.0.0',
			'changelog' => '<h4>1.0.0</h4><ul><li>First release.</li></ul>',
		] );

		$this->assertSame( '<h4>1.0.0</h4><ul><li>First release.</li></ul>', $result['changelog'] );
		$this->assertSame( '1.0.0', $result['sl_version'], 'No leading "v" to strip.' );
	}

	public function test_changelog_html_is_sanitised() {

		$id = $this->create_git_product( [ 'repo' => 'acme/widget' ] );
		$this->mock_zipball( 'widget' );

		$result = $this->run_ok( 'edd-alt/release-product-version', [
			'id'        => $id,
			'version'   => '1.0.0',
			'changelog' => '<h4>1.0.0</h4><script>alert(1)</script><ul><li>Fixed things.</li></ul>',
		] );

		$this->assertStringNotContainsString( '<script>', $result['changelog'] );
		$this->assertStringContainsString( '<h4>1.0.0</h4>', $result['changelog'] );
		$this->assertStringContainsString( 'Fixed things.', $result['changelog'] );
	}

	public function test_bitbucket_repositories_are_detected_and_supported() {

		edd_update_option( 'bb_username', 'acme' );
		edd_update_option( 'bb_app_password', 'fake-app-password' );

		$id = $this->create_product();
		update_post_meta( $id, '_edd_download_use_git', 1 );
		update_post_meta( $id, 'edd_download_files', [
			0 => [ 'git_url' => 'https://bitbucket.org/acme/widget', 'condition' => 'all', 'name' => 'download.zip' ],
		] );

		$this->mock_zipball( 'widget' );

		$result = $this->run_ok( 'edd-alt/release-product-version', [
			'id'        => $id,
			'version'   => '1.0.0',
			'changelog' => '<h4>1.0.0</h4>',
		] );

		$this->assertSame( 'bitbucket', $result['provider'] );
		$this->assertSame( 'acme/widget', $result['repo'] );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* guard clauses - none of these should touch the network or the product's meta             */
	/* ---------------------------------------------------------------------------------------- */

	public function test_refuses_an_unknown_product() {
		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd-alt/release-product-version', [ 'id' => 999999, 'version' => '1.0.0', 'changelog' => 'x' ] ) );
	}

	public function test_refuses_a_product_that_is_not_git_enabled() {

		$id = $this->create_product();

		$this->assertAbilityError( 'edd_abilities_not_git_enabled', $this->run_ability( 'edd-alt/release-product-version', [ 'id' => $id, 'version' => '1.0.0', 'changelog' => 'x' ] ) );
	}

	public function test_refuses_a_git_enabled_product_with_no_repo_configured() {

		$id = $this->create_product();
		update_post_meta( $id, '_edd_download_use_git', 1 );
		update_post_meta( $id, 'edd_download_files', [ 0 => [ 'git_url' => '', 'condition' => 'all' ] ] );

		$this->assertAbilityError( 'edd_abilities_no_git_repo', $this->run_ability( 'edd-alt/release-product-version', [ 'id' => $id, 'version' => '1.0.0', 'changelog' => 'x' ] ) );
	}

	public function test_refuses_a_product_with_more_than_one_file_entry_to_avoid_deleting_the_others() {

		$id = $this->create_git_product();
		$files = get_post_meta( $id, 'edd_download_files', true );
		$files[1] = [ 'name' => 'A second, unrelated file', 'file' => 'https://example.com/other.zip', 'condition' => 'all' ];
		update_post_meta( $id, 'edd_download_files', $files );

		$this->assertAbilityError( 'edd_abilities_multiple_files', $this->run_ability( 'edd-alt/release-product-version', [ 'id' => $id, 'version' => '1.0.0', 'changelog' => 'x' ] ) );
		$this->assertCount( 2, get_post_meta( $id, 'edd_download_files', true ), 'Refusing must leave both file entries untouched.' );
	}

	public function test_refuses_when_no_git_account_is_connected() {

		$id = $this->create_product();
		update_post_meta( $id, '_edd_download_use_git', 1 );
		update_post_meta( $id, 'edd_download_files', [ 0 => [ 'git_url' => 'https://github.com/acme/widget', 'condition' => 'all', 'name' => 'download.zip' ] ] );

		// EDD's settings cache is in-memory and outlives a test's DB rollback, so an earlier test
		// connecting a GitHub account (create_git_product()) would otherwise leak into this one.
		edd_delete_option( 'gh_access_token' );

		$this->assertAbilityError( 'edd_abilities_git_not_connected', $this->run_ability( 'edd-alt/release-product-version', [ 'id' => $id, 'version' => '1.0.0', 'changelog' => 'x' ] ) );
	}

	public function test_an_omitted_or_blank_changelog_is_skipped_not_an_error() {

		$id = $this->create_git_product();
		update_post_meta( $id, '_edd_sl_changelog', addslashes( '<h4>0.9.0</h4><ul><li>Existing.</li></ul>' ) );

		$this->mock_zipball( 'widget' );
		$omitted = $this->run_ok( 'edd-alt/release-product-version', [ 'id' => $id, 'version' => '1.0.0' ] );

		$this->assertSame( '<h4>0.9.0</h4><ul><li>Existing.</li></ul>', $omitted['changelog'], 'Unchanged when changelog is omitted.' );
		$this->assertSame( '<h4>0.9.0</h4><ul><li>Existing.</li></ul>', stripslashes( get_post_meta( $id, '_edd_sl_changelog', true ) ) );

		// The fetch itself still happened.
		$this->assertSame( '1.0.0', get_post_meta( $id, '_edd_sl_version', true ) );

		$this->mock_zipball( 'widget' );
		$blank = $this->run_ok( 'edd-alt/release-product-version', [ 'id' => $id, 'version' => '1.1.0', 'changelog' => '   ' ] );

		$this->assertSame( '<h4>0.9.0</h4><ul><li>Existing.</li></ul>', $blank['changelog'], 'Unchanged when changelog is blank.' );
		$this->assertSame( '1.1.0', get_post_meta( $id, '_edd_sl_version', true ) );
	}

	public function test_an_omitted_changelog_can_force_regenerate_an_already_shipped_version() {

		// Reproduces the live cleanup case: a product whose stored file name is stale for the
		// version it claims (see the file-name regression test above). Re-run the same tag with no
		// changelog to fix just the file, without re-prepending the changelog entry already shipped.
		$id = $this->create_git_product( [ 'repo' => 'acme/widget' ] );
		update_post_meta( $id, '_edd_sl_changelog', addslashes( '<h4>1.3</h4><ul><li>Already shipped.</li></ul>' ) );

		$files            = get_post_meta( $id, 'edd_download_files', true );
		$files[0]['name'] = 'widget-1.2.zip'; // stale, as if a prior release mis-named it
		update_post_meta( $id, 'edd_download_files', $files );

		$this->mock_zipball( 'widget' );

		$result = $this->run_ok( 'edd-alt/release-product-version', [ 'id' => $id, 'version' => '1.3' ] );

		$this->assertSame( 'widget-1.3.zip', $result['file_name'] );
		$this->assertSame( '<h4>1.3</h4><ul><li>Already shipped.</li></ul>', $result['changelog'], 'Re-running with no changelog must not duplicate the entry.' );
	}

	public function test_schema_rejects_a_missing_id_or_version_but_changelog_is_optional() {

		$id = $this->create_git_product();

		foreach ( [
			[ 'version' => '1.0.0', 'changelog' => 'x' ],
			[ 'id' => $id, 'changelog' => 'x' ],
		] as $input ) {
			$this->assertAbilityError( 'ability_invalid_input', $this->run_ability( 'edd-alt/release-product-version', $input ) );
		}

		$this->mock_zipball( 'widget' );
		$this->run_ok( 'edd-alt/release-product-version', [ 'id' => $id, 'version' => '1.0.0' ] );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* the fetch itself fails                                                                   */
	/* ---------------------------------------------------------------------------------------- */

	public function test_an_unknown_tag_is_a_clean_error_and_changes_nothing() {

		$id = $this->create_git_product();
		$this->mock_zipball_not_found();

		$result = $this->run_ability( 'edd-alt/release-product-version', [
			'id'        => $id,
			'version'   => 'v9.9.9',
			'changelog' => '<h4>9.9.9</h4>',
		] );

		$this->assertAbilityError( 'edd_abilities_release_failed', $result );

		$files = get_post_meta( $id, 'edd_download_files', true );
		$this->assertEmpty( $files[0]['git_version'] ?? '', 'A failed fetch must not touch the file meta.' );
		$this->assertEmpty( get_post_meta( $id, '_edd_sl_changelog', true ), 'A failed fetch must not touch the changelog either.' );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* permissions                                                                              */
	/* ---------------------------------------------------------------------------------------- */

	public function test_needs_edit_products() {

		$id = $this->create_git_product();
		$this->mock_zipball( 'widget' );

		wp_set_current_user( $this->create_shop_user( [ 'edit_shop_payments', 'view_shop_reports', 'manage_licenses' ] ) );

		$this->assertAbilityError( 'ability_invalid_permissions', $this->run_ability( 'edd-alt/release-product-version', [
			'id'        => $id,
			'version'   => '1.0.0',
			'changelog' => '<h4>1.0.0</h4>',
		] ) );

		$files = get_post_meta( $id, 'edd_download_files', true );
		$this->assertEmpty( $files[0]['git_version'] ?? '' );
	}
}
