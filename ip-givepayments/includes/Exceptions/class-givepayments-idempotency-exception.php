<?php
/**
 * Idempotency exception, thrown when an operation cannot proceed because
 * the idempotency guard detects a duplicate or conflicting request.
 *
 * Examples: refund already completed, refund already pending (within
 * the pending-window), duplicate payment detected on retry.
 *
 * Usage:
 *   throw GIVEPAYMENTS_Idempotency_Exception::from_message(
 *       'givepayments_refund_already_completed',
 *       __( 'This order has already been refunded.', 'givepayments-for-woocommerce' )
 *   );
 *
 * @package GivePayments_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Idempotency_Exception extends GIVEPAYMENTS_Exception {

    /** @var string WP_Error code for the WooCommerce boundary. */
    private $wp_error_code;

    /**
     * @param string $wp_error_code WP_Error code.
     * @param string $message       Human-readable message.
     */
    private function __construct( string $wp_error_code, string $message ) {
        parent::__construct( $message );
        $this->wp_error_code = $wp_error_code;
    }

    /**
     * Named constructor.
     *
     * @param string $wp_error_code
     * @param string $message
     * @return static
     */
    public static function from_message( string $wp_error_code, string $message ): self {
        return new static( $wp_error_code, $message );
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
