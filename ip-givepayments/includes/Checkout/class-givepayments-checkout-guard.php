<?php
/**
 * Checkout flow guards for GivePayments.
 *
 * Stateless helpers that protect checkout session integrity and control
 * front-end asset enqueuing, extracted from GIVEPAYMENTS_Gateway so they
 * can be tested and reused independently of the gateway instance.
 *
 * All methods are static, no instance state is required.
 *
 * @package GivePayments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Checkout_Guard {

    /**
     * If a GivePayments draft/pending order exists in the current WC session,
     * set it to "failed" and clear the Blocks draft-order pointer.
     *
     * Called from validate_fields() failures so that:
     * - Blocks / Store API: the draft order in 'store_api_draft_order' is failed
     *   immediately, preventing WC Blocks from reusing it on the next attempt.
     * - Classic checkout: the method exits harmlessly (no draft order in session).
     *
     * @param string $note Admin-visible order note explaining the failure.
     * @return void
     */
    public static function fail_if_pending( string $note = '' ): void {
        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session ) {
            return;
        }

        // WC Blocks stores the current draft order under 'store_api_draft_order'.
        // Classic checkout uses 'order_awaiting_payment'.
        $order_id = absint( WC()->session->get( 'store_api_draft_order' ) );
        if ( ! $order_id ) {
            $order_id = absint( WC()->session->get( 'order_awaiting_payment' ) );
        }
        if ( ! $order_id ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || 'givepayments' !== $order->get_payment_method() || $order->is_paid() ) {
            return;
        }

        if ( $order->has_status( array( 'pending', 'checkout-draft' ) ) ) {
            $order->update_status(
                'failed',
                $note ?: __( 'GivePayments: payment failed at field validation.', 'givepayments-for-woocommerce' )
            );
            $order->save();

            GIVEPAYMENTS_Logger::log(
                sprintf( 'Order %d set to failed, payment validation error (Blocks/retry context).', $order_id ),
                'warning'
            );

            // Clear the draft-order session pointer so WC Blocks creates a fresh
            // order on the next attempt. Without this, older WC/Blocks builds can
            // try to reuse the now-failed order and return a 400 before our
            // gateway code even runs.
            WC()->session->set( 'store_api_draft_order', 0 );
        }
    }

    /**
     * Return true only when the GivePayments gateway is available on the current
     * checkout page (excludes the order-received/thank-you page).
     *
     * Used to guard front-end checkout script/style enqueuing so assets are not
     * loaded on pages where the card form is not rendered.
     *
     * @return bool
     */
    public static function should_enqueue_assets(): bool {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return false;
        }

        if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
            return false;
        }

        if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->payment_gateways() ) {
            return false;
        }

        $available = WC()->payment_gateways()->get_available_payment_gateways();
        return is_array( $available ) && isset( $available['givepayments'] );
    }
}
