<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Subscription_Adapter_WCS implements GIVEPAYMENTS_Subscription_Adapter_Interface {
    public function get_name() {
        return 'wcs';
    }

    public function order_contains_subscription( $order ) {
        return function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order );
    }

    public function store_token_for_order( $order_id, $token_id ) {
        $token_id = sanitize_text_field( (string) $token_id );
        if ( '' === $token_id || ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
            return;
        }

        foreach ( wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'parent' ) ) as $subscription ) {
            if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'update_meta_data' ) ) {
                continue;
            }

            $subscription->update_meta_data( GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, $token_id );
            $subscription->save();
        }
    }

    public function resolve_renewal_card_token( $renewal_order, array $context = array() ) {
        $source = isset( $context['source'] ) ? (string) $context['source'] : '';
        if ( 'wcs' !== $source ) {
            return '';
        }

        $subscription = isset( $context['subscription'] ) && is_object( $context['subscription'] )
            ? $context['subscription']
            : null;

        if ( ! $subscription && function_exists( 'wcs_get_subscription' ) && is_object( $renewal_order ) && method_exists( $renewal_order, 'get_meta' ) ) {
            $subscription = wcs_get_subscription( $renewal_order->get_meta( '_subscription_renewal' ) );
        }

        if ( ! $subscription || ! method_exists( $subscription, 'get_meta' ) ) {
            return '';
        }

        $card_token_id = (string) $subscription->get_meta( GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, true );

        if ( '' !== $card_token_id ) {
            return $card_token_id;
        }

        // The original code used $subscription->get_meta('wps_parent_order', true).
        // That is a WPS-SFW meta key, it is never set on WCS subscriptions, so the
        // fallback path was permanently dead for all WCS customers. WCS subscriptions
        // expose the parent order ID via get_parent_id().
        $parent_order_id = (int) $subscription->get_parent_id();
        if ( $parent_order_id <= 0 ) {
            return '';
        }

        $parent_order = wc_get_order( $parent_order_id );
        if ( ! $parent_order || ! method_exists( $parent_order, 'get_meta' ) ) {
            return '';
        }

        $card_token_id = (string) $parent_order->get_meta( GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, true );
        if ( '' === $card_token_id ) {
            return '';
        }

        if ( method_exists( $subscription, 'update_meta_data' ) ) {
            $subscription->update_meta_data( GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, $card_token_id );
            $subscription->save();
        }

        return $card_token_id;
    }
}
