<?php
/**
 * GivePayments base exception.
 *
 * All plugin-specific exceptions extend this class so callers can catch the
 * full taxonomy with a single `catch ( GIVEPAYMENTS_Exception $e )`.
 * Every concrete subclass must implement `to_wp_error()` which translates the
 * exception into a `WP_Error` suitable for returning from WooCommerce hooks.
 *
 * @package GivePayments_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class GIVEPAYMENTS_Exception extends \RuntimeException {

    /**
     * Translate this exception into a WP_Error for WooCommerce hook boundaries.
     *
     * @return WP_Error
     */
    abstract public function to_wp_error(): WP_Error;
}
