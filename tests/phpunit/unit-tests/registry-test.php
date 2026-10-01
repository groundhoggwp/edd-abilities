<?php

use EDD_Abilities\Registry;

/**
 * Registration, annotations and permissions across every ability.
 */
class Registry_Test extends EDD_Abilities_Test_Case {

	/**
	 * Abilities that must exist whenever EDD itself is active.
	 */
	protected const CORE_ABILITIES = [
		'edd/list-orders'          => 'edd-orders',
		'edd/get-order'            => 'edd-orders',
		'edd/update-order-status'  => 'edd-orders',
		'edd/refund-order'         => 'edd-orders',
		'edd/add-order-note'       => 'edd-orders',
		'edd/search-customers'     => 'edd-customers',
		'edd/get-customer'         => 'edd-customers',
		'edd/create-customer'      => 'edd-customers',
		'edd/update-customer'      => 'edd-customers',
		'edd/list-products'        => 'edd-products',
		'edd/get-product'          => 'edd-products',
		'edd/list-discounts'       => 'edd-discounts',
		'edd/get-discount'         => 'edd-discounts',
		'edd/create-discount'      => 'edd-discounts',
		'edd/update-discount-status' => 'edd-discounts',
		'edd/get-store-stats'      => 'edd-reports',
	];

	public function test_core_abilities_are_registered_in_their_category() {

		foreach ( self::CORE_ABILITIES as $name => $category ) {
			$ability = wp_get_ability( $name );

			$this->assertNotNull( $ability, "$name is not registered" );
			$this->assertSame( $category, $ability->get_category(), "$name is in the wrong category" );
		}
	}

	/**
	 * Abilities that only exist when an add-on is active, with the category they belong to.
	 */
	protected const ADDON_ABILITIES = [
		'software-licensing' => [
			'edd/list-licenses'         => 'edd-licenses',
			'edd/get-license'           => 'edd-licenses',
			'edd/update-license-status' => 'edd-licenses',
		],
		'recurring'          => [
			'edd/list-subscriptions' => 'edd-subscriptions',
			'edd/get-subscription'   => 'edd-subscriptions',
			'edd/cancel-subscription' => 'edd-subscriptions',
		],
		// Needs Software Licensing AND the Git Download Updater, so it's kept out of the generic
		// single-addon loop below and checked on its own in test_the_release_ability_needs_both_addons().
	];

	protected function addon_is_active( string $addon ): bool {
		return 'software-licensing' === $addon
			? function_exists( 'edd_software_licensing' )
			: class_exists( 'EDD_Subscription' );
	}

	protected function release_addons_are_active(): bool {
		return function_exists( 'edd_git_download_updater' )
			&& function_exists( 'edd_software_licensing' )
			&& null !== edd_git_download_updater()->process_file;
	}

	/**
	 * Every ability that should be registered in this run: the core ones plus those of active add-ons.
	 *
	 * @return string[]
	 */
	protected function expected_abilities(): array {

		$names = array_keys( self::CORE_ABILITIES );

		foreach ( self::ADDON_ABILITIES as $addon => $abilities ) {
			if ( $this->addon_is_active( $addon ) ) {
				$names = array_merge( $names, array_keys( $abilities ) );
			}
		}

		if ( $this->release_addons_are_active() ) {
			$names[] = 'edd/release-product-version';
		}

		return $names;
	}

	public function test_the_release_ability_needs_both_software_licensing_and_the_git_updater() {

		$active = $this->release_addons_are_active();

		$this->assertSame( $active, wp_has_ability( 'edd/release-product-version' ), $active ? 'edd/release-product-version should be registered' : 'edd/release-product-version must not register without both add-ons' );

		if ( $active ) {
			$this->assertSame( 'edd-releases', wp_get_ability( 'edd/release-product-version' )->get_category() );
		}
	}

	public function test_addon_abilities_register_if_and_only_if_their_addon_is_active() {

		foreach ( self::ADDON_ABILITIES as $addon => $abilities ) {

			$active = $this->addon_is_active( $addon );

			foreach ( $abilities as $name => $category ) {

				$this->assertSame( $active, wp_has_ability( $name ), $active ? "$name should be registered" : "$name must not register without $addon" );

				if ( $active ) {
					$this->assertSame( $category, wp_get_ability( $name )->get_category(), "$name is in the wrong category" );
				}
			}
		}
	}

	public function test_every_ability_is_public_and_annotated() {

		foreach ( $this->expected_abilities() as $name ) {
			$meta = wp_get_ability( $name )->get_meta();

			$this->assertTrue( $meta['public'], "$name should be exposed to MCP" );
			$this->assertArrayHasKey( 'annotations', $meta );
			foreach ( [ 'readonly', 'destructive', 'idempotent' ] as $key ) {
				$this->assertIsBool( $meta['annotations'][ $key ], "$name annotation $key" );
			}
		}
	}

