<?php
/**
 * Subscription renewal routing: WCS, WPS SFW, and YITH YWSBS handlers.
 *
 * Extracted from GIVEPAYMENTS_Gateway. All methods are static.
 *
 * @package GivePayments_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Renewal_Handler {

    /**
     * Process a WooCommerce Subscriptions (WCS) or Flexible Subscriptions (FSB)
     * renewal payment using a stored card token.
     *
     * Both plugins fire woocommerce_scheduled_subscription_payment_{gateway_id}
     * with the same ($amount_to_charge, $renewal_order) signature. WCS and FSB
     * are mutually exclusive, FSB blocks activation when WCS is present.
     *
     * @param float    $amount_to_charge
     * @param WC_Order $renewal_order
     * @return void
     */
    public static function process_wcs_renewal( float $amount_to_charge, $renewal_order ): void {
        if ( ! $renewal_order || ! is_object( $renewal_order ) || ! method_exists( $renewal_order, 'get_meta' ) ) {
            return;
        }

        if ( function_exists( 'wcs_get_subscription' ) ) {
            $source          = 'wcs';
            $subscription_id = $renewal_order->get_meta( '_subscription_renewal' );
            $subscription    = wcs_get_subscription( $subscription_id );
            if ( ! $subscription ) {
                $renewal_order->update_status( 'failed', __( 'GivePayments: could not load WCS subscription for renewal.', 'givepayments-for-woocommerce' ) );
                return;
            }
        } elseif ( class_exists( 'WPDesk\\FlexibleSubscriptions\\Plugin' ) ) {
            $source          = 'fsb';
            $subscription_id = (int) $renewal_order->get_meta( '_subscription_renewal', true );
            $subscription    = $subscription_id > 0 ? wc_get_order( $subscription_id ) : null;
            if ( ! $subscription ) {
                $renewal_order->update_status( 'failed', __( 'GivePayments: could not load Flexible Subscriptions record for renewal.', 'givepayments-for-woocommerce' ) );
                return;
            }
        } else {
            $renewal_order->update_status( 'failed', __( 'GivePayments: no compatible subscription plugin found for renewal.', 'givepayments-for-woocommerce' ) );
            return;
        }

        $registry = GIVEPAYMENTS_Subscription_Service::get_adapter_registry();

        $card_token_id = $registry
            ? $registry->resolve_renewal_card_token(
                $renewal_order,
                array(
                    'source'       => $source,
                    'subscription' => $subscription,
                )
            )
            : '';

        if ( '' === $card_token_id ) {
            $renewal_order->update_status( 'failed', __( 'GivePayments: no card token stored for renewal.', 'givepayments-for-woocommerce' ) );
            return;
        }

        GIVEPAYMENTS_Subscription_Service::charge_renewal_with_token( $renewal_order, $amount_to_charge, $card_token_id );
    }

    /**
     * Process a Subscriptions for WooCommerce (WPS SFW) renewal payment.
     *
     * @param WC_Order $renewal_order
     * @param int      $subscription_id
     * @param string   $payment_method
     * @return void
     */
    public static function process_wps_sfw_renewal( $renewal_order, $subscription_id, string $payment_method ): void {
        if ( 'givepayments' !== $payment_method ) {
            return;
        }

        if ( ! $renewal_order || ! is_object( $renewal_order ) || ! method_exists( $renewal_order, 'get_total' ) ) {
            return;
        }

        $subscription = wc_get_order( absint( $subscription_id ) );
        if ( ! $subscription || ! method_exists( $subscription, 'get_meta' ) ) {
            $renewal_order->update_status( 'failed', __( 'GivePayments: could not load subscription for renewal.', 'givepayments-for-woocommerce' ) );
            return;
        }

        $registry = GIVEPAYMENTS_Subscription_Service::get_adapter_registry();

        $card_token_id = $registry
            ? $registry->resolve_renewal_card_token(
                $renewal_order,
                array(
                    'source'          => 'wps_sfw',
                    'subscription'    => $subscription,
                    'subscription_id' => (int) $subscription_id,
                )
            )
            : '';

        if ( '' === $card_token_id ) {
            $renewal_order->update_status( 'failed', __( 'GivePayments: no card token stored for renewal.', 'givepayments-for-woocommerce' ) );
            return;
        }

        GIVEPAYMENTS_Subscription_Service::charge_renewal_with_token( $renewal_order, (float) $renewal_order->get_total(), $card_token_id );
    }

    /**
     * Process a YITH WooCommerce Subscription (YWSBS) renewal payment.
     *
     * YWSBS fires ywsbs_pay_renew_order_with_{gateway_id} with a single $renewal_order arg.
     * Amount is read from the renewal order total.
     *
     * @param WC_Order $renewal_order
     * @return void
     */
    public static function process_ywsbs_renewal( $renewal_order ): void {
        if ( ! $renewal_order || ! is_object( $renewal_order ) || ! method_exists( $renewal_order, 'get_meta' ) ) {
            return;
        }

        $registry = GIVEPAYMENTS_Subscription_Service::get_adapter_registry();
        if ( ! $registry ) {
            $renewal_order->update_status( 'failed', __( 'GivePayments: subscription adapter unavailable for YWSBS renewal.', 'givepayments-for-woocommerce' ) );
            return;
        }

        $card_token_id = $registry->resolve_renewal_card_token(
            $renewal_order,
            array( 'source' => 'ywsbs' )
        );

        if ( '' === $card_token_id ) {
            $renewal_order->update_status( 'failed', __( 'GivePayments: no card token stored for YWSBS subscription renewal.', 'givepayments-for-woocommerce' ) );
            return;
        }

        GIVEPAYMENTS_Subscription_Service::charge_renewal_with_token( $renewal_order, (float) $renewal_order->get_total(), $card_token_id );
    }

    /**
     * Mark GivePayments as a supported recurring gateway for WPS Subscriptions
     * for WooCommerce checkout filtering.
     *
     * Hook: wps_sfw_supported_payment_gateway_for_woocommerce
     *
     * @param array $gateways
     * @return array
     */
    public static function add_wps_sfw_gateway( array $gateways ): array {
        if ( ! in_array( 'givepayments', $gateways, true ) ) {
            $gateways[] = 'givepayments';
        }
        return $gateways;
    }
}
