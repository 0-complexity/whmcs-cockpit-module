<?php

if (!defined("WHMCS"))
	die("This file cannot be accessed directly");

require_once 'lib/CockpitApi.php';
require_once 'lib/ItsYouOnlineApi.php';
require_once 'lib/Util.php';
require_once 'lib/Spyc.php';

/**
 * Define product configuration options.
 *
 * The values you return here define the configuration options that are presented to a user when configuring a product
 * for use with the module. These values are then made available in all module function calls with the key name
 * configoptionX - with X being the index number of the field from 1 to 24.
 *
 * You can specify up to 24 parameters, with field types:
 * * text
 * * password
 * * yesno
 * * dropdown
 * * radio
 * * textarea
 *
 * Examples of each and their possible configuration parameters are provided in
 * this sample function.
 *
 * @see http://docs.whmcs.com/Provisioning_Module_Developer_Docs
 *
 * @return array
 */
function cockpit_ConfigOptions() {
	$configarray = array(

		'url'              => array(
			'FriendlyName' => 'Cockpit api URL',
			'Type'         => 'text',
			'Size'         => '60',
			'Description'  => 'The base URl for the cockpit. Example: https://cockpit.cloudpotato.org:8443/api',
		), 'client_id'     => array(
			'FriendlyName' => 'Client id',
			'Type'         => 'text',
			'Size'         => '60',
			'Description'  => 'OAuth2 client id.',
		), 'client_secret' => array(
			'FriendlyName' => 'Client Secret',
			'Type'         => 'password',
			'Size'         => '60',
			'Description'  => 'OAuth2 client secret',
		), 'blueprint'     => array(
			'FriendlyName' => 'VDC blueprint',
			'Type'         => 'textarea',
			'Rows'         => '20',
			'Cols'         => '60',
			'Description'  => 'Blueprint containing the specs of the virtual datacenter.',
		),
	);
	return $configarray;
}


/**
 * Additional actions a client user can invoke.
 *
 * Define additional actions a client user can perform for an instance of a product/service.
 *
 * Any actions you define here will be automatically displayed in the available list of actions within the client area.
 *
 * @param array $vars module parameters provided by WHMCS
 *
 * @see http://docs.whmcs.com/Provisioning_Module_Developer_Docs
 * @see cockpit_vdc()
 *
 * @return array array containing the custom buttons. Key is the button text, value is the function that will be executed
 */
function cockpit_ClientAreaCustomButtonArray(array $vars) {
	$buttonarray = array(
		"Control panel" => "vdc",
	);
	return $buttonarray;
}

/**
 * Redirects to vdc.php with the vdc_id as parameter.
 *
 * @param array $vars common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 * @see cockpit_ClientAreaCustomButtonArray()
 *
 */
function cockpit_vdc(array $vars) {
	$username = get_external_username($vars['userid']);
	$api_url = $vars['configoption1'];
	$client_id = $vars['configoption2'];
	$client_secret = $vars['configoption3'];
	$blueprint = $vars['configoption4'];
	try {
		$blueprint_yaml = spyc_load($blueprint);
		$g8_domain = null;
		logModuleCall('cockpit', 'blueprint yaml', $blueprint_yaml);
		foreach ($blueprint_yaml as $key => $value) {
			if (strrpos($key, 'g8client__', -strlen($key)) !== false) {
				$g8_domain = $value['g8.url'];
			}
		}
		logModulecall('cockpit', 'g8url test value from blueprint', $g8_domain);
		if (!$g8_domain) {
			throw new Exception("g8.url not found in blueprint");
		}
		$itsyou_online_api = new ItsYouOnlineApi($client_id, $client_secret);
		$jwt = $itsyou_online_api->get_jwt();
		$cockpit_api = new CockpitApi($jwt, $api_url);
		$vdc_name = $vars['customfields']['Name'];
		$vdc = $cockpit_api->get_service_vdc($username, $vdc_name);
		header(sprintf('Location: vdc.php?vdc_id=%s&g8_domain=%s', $vdc['instance.hrd']['vdc.id'], $g8_domain));
	} catch (Exception $e) {
		log_action(__FUNCTION__, $vars, $e->getMessage());
		header('Location: index.php');
	}
	exit();
}


