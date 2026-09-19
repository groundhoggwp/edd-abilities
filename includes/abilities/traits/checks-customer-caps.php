<?php

namespace EDD_Abilities\Abilities\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * EDD lets stores remap which capability views and edits customers through the
 * 'edd_view_customers_role' and 'edd_edit_customers_role' filters, so customer abilities defer
 * to the same rules instead of hardcoding a capability. Read-only abilities use the view role,
 * everything else the edit role.
 */
trait Checks_Customer_Caps {

	public function can_execute( $input = null ) {

		$cap = static::READONLY
			? apply_filters( 'edd_view_customers_role', 'view_shop_reports' )
			: edd_get_edit_customers_role();

		return current_user_can( $cap );
	}
}
