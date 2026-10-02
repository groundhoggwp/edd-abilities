<?php

namespace EDD_Abilities;

use EDD_Abilities\Abilities\Customers\Create_Customer;
use EDD_Abilities\Abilities\Customers\Get_Customer;
use EDD_Abilities\Abilities\Customers\List_Customers;
use EDD_Abilities\Abilities\Customers\Update_Customer;
use EDD_Abilities\Abilities\Discounts\Create_Discount;
use EDD_Abilities\Abilities\Discounts\Get_Discount;
use EDD_Abilities\Abilities\Discounts\List_Discounts;
use EDD_Abilities\Abilities\Discounts\Update_Discount_Status;
use EDD_Abilities\Abilities\Licenses\Get_License;
use EDD_Abilities\Abilities\Licenses\List_Licenses;
use EDD_Abilities\Abilities\Licenses\Update_License_Status;
use EDD_Abilities\Abilities\Orders\Add_Order_Note;
use EDD_Abilities\Abilities\Orders\Get_Order;
use EDD_Abilities\Abilities\Orders\List_Orders;
use EDD_Abilities\Abilities\Orders\Refund_Order;
use EDD_Abilities\Abilities\Orders\Update_Order_Status;
use EDD_Abilities\Abilities\Products\Get_Product;
use EDD_Abilities\Abilities\Products\List_Products;
use EDD_Abilities\Abilities\Releases\Release_Product_Version;
use EDD_Abilities\Abilities\Reports\Get_Store_Stats;
use EDD_Abilities\Abilities\Subscriptions\Cancel_Subscription;
use EDD_Abilities\Abilities\Subscriptions\Get_Subscription;
use EDD_Abilities\Abilities\Subscriptions\List_Subscriptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers this plugin's ability categories and abilities with the WP Abilities API, and lets
 * other plugins add their own alongside them.
 *
 *     add_action( 'plugins_loaded', function () {
 *         \EDD_Abilities\Registry::add_category( 'my-addon', [ 'label' => '...', 'description' => '...' ] );
 *         \EDD_Abilities\Registry::add_ability( My_Addon\Do_The_Thing::class );
 *     } );
 *
 * WordPress only accepts registrations while the 'wp_abilities_api_categories_init' and
 * 'wp_abilities_api_init' actions are running, so add_category()/add_ability() queue the request
 * until then. They also work from inside those actions (registered straight away). Calling them
 * after the action has finished is too late: WordPress would refuse it, so it is reported with
 * _doing_it_wrong() instead of being silently lost.
 *
 * An ability class may declare a static is_available() to opt out when the plugin it wraps (e.g.
 * Software Licensing) isn't active.
 */
class Registry {

	const CATEGORIES_HOOK = 'wp_abilities_api_categories_init';
	const ABILITIES_HOOK  = 'wp_abilities_api_init';

	/**
	 * @var string[]
	 */
	protected static $extra_abilities = [];

	/**
	 * @var array<string, array>
	 */
	protected static $extra_categories = [];

	/**
	 * Ability classes / category slugs already handed to WordPress, so a request that is both
	 * queued and made mid-action is never registered twice.
	 *
	 * @var array<string, true>
	 */
	protected static $registered = [];

	public function __construct() {
		add_action( self::CATEGORIES_HOOK, [ $this, 'register_categories' ] );
		add_action( self::ABILITIES_HOOK, [ $this, 'register_abilities' ] );
	}

	/**
	 * @param string $slug
	 * @param array  $args [ 'label' => string, 'description' => string ]
	 *
	 * @return void
	 */
	public static function add_category( string $slug, array $args ) {

		if ( ! doing_action( self::CATEGORIES_HOOK ) && did_action( self::CATEGORIES_HOOK ) ) {
			self::too_late( __METHOD__, self::CATEGORIES_HOOK );

			return;
		}

		self::$extra_categories[ $slug ] = $args;

		if ( doing_action( self::CATEGORIES_HOOK ) ) {
			self::register_category( $slug, $args );
		}
	}

	/**
	 * @param string $class fully-qualified class name extending EDD_Abilities\Abilities\Ability
	 *
	 * @return void
	 */
	public static function add_ability( string $class ) {

		if ( ! doing_action( self::ABILITIES_HOOK ) && did_action( self::ABILITIES_HOOK ) ) {
			self::too_late( __METHOD__, self::ABILITIES_HOOK );

			return;
		}

		self::$extra_abilities[] = $class;

		if ( doing_action( self::ABILITIES_HOOK ) ) {
			self::instantiate( $class );
		}
	}

