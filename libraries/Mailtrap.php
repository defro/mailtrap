<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 *
 * @author Jaikora <kora.jayaram@gmail.com>
 */
class Mailtrap_Exception extends Exception
{
}

class Mailtrap
{

	const API_VERSION = 'v1';
	const END_POINT = 'https://mailtrap.io/api/';

	private $_apiToken;
	private $_isDebugEnabled;

	public function __construct(array $config = array())
	{
		$CI =& get_instance();

		log_message('info', "Mailtrap Library class initialized");

		$CI->load->config('mailtrap');

		$config = array_merge(
			  array (
					'mailtrap_api_token' => $CI->config->item('mailtrap_api_token'),
					'mailtrap_debug'   => $CI->config->item('mailtrap_debug')
			  ),
			  $config
		);

		try {
			$this->_isDebugEnabled = (bool)$config['mailtrap_debug'];
			$this->init($config['mailtrap_api_token']);
		} catch (Mailtrap_Exception $e) {
			var_dump($e);
			die();
		}
	}

	function init($api)
	{
		if (empty($api)) {
			throw new Mailtrap_Exception('Invalid API token key');
		}

		try {
			$response = $this->request('user', array ('api_token' => $api), 'GET');
			if (!isset($response['billing_subscription_status']) || $response['billing_subscription_status'] != 'active') {
				throw new Mailtrap_Exception('Invalid API key or account not active');
			}
			$this->_apiToken = $api;
		} catch (Exception $e) {
			throw new Mailtrap_Exception($e->getMessage());
		}
	}

	### The following is a section of resources related to the user.

	public function getUser()
	{
		return ($this->request('user', array (), 'GET'));
	}

	public function patchUser($name, $email)
	{
		return $this->request('user', compact('name', 'email'), 'PATCH');
	}

	public function deleteUser()
	{
		return $this->request('user/reset_api_token', array (), 'DELETE');
	}

	### The following is a section of resources related to the inbox

	public function getInboxes()
	{
		return $this->request('inboxes', array (), 'GET');
	}

	public function getInbox($id)
	{
		return $this->request('inboxes', compact('id'), 'GET');
	}

	//inboxes/{inbox_id}/clean
	public function patchInboxClean($inbox_id)
	{
		return $this->request('inboxes/{inbox_id}/clean', compact('inbox_id'), 'PATCH');
	}

	//inboxes/{inbox_id}/all_read
	public function patchInboxAsRead($inbox_id)
	{
		return $this->request('inboxes/{inbox_id}/all_read', compact('inbox_id'), 'PATCH');
	}

	### The following is a section of resources related to the message

	public function getMessages($inbox_id, $pages = 1)
	{
		return $this->request('inboxes/' . $inbox_id . '/messages', compact('pages'), 'GET');
	}

	public function getMessageById($inbox_id, $message_id)
	{
		$this->checkValues(func_get_args(), __FUNCTION__);
		return $this->request('inboxes/' . $inbox_id . '/messages/' . $message_id, array(), 'GET');
	}

	public function patchMessageAsRead($inbox_id, $message_id)
	{
		$this->checkValues(func_get_args(), __FUNCTION__);
		return $this->request('inboxes/' . $inbox_id . '/messages/' . $message_id,
			  array ('message' => array ('is_read' => true)), 'PATCH');
	}

	public function deleteMessageById($inbox_id, $message_id)
	{
		$this->checkValues(func_get_args(), __FUNCTION__);
		return $this->request('inboxes/' . $inbox_id . '/messages/' . $message_id, array (), 'DELETE');
	}

	### Body

	// type : html, raw, txt, eml
	public function getMessageBody($inbox_id, $message_id, $type = 'html')
	{
		$this->checkValues(func_get_args(), __FUNCTION__);
		return $this->request('inboxes/' . $inbox_id . '/messages/' . $message_id . '/body', array (), 'GET', $type);
	}


