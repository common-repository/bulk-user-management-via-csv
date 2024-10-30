<?php
/**
 * Bulk User Management plugin main file
 *
 * @link https://colettesnow.com/products/bulk-user-management/
 * @package csm-bulk-user-management
 *
 * @csm-bulk-user-management
 * Plugin Name: Bulk User Management via CSV
 * Plugin URI: https://colettesnow.com/products/bulk-user-management/
 * Description: CSV user management addon
 * Version: 0.4
 * Author: Colette Snow
 * Author URI: https://colettesnow.com/
 * Text Domain: csm_membership
 * Domain Path: /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

if ( is_admin() ) {
	require_once 'class-csm-bulk-user-management.php';

	$csm_bulk = new CSM_Bulk_User_Management();
}
