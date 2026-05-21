<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Subscription_Adapter_Registry {
    /**
     * @var array<int, GIVEPAYMENTS_Subscription_Adapter_Interface>
     */
    private $adapters = array();

    /**
     * @param array<int, GIVEPAYMENTS_Subscription_Adapter_Interface> $adapters
     */
    public function __construct( array $adapters ) {
        foreach ( $adapters as $adapter ) {
            if ( $adapter instanceof GIVEPAYMENTS_Subscription_Adapter_Interface ) {
                $this->adapters[] = $adapter;
            }
        }
    }

    /**
     * @return self
     */
    public static function build_default() {
        return new self(
            array(
                new GIVEPAYMENTS_Subscription_Adapter_WCS(),
                new GIVEPAYMENTS_Subscription_Adapter_WPS_SFW(),
                new GIVEPAYMENTS_Subscription_Adapter_YITH(),
                new GIVEPAYMENTS_Subscription_Adapter_FSB(),
            )
        );
    }

    /**
     * @param mixed $order
     * @return bool
     */
    public function order_contains_subscription( $order ) {
        $order_id = $this->get_order_id( $order );
        foreach ( $this->adapters as $adapter ) {
            if ( $adapter->order_contains_subscription( $order ) ) {
                $this->log_debug(
                    sprintf(
                        'Subscription adapter matched order context. adapter=%s order_id=%d',
                        $adapter->get_name(),
                        $order_id
                    )
                );
                return true;
            }
        }

        $this->log_debug(
            sprintf(
                'No subscription adapter matched order context. order_id=%d',
                $order_id
            )
        );

        return false;
    }

    /**
     * @param int    $order_id
     * @param string $token_id
     * @return void
     */
    public function store_token_for_order( $order_id, $token_id ) {
        $token_suffix = $this->mask_token_suffix( $token_id );
        $this->log_debug(
            sprintf(
                'Dispatching subscription token storage. order_id=%d token_suffix=%s adapter_count=%d',
                (int) $order_id,
                $token_suffix,
                count( $this->adapters )
            )
        );

        foreach ( $this->adapters as $adapter ) {
            $adapter->store_token_for_order( $order_id, $token_id );
            $this->log_debug(
                sprintf(
                    'Subscription token storage adapter executed. adapter=%s order_id=%d',
                    $adapter->get_name(),
                    (int) $order_id
                )
            );
        }
    }

    /**
     * @param mixed $renewal_order
     * @param array $context
     * @return string
     */
    public function resolve_renewal_card_token( $renewal_order, array $context = array() ) {
        $order_id = $this->get_order_id( $renewal_order );
        $source   = isset( $context['source'] ) ? (string) $context['source'] : '';

        foreach ( $this->adapters as $adapter ) {
            $token = sanitize_text_field( (string) $adapter->resolve_renewal_card_token( $renewal_order, $context ) );
            if ( '' !== $token ) {
                $this->log_debug(
                    sprintf(
                        'Renewal token resolved by adapter. adapter=%s source=%s renewal_order_id=%d token_suffix=%s',
                        $adapter->get_name(),
                        $source,
                        $order_id,
                        $this->mask_token_suffix( $token )
                    )
                );
                return $token;
            }
        }

        $this->log_debug(
            sprintf(
                'Renewal token could not be resolved by any adapter. source=%s renewal_order_id=%d',
                $source,
                $order_id
            )
        );

        return '';
    }

    /**
     * @param mixed $order
     * @return int
     */
    private function get_order_id( $order ) {
        if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
            return (int) $order->get_id();
        }

        return 0;
    }

    /**
     * @param string $token
     * @return string
     */
    private function mask_token_suffix( $token ) {
        $token = sanitize_text_field( (string) $token );
        if ( '' === $token ) {
            return 'none';
        }

        return substr( $token, -6 );
    }

    /**
     * @param string $message
     * @return void
     */
    private function log_debug( $message ) {
        if ( class_exists( 'GIVEPAYMENTS_Logger' ) ) {
            GIVEPAYMENTS_Logger::log( $message, 'debug' );
        }
    }
}