	private static function checkValues($values, $name)
	{
		foreach ($values as $value) {
			if (is_null($value) || $value == '') {
				throw new Mailtrap_Exception('Invalid function values in ::' . $name);
			}
		}
	}

	/**
	 * Work horse. Every API call use this function to actually make the request to Mailtraps's servers.
	 *
	 * @link:http://docs.mailtrap.apiary.io/
	 *
	 * @param string $method API method name
	 * @param array $args query arguments
	 * @param string $http GET , POST , PATCH , DELETE  request type
	 * @param string $output API response format (json,php,xml,yaml). json and xml are decoded into arrays automatically.
	 * @return array|string|Mailtrap_Exception
	 */
	function request($method, $args = array (), $http = 'POST', $output = 'json')
	{
		if (!isset($args['api_token'])) {
			$args['api_token'] = $this->_apiToken;
		}

		$api_version = self::API_VERSION;
		$dot_output = ('json' == $output) ? '' : ".{$output}";

		$url = self::END_POINT . "{$api_version}/{$method}{$dot_output}";

		switch ($http) {

			case 'GET':
				//some distribs change arg sep to &amp; by default
				$sep_changed = false;
				if (ini_get("arg_separator.output") != "&") {
					$sep_changed = true;
					$orig_sep = ini_get("arg_separator.output");
					ini_set("arg_separator.output", "&");
				}

				$url .= '?' . http_build_query($args);

				if ($sep_changed) {
					ini_set("arg_separator.output", $orig_sep);
				}

				$response = $this->http_request($url, array (), 'GET');
				break;

			case 'POST':
				$response = $this->http_request($url, $args, 'POST');
				break;

			case 'PATCH':
				// replace url by value
				$sep_changed = false;
				if (ini_get("arg_separator.output") != "&") {
					$sep_changed = true;
					$orig_sep = ini_get("arg_separator.output");
					ini_set("arg_separator.output", "&");
				}

				$url .= '?' . http_build_query($args);

				if ($sep_changed) {
					ini_set("arg_separator.output", $orig_sep);
				}

				$response = $this->http_request($url, $args, 'PATCH');
				break;

			case 'DELETE':
				$response = $this->http_request($url, $args, 'DELETE');
				break;

			default:
				throw new Mailtrap_Exception('Unknown request type');
		}

		$response_code = $response['header']['http_code'];
		$body = $response['body'];

		switch ($output) {

			case 'json':
				$body = json_decode($body, true);
				break;

			case 'php':
				$body = unserialize($body);
				break;

			case 'txt':
				$body = $body;
				break;

			case 'html':
				$body = $body;
				break;

			default:
				$body = $body;
		}

		if (200 == $response_code || 201 == $response_code) {
			return $body;
		} else {
			switch ($response_code) {
				case '404':
					$message = 'Not Found - resource was not found.';
					break;
				case '204':
					$message = 'No Content - the request was successful but there is no representation to return (i.e. the response is empty).';
					break;
				case '400':
					$message = 'Bad Request - the request could not be understood or was missing required parameters.';
					break;
				case '401':
					$message = 'Unauthorized - authentication failed or user doesnt have permissions for requested operation.';
					break;
				case '403':
					$message = 'Forbidden - access denied.';
					break;
				case '404':
					$message = 'Not Found - resource was not found.';
					break;
				case '405':
					$message = 'Method Not Allowed - requested method is not supported for resource.';
					break;
				case '422':
					$message = 'Unprocessable Entity - requested data contain invalid values.';
					break;
				case '429':
					$message = 'Too Many Requests - exceeded Mailtrap API limits. Pause requests, wait up to one minute, and try again.';
					break;
				default:
					$message = 'Something went wrong ! No valid response code found.';
			}

			throw new Mailtrap_Exception($response_code . '-' . $message);
		}
	}