	protected static function too_late( string $method, string $hook ) {
		_doing_it_wrong(
			esc_html( $method ),
			/* translators: %s: an action name */
			esc_html( sprintf( __( 'Too late to register: WordPress only accepts registrations while the %s action runs. Call this on plugins_loaded or earlier.', 'edd-abilities' ), $hook ) ),
			'0.1.0'
		);
	}

	protected static function register_category( string $slug, array $args ) {

		if ( isset( self::$registered[ 'category:' . $slug ] ) ) {
			return;
		}

		self::$registered[ 'category:' . $slug ] = true;

		wp_register_ability_category( $slug, $args );
	}

	protected static function instantiate( string $class ) {

		if ( isset( self::$registered[ $class ] ) || ! class_exists( $class ) || ! $class::is_available() ) {
			return;
		}

		self::$registered[ $class ] = true;

		new $class();
	}

	public function register_categories() {

		$categories = [
			'edd-orders'        => [
				'label'       => __( 'EDD Orders', 'edd-abilities' ),
				'description' => __( 'Find, inspect, and manage Easy Digital Downloads orders.', 'edd-abilities' ),
			],
			'edd-customers'     => [
				'label'       => __( 'EDD Customers', 'edd-abilities' ),
				'description' => __( 'Find, inspect, and manage Easy Digital Downloads customers.', 'edd-abilities' ),
			],
			'edd-products'      => [
				'label'       => __( 'EDD Products', 'edd-abilities' ),
				'description' => __( 'Find and inspect Easy Digital Downloads products.', 'edd-abilities' ),
			],
			'edd-discounts'     => [
				'label'       => __( 'EDD Discounts', 'edd-abilities' ),
				'description' => __( 'Find and manage Easy Digital Downloads discount codes.', 'edd-abilities' ),
			],
			'edd-reports'       => [
				'label'       => __( 'EDD Reports', 'edd-abilities' ),
				'description' => __( 'Store-wide earnings and sales statistics.', 'edd-abilities' ),
			],
			'edd-licenses'      => [
				'label'       => __( 'EDD Software Licensing', 'edd-abilities' ),
				'description' => __( 'Find and manage Software Licensing licenses.', 'edd-abilities' ),
			],
			'edd-subscriptions' => [
				'label'       => __( 'EDD Recurring Payments', 'edd-abilities' ),
				'description' => __( 'Find and manage Recurring Payments subscriptions.', 'edd-abilities' ),
			],
			'edd-releases'      => [
				'label'       => __( 'EDD Releases', 'edd-abilities' ),
				'description' => __( 'Ship new versions of products whose files are pulled from a connected git repository.', 'edd-abilities' ),
			],
		];

		/**
		 * Filter the built-in ability categories.
		 *
		 * @param array<string, array> $categories slug => [ 'label', 'description' ]
		 */
		$categories = apply_filters( 'edd_abilities/categories', $categories );

		foreach ( array_merge( $categories, self::$extra_categories ) as $slug => $args ) {
			self::register_category( $slug, $args );
		}
	}

	public function register_abilities() {

		$abilities = [
			// Orders
			List_Orders::class,
			Get_Order::class,
			Update_Order_Status::class,
			Refund_Order::class,
			Add_Order_Note::class,
			// Customers
			List_Customers::class,
			Get_Customer::class,
			Create_Customer::class,
			Update_Customer::class,
			// Products
			List_Products::class,
			Get_Product::class,
			// Discounts
			List_Discounts::class,
			Get_Discount::class,
			Create_Discount::class,
			Update_Discount_Status::class,
			// Reports
			Get_Store_Stats::class,
			// Software Licensing
			List_Licenses::class,
			Get_License::class,
			Update_License_Status::class,
			// Recurring Payments
			List_Subscriptions::class,
			Get_Subscription::class,
			Cancel_Subscription::class,
			// Releases (Git Download Updater + Software Licensing)
			Release_Product_Version::class,
		];

		/**
		 * Filter the built-in ability classes, e.g. to remove one.
		 *
		 * @param string[] $abilities fully-qualified class names
		 */
		$abilities = apply_filters( 'edd_abilities/abilities', $abilities );

		foreach ( array_merge( $abilities, self::$extra_abilities ) as $class ) {
			self::instantiate( $class );
		}
	}
}
