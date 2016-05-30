<?php

if (!defined("WHMCS"))
	die("This file cannot be accessed directly");

require_once __DIR__ . '/../../modules/servers/cockpit/lib/CockpitApi.php';
require_once __DIR__ . '/../../modules/servers/cockpit/lib/ItsYouOnlineApi.php';
require_once __DIR__ . '/../../modules/servers/cockpit/lib/Util.php';
use Illuminate\Database\Capsule\Manager as DB;

/**
 * This hook runs on the load of every client area page and can accept a return of values to be included as additional smarty fields.
 * @param $vars array parameters provided by WHMCS
 * @return array An array of field => value combinations can be returned if required.
 * @see http://docs.whmcs.com/Hooks:ClientAreaPage
 */
function hook_template_variables($vars) {
	return array('jwt' => $_SESSION['jwt']);
}

/**
 * Checks if a service with this name already exists.
 * @param $vars array parameters provided by WHMCS
 * @return array array of strings containing the errors that occurred.
 * @see http://docs.whmcs.com/Hooks:ShoppingCartValidateProductUpdate
 */
function validate_product($vars) {
	// Check if user is logged in.
	if (!isset($_SESSION['uid']) || !$_SESSION['uid']) {
		return array('Please <a href="login.php">login</a> before placing orders.');
	}
	$username = get_external_username($_SESSION['uid']);
	$item = $_SESSION['cart']['products'][$vars['i']];
	// Get product details.
	$name = null;
	$custom_field_name_id = DB::table('tblcustomfields')
		->where('relid', '=', $item['pid'])
		->where('fieldname', '=', 'Name')
		->pluck('id');
	$name = $item['customfields'][strval($custom_field_name_id)];
	$product_details = DB::table('tblproducts')
		->select('configoption1', 'configoption2', 'configoption3')
		->where('id', $item['pid'])
		->get();
	$api_url = $product_details[0]->configoption1;
	$client_id = $product_details[0]->configoption2;
	$client_secret = $product_details[0]->configoption3;
	try {
		$itsyou_online_api = new ItsYouOnlineApi($client_id, $client_secret);
		$jwt = $itsyou_online_api->get_jwt();
		$cockpit_api = new CockpitApi($jwt, $api_url);
		$exists = $cockpit_api->vdc_exists($username, $name);
		if ($exists) {
			return array('A service with the name "' . $name . '" already exists. Please choose a different name.');
		}
		return array();
	} catch (Exception $exception) {
		log_action(__FUNCTION__, $exception->getMessage(), $exception->getTraceAsString());
		return array('An unknown error occurred. Please try again later.');
	}
}

add_hook('ClientAreaPage', 1, 'hook_template_variables');
add_hook('ShoppingCartValidateProductUpdate', 1, 'validate_product');