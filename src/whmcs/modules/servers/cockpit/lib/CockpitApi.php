<?php
require_once 'Util.php';

if (!function_exists("curl_init")) {
	die("You need curl installed for this library to function.");
}


class CockpitException extends Exception {
	function __construct($message, $code = null, Exception $previous = null) {
		parent::__construct($message, $code, $previous);
	}
}

class CockpitApi {
	private $jwt;
	private $api_url;

	/**
	 * CockpitApi constructor.
	 * @param $jwt string JSON Web Token used to authenticate all cockpit actions
	 * @param $api_url string Base url of the cockpit
	 */
	public function __construct($jwt, $api_url) {
		$this->jwt = $jwt;
		$this->api_url = rtrim(trim($api_url), '/');
	}

	/**
	 * @param $path string path of the call. This wil be appended to the base url api_url.
	 * @param $data array URL params in case of GET, json body data in case of POST/PUT/DELETE
	 * @param $method string GET/POST/PUT/DELETE
	 * @return stdClass with properties status(status code of the request) and data (response body)
	 */
	private function call($path, $data, $method) {
		$method = strtoupper($method);
		$ch = curl_init();
		$headers = array(
			sprintf('authorization: bearer %s', $this->jwt),
			"cache-control: no-cache",
			"content-type: application/json",
		);
		$curl_opts = array(
			CURLOPT_URL            => $this->api_url . $path,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_VERBOSE        => false,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_HTTPHEADER     => $headers,
		);
		switch ($method) {
			case 'DELETE':
			case 'POST':
			case 'PUT':
				$curl_opts[CURLOPT_POSTFIELDS] = json_encode($data);
				break;
			case 'GET':
				if ($data && count($data)) {
					$curl_opts[CURLOPT_URL] = sprintf('%s?%s', $path, http_build_query($data));
				}
				break;
		}
		curl_setopt_array($ch, $curl_opts);
		$result = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$result_data = new stdClass();
		$result_data->status = $http_code;
		$result_data->data = json_decode($result, true);
		if (DEBUG) {
			log_action($path, $curl_opts, sprintf('%d - %s', $result_data->status, $result));
		}
		return $result_data;
	}

	private function sanitize_blueprint_name($name) {
		return strip_special_chars(strtolower($name));
	}

	/**
	 * Creates a repository and blueprint and executes that blueprint.
	 * @param $repository_name string name of the repository
	 * @param $blueprint_name string name of the blueprint
	 * @param $blueprint string yaml containing the blueprint
	 * @throws CockpitException when deploying failed
	 */
	public function deploy($repository_name, $blueprint_name, $blueprint) {
		$this->create_repository($repository_name);
		$this->create_blueprint($repository_name, $blueprint_name, $blueprint);
		$this->execute_blueprint($repository_name, $blueprint_name);
	}

	public function create_repository($repository_name) {
		$url = '/ays/repository';
		$fields = array('name' => $repository_name);
		$response = $this->call($url, $fields, 'POST');
		// 201: new repo, 409: repo already exists (which is possible and doesn't matter)
		if (!in_array($response->status, array(201, 409))) {
			log_action('create repository', $fields, $response->data);
			throw new CockpitException('Failed to create repository.');
		}
	}

	/**
	 * Creates a new blueprint. Will not fail if the blueprint already existed.
	 * @param $repository_name string name of the repository
	 * @param $blueprint_name string name of the blueprint
	 * @param $blueprint string the blueprint YAML
	 * @throws CockpitException in case the action did not succeed
	 */
	public function create_blueprint($repository_name, $blueprint_name, $blueprint) {
		$blueprint_name = $this->sanitize_blueprint_name($blueprint_name);
		$url = sprintf('/ays/repository/%s/blueprint', $repository_name);
		$fields = array(
			'name'    => $blueprint_name,
			'content' => $blueprint,
		);
		$create_blueprint_response = $this->call($url, $fields, 'POST');
		// Ignore 409 (blueprint already exists) since the existence of a blueprint is checked in the
		// ShoppingCartValidateProductUpdate hook in file hook_cockpit.php when ordering a new vdc
		if (!in_array($create_blueprint_response->status, array(201, 409))) {
			log_action('create blueprint', $fields, $create_blueprint_response->data);
			throw new CockpitException('Failed to create blueprint');
		}
	}


