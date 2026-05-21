<?php
/**
 * API exception, wraps a non-2xx HTTP response from the GivePayments API.
 *
 * Thrown by internal services when the provider returns an error, so the
 * boundary method (e.g. process_refund) can catch a single typed exception
 * and map it to a WP_Error without repeating HTTP-code inspection logic.
 *
 * Usage:
 *   throw GIVEPAYMENTS_Api_Exception::from_response(
 *       422,
 *       'Payment still settling',
 *       'givepayments_refund_failed'
 *   );
 *
 * @package GivePayments_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Api_Exception extends GIVEPAYMENTS_Exception {

    /** @var int HTTP status code returned by the API. */
    private $http_code;

    /** @var string WP_Error code for the WooCommerce boundary. */
    private $wp_error_code;

    /**
     * @param int    $http_code     HTTP status code.
     * @param string $message       Human-readable provider error message.
     * @param string $wp_error_code WP_Error code passed through to to_wp_error().
     */
    private function __construct( int $http_code, string $message, string $wp_error_code ) {
        parent::__construct( $message );
        $this->http_code     = $http_code;
        $this->wp_error_code = $wp_error_code;
    }

    /**
     * Named constructor, preferred over new directly.
     *
     * @param int    $http_code
     * @param string $message
     * @param string $wp_error_code
     * @return static
     */
    public static function from_response( int $http_code, string $message, string $wp_error_code = 'givepayments_api_error' ): self {
        return new static( $http_code, $message, $wp_error_code );
    }

    /**
     * HTTP status code from the provider response.
     *
     * @return int
     */
    public function http_code(): int {
        return $this->http_code;
    }

    /**
     * WP_Error code that will be used in to_wp_error().
     *
     * @return string
     */
    public function wp_error_code(): string {
        return $this->wp_error_code;
    }

    /**
     * @inheritDoc
     */
    public function to_wp_error(): WP_Error {
        return new WP_Error( $this->wp_error_code, $this->getMessage() );
    }
}
