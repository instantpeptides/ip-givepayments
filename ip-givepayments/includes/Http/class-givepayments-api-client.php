<?php
/**
 * GivePayments HTTP API Client.
 *
 * Centralises wp_remote_post / wp_remote_get: auth headers, base URL
 * construction, and timeout defaults. Returns the raw WP HTTP response
 * (array or WP_Error) so all existing callers continue to work unchanged.
 */

defined( 'ABSPATH' ) || exit;

class GIVEPAYMENTS_Api_Client {

	/** @var string Decrypted, CRLF-stripped API key. */
	private $api_key;

	/** @var string Base URL without trailing slash. */
	private $base_url;

	/**
	 * @param string $api_key  Decrypted API key. Must be CRLF-stripped by the caller.
	 * @param string $base_url e.g. 'https://connect.givepayments.com'
	 */
	public function __construct( $api_key, $base_url ) {
		$this->api_key  = $api_key;
		$this->base_url = rtrim( $base_url, '/' );
	}

	/**
	 * POST to a given path.
	 *
	 * Returns the raw WP HTTP response array, or WP_Error on transport failure.
	 *
	 * @param string $path          e.g. '/payments', '/refunds'
	 * @param array  $body          Payload, will be JSON-encoded.
	 * @param array  $extra_headers Optional headers merged over the auth defaults.
	 * @param int    $timeout       Request timeout in seconds.
	 * @return array|WP_Error
	 */
	public function post( $path, array $body, array $extra_headers = array(), $timeout = 20 ) {
		/** Filterable POST timeout. @param int $timeout Seconds. @param string $path API path. */
		$timeout = (int) apply_filters( 'givepayments_api_timeout', $timeout, $path, 'post' );
		return wp_remote_post(
			$this->url( $path ),
			array(
				'method'  => 'POST',
				'timeout' => $timeout,
				'headers' => array_merge( $this->default_headers(), $extra_headers ),
				'body'    => wp_json_encode( $body ),
			)
		);
	}

	/**
	 * GET a given path.
	 *
	 * @param string $path
	 * @param array  $extra_headers Optional headers merged over auth defaults.
	 * @param int    $timeout
	 * @return array|WP_Error
	 */
	public function get( $path, array $extra_headers = array(), $timeout = 15 ) {
		/** Filterable GET timeout. @param int $timeout Seconds. @param string $path API path. */
		$timeout = (int) apply_filters( 'givepayments_api_timeout', $timeout, $path, 'get' );
		return wp_remote_get(
			$this->url( $path ),
			array(
				'headers' => array_merge( $this->default_headers(), $extra_headers ),
				'timeout' => $timeout,
			)
		);
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	private function url( $path ) {
		return $this->base_url . '/' . ltrim( $path, '/' );
	}

	private function default_headers() {
		return array(
			'Authorization' => 'Bearer ' . $this->api_key,
			'X-Api-Key'     => $this->api_key,
			'Content-Type'  => 'application/json',
		);
	}
}
