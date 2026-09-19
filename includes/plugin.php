<?php

namespace EDD_Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap. Currently just owns the ability registry.
 */
class Plugin {

	/**
	 * @var Plugin|null
	 */
	protected static $instance = null;

	/**
	 * @var Registry
	 */
	public $registry;

	public static function instance(): Plugin {

		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	protected function __construct() {
		$this->registry = new Registry();
	}
}
