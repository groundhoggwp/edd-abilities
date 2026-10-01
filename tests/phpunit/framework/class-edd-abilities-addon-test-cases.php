<?php
/**
 * Base test cases for abilities that only exist when an EDD add-on is active. Each one skips
 * itself when its add-on wasn't loaded by the bootstrap, so the suite still runs on a machine
 * that only has EDD.
 *
 * @package EDD_Abilities
 */

/**
 * Software Licensing.
 */
abstract class EDD_Abilities_Licensing_Test_Case extends EDD_Abilities_Test_Case {

	public function set_up(): void {

		if ( ! function_exists( 'edd_software_licensing' ) ) {
			$this->markTestSkipped( 'EDD Software Licensing is not loaded (set EDD_SL_DIR).' );
		}

		parent::set_up();
	}

	/**
	 * A product with licensing switched on.
	 *
	 * @param array $args post fields, plus 'limit' (activation limit)
	 *
	 * @return int
	 */
	protected function create_licensed_product( array $args = [] ): int {

		$limit = $args['limit'] ?? 2;
		unset( $args['limit'] );

		$id = $this->create_product( $args );

		update_post_meta( $id, '_edd_sl_enabled', 1 );
		update_post_meta( $id, '_edd_sl_limit', $limit );
		update_post_meta( $id, '_edd_sl_exp_length', 1 );
		update_post_meta( $id, '_edd_sl_exp_unit', 'years' );

		return $id;
	}

	/**
	 * Issue a license the way a purchase does, through SL's own create().
	 *
	 * @param int   $product_id a licensed product
	 * @param int   $order_id   the order it was bought on; created if 0
	 * @param array $options    SL creation options: activation_limit, is_lifetime, expiration_date, ...
	 *
	 * @return int license id
	 */
	protected function create_license( int $product_id, int $order_id = 0, array $options = [] ): int {

		if ( ! $order_id ) {
			$order_id = $this->create_order( [ [ 'product_id' => $product_id, 'subtotal' => 20.0 ] ] );
		}

		$ids = ( new EDD_SL_License() )->create( $product_id, $order_id, false, 0, $options );

		$this->assertNotEmpty( $ids, 'Software Licensing did not issue a license.' );

		return (int) $ids[0];
	}
}

/**
 * Recurring Payments.
 */
abstract class EDD_Abilities_Recurring_Test_Case extends EDD_Abilities_Test_Case {

	public function set_up(): void {

		if ( ! class_exists( 'EDD_Subscription' ) || ! class_exists( 'EDD_Subscriptions_DB' ) ) {
			$this->markTestSkipped( 'EDD Recurring Payments is not loaded (set EDD_RECURRING_DIR).' );
		}

		parent::set_up();
	}

	/**
	 * Create a subscription, with its parent order, through EDD Recurring's own create().
	 *
	 * @param array $args subscription fields; product_id / customer_id / parent_payment_id are
	 *                    created when not given
	 *
	 * @return EDD_Subscription
	 */
	protected function create_subscription( array $args = [] ): EDD_Subscription {

		$product_id  = $args['product_id'] ?? $this->create_product( [ 'price' => '10.00' ] );
		$customer_id = $args['customer_id'] ?? $this->create_customer();

		$order_id = $args['parent_payment_id'] ?? $this->create_order(
			[ [ 'product_id' => $product_id, 'subtotal' => 10.0 ] ],
			[ 'customer_id' => $customer_id ]
		);

		$subscription = new EDD_Subscription();
		$subscription->create( array_merge( [
			'customer_id'        => $customer_id,
			'period'             => 'month',
			'initial_amount'     => '10.00',
			'recurring_amount'   => '10.00',
			'bill_times'         => 0,
			'parent_payment_id'  => $order_id,
			'product_id'         => $product_id,
			'price_id'           => null,
			'created'            => gmdate( 'Y-m-d H:i:s' ),
			'expiration'         => gmdate( 'Y-m-d 23:59:59', strtotime( '+1 month' ) ),
			'status'             => 'active',
			'profile_id'         => 'profile_' . wp_generate_password( 8, false ),
		], $args, [
			'product_id'        => $product_id,
			'customer_id'       => $customer_id,
			'parent_payment_id' => $order_id,
		] ) );

		$this->assertNotEmpty( $subscription->id, 'EDD Recurring did not create the subscription.' );

		return $subscription;
	}
}

/**
 * Git Download Updater (paired with Software Licensing - edd/release-product-version needs both).
 *
 * Never makes a real request to GitHub or Bitbucket. mock_zipball()/mock_zipball_not_found() make the
 * Git Download Updater's own HTTP call return a fixture instead, by registering a `pre_http_request`
 * filter at a higher priority than the blanket "no real HTTP" one every test already runs under
 * (see bootstrap.php) - that one still runs first and still blocks every other request, this one
 * only overrides it for a matching `/zipball/` URL.
 */
abstract class EDD_Abilities_Release_Test_Case extends EDD_Abilities_Test_Case {

	/**
	 * pre_http_request callbacks this test registered, so tear_down() removes exactly these.
	 *
	 * @var callable[]
	 */
	private $http_mocks = [];

