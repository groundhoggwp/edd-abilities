<?php

namespace EDD_Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PSR-4-ish autoloader using the same file naming rule as Groundhogg's.
 *
 * The class name relative to the registered namespace root is lowercased, CamelCase becomes
 * camel-case, underscores become dashes and namespace separators become directory separators:
 *
 *     EDD_Abilities\Abilities\Orders\List_Orders => {base}/abilities/orders/list-orders.php
 *
 * Standalone on purpose - it has no dependency on Groundhogg or anything else.
 */
class Autoloader {

	/**
	 * Namespace root => base directory (with trailing slash).
	 *
	 * @var array<string, string>
	 */
	private static $roots = [];

	/**
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Map a namespace root to a directory and make sure the autoload callback is active.
	 *
	 * @param string $namespace root namespace, without leading/trailing backslash
	 * @param string $base_dir  directory the root maps to
	 *
	 * @return void
	 */
	public static function register( string $namespace, string $base_dir ) {

		self::$roots[ trim( $namespace, '\\' ) . '\\' ] = trailingslashit( $base_dir );

		if ( ! self::$registered ) {
			spl_autoload_register( [ __CLASS__, 'autoload' ] );
			self::$registered = true;
		}
	}

	/**
	 * Derive the file path for a class relative to its namespace root.
	 *
	 * @param string $relative_class
	 *
	 * @return string
	 */
	public static function relative_path( string $relative_class ): string {
		return strtolower(
			preg_replace(
				[ '/([a-z])([A-Z])/', '/_/', '/\\\\/' ],
				[ '$1-$2', '-', '/' ],
				$relative_class
			)
		) . '.php';
	}

	/**
	 * @param string $class
	 *
	 * @return void
	 */
	private static function autoload( $class ) {

		foreach ( self::$roots as $prefix => $base_dir ) {

			if ( strpos( $class, $prefix ) !== 0 ) {
				continue;
			}

			$file = $base_dir . self::relative_path( substr( $class, strlen( $prefix ) ) );

			if ( is_readable( $file ) ) {
				require $file;
			}

			return;
		}
	}
}
