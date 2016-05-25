<?php

define('DEBUG', true);

use Illuminate\Database\Capsule\Manager as DB;

function log_action($action, $request_data, $response_data = null) {
	logModuleCall('cockpit', chunk_split($action, 25, ' '), $request_data, $response_data);
}


function strip_special_chars($input) {
	return preg_replace('/[^A-Za-z0-9\-]/', '', str_replace(' ', '_', $input));
}

/**
 * Returns the external(in our case itsyou.online) username of a WHMCS user.
 * @param $user_id int internal WHMCS user id
 * @return string external username
 */
function get_external_username($user_id) {
	if (isset($_SESSION['external_username']) && $_SESSION['external_username']) {
		return $_SESSION['external_username'];
	}
	return DB::table('mod_custom_oauth2_tokens')
		->where('client_id', $user_id)
		->pluck('external_username');
}