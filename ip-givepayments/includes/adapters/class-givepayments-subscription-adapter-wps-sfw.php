<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Subscription_Adapter_WPS_SFW implements GIVEPAYMENTS_Subscription_Adapter_Interface {
    public function get_name() {
        return 'wps_sfw';
    }

    public function order_contains_subscription( $order ) {
        if ( ! is_object( $order ) ) {
            return false;
        }

        if ( method_exists( $order, 'get_meta' ) ) {
            $wps_subscription_id = (int) $order->get_meta( 'wps_subscription_id', true );
            if ( $wps_subscription_id > 0 ) {
                return true;
            }

            $wps_order_has_subscription = strtolower( trim( (string) $order->get_meta( 'wps_sfw_order_has_subscription', true ) ) );
            if ( in_array( $wps_order_has_subscription, array( 'yes', '1', 'true' ), true ) ) {
                return true;
            }
        }

        if ( method_exists( $order, 'get_items' ) ) {
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                if ( ! is_object( $item ) ) {
                    continue;
                }

                $product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
                if ( $product_id <= 0 && method_exists( $item, 'get_variation_id' ) ) {
                    $product_id = (int) $item->get_variation_id();
                }
                if ( $product_id <= 0 ) {
                    continue;
                }

                $is_sfw_product = strtolower( trim( (string) get_post_meta( $product_id, '_wps_sfw_product', true ) ) );
                if ( '' === $is_sfw_product ) {
                    $is_sfw_product = strtolower( trim( (string) get_post_meta( $product_id, 'wps_sfw_product', true ) ) );
                }
                if ( in_array( $is_sfw_product, array( 'yes', '1', 'true' ), true ) ) {
                    return true;
                }

                $sfw_interval = trim( (string) get_post_meta( $product_id, 'wps_sfw_subscription_interval', true ) );
                if ( '' !== $sfw_interval ) {
                    return true;
                }
            }
        }

        if ( function_exists( 'wps_sfw_is_cart_has_subscription_product' ) ) {
            return (bool) wps_sfw_is_cart_has_subscription_product();
        }

        return false;
    }

    public function store_token_for_order( $order_id, $token_id ) {
        $token_id = sanitize_text_field( (string) $token_id );
        if ( '' === $token_id ) {
            return;
        }

        if ( function_exists( 'wc_get_orders' ) ) {
            $sfw_subscriptions = wc_get_orders(
                array(
                    'type'       => 'wps_subscriptions',
                    'status'     => 'any',
                    'limit'      => -1,
                    'return'     => 'objects',
                    'meta_query' => array(
                        array(
                            'key'     => 'wps_parent_order',
                            'value'   => (string) absint( $order_id ),
                            'compare' => '=',
                        ),
                    ),
                )
            );

            foreach ( $sfw_subscriptions as $sfw_subscription ) {
                if ( ! is_object( $sfw_subscription ) || ! method_exists( $sfw_subscription, 'update_meta_data' ) ) {
                    continue;
                }

                $sfw_subscription->update_meta_data( GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, $token_id );
                $sfw_subscription->save();
            }
        }

        $wps_subscription_id = (int) get_post_meta( $order_id, 'wps_subscription_id', true );
        if ( $wps_subscription_id <= 0 ) {
            return;
        }

        if ( function_exists( 'wps_sfw_update_meta_data' ) ) {
            wps_sfw_update_meta_data( $wps_subscription_id, GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, $token_id );
            return;
        }

        update_post_meta( $wps_subscription_id, GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, $token_id );
    }

    public function resolve_renewal_card_token( $renewal_order, array $context = array() ) {
        $source = isset( $context['source'] ) ? (string) $context['source'] : '';
        if ( 'wps_sfw' !== $source ) {
            return '';
        }

        $subscription = isset( $context['subscription'] ) && is_object( $context['subscription'] )
            ? $context['subscription']
            : null;

        if ( ! $subscription && isset( $context['subscription_id'] ) ) {
            $subscription = wc_get_order( absint( $context['subscription_id'] ) );
        }

        if ( ! $subscription || ! method_exists( $subscription, 'get_meta' ) ) {
            return '';
        }

        return (string) $subscription->get_meta( GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, true );
    }
}
