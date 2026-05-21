<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adapter for Flexible Subscriptions by WP Desk (flexible-subscriptions, v1.7.x).
 *
 * Detection:    product type is 'fsb-subscription' or 'fsb-variable-subscription'.
 * Subscriptions: stored as WC_Order subtype 'fsb_subscription', linked to the parent
 *                order via standard WC order parent (post_parent / HPOS parent).
 * Renewal hook: woocommerce_scheduled_subscription_payment_{gateway_id}($amount, $renewal_order)
 *              , fired by FSB's HookMapper for WCS compatibility. The renewal order carries
 *                 '_subscription_renewal' meta pointing to the fsb_subscription order ID.
 * Token storage: both post-payment registry path and fsub/subscription/new hook path (in gateway
 *                constructor) write '_givepayments_card_token_id' onto the subscription order.
 */
class GIVEPAYMENTS_Subscription_Adapter_FSB implements GIVEPAYMENTS_Subscription_Adapter_Interface {

    public function get_name() {
        return 'fsb';
    }

    /**
     * Detect whether an order contains a Flexible Subscriptions product.
     *
     * @param mixed $order
     * @return bool
     */
    public function order_contains_subscription( $order ) {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
            return false;
        }

        if ( ! function_exists( 'wc_get_product' ) ) {
            return false;
        }

        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! is_object( $item ) ) {
                continue;
            }

            $product_id = 0;
            if ( is_callable( array( $item, 'get_variation_id' ) ) ) {
                $product_id = (int) $item->get_variation_id();
            }
            if ( $product_id <= 0 && is_callable( array( $item, 'get_product_id' ) ) ) {
                $product_id = (int) $item->get_product_id();
            }
            if ( $product_id <= 0 ) {
                continue;
            }

            $product = wc_get_product( $product_id );
            if ( ! $product || ! method_exists( $product, 'get_type' ) ) {
                continue;
            }

            $type = (string) $product->get_type();
            if ( 'fsb-subscription' === $type || 'fsb-variable-subscription' === $type ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store the card token on all FSB subscription records linked to this parent order.
     *
     * FSB stores subscriptions as WC_Order objects of type 'fsb_subscription' with the
     * parent order set via standard WC order parent relationship.
     *
     * @param int    $order_id
     * @param string $token_id
     * @return void
     */
    public function store_token_for_order( $order_id, $token_id ) {
        $token_id = sanitize_text_field( (string) $token_id );
        if ( '' === $token_id ) {
            return;
        }

        if ( ! function_exists( 'wc_get_orders' ) ) {
            return;
        }

        $order_id      = (int) absint( $order_id );
        $subscriptions = wc_get_orders(
            array(
                'type'   => 'fsb_subscription',
                'parent' => $order_id,
                'limit'  => -1,
                'return' => 'objects',
            )
        );

        foreach ( $subscriptions as $subscription ) {
            if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'update_meta_data' ) ) {
                continue;
            }

            $subscription->update_meta_data( GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, $token_id );
            $subscription->save();
        }
    }

    /**
     * Resolve the stored card token for a Flexible Subscriptions renewal.
     *
     * FSB's HookMapper sets '_subscription_renewal' meta on the renewal order (identical
     * to WCS) pointing to the fsb_subscription order ID. We read the token from that
     * subscription record, with a fallback to the parent order and backfill.
     *
     * @param mixed $renewal_order
     * @param array $context  Must contain ['source' => 'fsb'].
     * @return string
     */
    public function resolve_renewal_card_token( $renewal_order, array $context = array() ) {
        $source = isset( $context['source'] ) ? (string) $context['source'] : '';
        if ( 'fsb' !== $source ) {
            return '';
        }

        if ( ! is_object( $renewal_order ) || ! method_exists( $renewal_order, 'get_meta' ) ) {
            return '';
        }

        // Prefer subscription passed directly in context (avoids an extra DB hit).
        $subscription = isset( $context['subscription'] ) && is_object( $context['subscription'] )
            ? $context['subscription']
            : null;

        // Resolve from '_subscription_renewal' meta if not in context.
        if ( ! $subscription ) {
            $subscription_id = (int) $renewal_order->get_meta( '_subscription_renewal', true );
            if ( $subscription_id > 0 && function_exists( 'wc_get_order' ) ) {
                $subscription = wc_get_order( $subscription_id );
            }
        }

        if ( ! $subscription || ! method_exists( $subscription, 'get_meta' ) ) {
            return '';
        }

        $token = (string) $subscription->get_meta( GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, true );
        if ( '' !== $token ) {
            return $token;
        }

        // Fallback: look up token on parent order and backfill subscription.
        $parent_id = method_exists( $subscription, 'get_parent_id' ) ? (int) $subscription->get_parent_id() : 0;
        if ( $parent_id <= 0 ) {
            return '';
        }

        $parent_order = function_exists( 'wc_get_order' ) ? wc_get_order( $parent_id ) : null;
        if ( ! $parent_order || ! method_exists( $parent_order, 'get_meta' ) ) {
            return '';
        }

        $token = (string) $parent_order->get_meta( GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, true );
        if ( '' === $token ) {
            return '';
        }

        // Backfill so future renewals skip the parent lookup.
        if ( method_exists( $subscription, 'update_meta_data' ) ) {
            $subscription->update_meta_data( GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, $token );
            $subscription->save();
        }

        return $token;
    }
}