/**
 * Terminate instance of a product/service.
 *
 * Called when a termination is requested. This can be invoked automatically for
 * overdue products if enabled, or requested manually by an admin user.
 *
 * @param array $vars common module parameters provided by WHMCS
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return string "success" or an error message
 */
function cockpit_TerminateAccount(array $vars) {
	try {
		log_action(__FUNCTION__, $vars, null);
		$username = get_external_username($vars['userid']);
		$api_url = $vars['configoption1'];
		$client_id = $vars['configoption2'];
		$client_secret = $vars['configoption3'];
		$itsyou_online_api = new ItsYouOnlineApi($client_id, $client_secret);
		$jwt = $itsyou_online_api->get_jwt();
		$cockpit_api = new CockpitApi($jwt, $api_url);
		$vdc_name = $vars['customfields']['Name'];
		$cockpit_api->delete_service_vdc($username, $vdc_name);
		return "success";
	} catch (Exception $e) {
		log_action(__FUNCTION__, $vars, $e->getTraceAsString());
		return $e->getMessage();
	}
}


/**
 * Provision a new instance of a product/service.
 *
 * Attempt to provision a new instance of a given product/service. This is
 * called any time provisioning is requested inside of WHMCS. Depending upon the
 * configuration, this can be any of:
 * * When a new order is placed
 * * When an invoice for a new order is paid
 * * Upon manual request by an admin user
 *
 * @param array $vars common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return string "success" or an error message
 */

function cockpit_CreateAccount(array $vars) {
	try {
		$username = get_external_username($vars['userid']);
		$location = $vars['customfields']['Location'];
		$vdc_name = strtolower($vars['customfields']['Name']);
		$vdc_name = strip_special_chars($vdc_name);
		$api_url = $vars['configoption1'];
		$client_id = $vars['configoption2'];
		$client_secret = $vars['configoption3'];
		$blueprint = $vars['configoption4'];
		$blueprint = sprintf(
			$blueprint,
			$location,
			$vdc_name,
			$username,
			$vars['clientsdetails']['email'],
			'itsyouonline');
		log_action(__FUNCTION__, 'blueprint', $blueprint);
		$itsyou_online_api = new ItsYouOnlineApi($client_id, $client_secret);
		$jwt = $itsyou_online_api->get_jwt();
		$cockpit_api = new CockpitApi($jwt, $api_url);
		$cockpit_api->deploy($username, $vdc_name, $blueprint);
		return 'success';

	} catch (CockpitException $e) {
		return $e->getMessage();
	} catch (ItsYouOnlineException $e) {
		return 'An error occured. Please retry later.';
	}
}

/**
 * Upgrade or downgrade an instance of a product/service.
 *
 * Called to apply any change in product assignment or parameters. It is called to provision upgrade or downgrade
 * orders, as well as being able to be invoked manually by an admin user.
 *
 * This same function is called for upgrades and downgrades of both products and configurable options.
 *
 * @param array $vars common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return string "success" or an error message
 */
function cockpit_ChangePackage(array $vars) {
	log_action(__FUNCTION__, $vars);
	$username = get_external_username($vars['userid']);
	$location = $vars['customfields']['Location'];
	$vdc_name = strtolower($vars['customfields']['Name']);
	$vdc_name = strip_special_chars($vdc_name);
	$api_url = $vars['configoption1'];
	$client_id = $vars['configoption2'];
	$client_secret = $vars['configoption3'];
	$blueprint = $vars['configoption4'];
	$blueprint = sprintf(
		$blueprint,
		$location,
		$vdc_name,
		$username,
		$vars['clientsdetails']['email'],
		'itsyouonline');
	log_action(__FUNCTION__, 'blueprint', $blueprint);
	try {
		$itsyou_online_api = new ItsYouOnlineApi($client_id, $client_secret);
		$jwt = $itsyou_online_api->get_jwt();
		$cockpit_api = new CockpitApi($jwt, $api_url);
		$cockpit_api->update_blueprint($username, $vdc_name, $blueprint);
		return 'success';
	} catch (CockpitException $exception) {
		return $exception->getMessage();
	} catch (ItsYouOnlineException $exception) {
		return $exception->getMessage();
	}
}