	public function test_only_refund_and_cancel_are_destructive_and_reads_are_readonly() {

		foreach ( $this->expected_abilities() as $name ) {
			$annotations = wp_get_ability( $name )->get_meta()['annotations'];
			$is_read     = 0 === strpos( $name, 'edd/list-' ) || 0 === strpos( $name, 'edd/get-' ) || 'edd/search-customers' === $name;

			$this->assertSame( $is_read, $annotations['readonly'], "$name readonly annotation" );
			$this->assertSame( in_array( $name, [ 'edd/refund-order', 'edd/cancel-subscription', 'edd/release-product-version' ], true ), $annotations['destructive'], "$name destructive annotation" );
		}
	}

	public function test_shop_admin_can_run_every_ability_but_a_subscriber_cannot() {

		$subscriber = $this->create_shop_user();

		foreach ( $this->expected_abilities() as $name ) {
			$ability = wp_get_ability( $name );

			wp_set_current_user( $this->admin_id );
			$this->assertTrue( $ability->check_permissions( [] ) === true, "admin should pass $name permission" );

			wp_set_current_user( $subscriber );
			$this->assertNotTrue( $ability->check_permissions( [] ), "subscriber must not pass $name permission" );
		}
	}

	public function test_customer_abilities_follow_the_edd_customer_role_filters() {

		// A user who can view reports but not edit payments: may read customers, may not change them.
		$analyst = $this->create_shop_user( [ 'view_shop_reports' ] );
		wp_set_current_user( $analyst );

		$this->assertTrue( wp_get_ability( 'edd/search-customers' )->check_permissions( [] ) );
		$this->assertNotTrue( wp_get_ability( 'edd/create-customer' )->check_permissions( [] ) );

		// Stores can remap the roles through EDD's filters; the abilities must follow.
		add_filter( 'edd_view_customers_role', function () {
			return 'some_custom_cap';
		} );

		$this->assertNotTrue( wp_get_ability( 'edd/search-customers' )->check_permissions( [] ) );
	}

	public function test_registering_after_the_api_hooks_finished_is_reported_not_silently_dropped() {

		// WordPress refuses registrations outside its init actions, so the only honest options are
		// to register on time or to say so. This must say so.
		$this->setExpectedIncorrectUsage( 'EDD_Abilities\Registry::add_category' );
		$this->setExpectedIncorrectUsage( 'EDD_Abilities\Registry::add_ability' );

		Registry::add_category( 'edd-test-too-late', [ 'label' => 'Too late', 'description' => 'Fixture.' ] );
		Registry::add_ability( Late_Test_Ability::class );

		$this->assertFalse( wp_has_ability( 'edd/late-test' ) );
		$this->assertFalse( wp_has_ability_category( 'edd-test-too-late' ) );
	}

	public function test_registering_from_inside_the_api_hooks_registers_immediately() {

		global $wp_current_filter;

		// Simulate a third party calling us from its own callback on the WP init actions.
		$wp_current_filter[] = Registry::CATEGORIES_HOOK;
		try {
			Registry::add_category( 'edd-test-late', [ 'label' => 'Late', 'description' => 'Fixture.' ] );
		} finally {
			array_pop( $wp_current_filter );
		}

		$wp_current_filter[] = Registry::ABILITIES_HOOK;
		try {
			Registry::add_ability( Late_Test_Ability::class );
			// Asking twice must not register twice (WordPress would flag a duplicate).
			Registry::add_ability( Late_Test_Ability::class );
		} finally {
			array_pop( $wp_current_filter );
		}

		$this->assertTrue( wp_has_ability_category( 'edd-test-late' ) );
		$this->assertTrue( wp_has_ability( 'edd/late-test' ) );
		$this->assertSame( 'edd-test-late', wp_get_ability( 'edd/late-test' )->get_category() );
	}
}

/**
 * Fixture: an ability registered by a third party after the Abilities API already initialised.
 */
class Late_Test_Ability extends \EDD_Abilities\Abilities\Ability {

	protected const NAME     = 'edd/late-test';
	protected const CATEGORY = 'edd-test-late';

	protected function get_args(): array {
		return [
			'label'         => 'Late test',
			'description'   => 'Fixture.',
			'input_schema'  => [ 'type' => 'object' ],
			'output_schema' => [ 'type' => 'object' ],
		];
	}

	public function __invoke( $input ) {
		return [];
	}
}