	/**
	 * @param $repository_name string name of the repository
	 * @param $blueprint_name string name of the blueprint to update
	 * @param $blueprint string content of the updated blueprint
	 * @throws CockpitException in case the action did not succeed
	 */
	public function update_blueprint($repository_name, $blueprint_name, $blueprint) {
		$blueprint_name = $this->sanitize_blueprint_name($blueprint_name);
		$url = sprintf('/ays/repository/%s/blueprint/%s', $repository_name, $blueprint_name);
		$fields = array(
			'name'    => $blueprint_name,
			'content' => $blueprint,
		);
		$response = $this->call($url, $fields, 'PUT');
		if ($response->status !== 204) {
			log_action(__FUNCTION__, sprintf('%s returned code %d', $url, $response->status), $response->data);
			throw new CockpitException('Failed to upgrade or downgrade service.');
		}
		$this->execute_blueprint($repository_name, $blueprint_name);
	}

	/**
	 * Executes a blueprint.
	 * @param $repository_name string name of the repository
	 * @param $blueprint_name string name of the blueprint
	 * @throws CockpitException in case the action did not succeed
	 */
	public function execute_blueprint($repository_name, $blueprint_name) {
		$blueprint_name = $this->sanitize_blueprint_name($blueprint_name);
		$url = sprintf('/ays/repository/%s/blueprint/%s', $repository_name, $blueprint_name);
		$response = $this->call($url, array(), 'POST');
		if ($response->status !== 200) {
			log_action('execute blueprint', array(), $response->data);
			throw new CockpitException('Failed to execute blueprint');
		}
	}


	/**
	 * Checks if a blueprint exists.
	 * @param $repository_name string name of the repository
	 * @param $blueprint_name string name of the blueprint
	 * @throws CockpitException In case a blueprint with this name already exists.
	 * @return boolean true if a blueprint with specified $blueprint_name exists, else false.
	 */
	public function blueprint_exists($repository_name, $blueprint_name) {
		$blueprint_name = $this->sanitize_blueprint_name($blueprint_name);
		$url = sprintf('/ays/repository/%s/blueprint/%s', $repository_name, $blueprint_name);
		$response = $this->call($url, null, 'GET');
		if (in_array($response->status, array(404, 200))) {
			return $response->status === 200;
		}
		else {
			log_action('blueprint exists', sprintf('%s returned code %d', $url, $response->status), $response->data);
			throw new CockpitException('Failed to check if blueprint already exists.');
		}
	}

	/**
	 * Get the details from a virtual data center
	 * @param $repository_name string name of the repository
	 * @param $blueprint_name string name of the blueprint
	 * @return array service details
	 * @throws CockpitException in case the call failed
	 */
	public function get_service_vdc($repository_name, $blueprint_name) {
		$blueprint_name = $this->sanitize_blueprint_name($blueprint_name);
		$url = sprintf('/ays/repository/%s/service/vdc/%s', $repository_name, $blueprint_name);
		$response = $this->call($url, null, 'GET');
		if ($response->status !== 200) {
			log_action(__FUNCTION__, sprintf('%s returned code %d', $url, $response->status), $response->data);
			throw new CockpitException('Failed to get service VDC info');
		}
		return $response->data;
	}


	/**
	 * Deletes a virtual data center.
	 * @param $repository_name string name of the repository
	 * @param $blueprint_name string name of the blueprint
	 * @throws CockpitException in case the action did not succeed
	 */
	public function delete_service_vdc($repository_name, $blueprint_name) {
		$blueprint_name = $this->sanitize_blueprint_name($blueprint_name);
		$url = sprintf('/ays/repository/%s/service/vdc/%s', $repository_name, $blueprint_name);
		$response = $this->call($url, null, 'DELETE');
		if ($response->status !== 204) {
			log_action('delete blueprint', sprintf('%s returned code %d', $url, $response->status), $response->data);
			throw new CockpitException('Failed to delete service');
		}
	}
}