	function http_request($url, $fields = array (), $method = 'POST')
	{
		if (!in_array($method, array ('POST', 'GET', 'PATCH', 'DELETE'))) {
			$method = 'POST';
		}
		if (!isset($fields['api_token'])) {
			$fields['api_token'] = $this->_apiToken;
		}

		//some distribs change arg sep to &amp; by default
		$sep_changed = false;
		if (ini_get("arg_separator.output") != "&") {
			$sep_changed = true;
			$orig_sep = ini_get("arg_separator.output");
			ini_set("arg_separator.output", "&");
		}

		$fields = is_array($fields) ? http_build_query($fields) : $fields;

		if ($sep_changed) {
			ini_set("arg_separator.output", $orig_sep);
		}

		if ($this->_isDebugEnabled) {
			log_message('debug', "Mailtrap::http_request: URL: $url - Fields: $fields");
		}

		if (function_exists('curl_init') && function_exists('curl_exec')) {

			if (!ini_get('safe_mode')) {
				set_time_limit(2 * 60);
			}
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);

			//curl_setopt($ch, CURLOPT_POST, $method == 'POST');
			//curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
			// need to know how are fiels passed
			// Delete
			//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");

			//post
			//curl_setopt($ch, CURLOPT_POST, TRUE);
			//curl_setopt($ch, CURLOPT_POSTFIELDS, "{\n    \"inbox\": {\n        \"name\": \"New inbox\"\n    }\n}");
			//curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

			// Patch
			//curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
			//curl_setopt($ch, CURLOPT_POSTFIELDS, "{\n    \"user\": {\n        \"name\": \"James Bond\"\n    }\n}");
			if ($method == 'PATCH') {
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
				if (count($fields) > 1) {
					curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
				}
			}

			curl_setopt($ch, CURLOPT_HTTPHEADER, array ('Expect:'));
			curl_setopt($ch, CURLOPT_HEADER, false);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2 * 60 * 1000);

			$response = curl_exec($ch);
			$info = curl_getinfo($ch);
			$error = curl_error($ch);

			curl_close($ch);


		} elseif (function_exists('fsockopen')) {
			$parsed_url = parse_url($url);

			$host = $parsed_url['host'];
			if (isset($parsed_url['path'])) {
				$path = $parsed_url['path'];
			} else {
				$path = '/';
			}

			$params = '';
			if (isset($parsed_url['query'])) {
				$params = $parsed_url['query'] . '&' . $fields;
			} elseif (trim($fields) != '') {
				$params = $fields;
			}

			if (isset($parsed_url['port'])) {
				$port = $parsed_url['port'];
			} else {
				$port = ($parsed_url['scheme'] == 'https') ? 443 : 80;
			}

			$response = false;

			$errno = '';
			$errstr = '';
			ob_start();
			$fp = fsockopen('ssl://' . $host, $port, $errno, $errstr, 5);

			if ($fp !== false) {
				stream_set_timeout($fp, 30);

				$payload = "$method $path HTTP/1.0\r\n" .
					  "Host: $host\r\n" .
					  "Connection: close\r\n" .
					  "Content-type: application/x-www-form-urlencoded\r\n" .
					  "Content-length: " . strlen($params) . "\r\n" .
					  "Connection: close\r\n\r\n" .
					  $params;
				fwrite($fp, $payload);
				stream_set_timeout($fp, 30);

				$info = stream_get_meta_data($fp);
				while ((!feof($fp)) && (!$info["timed_out"])) {
					$response .= fread($fp, 4096);
					$info = stream_get_meta_data($fp);
				}

				fclose($fp);
				ob_end_clean();

				list($headers, $response) = explode("\r\n\r\n", $response, 2);

				if (ini_get("magic_quotes_runtime")) {
					$response = stripslashes($response);
				}
				$info = array ('http_code' => 200);
			} else {
				ob_end_clean();
				$info = array ('http_code' => 500);
				throw new Exception($errstr, $errno);
			}
			$error = '';
		} else {
			throw new Mailtrap_Exception("No valid HTTP transport found", -99);
		}

		return array ('header' => $info, 'body' => $response, 'error' => $error);
	}

}