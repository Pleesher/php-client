<?php
namespace Pleesher\Client;

use Pleesher\Client\Exception\Exception;
use Pleesher\Client\Exception\NoSuchObjectException;

abstract class Oauth2Client
{
	protected $client_id;
	protected $client_secret;
	protected $api_version;
	protected $cache_storage;

	public $logger;
	public $in_error;

	public function __construct($client_id, $client_secret, $api_version = '1.0', array $options = array())
	{
		$this->client_id = $client_id;
		$this->client_secret = $client_secret;
		$this->api_version = $api_version;
		$this->options = $options;
		$this->in_error = false;

		$this->setCacheStorage(new \Pleesher\Client\Cache\LocalStorage());
		$this->setLogger(new \Psr\Log\NullLogger());
	}

	public function setCacheStorage(\Pleesher\Client\Cache\Storage $cache_storage, $scope = null)
	{
		$this->cache_storage = $cache_storage;
		$this->cache_storage->setScope($scope);
	}

	public function setLogger(\Psr\Log\LoggerInterface $logger)
	{
		$this->logger = $logger;
	}

	public function call($verb, $url, array $data = array())
	{
		$this->logger->info(__METHOD__, func_get_args());

		try {
			return $this->getResultContents($this->callWebservice($verb, $url, $data));

		} catch (Exception $e) {
			if ($e->getErrorCode() == 'invalid_token')
			{
				$this->refreshAccessToken();
				return $this->getResultContents($this->callWebservice($verb, $url, $data));
			}

			$this->in_error = true;
			throw $e;
		}
	}

	protected function curl(array $curl_options)
	{
		$this->logger->info(__METHOD__, func_get_args());

		// Initialize cURL

		if (!function_exists('curl_version'))
			throw new Exception('cURL is not available');

		$curl_request = curl_init();
		if ($curl_request === false)
			throw new Exception('Could not initialize cURL request');

		if (curl_setopt_array($curl_request, $curl_options + $this->getBaseCurlOptions()) === false)
			throw new Exception('Could not set cURL request options (' . curl_error($curl_request) . ')');

		// Execute cURL request and retrieve HTTP status code

		$response = curl_exec($curl_request);
		if ($response === false)
			throw new Exception('Could not execute cURL request (' . curl_error($curl_request) . ')');

		if (($http_status = curl_getinfo($curl_request, CURLINFO_HTTP_CODE)) === false
				|| ($header_size = curl_getinfo($curl_request, CURLINFO_HEADER_SIZE)) === false)
			throw new Exception('Could not retrieve http status code or header size (' . curl_error($curl_request) . ')');

		curl_close($curl_request);

		// Retrieve headers

		$header = substr($response, 0, $header_size);

		$matches = array();
		if (preg_match_all('/^([^:]+):\s+(.*)$/m', $header, $matches, PREG_SET_ORDER) === false)
			throw new Exception('Could not parse http response headers');

		$headers = array();

		if (count($matches) > 1)
			foreach (array_slice($matches, 1) as $match)
				$headers[$match[1]] = isset($match[2]) ? $match[2] : null;

		// Retrieve body

		$body = substr($response, $header_size);

		return array($http_status, $headers, $body);
	}

	/**
	 * Calls a webservice and return HTTP status code, headers and body
	 * @param string $verb The HTTP verb to use (GET, POST or DELETE are supported)
	 * @param string $uri The webservice URI to target
	 * @param array $data An optional data array (for POST queries)
	 * @return array An array containing three values: HTTP status code (as integer), headers (as array), body (as string)
	 */
	protected function callWebservice($verb, $uri, array $data = array(), array $options = array())
	{
		$this->logger->info(__METHOD__, func_get_args());

		// Data

// 		$data = array_map(function($value) {
// 			if (is_bool($value))
// 				$value = $value ? 1 : 0;

// 			return $value;
// 		}, $data);

		// Build cURL option array

		if ($verb == 'GET')
		{
			$post_fields = array();
			$uri .= '?' . html_entity_decode(http_build_query($data));
		}
		else
			$post_fields = $data;

		$access_token_object = $this->getAccessToken();

		return $this->curl(array(
			CURLOPT_CUSTOMREQUEST => $verb,
			CURLOPT_URL           => $this->getRootUrl() . '/' . $this->api_version . '/' . $uri,
			CURLOPT_POSTFIELDS    => html_entity_decode(http_build_query($post_fields)),
			CURLOPT_HTTPHEADER    => array('Authorization: Bearer ' . $access_token_object->access_token)
		));
	}

	protected function getResultContents($webservice_result)
	{
		list($http_status, , $body) = $webservice_result;
		$result_contents = json_decode($body);
		if (($json_error = json_last_error()) !== JSON_ERROR_NONE)
		{
			$this->logger->error(sprintf('cURL result error (JSON error code %d): %s', $json_error, $body));
			throw new Exception('Could not parse webservice query result');
		}

		switch ($http_status)
		{
			case 200:
				return $result_contents;

			case 404:
				throw new NoSuchObjectException($result_contents->error_description, $result_contents->error);

			default:
				throw new Exception($result_contents->error_description, $result_contents->error);
		}
	}

	protected function getBaseCurlOptions()
	{
		$options = array(
			CURLOPT_HEADER => true,
			CURLOPT_RETURNTRANSFER => true
		);

		// If cURL version < 7.10.0, these options aren't included by default
		if (curl_version() < 461312)
		{
			$options += array(
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_CAINFO => __DIR__ . '/cacert.pem',
			);
		}

		return $options;
	}

	protected abstract function getRootUrl();
	protected abstract function getAccessToken();
	protected abstract function refreshAccessToken();
}