	/**
	 * Fixture zip scratch directories to remove in tear_down(), separate from (and never touched
	 * by) the git updater's own tmp dir, which it cleans up itself.
	 *
	 * @var string[]
	 */
	private $fixture_dirs = [];

	public function set_up(): void {

		if ( ! function_exists( 'edd_git_download_updater' )
			|| ! function_exists( 'edd_software_licensing' )
			|| null === edd_git_download_updater()->process_file
		) {
			$this->markTestSkipped( 'EDD Git Download Updater (with Software Licensing) is not loaded (set EDD_GIT_DIR).' );
		}

		parent::set_up();
	}

	public function tear_down(): void {

		foreach ( $this->http_mocks as $mock ) {
			remove_filter( 'pre_http_request', $mock, 20 );
		}

		$this->http_mocks = [];

		foreach ( $this->fixture_dirs as $dir ) {
			$this->rrmdir( $dir );
		}

		$this->fixture_dirs = [];

		parent::tear_down();
	}

	/**
	 * @param string $dir
	 *
	 * @return void
	 */
	private function rrmdir( string $dir ): void {

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}

		rmdir( $dir );
	}

	/**
	 * A product configured to pull updates from a (fake) GitHub repo, with a GitHub account connected.
	 *
	 * @param array $args post fields, plus 'repo' ("owner/name", default "acme/widget") and
	 *                    'condition' (default 'all')
	 *
	 * @return int
	 */
	protected function create_git_product( array $args = [] ): int {

		$repo      = $args['repo'] ?? 'acme/widget';
		$condition = $args['condition'] ?? 'all';
		unset( $args['repo'], $args['condition'] );

		$id = $this->create_product( $args );

		update_post_meta( $id, '_edd_download_use_git', 1 );
		update_post_meta( $id, 'edd_download_files', [
			0 => [
				'git_version'     => '',
				'git_url'         => 'https://github.com/' . $repo,
				'git_folder_name' => '',
				'git_file_asset'  => '',
				// EDD's own sanitizer (EDD_Register_Meta::remove_blank_rows()) deletes a file row
				// outright if name, amount and file are ALL empty - a real "connected but not yet
				// fetched" row always has a name for this reason, so the fixture needs one too.
				'file'            => '',
				'name'            => 'download.zip',
				'condition'       => $condition,
				'attachment_id'   => 0,
			],
		] );

		edd_update_option( 'gh_access_token', 'fake-token-for-tests' );

		return $id;
	}

	/**
	 * Build a tiny real zip whose single top-level folder name contains $repo_name, the way a
	 * GitHub zipball's does, and make the next `/zipball/` request return it instead of hitting
	 * the network.
	 *
	 * @param string $repo_name e.g. "widget" for a repo "acme/widget" - must appear in the folder name
	 * @param array  $files     relative path => contents, written inside that folder
	 *
	 * @return void
	 */
	protected function mock_zipball( string $repo_name, array $files = [ 'plugin.php' => "<?php\n// fixture\n" ] ): void {

		$tmp_dir = trailingslashit( get_temp_dir() ) . 'edd-abilities-test-' . wp_generate_password( 8, false ) . '/';
		$folder  = 'acme-' . $repo_name . '-abc1234';

		$this->fixture_dirs[] = $tmp_dir;

		wp_mkdir_p( $tmp_dir . $folder );

		foreach ( $files as $relative_path => $contents ) {
			$path = $tmp_dir . $folder . '/' . $relative_path;
			wp_mkdir_p( dirname( $path ) );
			file_put_contents( $path, $contents );
		}

		$zip_path = $tmp_dir . 'fixture.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $tmp_dir . $folder, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$zip->addFile( $file->getPathname(), $folder . '/' . substr( $file->getPathname(), strlen( $tmp_dir . $folder ) + 1 ) );
		}

		$zip->close();

		$this->add_zipball_mock( file_get_contents( $zip_path ), 200, 'OK' );
	}

	/**
	 * Makes the next `/zipball/` request fail with a 404, the way an unknown tag would.
	 *
	 * @return void
	 */
	protected function mock_zipball_not_found(): void {
		$this->add_zipball_mock( wp_json_encode( [ 'message' => 'Not Found' ] ), 404, 'Not Found' );
	}

	/**
	 * @param string $body
	 * @param int    $code
	 * @param string $message
	 *
	 * @return void
	 */
	private function add_zipball_mock( string $body, int $code, string $message ): void {

		$mock = function ( $preempt, $args, $url ) use ( $body, $code, $message ) {

			// GitHub: .../repos/{owner}/{repo}/zipball/{tag}. Bitbucket: bitbucket.org/{owner}/{repo}/get/{tag}.zip.
			$is_zip_request = false !== strpos( $url, '/zipball/' )
				|| ( false !== strpos( $url, 'bitbucket.org' ) && false !== strpos( $url, '/get/' ) );

			if ( ! $is_zip_request ) {
				return $preempt;
			}

			return [
				// Bitbucket's provider additionally checks this on a successful response.
				'headers'  => [ 'content-type' => 'application/zip' ],
				'body'     => $body,
				'response' => [ 'code' => $code, 'message' => $message ],
				'cookies'  => [],
				'filename' => null,
			];
		};

		add_filter( 'pre_http_request', $mock, 20, 3 );
		$this->http_mocks[] = $mock;
	}
}
