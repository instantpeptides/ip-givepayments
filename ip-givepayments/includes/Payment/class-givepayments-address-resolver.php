<?php
/**
 * Address/state resolution utilities for GivePayments API requests.
 *
 * The GivePayments API requires a non-empty state/subdivision string for every
 * payment. Many countries (Czech Republic, Netherlands, most of continental
 * Europe) have no WooCommerce state list and leave the field blank. This class
 * handles the normalisation so callers never have to worry about it.
 *
 * All methods are static, no instance state is required.
 *
 * @package GivePayments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Address_Resolver {

    /**
     * Return a non-empty billing state string suitable for the GivePayments API.
     *
     * @param WC_Order $order
     * @return string
     */
    public static function resolve_billing_state( WC_Order $order ): string {
        return self::resolve_state( $order, 'billing' );
    }

    /**
     * Return a non-empty state/subdivision string for a billing or shipping address.
     *
     * Resolution order:
     *  1. Use the WooCommerce state value when present.
     *  2. Use '-' for countries that have no subdivision list in WooCommerce.
     *  3. Use '-' for countries whose locale marks state as optional.
     *  4. Fall back to city (truncated to 48 chars) when subdivisions exist but
     *     the customer left the state field empty.
     *  5. Ultimate fallback: '-'.
     *
     * @param WC_Order $order
     * @param string   $type 'billing' or 'shipping'
     * @return string Non-empty state/subdivision string for the API.
     */
    public static function resolve_state( WC_Order $order, string $type ): string {
        if ( 'shipping' === $type ) {
            $state   = trim( (string) $order->get_shipping_state() );
            $country = strtoupper( trim( (string) $order->get_shipping_country() ) );
            $city    = trim( (string) $order->get_shipping_city() );
        } else {
            $state   = trim( (string) $order->get_billing_state() );
            $country = strtoupper( trim( (string) $order->get_billing_country() ) );
            $city    = trim( (string) $order->get_billing_city() );
        }

        if ( '' !== $state ) {
            return $state;
        }

        if ( '' === $country ) {
            return '-';
        }

        if ( function_exists( 'WC' ) && WC()->countries ) {
            $states = WC()->countries->get_states( $country );
            // No subdivisions configured for this country, checkout usually omits state.
            if ( empty( $states ) ) {
                return '-';
            }

            $locale_all = WC()->countries->get_country_locale();
            if ( isset( $locale_all[ $country ]['state']['required'] ) && false === $locale_all[ $country ]['state']['required'] ) {
                return '-';
            }
        }

        // Subdivisions exist and may be required, but value is still empty, use city as best-effort.
        if ( '' !== $city ) {
            return function_exists( 'mb_substr' ) ? mb_substr( $city, 0, 48 ) : substr( $city, 0, 48 );
        }

        return '-';
    }
}
