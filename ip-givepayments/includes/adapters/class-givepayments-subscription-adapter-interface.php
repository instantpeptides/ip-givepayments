<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface GIVEPAYMENTS_Subscription_Adapter_Interface {
    /**
     * @return string
     */
    public function get_name();

    /**
     * @param mixed $order
     * @return bool
     */
    public function order_contains_subscription( $order );

    /**
     * @param int    $order_id
     * @param string $token_id
     * @return void
     */
    public function store_token_for_order( $order_id, $token_id );

    /**
     * @param mixed $renewal_order
     * @param array $context
     * @return string
     */
    public function resolve_renewal_card_token( $renewal_order, array $context = array() );
}
