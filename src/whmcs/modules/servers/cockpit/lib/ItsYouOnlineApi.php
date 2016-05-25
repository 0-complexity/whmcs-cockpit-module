<?php

if (!function_exists("curl_init")) {
	die("You need curl installed for this library to function.");
}

require_once 'Util.php';

/**
 * Class ItsYouOnlineException
 */
class ItsYouOnlineException extends Exception {
	/**
	 * ItsYouOnlineException constructor.
	 * @param string $message
	 * @param null $code
	 * @param Exception|null $previous
	 */
	function __construct($message, $code = null, Exception $previous = null) {
		parent::__construct($message, $code, $previous);
	}
}


/**
 * Class ItsYouOnlineApi used to interact with the itsyou.online API
 */
class ItsYouOnlineApi {
	/**
	 * @var
	 */
	private $token;
	/**
	 * @var
	 */
	private $jwt;

	/**
	 * Gets an authentication token from an itsyou.online organization. Caches the token in the session.
	 * @param string $client_id itsyou.online client id
	 * @param string $client_secret itsyou.online client secret
	 * @throws ItsYouOnlineException When obtaining a token has failed
	 */
	public function get_token($client_id, $client_secret) {
		// XXX: store this in database instead of session for perf. improvement with multiple users
		if (isset($_SESSION['organization_token'])) {
			$this->token = $_SESSION['organization_token'];
		}
		// Get a new token when no token was found in the session or when that token was expired.
		$cur_time = time();
		if (!$this->token || $this->token['expires_on'] <= ($cur_time + 30)) {
			unset($_SESSION['organization_token']);
			$ch = curl_init();

			$fields = array(
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'client_credentials',
			);
			$curl_opts = array(
				CURLOPT_URL            => 'https://itsyou.online/v1/oauth/access_token',
				CURLOPT_POST           => 1,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_POSTFIELDS     => http_build_query($fields),
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_VERBOSE        => false,
			);
			curl_setopt_array($ch, $curl_opts);
			$result = curl_exec($ch);
			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			if ($http_code !== 200) {
				log_action(__FUNCTION__, sprintf('Failed to obtain access token: itsyou.online returned code %d', $http_code), $result);
				throw new ItsYouOnlineException(sprintf('%d: Could not authenticate with itsyou.online.', $http_code));
			}
			$this->token = json_decode($result, true);
			$expires_in = intval($this->token['expires_in']);
			$this->token['expires_on'] = time() + ($expires_in == 0 ? 3600 : $expires_in);
			$_SESSION['organization_token'] = $this->token;
		}
	}

	/**
	 * ItsYouOnlineApi constructor.
	 * @param string $client_id itsyou.online client id
	 * @param string $client_secret itsyou.online client secret
	 */
	public function __construct($client_id, $client_secret) {
		$this->get_token($client_id, $client_secret);
	}

	/**
	 * @return string JSON web token
	 * @throws ItsYouOnlineException when obtaining a token has failed
	 */
	public function get_jwt() {
		// XXX: store this in database instead of session for perf. improvement with multiple users
		if (isset($_SESSION['organization_jwt'])) {
			$jwt = $_SESSION['organization_jwt'];
			$decoded_jwt = json_decode(preg_split('}', base64_decode($jwt))[1] . '}');
			if ($decoded_jwt['exp'] > time()) {
				$this->jwt = $jwt;
				logModuleCall('cockpit', 'using cached jwt');
				return $this->jwt;
			}
		}
		$ch = curl_init();
		$headers = array(
			'Authorization: token ' . $this->token['access_token'],
		);
		$curl_opts = array(
			CURLOPT_URL            => 'https://itsyou.online/v1/oauth/jwt',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_VERBOSE        => false,
			CURLOPT_HTTPHEADER     => $headers,
		);
		curl_setopt_array($ch, $curl_opts);
		$result = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($http_code !== 200) {
			log_action(__FUNCTION__, sprintf('%d Could not get JWT token from itsyou.online', $http_code), $result);
			throw new ItsYouOnlineException(sprintf('%d Could not authenticate with itsyou.online', $http_code));
		}
		$this->jwt = $result;
		$_SESSION['organization_jwt'] = $this->jwt;
		return $this->jwt;
	}
}