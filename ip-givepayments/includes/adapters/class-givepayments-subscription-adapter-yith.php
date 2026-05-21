<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Adapter for YITH WooCommerce Subscription (YWSBS), the free plugin by YITH.
 *
 * Detection:  product has `_ywsbs_subscription = yes` in wp_postmeta.
 * Subscriptions: custom post type `ywsbs_subscription` linked to parent order
 *                via postmeta key `order_id`.
 * Renewal hook: ywsbs_pay_renew_order_with_{gateway_id}($renewal_order)
 *              , renewal order has postmeta `subscriptions` (serialized array of
 *                 ywsbs_subscription post IDs).
 */
class GIVEPAYMENTS_Subscription_Adapter_YITH implements GIVEPAYMENTS_Subscription_Adapter_Interface {
    public function get_name() {
        return 'ywsbs';
    }

    public function order_contains_subscription( $order ) {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
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

            $flag = get_post_meta( $product_id, '_ywsbs_subscription', true );
            if ( 'yes' === $flag ) {
                return true;
            }
        }

        return false;
    }

    public function store_token_for_order( $order_id, $token_id ) {
        $token_id = sanitize_text_field( (string) $token_id );
        if ( '' === $token_id ) {
            return;
        }

        $order_id = (int) absint( $order_id );

        // Find ywsbs_subscription posts linked to this parent order via 'order_id' meta.
        $subscription_ids = get_posts( array(
            'post_type'      => 'ywsbs_subscription',
            'post_status'    => 'any',
            'numberposts'    => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => 'order_id',
                    'value'   => (string) $order_id,
                    'compare' => '=',
                ),
            ),
        ) );

        // Fallback: search serialized payed_order_list meta.
        if ( empty( $subscription_ids ) ) {
            $subscription_ids = get_posts( array(
                'post_type'   => 'ywsbs_subscription',
                'post_status' => 'any',
                'numberposts' => -1,
                'fields'      => 'ids',
                'meta_query'  => array(
                    array(
                        'key'     => 'payed_order_list',
                        'value'   => 'i:' . $order_id . ';',
                        'compare' => 'LIKE',
                    ),
                ),
            ) );
        }

        foreach ( $subscription_ids as $subscription_id ) {
            update_post_meta( (int) $subscription_id, GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, $token_id );
        }
    }

    public function resolve_renewal_card_token( $renewal_order, array $context = array() ) {
        $source = isset( $context['source'] ) ? (string) $context['source'] : '';
        if ( 'ywsbs' !== $source ) {
            return '';
        }

        if ( ! is_object( $renewal_order ) || ! method_exists( $renewal_order, 'get_meta' ) ) {
            return '';
        }

        // Renewal order has 'subscriptions' meta = serialized array of ywsbs_subscription IDs.
        $subscriptions = $renewal_order->get_meta( 'subscriptions', true );
        if ( ! is_array( $subscriptions ) && is_string( $subscriptions ) && '' !== $subscriptions ) {
            $subscriptions = maybe_unserialize( $subscriptions );
        }

        if ( is_array( $subscriptions ) ) {
            foreach ( $subscriptions as $subscription_id ) {
                $subscription_id = (int) $subscription_id;
                if ( $subscription_id <= 0 ) {
                    continue;
                }

                $token = (string) get_post_meta( $subscription_id, GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, true );
                if ( '' !== $token ) {
                    return $token;
                }

                // Fallback: read token from parent order (HPOS-aware via WC order object).
                $parent_order_id = (int) get_post_meta( $subscription_id, 'order_id', true );
                if ( $parent_order_id > 0 ) {
                    $parent_order = wc_get_order( $parent_order_id );
                    if ( $parent_order && method_exists( $parent_order, 'get_meta' ) ) {
                        $token = (string) $parent_order->get_meta( GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, true );
                        if ( '' !== $token ) {
                            // Backfill so future renewals don't need the parent lookup.
                            update_post_meta( $subscription_id, GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, $token );
                            return $token;
                        }
                    }
                }
            }
        }

        // Last resort: token stored directly on the renewal order.
        return (string) $renewal_order->get_meta( GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, true );
    }
}
