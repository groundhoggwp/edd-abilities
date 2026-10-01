<?php

/**
 * edd/release-product-version. Skipped unless both EDD Software Licensing and the EDD Git Download
 * Updater are loaded. Never makes a real request to GitHub or Bitbucket - see mock_zipball() in
 * EDD_Abilities_Release_Test_Case.
 */
class Releases_Test extends EDD_Abilities_Release_Test_Case {

	public function test_the_release_ability_is_registered() {
		$this->assertTrue( wp_has_ability( 'edd/release-product-version' ) );
		$this->assertSame( 'edd-releases', wp_get_ability( 'edd/release-product-version' )->get_category() );
	}

	/* ---------------------------------------------------------------------------------------- */
	/* happy path                                                                               */
	/* ---------------------------------------------------------------------------------------- */

	public function test_releasing_a_version_fetches_the_tag_and_prepends_the_changelog() {

		$id = $this->create_git_product( [ 'post_title' => 'Widget', 'repo' => 'acme/widget' ] );

		update_post_meta( $id, '_edd_sl_changelog', addslashes( '<h4>1.0.0</h4><ul><li>First release.</li></ul>' ) );

		$this->mock_zipball( 'widget' );

		$result = $this->run_ok( 'edd/release-product-version', [
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

	public function test_releasing_the_first_version_needs_no_existing_changelog() {

		$id = $this->create_git_product();
		$this->mock_zipball( 'widget' );

		$result = $this->run_ok( 'edd/release-product-version', [
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

		$result = $this->run_ok( 'edd/release-product-version', [
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

		$result = $this->run_ok( 'edd/release-product-version', [
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
		$this->assertAbilityError( 'edd_abilities_not_found', $this->run_ability( 'edd/release-product-version', [ 'id' => 999999, 'version' => '1.0.0', 'changelog' => 'x' ] ) );
	}

	public function test_refuses_a_product_that_is_not_git_enabled() {

		$id = $this->create_product();

		$this->assertAbilityError( 'edd_abilities_not_git_enabled', $this->run_ability( 'edd/release-product-version', [ 'id' => $id, 'version' => '1.0.0', 'changelog' => 'x' ] ) );
	}

	public function test_refuses_a_git_enabled_product_with_no_repo_configured() {

		$id = $this->create_product();
		update_post_meta( $id, '_edd_download_use_git', 1 );
		update_post_meta( $id, 'edd_download_files', [ 0 => [ 'git_url' => '', 'condition' => 'all' ] ] );

		$this->assertAbilityError( 'edd_abilities_no_git_repo', $this->run_ability( 'edd/release-product-version', [ 'id' => $id, 'version' => '1.0.0', 'changelog' => 'x' ] ) );
	}

	public function test_refuses_a_product_with_more_than_one_file_entry_to_avoid_deleting_the_others() {

		$id = $this->create_git_product();
		$files = get_post_meta( $id, 'edd_download_files', true );
		$files[1] = [ 'name' => 'A second, unrelated file', 'file' => 'https://example.com/other.zip', 'condition' => 'all' ];
		update_post_meta( $id, 'edd_download_files', $files );

		$this->assertAbilityError( 'edd_abilities_multiple_files', $this->run_ability( 'edd/release-product-version', [ 'id' => $id, 'version' => '1.0.0', 'changelog' => 'x' ] ) );
		$this->assertCount( 2, get_post_meta( $id, 'edd_download_files', true ), 'Refusing must leave both file entries untouched.' );
	}

	public function test_refuses_when_no_git_account_is_connected() {

		$id = $this->create_product();
		update_post_meta( $id, '_edd_download_use_git', 1 );
		update_post_meta( $id, 'edd_download_files', [ 0 => [ 'git_url' => 'https://github.com/acme/widget', 'condition' => 'all', 'name' => 'download.zip' ] ] );

		// EDD's settings cache is in-memory and outlives a test's DB rollback, so an earlier test
		// connecting a GitHub account (create_git_product()) would otherwise leak into this one.
		edd_delete_option( 'gh_access_token' );

		$this->assertAbilityError( 'edd_abilities_git_not_connected', $this->run_ability( 'edd/release-product-version', [ 'id' => $id, 'version' => '1.0.0', 'changelog' => 'x' ] ) );
	}

	public function test_refuses_an_empty_changelog_without_fetching_anything() {

		$id = $this->create_git_product();
		$this->mock_zipball( 'widget' );

		$this->assertAbilityError( 'edd_abilities_empty_changelog', $this->run_ability( 'edd/release-product-version', [ 'id' => $id, 'version' => '1.0.0', 'changelog' => '   ' ] ) );

		$files = get_post_meta( $id, 'edd_download_files', true );
		$this->assertEmpty( $files[0]['git_version'] ?? '', 'Nothing should have been fetched before the changelog was validated.' );
	}

	public function test_schema_rejects_missing_required_fields() {

		$id = $this->create_git_product();

		foreach ( [
			[ 'version' => '1.0.0', 'changelog' => 'x' ],
			[ 'id' => $id, 'changelog' => 'x' ],
			[ 'id' => $id, 'version' => '1.0.0' ],
		] as $input ) {
			$this->assertAbilityError( 'ability_invalid_input', $this->run_ability( 'edd/release-product-version', $input ) );
		}
	}

	/* ---------------------------------------------------------------------------------------- */
	/* the fetch itself fails                                                                   */
	/* ---------------------------------------------------------------------------------------- */

	public function test_an_unknown_tag_is_a_clean_error_and_changes_nothing() {

		$id = $this->create_git_product();
		$this->mock_zipball_not_found();

		$result = $this->run_ability( 'edd/release-product-version', [
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

		$this->assertAbilityError( 'ability_invalid_permissions', $this->run_ability( 'edd/release-product-version', [
			'id'        => $id,
			'version'   => '1.0.0',
			'changelog' => '<h4>1.0.0</h4>',
		] ) );

		$files = get_post_meta( $id, 'edd_download_files', true );
		$this->assertEmpty( $files[0]['git_version'] ?? '' );
	}
}
