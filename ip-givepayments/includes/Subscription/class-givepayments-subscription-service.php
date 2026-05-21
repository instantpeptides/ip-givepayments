<?php
/**
 * Subscription service utilities for GivePayments.
 *
 * Centralises the subscription-related helpers that the gateway previously
 * owned inline:
 *  - Adapter-registry access (with static cache to match previous per-request behaviour)
 *  - Order-contains-subscription check
 *  - Token storage on subscription records
 *  - FSB "new subscription" hook handler (token copy-across)
 *  - Card-token provisioning via POST /tokens
 *
 * All methods are static, no instance state is required.
 *
 * @package GivePayments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Subscription_Service {

    /**
     * Per-request registry cache (replaces the gateway's $subscription_adapter_registry property).
     *
     * @var GIVEPAYMENTS_Subscription_Adapter_Registry|null
     */
    private static ?GIVEPAYMENTS_Subscription_Adapter_Registry $registry_cache = null;

    // -------------------------------------------------------------------------
    // Registry
    // -------------------------------------------------------------------------

    /**
     * Return (and lazily build) the subscription adapter registry.
     *
     * @return GIVEPAYMENTS_Subscription_Adapter_Registry|null
     */
    public static function get_adapter_registry(): ?GIVEPAYMENTS_Subscription_Adapter_Registry {
        if ( self::$registry_cache instanceof GIVEPAYMENTS_Subscription_Adapter_Registry ) {
            return self::$registry_cache;
        }

        if ( ! class_exists( 'GIVEPAYMENTS_Subscription_Adapter_Registry' ) ) {
            return null;
        }

        self::$registry_cache = GIVEPAYMENTS_Subscription_Adapter_Registry::build_default();
        return self::$registry_cache;
    }

    // -------------------------------------------------------------------------
    // Subscription checks & token storage
    // -------------------------------------------------------------------------

    /**
     * Whether the order/cart contains subscription line-items from a supported plugin.
     *
     * @param WC_Order $order
     * @return bool
     */
    public static function order_contains_subscription( $order ): bool {
        $registry = self::get_adapter_registry();
        if ( ! $registry ) {
            return false;
        }

        try {
            return (bool) $registry->order_contains_subscription( $order );
        } catch ( \Throwable $t ) {
            return false;
        }
    }

    /**
     * Store the reusable card token against every subscription record linked to
     * the parent order for all supported subscription plugins.
     *
     * Adapter errors are swallowed silently to avoid breaking the checkout flow.
     *
     * @param int    $order_id
     * @param string $token_id
     * @return void
     */
    public static function store_token_for_order( int $order_id, string $token_id ): void {
        $token_id = sanitize_text_field( $token_id );
        if ( '' === $token_id ) {
            return;
        }

        $registry = self::get_adapter_registry();
        if ( ! $registry ) {
            return;
        }

        try {
            $registry->store_token_for_order( $order_id, $token_id );
        } catch ( \Throwable $t ) {
            // Swallow, adapter errors must not break checkout.
        }
    }

    // -------------------------------------------------------------------------
    // FSB hook handler
    // -------------------------------------------------------------------------

    /**
     * Copy the card token stored on a parent order across to a newly created
     * Flexible Subscriptions (FSB) subscription record.
     *
     * Hooked to the FSB-specific action that fires after subscription creation.
     *
     * @param object $subscription         The new subscription record.
     * @param object $order                The originating WooCommerce order.
     * @param mixed  $subscription_candidate Raw candidate data from FSB (unused).
     * @return void
     */
    public static function on_fsb_subscription_new( $subscription, $order, $subscription_candidate ): void {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
            return;
        }

        if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'update_meta_data' ) ) {
            return;
        }

        $token_id = sanitize_text_field( GIVEPAYMENTS_Payment_Record::for( $order )->card_token_id() );
        if ( '' === $token_id ) {
            return;
        }

        $subscription->update_meta_data( GIVEPAYMENTS_Payment_Record::META_CARD_TOKEN_ID, $token_id );
        $subscription->save();
    }

    // -------------------------------------------------------------------------
    // Card-token provisioning
    // -------------------------------------------------------------------------

    /**
     * Create a reusable card token via POST /tokens as a fallback when the
     * /payments response omits paymethod_token.
     *
     * @param string $api_key      Plaintext (CRLF-stripped) API key.
     * @param string $base_url     API base URL (no trailing slash).
     * @param string $requestor_ip End-user IP for the GP-Requestor-IP header.
     * @param string $requestor_ua User-agent string for the GP-Requestor-UA header.
     * @param string $card_name    Name on card.
     * @param string $card_number  Raw card number (digits only after sanitisation).
     * @param string $card_cvv     Raw CVV (digits only after sanitisation).
     * @param int    $exp_month    Expiry month (1–12).
     * @param int    $exp_year     Expiry year (4-digit).
     * @param string $line1        Billing address line 1.
     * @param string $line2        Billing address line 2.
     * @param string $city         Billing city.
     * @param string $state        Billing state/subdivision.
     * @param string $zip          Billing ZIP/postal code.
     * @param string $country      Billing country (ISO 3166-1 alpha-2).
     * @return string Token ID, or '' on failure.
     */
    public static function create_card_token(
        string $api_key,
        string $base_url,
        string $requestor_ip,
        string $requestor_ua,
        string $card_name,
        string $card_number,
        string $card_cvv,
        int    $exp_month,
        int    $exp_year,
        string $line1,
        string $line2,
        string $city,
        string $state,
        string $zip,
        string $country
    ): string {
        $payload = array(
            'card' => array(
                'name'      => sanitize_text_field( $card_name ),
                'number'    => preg_replace( '/\D+/', '', $card_number ),
                'cvv'       => preg_replace( '/\D+/', '', $card_cvv ),
                'exp_year'  => $exp_year,
                'exp_month' => $exp_month,
                'address'   => array(
                    'line1'   => sanitize_text_field( $line1 ),
                    'line2'   => sanitize_text_field( $line2 ),
                    'city'    => sanitize_text_field( $city ),
                    'state'   => sanitize_text_field( $state ),
                    'zip'     => sanitize_text_field( $zip ),
                    'country' => sanitize_text_field( $country ),
                ),
            ),
        );

        $response = ( new GIVEPAYMENTS_Api_Client( $api_key, $base_url ) )->post(
            '/tokens',
            $payload,
            array(
                'GP-Requestor-IP' => $requestor_ip,
                'GP-Requestor-UA' => $requestor_ua,
            )
        );

        if ( is_wp_error( $response ) ) {
            GIVEPAYMENTS_Logger::log(
                sprintf( 'Token provisioning transport error: %s', $response->get_error_message() ),
                'warning'
            );
            return '';
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( ! in_array( $code, array( 200, 201 ), true ) ) {
            GIVEPAYMENTS_Logger::log(
                sprintf( 'Token provisioning failed (HTTP %d).', $code ),
                'warning'
            );
            return '';
        }

        if ( ! is_object( $body ) || empty( $body->id ) ) {
            GIVEPAYMENTS_Logger::log( 'Token provisioning response missing token id.', 'warning' );
            return '';
        }

        return sanitize_text_field( (string) $body->id );
    }

    // -------------------------------------------------------------------------
    // Subscription renewal charge
    // -------------------------------------------------------------------------

    /**
     * Charge a subscription renewal order using a stored card token.
     *
     * Updates the renewal order to 'failed' on any hard error, or to 'pending'
     * while awaiting webhook confirmation of success.
     *
     * @param WC_Order $renewal_order   The renewal order to charge.
     * @param float    $amount_to_charge Amount in the store's base currency.
     * @param string   $card_token_id   Reusable card token ID from a previous checkout.
     * @return void
     */
    public static function charge_renewal_with_token( WC_Order $renewal_order, float $amount_to_charge, string $card_token_id ): void {
        $card_token_id = sanitize_text_field( $card_token_id );
        if ( '' === $card_token_id ) {
            $renewal_order->update_status( 'failed', __( 'GivePayments: no card token stored for renewal.', 'givepayments-for-woocommerce' ) );
            return;
        }

        $environment  = get_option( 'givepayments_environment', 'test' );
        $api_key_raw  = GIVEPAYMENTS_Request_Context::get_api_key_for_environment();
        $api_key      = str_replace( array( "\r", "\n" ), '', GIVEPAYMENTS_Request_Context::resolve_api_key( $api_key_raw ) );
        $base_url     = GIVEPAYMENTS_Request_Context::api_base_url( $environment );
        $decimals     = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
        $amount_cents = (int) round( $amount_to_charge * pow( 10, $decimals ) );
        $order_id     = $renewal_order->get_id();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $requestor_ip = GIVEPAYMENTS_Request_Context::get_client_ip();
        $requestor_ua = isset( $_SERVER['HTTP_USER_AGENT'] )
            ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 1024 )
            : 'WC-Scheduler';

        $idempotency_key = GIVEPAYMENTS_Idempotency_Manager::get_or_create( $renewal_order );

        $payload = array(
            'amount'             => $amount_cents,
            'external_reference' => (string) $order_id,
            'fee_payer'          => 'merchant',
            'send_receipt'       => false,
            'customer'           => array(
                'email'      => sanitize_email( $renewal_order->get_billing_email() ),
                'first_name' => sanitize_text_field( $renewal_order->get_billing_first_name() ),
                'last_name'  => sanitize_text_field( $renewal_order->get_billing_last_name() ),
                'phone'      => preg_replace( '/\D+/', '', (string) $renewal_order->get_billing_phone() ),
            ),
            'paymethod' => array(
                'card_token' => array( 'id' => $card_token_id ),
            ),
        );

        $response = ( new GIVEPAYMENTS_Api_Client( $api_key, $base_url ) )->post(
            '/payments',
            $payload,
            array(
                'GP-Requestor-IP' => $requestor_ip,
                'GP-Requestor-UA' => $requestor_ua,
                'Idempotency-Key' => $idempotency_key,
            )
        );

        if ( is_wp_error( $response ) ) {
            $renewal_order->update_status(
                'failed',
                sprintf(
                    /* translators: %s: error message */
                    __( 'GivePayments renewal failed (transport error): %s', 'givepayments-for-woocommerce' ),
                    $response->get_error_message()
                )
            );
            return;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( in_array( $code, array( 200, 201, 202 ), true ) ) {
            $txn_id = GIVEPAYMENTS_Api_Response::extract_transaction_id( $body );
            if ( '' !== $txn_id ) {
                $renewal_order->set_transaction_id( $txn_id );
                GIVEPAYMENTS_Payment_Record::for( $renewal_order )->set_transaction_id( $txn_id );
            }

            // Mirror the sync-decline guard from Payment_Processor::process().
            // A 200 with processingState='declined' is an instant card-level failure
            // on the stored token, the subscription charge was rejected immediately.
            // Move the renewal to 'failed' now; do not wait for a webhook that may
            // never arrive. Without this guard the renewal order sits in 'pending'
            // indefinitely, keeping the subscription active while no money moved.
            $sync_ps = GIVEPAYMENTS_Api_Response::extract_processing_state( $body );
            if ( '' !== $sync_ps && GIVEPAYMENTS_Api_Response::is_terminal_failure_state( $sync_ps ) ) {
                $renewal_order->update_status(
                    'failed',
                    sprintf(
                        /* translators: %s: processingState value from the provider API */
                        __( 'GivePayments renewal declined (processingState: %s). No funds were captured.', 'givepayments-for-woocommerce' ),
                        $sync_ps
                    )
                );
                $renewal_order->save();
                return;
            }

            $renewal_order->update_status( 'pending', __( 'GivePayments renewal initiated. Awaiting webhook confirmation.', 'givepayments-for-woocommerce' ) );
            $renewal_order->save();
            return;
        }

        $renewal_order->update_status(
            'failed',
            sprintf(
                /* translators: %d: HTTP status code */
                __( 'GivePayments renewal failed (HTTP %d).', 'givepayments-for-woocommerce' ),
                $code
            )
        );
    }
}
