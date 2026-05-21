<?php
/**
 * Core payment processing logic.
 *
 * Extracted from GIVEPAYMENTS_Gateway. All methods are static.
 *
 * @package GivePayments_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Payment_Processor {

    /**
     * Process a checkout payment for the given WooCommerce order.
     *
     * Mirrors the original GIVEPAYMENTS_Gateway::process_payment() exactly.
     * Returns the array expected by WooCommerce ('result' => 'success'|'failure').
     *
     * @param int $order_id WooCommerce order ID.
     * @return array{result: string, redirect?: string}
     */
    public static function process( int $order_id ): array {
        // Only enforce when the merchant ran connection test for THIS environment
        // and capability is explicitly not enabled. (Legacy single option supported.)
        $env_for_cap = get_option( 'givepayments_environment', 'test' );
        $cap_option  = ( 'production' === $env_for_cap )
            ? 'givepayments_can_process_money_production'
            : 'givepayments_can_process_money_test';
        $process_cap = get_option( $cap_option, null );
        if ( null === $process_cap || '' === $process_cap ) {
            $process_cap = get_option( 'givepayments_can_process_money', null );
        }
        if ( null !== $process_cap && '' !== $process_cap ) {
            $ok = in_array(
                strtolower( (string) $process_cap ),
                array( 'enabled', 'enable', 'active', 'yes', 'true', '1' ),
                true
            );
            if ( ! $ok ) {
                GIVEPAYMENTS_Logger::log( sprintf( 'Order %d blocked by capability guard. option=%s value=%s', (int) $order_id, $cap_option, (string) $process_cap ), 'warning' );
                wc_add_notice(
                    __( 'Sorry, we are unable to process payments at the moment. Please try again later.', 'givepayments-for-woocommerce' ),
                    'error'
                );
                return array( 'result' => 'failure' );
            }
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            GIVEPAYMENTS_Logger::log( sprintf( 'process_payment could not load order %d.', (int) $order_id ), 'error' );
            wc_add_notice( __( 'Order not found.', 'givepayments-for-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // Pre-flight guard, prevent duplicate charges when a payment
        // already exists on this order (timeout retry outside idempotency window,
        // race condition, stale browser tab, etc.).
        if ( $order->is_paid() ) {
            GIVEPAYMENTS_Logger::log( sprintf( 'Order %d is already paid.', (int) $order_id ), 'warning' );
            wc_add_notice( __( 'This order has already been paid.', 'givepayments-for-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }
        // GivePayments-specific guard: a successful charge was already confirmed for this
        // order. Blocks the narrow race window between API success and order-status update.
        if ( GIVEPAYMENTS_Idempotency_Manager::has_succeeded( $order ) ) {
            GIVEPAYMENTS_Logger::log(
                sprintf( 'Order %d blocked: GivePayments payment already succeeded (last_success_idempotency_key set).', (int) $order_id ),
                'warning'
            );
            wc_add_notice( __( 'A payment has already been successfully processed for this order.', 'givepayments-for-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }
        $existing_txn = (string) $order->get_transaction_id();
        if ( '' === $existing_txn ) {
            $existing_txn = GIVEPAYMENTS_Payment_Record::for( $order )->transaction_id();
        }
        if ( '' !== $existing_txn ) {
            GIVEPAYMENTS_Logger::log(
                sprintf( 'Duplicate payment attempt blocked for order %d (existing txn: %s).', $order_id, $existing_txn ),
                'warning'
            );
            wc_add_notice( __( 'A payment has already been submitted for this order. Please check your order status or contact support.', 'givepayments-for-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }
        // Note: 'pending' is intentionally NOT included, Woo creates every new order
        // in 'pending' status, so process_payment() always runs against a pending order.
        // 'on-hold' is kept for back-compat with orders set to on-hold by older versions.
        if ( in_array( $order->get_status(), array( 'processing', 'completed', 'on-hold' ), true ) ) {
            GIVEPAYMENTS_Logger::log( sprintf( 'Order %d is already in non-payable status: %s', (int) $order_id, (string) $order->get_status() ), 'warning' );
            wc_add_notice( __( 'This order is already being processed.', 'givepayments-for-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // Race Condition guard: Per-order checkout lock (MySQL atomic INSERT IGNORE via add_option).
        // Bounces a second concurrent request (rapid double-click, WC Blocks race) with a
        // user-friendly notice before it reaches billing validation or the API.
        // Released in the `finally` block below regardless of how process() exits.
        $checkout_lock     = 'gp_checkout_lock_' . (int) $order_id;
        $checkout_lock_now = time();
        if ( ! add_option( $checkout_lock, (string) $checkout_lock_now, '', 'no' ) ) {
            // Lock is held. Check for staleness, a PHP fatal leaves the lock permanently,
            // making the order un-payable. Locks older than 60 s are treated as stale.
            $lock_held_at = (int) get_option( $checkout_lock, $checkout_lock_now );
            if ( ( $checkout_lock_now - $lock_held_at ) <= 60 ) {
                GIVEPAYMENTS_Logger::log(
                    sprintf( 'Order %d: checkout lock held (%ds ago), concurrent payment request blocked.', (int) $order_id, $checkout_lock_now - $lock_held_at ),
                    'info'
                );
                wc_add_notice( __( 'Your order is already being processed. Please wait a moment.', 'givepayments-for-woocommerce' ), 'notice' );
                return array( 'result' => 'failure' );
            }
            // Stale lock from a crashed process, delete and re-acquire once.
            GIVEPAYMENTS_Logger::log(
                sprintf( 'Order %d: stale checkout lock recycled (was %ds old).', (int) $order_id, $checkout_lock_now - $lock_held_at ),
                'warning'
            );
            delete_option( $checkout_lock );
            if ( ! add_option( $checkout_lock, (string) $checkout_lock_now, '', 'no' ) ) {
                GIVEPAYMENTS_Logger::log(
                    sprintf( 'Order %d: checkout lock re-acquired by another process after stale-delete, blocking.', (int) $order_id ),
                    'info'
                );
                wc_add_notice( __( 'Your order is already being processed. Please wait a moment.', 'givepayments-for-woocommerce' ), 'notice' );
                return array( 'result' => 'failure' );
            }
        }

        // phpcs:ignore -- try/finally intentionally not re-indented to keep the diff minimal.
        try {

        $billing_zip = $order->get_billing_postcode();
        if ( empty( $billing_zip ) ) {
            if ( ! $order->is_paid() ) {
                $order->update_status( 'failed', __( 'GivePayments: billing ZIP code missing.', 'givepayments-for-woocommerce' ) );
                $order->save();
            }
            wc_add_notice( __( 'Please enter a valid ZIP code.', 'givepayments-for-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // GivePayments requires a valid, verified (non-disposable) email address.
        // Validate basic format early so customers get actionable feedback
        // instead of a provider-side invalid_input response.
        $billing_email = trim( (string) $order->get_billing_email() );
        if ( '' === $billing_email || ! filter_var( $billing_email, FILTER_VALIDATE_EMAIL ) ) {
            if ( ! $order->is_paid() ) {
                $order->update_status( 'failed', __( 'GivePayments: billing email missing or invalid.', 'givepayments-for-woocommerce' ) );
                $order->save();
            }
            wc_add_notice( __( 'Please enter a valid email address.', 'givepayments-for-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $api_key     = GIVEPAYMENTS_Request_Context::get_api_key_for_environment();
        $environment = get_option( 'givepayments_environment', 'test' );

        // Collect sanitized card data from native Woo checkout.
        $card_data   = GIVEPAYMENTS_Card_Input_Reader::get_sanitized_card_data();
        $card_name   = $card_data['card_name'];
        $card_number = $card_data['card_number'];
        $card_cvv    = $card_data['card_cvv'];
        $exp_month   = $card_data['exp_month'];
        $exp_year    = $card_data['exp_year'];
        $card_brand  = $card_data['card_brand'] ?? 'unknown';

        // Normalize expiry year: accept YY or YYYY, then convert to full year (20YY).
        if ( strlen( $exp_year ) === 2 ) {
            $exp_year = (int) ( '20' . $exp_year );
        } else {
            $exp_year = (int) $exp_year;
        }

        $requestor_ip = (string) GIVEPAYMENTS_Request_Context::get_client_ip();
        $requestor_ua = '';
        if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
            $requestor_ua = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 1024 );
        }

        $api_base = GIVEPAYMENTS_Request_Context::api_base_url( $environment );

        $decimals     = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
        $multiplier   = pow( 10, $decimals );
        $amount_cents = (int) round( (float) $order->get_total() * $multiplier );

        // WC Blocks creates draft orders before totals are fully settled.
        // A race between Store API order creation and total calculation can produce
        // a $0 amount here. Force a recalculation before accepting the value.
        if ( $amount_cents <= 0 ) {
            $order->calculate_totals();
            $amount_cents = (int) round( (float) $order->get_total() * $multiplier );
        }

        // Cross-order dedup sentinel.
        // A browser "Confirm Form Resubmission" creates a brand-new WC order; all
        // per-order guards (is_paid, has_succeeded, txn check) pass on the new ID.
        // Key = user_id + normalised cardholder name + last4 + amount_cents.
        // Name reduces false-positive collisions (especially for guest sessions where
        // user_id is always 0). Amount is kept so sequential same-card purchases at
        // the same price are never blocked.
        // Sentinel is SET only on confirmed success, declined-card retries are never blocked.
        $dedup_key = 'gp_dedup_' . md5(
            (string) get_current_user_id()
            . '_' . strtolower( trim( $card_name ) )
            . '_' . $card_brand
            . '_' . substr( preg_replace( '/\D/', '', $card_number ), -4 )
            . '_' . (string) $amount_cents
        );
        $dedup_sentinel = get_transient( $dedup_key );
        if ( $dedup_sentinel ) {
            $is_timeout_sentinel = ( 'timeout' === $dedup_sentinel );
            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'Order %d: cross-order dedup sentinel active (type: %s), duplicate submission blocked.',
                    (int) $order_id,
                    $is_timeout_sentinel ? 'timeout' : 'success'
                ),
                'warning'
            );
            // Timeout sentinel: a prior attempt timed out and the API may have already
            // processed the charge. Guide the customer to check their order status
            // rather than showing a generic "already submitted" message.
            $notice = $is_timeout_sentinel
                ? __( 'A payment for this amount is already being processed. Please wait a few minutes, if the charge completes you will receive a confirmation email; otherwise you may try again shortly.', 'givepayments-for-woocommerce' )
                : __( 'A payment for this card and amount was already processed. Please wait a moment and check your email for a confirmation, or contact support if you believe this is an error.', 'givepayments-for-woocommerce' );

            // Set this newly-created order to failed so it does not linger as
            // a stale pending order. WC has already created it before calling
            // process_payment(), returning 'failure' alone does not cancel it.
            if ( ! $order->is_paid() ) {
                $order->update_status(
                    'failed',
                    $is_timeout_sentinel
                        ? __( 'GivePayments: blocked by dedup sentinel, a prior payment attempt for this card/amount may still be processing.', 'givepayments-for-woocommerce' )
                        : __( 'GivePayments: blocked by dedup sentinel, a payment for this card/amount was already confirmed.', 'givepayments-for-woocommerce' )
                );
                $order->save();
            }

            wc_add_notice( $notice, 'error' );
            return array( 'result' => 'failure' );
        }

        $phone = (string) $order->get_billing_phone();
        // Normalize to digits-only to avoid provider validation issues from spaces/dashes/etc.
        $phone = preg_replace( '/\D+/', '', $phone );

        $company_name = (string) $order->get_billing_company();

        $payload = array(
            'amount'   => $amount_cents,
            'customer' => array(
                'email'        => sanitize_email( $billing_email ),
                'phone'        => sanitize_text_field( $phone ),
                'first_name'   => sanitize_text_field( trim( $order->get_billing_first_name() ) ),
                'last_name'    => sanitize_text_field( trim( $order->get_billing_last_name() ) ),
                'company_name' => $company_name ? sanitize_text_field( $company_name ) : null,
                'shipping_address' => array(
                    'line1'   => sanitize_text_field( $order->get_shipping_address_1() ),
                    'line2'   => sanitize_text_field( $order->get_shipping_address_2() ),
                    'city'    => sanitize_text_field( $order->get_shipping_city() ),
                    'state'   => sanitize_text_field( GIVEPAYMENTS_Address_Resolver::resolve_state( $order, 'shipping' ) ),
                    'zip'     => sanitize_text_field( $order->get_shipping_postcode() ),
                    'country' => sanitize_text_field( $order->get_shipping_country() ),
                ),
            ),
            'external_reference' => (string) $order_id,
            'fee_payer'          => 'merchant',
            'send_receipt'       => false,
            'paymethod'          => array(
                'card' => array(
                    'name'      => $card_name,
                    'number'    => $card_number,
                    'cvv'       => $card_cvv,
                    'exp_year'  => $exp_year,
                    'exp_month' => (int) $exp_month,
                ),
                'billing_address' => array(
                    'line1'   => sanitize_text_field( $order->get_billing_address_1() ),
                    'line2'   => sanitize_text_field( $order->get_billing_address_2() ),
                    'city'    => sanitize_text_field( $order->get_billing_city() ),
                    'state'   => sanitize_text_field( GIVEPAYMENTS_Address_Resolver::resolve_billing_state( $order ) ),
                    'zip'     => sanitize_text_field( $billing_zip ),
                    'country' => sanitize_text_field( $order->get_billing_country() ),
                ),
            ),
        );

        // Remove null company name to avoid schema mismatch.
        if ( empty( $payload['customer']['company_name'] ) ) {
            unset( $payload['customer']['company_name'] );
        }

        // Remove shipping_address if all fields are empty (virtual products / no shipping address collected).
        $shipping = $payload['customer']['shipping_address'];
        if ( '' === $shipping['line1'] && '' === $shipping['city'] && '' === $shipping['zip'] ) {
            unset( $payload['customer']['shipping_address'] );
        }

        $is_subscription_checkout = GIVEPAYMENTS_Subscription_Service::order_contains_subscription( $order );

        // Tokenize cards only for subscription checkouts.
        if ( $is_subscription_checkout ) {
            $payload['paymethod']['card']['tokenize'] = true;
        }

        $idempotency_key   = GIVEPAYMENTS_Idempotency_Manager::get_or_create( $order );
        $decrypted_api_key = GIVEPAYMENTS_Request_Context::resolve_api_key( $api_key );
        // Defense-in-depth for header safety: prevent CRLF header injection.
        $decrypted_api_key = str_replace( array( "\r", "\n" ), '', $decrypted_api_key );
        if ( '' === $decrypted_api_key ) {
            GIVEPAYMENTS_Logger::log( sprintf( 'Order %d missing API key after resolution.', (int) $order_id ), 'error' );
            wc_add_notice( __( 'Payment configuration error: API key is missing.', 'givepayments-for-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // Final $0 guard with GivePayments API fallback.
        // If WC still reports $0 after recalculation and this order already has a
        // GivePayments transaction reference (e.g. a previous timeout attempt where
        // the API processed the charge but the TCP response was never received),
        // fetch the real amount from GET /payments/{id} rather than blocking blindly.
        if ( $amount_cents <= 0 ) {
            $fallback_txn = (string) $order->get_meta( '_givepayments_transaction_id' );
            if ( '' !== $fallback_txn ) {
                $api_fetch = ( new GIVEPAYMENTS_Api_Client( $decrypted_api_key, $api_base ) )
                    ->get( '/payments/' . rawurlencode( $fallback_txn ) );
                if ( ! is_wp_error( $api_fetch ) && 200 === (int) wp_remote_retrieve_response_code( $api_fetch ) ) {
                    $api_data = json_decode( wp_remote_retrieve_body( $api_fetch ) );
                    if ( is_object( $api_data ) && isset( $api_data->amount ) && (int) $api_data->amount > 0 ) {
                        $amount_cents    = (int) $api_data->amount;
                        $payload['amount'] = $amount_cents;
                        GIVEPAYMENTS_Logger::log(
                            sprintf( 'Order %d: recovered amount %d cents from GivePayments API (WC total was $0).', $order_id, $amount_cents ),
                            'info'
                        );
                    }
                }
            }
        }
        if ( $amount_cents <= 0 ) {
            GIVEPAYMENTS_Logger::log(
                sprintf( 'Order %d blocked: zero amount after WC recalculation and API fallback (get_total=%s).', $order_id, $order->get_total() ),
                'error'
            );
            wc_add_notice( __( 'Your order total could not be calculated. Please refresh the page and try again.', 'givepayments-for-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        $provisioned_token_id = '';
        if ( $is_subscription_checkout ) {
            $provisioned_token_id = GIVEPAYMENTS_Subscription_Service::create_card_token(
                $decrypted_api_key,
                $api_base,
                $requestor_ip,
                $requestor_ua,
                $card_name,
                $card_number,
                $card_cvv,
                (int) $exp_month,
                (int) $exp_year,
                (string) $order->get_billing_address_1(),
                (string) $order->get_billing_address_2(),
                (string) $order->get_billing_city(),
                (string) GIVEPAYMENTS_Address_Resolver::resolve_billing_state( $order ),
                (string) $billing_zip,
                (string) $order->get_billing_country()
            );
        }

        $debug_payload = $payload;
        if ( isset( $debug_payload['paymethod']['card']['number'] ) ) {
            $debug_payload['paymethod']['card']['number'] = '***' . substr( $debug_payload['paymethod']['card']['number'], -4 );
        }
        if ( isset( $debug_payload['paymethod']['card']['cvv'] ) ) {
            $debug_payload['paymethod']['card']['cvv'] = '***';
        }
        GIVEPAYMENTS_Logger::log( 'Payment request URL: ' . rtrim( $api_base, '/' ) . '/payments', 'debug' );
        GIVEPAYMENTS_Logger::log( 'Payment request payload (masked): ' . wp_json_encode( $debug_payload ), 'debug' );

        $response = ( new GIVEPAYMENTS_Api_Client( $decrypted_api_key, $api_base ) )->post(
            '/payments',
            $payload,
            array(
                'GP-Requestor-IP' => $requestor_ip,
                'GP-Requestor-UA' => $requestor_ua,
                'Idempotency-Key' => $idempotency_key,
            )
        );

        if ( is_wp_error( $response ) ) {
            $parsed_error = GIVEPAYMENTS_Api_Response::parse_error( $response, null );
            if ( ! $order->is_paid() ) {
                $order->add_order_note(
                    sprintf(
                        /* translators: %s: transport or WP_Error message */
                        __( 'GivePayments: could not reach the payment service (%s). The order was not charged; the customer can try again.', 'givepayments-for-woocommerce' ),
                        $parsed_error['provider_message']
                    )
                );
                // Flag the order so the WC cancel-unpaid-orders cron leaves it alone
                // while awaiting a late webhook. The API may have processed the charge
                // before the TCP timeout; auto-cancelling it causes the
                // "sale went through then failed" notification sequence.
                $order->update_meta_data( '_givepayments_pending_timeout', '1' );
                $order->save();
            }
            // Pessimistic dedup sentinel on timeout.
            // cURL error 28 fires on the PHP side when the socket wait exceeds the
            // configured timeout, it does NOT cancel the in-flight HTTP request on
            // the GivePayments server. The API may have already processed the charge
            // and queued the webhook before PHP gave up waiting.
            //
            // Clearing the Blocks session below causes WC Blocks to create a brand-new
            // WC order on the customer's next attempt. That new order gets a fresh
            // idempotency key and bypasses every per-order guard (is_paid,
            // has_succeeded, existing txn check). Without this sentinel a second API
            // charge would be submitted, producing a duplicate on the platform.
            //
            // TTL: 5 minutes, enough for the GivePayments webhook to arrive and
            // resolve the original order. A genuine server-error retry (where no charge
            // was made) is unblocked naturally after the window expires.
            set_transient( $dedup_key, 'timeout', 300 );
            // Clear the Blocks draft-order session pointer so WC Blocks creates a
            // fresh order on the next attempt. Without this, Blocks reuses the stale
            // pending order and the existing-txn guard fires, showing a confusing
            // 400 error instead of a clean retry checkout.
            if ( WC()->session ) {
                WC()->session->set( 'store_api_draft_order', 0 );
            }
            wc_add_notice( $parsed_error['user_message'], 'error' );
            return array( 'result' => 'failure' );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $raw_body      = wp_remote_retrieve_body( $response );
        $response_body = json_decode( $raw_body );
        $log_body      = function_exists( 'givepayments_redact_sensitive_response_body' )
            ? givepayments_redact_sensitive_response_body( $raw_body )
            : $raw_body;

        $is_success = in_array( (int) $response_code, array( 200, 201, 202 ), true );
        GIVEPAYMENTS_Logger::log(
            sprintf( 'Payment response HTTP %d: %s', $response_code, $log_body ),
            $is_success ? 'debug' : 'error'
        );

        // HTTP 200, 201, or 202: treat the API response as "payment request accepted"
        // only. Do not call payment_complete() here, the webhook is the single source
        // of truth for capture/success vs failure (avoids racing or contradicting
        // delayed webhooks). 201 Created is the common success code for POST /payments.
        if ( in_array( (int) $response_code, array( 200, 201, 202 ), true ) ) {
            $provider_transaction_id = GIVEPAYMENTS_Api_Response::extract_transaction_id( $response_body );

            // ── Synchronous decline guard ─────────────────────────────────────────
            // GivePayments returns HTTP 2xx for ALL payment outcomes, including
            // synchronous declines (processingState=declined/failed/voided in the body).
            // Without this check the order is left in on-hold indefinitely waiting for
            // a webhook that may never arrive, and the customer sees no error.
            // Detect terminal failure states here and handle them immediately.
            $sync_ps = GIVEPAYMENTS_Api_Response::extract_processing_state( $response_body );
            // Use the explicit deny-list (declined/failed/voided/canceled/cancelled/rejected/error)
            // rather than an allow-list. Unknown or intermediate states, e.g. 'processing',
            // 'processing_issue', or any future provider state, fall through to the success
            // path conservatively; the webhook is the authoritative source of truth for those.
            // An empty processingState means the provider omitted the field; fall through too.
            if ( '' !== $sync_ps && GIVEPAYMENTS_Api_Response::is_terminal_failure_state( $sync_ps ) ) {
                GIVEPAYMENTS_Logger::log(
                    sprintf(
                        'Order %d: synchronous decline in HTTP %d response (processingState: %s, txn: %s). Setting to failed.',
                        $order->get_id(),
                        (int) $response_code,
                        $sync_ps,
                        $provider_transaction_id ?: 'none'
                    ),
                    'warning'
                );
                // Rotate idempotency key AFTER the order state is persisted.
                // The original code called rotate() before update_status(), a fatal
                // between rotate() and update_status() would clear the key while the
                // order remained in its pre-failure state, leaving it unguarded for
                // duplicate payment retries.
                if ( '' !== $provider_transaction_id ) {
                    $order->set_transaction_id( $provider_transaction_id );
                    GIVEPAYMENTS_Payment_Record::for( $order )->set_transaction_id( $provider_transaction_id );
                }
                // Persist the terminal state so admin screens and guards reflect it immediately.
                GIVEPAYMENTS_Payment_Record::for( $order )->set_processing_state( $sync_ps );
                $decline_note = sprintf(
                    /* translators: 1: processingState value from API (e.g. "declined") 2: Give transaction ID */
                    __( 'GivePayments payment failed at submission (processingState: %1$s). Give transaction reference: %2$s', 'givepayments-for-woocommerce' ),
                    $sync_ps,
                    $provider_transaction_id ?: __( 'none', 'givepayments-for-woocommerce' )
                );
                if ( ! $order->is_paid() ) {
                    $order->update_status( 'failed', $decline_note );
                } else {
                    $order->add_order_note( $decline_note );
                    $order->save();
                }
                // Rotate AFTER update_status so the window between key-clear and state-
                // persist is eliminated. If a fatal occurs here the key is still valid
                // and the duplicate-payment guard (has_succeeded) remains effective.
                GIVEPAYMENTS_Idempotency_Manager::rotate( $order );
                // Tailor the customer notice: card-level declines get actionable copy;
                // technical failures get a generic retry message.
                $user_notice = GIVEPAYMENTS_Order_State::is_decline_state( $sync_ps )
                    ? __( 'Your payment was declined. Please check your card details or try a different card.', 'givepayments-for-woocommerce' )
                    : __( 'Payment processing failed. Please try again or contact support if the problem persists.', 'givepayments-for-woocommerce' );
                wc_add_notice( $user_notice, 'error' );
                // Clear the Blocks draft-order session pointer so WC Blocks creates a
                // fresh order on the next attempt rather than reusing this declined one.
                // Without this, the Store API returns 400 on retry before process_payment()
                // is even reached.
                if ( WC()->session ) {
                    WC()->session->set( 'store_api_draft_order', 0 );
                }
                return array( 'result' => 'failure' );
            }
            // ─────────────────────────────────────────────────────────────────────

            if ( '' !== $provider_transaction_id ) {
                $order->set_transaction_id( $provider_transaction_id );
                GIVEPAYMENTS_Payment_Record::for( $order )->set_transaction_id( $provider_transaction_id );
            }

            // N9: Persist the environment so refunds and voids issued after an
            // env-switch still target the correct API. Reading the global
            // givepayments_environment option at refund time is wrong when the
            // merchant switches from production → sandbox to test something.
            GIVEPAYMENTS_Payment_Record::for( $order )->set_environment( $env_for_cap );

            $token_id = GIVEPAYMENTS_Api_Response::extract_card_token_id( $response_body );
            if ( '' === $token_id && '' !== $provisioned_token_id ) {
                $token_id = $provisioned_token_id;
            }
            if ( '' !== $token_id ) {
                // Always store on parent order first so renewals can recover even if
                // subscription rows are created after checkout response handling.
                GIVEPAYMENTS_Payment_Record::for( $order )->set_card_token_id( $token_id );
                $order->save();

                GIVEPAYMENTS_Subscription_Service::store_token_for_order( $order_id, $token_id );
            } elseif ( GIVEPAYMENTS_Subscription_Service::order_contains_subscription( $order ) ) {
                GIVEPAYMENTS_Logger::log(
                    sprintf( 'Subscription checkout order %d has no reusable token in payment response.', $order->get_id() ),
                    'warning'
                );
            }

            // Store the provider state from the sync response so VoidHandler and
            // RefundGuard reflect the correct eligibility immediately, before the
            // first webhook arrives and updates the meta.
            if ( '' !== $sync_ps ) {
                GIVEPAYMENTS_Payment_Record::for( $order )->set_processing_state( $sync_ps );
            }
            // Derive the WC status from the canonical Order_State table.
            // created/authorized/captured → 'processing'; settled → 'completed'.
            // Falls back to 'processing' when state is empty (API omitted processingState).
            $wc_target = GIVEPAYMENTS_Order_State::wc_status_for( $sync_ps ) ?? 'processing';
            // promote() before update_status(): the last_success_idempotency_key meta must
            // already be written when a concurrent process reads has_succeeded() during the
            // narrow window between API success and the order-status change (H2).
            GIVEPAYMENTS_Idempotency_Manager::promote( $order, $idempotency_key );
            $order->update_status(
                $wc_target,
                sprintf(
                    /* translators: 1: processingState from provider API, 2: HTTP status code */
                    __( 'GivePayments payment accepted (processingState: %1$s, HTTP %2$d). Awaiting webhook confirmation.', 'givepayments-for-woocommerce' ),
                    $sync_ps ?: 'unknown',
                    (int) $response_code
                )
            );

            // Do NOT set a dedup sentinel on confirmed success, the payment is
            // done and the customer should be free to make another purchase with
            // the same card/amount immediately (e.g. buying a second gift).
            // Re-submission on the *same* order is already prevented by the
            // has_succeeded() / is_paid() per-order guards and the cart + session
            // clearing below. The timeout sentinel (set on WP_Error/cURL-28 paths)
            // is still in place for the genuinely ambiguous "did the charge go
            // through?" window.

            if ( WC()->cart ) {
                WC()->cart->empty_cart();
            }

            // Clear the Blocks draft-order session pointer so the next checkout
            // always starts from a fresh order rather than potentially reusing
            // this just-paid one. Older WC/Blocks builds can reuse 'pending'
            // orders found in session, which causes the existing-txn preflight
            // guard to fire and return a Store API 400 on the very next purchase.
            // WC also clears this on its side, but doing it here as well ensures
            // the session is correct even if an exception later interrupts WC's
            // own post-payment cleanup.
            if ( WC()->session ) {
                WC()->session->set( 'store_api_draft_order', 0 );
            }

            return array(
                'result'   => 'success',
                'redirect' => apply_filters( 'woocommerce_get_return_url', $order->get_checkout_order_received_url(), $order ),
            );
        }

        $parsed_error = GIVEPAYMENTS_Api_Response::parse_error( $response, $response_body );
        $status_code  = (int) $parsed_error['status_code'];

        $is_retryable = GIVEPAYMENTS_Api_Response::is_retryable_http_error( $status_code );

        if ( $is_retryable ) {
            // Matches FE-style handling: network-ish / rate-limit / server errors stay retryable;
            // do not mark the order failed so the customer is not pushed into pending → auto-cancel.
            if ( ! $order->is_paid() ) {
                $order->add_order_note(
                    sprintf(
                        /* translators: 1: HTTP status code (or 0), 2: error details */
                        __( 'GivePayments: payment not completed (retryable). HTTP %1$s. %2$s', 'givepayments-for-woocommerce' ),
                        (string) $status_code,
                        $parsed_error['provider_message']
                    )
                );
            }
        } elseif ( $status_code >= 400 && $status_code < 500 ) {
            // Terminal client errors (declines, validation, fraud blocks, auth to API as seen by checkout).
            $decline_note = GIVEPAYMENTS_Api_Response::format_decline_note( $parsed_error );
            GIVEPAYMENTS_Idempotency_Manager::rotate( $order );
            if ( ! $order->is_paid() ) {
                $order->update_status( 'failed', $decline_note );
            } else {
                $order->add_order_note( $decline_note );
            }
            // Clear the Blocks draft-order session pointer so the next attempt
            // creates a fresh order instead of reusing this declined one.
            if ( WC()->session ) {
                WC()->session->set( 'store_api_draft_order', 0 );
            }
        } else {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: API status code, 2: API error message */
                    __( 'GivePayments payment could not be confirmed (transient/provider error). Status: %1$s. Message: %2$s', 'givepayments-for-woocommerce' ),
                    (string) $status_code,
                    $parsed_error['provider_message']
                )
            );
        }

        wc_add_notice( $parsed_error['user_message'], 'error' );
        return array( 'result' => 'failure' );

        } finally {
            // release the per-order checkout lock regardless of how process() exits
            // (success, decline, validation failure, transport error, or uncaught exception).
            delete_option( $checkout_lock );
        }
        return array( 'result' => 'failure' ); // unreachable; satisfies static-analysis tools
    }
}
