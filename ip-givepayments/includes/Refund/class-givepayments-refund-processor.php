<?php
/**
 * Synchronous refund and async refund-retry logic.
 *
 * Extracted from GIVEPAYMENTS_Gateway. All methods are static.
 *
 * @package GivePayments_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Refund_Processor {

    /**
     * Initiate a WooCommerce-triggered refund against the GivePayments API.
     *
     * Called from GIVEPAYMENTS_Gateway::process_refund(). Returns true on
     * success/scheduled-retry, WP_Error on hard failure.
     *
     * @param int        $order_id WooCommerce order ID.
     * @param float|null $amount   Refund amount (null = full order total).
     * @return true|WP_Error
     */
    public static function process( $order_id, $amount = null ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'givepayments_invalid_order', __( 'Unable to load order for refund.', 'givepayments-for-woocommerce' ) );
        }

        $rec = GIVEPAYMENTS_Payment_Record::for( $order );

        // Validation and idempotency exceptions thrown inside this try block are
        // automatically converted to WP_Error at the WC gateway boundary.
        // Operational failures from the actual API call still use return new WP_Error()
        // directly because they carry queued/retry semantics that are not exceptions.
        try {

        // Guard against re-entry when the order is already confirmed refunded.
        // Do NOT use get_total_refunded() here, WooCommerce creates the WC refund
        // record *before* calling process_refund(), so get_total_refunded() already
        // equals the order total by the time this code runs. Using it as a guard
        // would block the API call on every legitimate full-refund attempt.
        if ( $rec->is_refund_completed() || 'refunded' === $order->get_status() ) {
            throw GIVEPAYMENTS_Idempotency_Exception::from_message(
                'givepayments_refund_already_completed',
                __( 'This order has already been refunded via GivePayments. No further refund action is required.', 'givepayments-for-woocommerce' )
            );
        }

        $order_total = (float) $order->get_total();

        GIVEPAYMENTS_Refund_Guard::release_stale_pending_lock( $order );
        if ( GIVEPAYMENTS_Refund_Guard::is_pending_window_active( $order ) ) {
            throw GIVEPAYMENTS_Idempotency_Exception::from_message(
                'givepayments_refund_pending',
                __( 'A GivePayments refund is already being processed for this order. Please wait a few minutes before trying again.', 'givepayments-for-woocommerce' )
            );
        }

        $transaction_id = (string) $order->get_transaction_id();
        if ( '' === $transaction_id ) {
            $transaction_id = $rec->transaction_id();
        }
        $transaction_id = sanitize_text_field( $transaction_id );
        if ( '' === $transaction_id ) {
            throw GIVEPAYMENTS_Validation_Exception::from_message(
                'givepayments_missing_transaction_id',
                __( 'Refund failed: missing GivePayments transaction ID on this order.', 'givepayments-for-woocommerce' )
            );
        }

        $refund_amount = is_null( $amount ) ? $order_total : (float) $amount;
        // The GivePayments API for this integration currently supports full transaction refunds from Woo.
        if ( abs( $refund_amount - $order_total ) > 0.0001 ) {
            throw GIVEPAYMENTS_Validation_Exception::from_message(
                'givepayments_partial_refund_not_supported',
                __( 'Partial refunds are not supported in WooCommerce for this gateway yet. Please issue a full refund.', 'givepayments-for-woocommerce' )
            );
        }

        $environment = get_option( 'givepayments_environment', 'test' );
        $base_url    = GIVEPAYMENTS_Request_Context::api_base_url( $environment );
        $api_key     = GIVEPAYMENTS_Request_Context::resolve_api_key( GIVEPAYMENTS_Request_Context::get_api_key_for_environment() );
        $api_key     = str_replace( array( "\r", "\n" ), '', $api_key );
        if ( '' === $api_key ) {
            throw GIVEPAYMENTS_Validation_Exception::from_message(
                'givepayments_refund_missing_api_key',
                __( 'Refund failed: API key is missing.', 'givepayments-for-woocommerce' )
            );
        }

        $requestor_ip = (string) GIVEPAYMENTS_Request_Context::get_client_ip();
        $requestor_ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 1024 ) : '';
        // Persist key in order meta so every retry (async or manual re-click) reuses it.
        // The provider deduplicates on this key and returns the cached result rather
        // than processing a second refund.
        $idempotency_key = self::get_or_create_refund_idempotency_key( $order );

        // Refund API expects "payment" as a transaction ID string.
        $payload = array(
            'payment' => $transaction_id,
        );

        GIVEPAYMENTS_Logger::log(
            sprintf(
                'Refund request for order %d to %s: %s',
                (int) $order->get_id(),
                rtrim( $base_url, '/' ) . '/refunds',
                wp_json_encode( $payload )
            ),
            'debug'
        );

        // Single synchronous attempt, if retryable, schedule via Action Scheduler
        // instead of blocking the PHP worker with sleep().

        GIVEPAYMENTS_Refund_Guard::mark_origin( $order, 'woocommerce_refund' );

        $response = ( new GIVEPAYMENTS_Api_Client( $api_key, $base_url ) )->post(
            '/refunds',
            $payload,
            array(
                'GP-Requestor-IP' => $requestor_ip,
                'GP-Requestor-UA' => $requestor_ua,
                'Idempotency-Key' => $idempotency_key,
            )
        );

        if ( is_wp_error( $response ) ) {
            if ( GIVEPAYMENTS_Refund_Guard::should_retry_for_transport_error( $response ) ) {
                GIVEPAYMENTS_Refund_Guard::schedule_retry( $order, $transaction_id, $idempotency_key, 1 );
                GIVEPAYMENTS_Refund_Guard::mark_pending( $order );
                $order->add_order_note( __( 'GivePayments refund request encountered a network error. An automatic retry has been scheduled.', 'givepayments-for-woocommerce' ) );
                // Return WP_Error (not true) so WooCommerce does NOT immediately
                // create an accounting refund record. WC deletes the pending refund
                // row on WP_Error, correct, since no money has moved yet. The async
                // retry will complete the flow once the provider is reachable.
                return new WP_Error(
                    'givepayments_refund_queued',
                    __( 'GivePayments: refund could not be reached and has been queued for automatic retry. It will be processed in the background.', 'givepayments-for-woocommerce' )
                );
            }
            GIVEPAYMENTS_Refund_Guard::clear_origin( $order );
            self::clear_refund_idempotency_key( $order ); // Hard failure, allow a clean fresh attempt later.
            return new WP_Error( 'givepayments_refund_http_error', sprintf( __( 'Refund request failed: %s', 'givepayments-for-woocommerce' ), $response->get_error_message() ) );
        }

        $last_status_code = (int) wp_remote_retrieve_response_code( $response );
        $last_raw_body    = wp_remote_retrieve_body( $response );
        $last_body        = json_decode( $last_raw_body );
        $last_log_body    = function_exists( 'givepayments_redact_sensitive_response_body' )
            ? givepayments_redact_sensitive_response_body( $last_raw_body )
            : $last_raw_body;

        GIVEPAYMENTS_Logger::log(
            sprintf( 'Refund response HTTP %d for order %d: %s', $last_status_code, (int) $order->get_id(), $last_log_body ),
            in_array( $last_status_code, array( 200, 201 ), true ) ? 'debug' : 'error'
        );

        $last_reversal_state = '';
        if ( is_object( $last_body ) && ! empty( $last_body->data ) && is_array( $last_body->data ) && ! empty( $last_body->data[0] ) && is_object( $last_body->data[0] ) && isset( $last_body->data[0]->reversalState ) ) {
            $last_reversal_state = sanitize_text_field( (string) $last_body->data[0]->reversalState );
        }

        $is_refund_initiated = in_array( $last_status_code, array( 200, 201 ), true )
            && (
                'fully_reversed' === $last_reversal_state
                || 'partially_reversed' === $last_reversal_state
                || 'pending' === $last_reversal_state
                || 'available' === $last_reversal_state
                || ( 201 === $last_status_code && '' === $last_reversal_state )
            );

        if ( $is_refund_initiated ) {
            if ( 'fully_reversed' === $last_reversal_state ) {
                GIVEPAYMENTS_Refund_Guard::clear_pending( $order );
                GIVEPAYMENTS_Refund_Guard::mark_completed( $order );
                self::clear_refund_idempotency_key( $order ); // Terminal, no further retries needed.
            } else {
                GIVEPAYMENTS_Refund_Guard::mark_pending( $order );
                // Key kept in meta: async retry will reuse it.
            }

            $order->add_order_note(
                sprintf(
                    /* translators: 1: transaction ID, 2: reversal state */
                    __( 'GivePayments refund request accepted. Transaction: %1$s. State: %2$s', 'givepayments-for-woocommerce' ),
                    $transaction_id,
                    $last_reversal_state ? $last_reversal_state : 'pending'
                )
            );
            return true;
        }

        // If the error is retryable (payment still settling), schedule background retry.
        if ( GIVEPAYMENTS_Refund_Guard::should_retry_for_response( $last_status_code, $last_body, $last_reversal_state ) ) {
            GIVEPAYMENTS_Refund_Guard::schedule_retry( $order, $transaction_id, $idempotency_key, 1 );
            GIVEPAYMENTS_Refund_Guard::mark_pending( $order );
            $order->add_order_note(
                sprintf(
                    /* translators: 1: HTTP status code */
                    __( 'GivePayments refund attempt returned HTTP %1$s (payment may still be settling). An automatic retry has been scheduled.', 'givepayments-for-woocommerce' ),
                    (string) $last_status_code
                )
            );
            // Return WP_Error so WooCommerce does NOT create an accounting refund
            // record. Without this, a failed-then-retried refund produces an orphaned
            // WC refund row whose amount is never actually returned to the customer.
            // WC deletes the pending refund object on WP_Error, keeping accounting clean.
            return new WP_Error(
                'givepayments_refund_queued',
                __( 'GivePayments: refund is queued for automatic retry. It will be processed in the background.', 'givepayments-for-woocommerce' )
            );
        }

        GIVEPAYMENTS_Refund_Guard::clear_origin( $order );
        self::clear_refund_idempotency_key( $order ); // Permanent failure, allow a clean fresh attempt later.

        $message          = __( 'Refund was not confirmed by GivePayments.', 'givepayments-for-woocommerce' );
        $provider_details = '';
        if ( is_object( $last_body ) ) {
            if ( ! empty( $last_body->message ) ) {
                $message = sanitize_text_field( (string) $last_body->message );
            }

            if ( ! empty( $last_body->input ) && is_array( $last_body->input ) ) {
                $field_errors = array();
                foreach ( $last_body->input as $err ) {
                    if ( ! is_object( $err ) ) {
                        continue;
                    }
                    $param          = ! empty( $err->param ) ? sanitize_text_field( (string) $err->param ) : 'unknown';
                    $msg            = ! empty( $err->message ) ? sanitize_text_field( (string) $err->message ) : __( 'Invalid value.', 'givepayments-for-woocommerce' );
                    $field_errors[] = $param . ': ' . $msg;
                }
                if ( ! empty( $field_errors ) ) {
                    $provider_details = implode( '; ', $field_errors );
                }
            } // end if input
        } // end if is_object

        $debug_note = sprintf(
            /* translators: 1: HTTP status code, 2: error message, 3: optional provider detail string */
            __( 'GivePayments refund failed. HTTP: %1$s. Message: %2$s%3$s', 'givepayments-for-woocommerce' ),
            (string) $last_status_code,
            $message,
            $provider_details ? ' | Details: ' . $provider_details : ''
        );
        $order->add_order_note( $debug_note );
        GIVEPAYMENTS_Logger::log( $debug_note, 'error' );

        $hint = '';
        if ( GIVEPAYMENTS_Refund_Guard::should_retry_for_response( $last_status_code, $last_body, $last_reversal_state ) || in_array( $last_status_code, array( 429, 502, 503, 504 ), true ) ) {
            $hint = ' ' . __( 'The payment may still be finalizing at GivePayments,wait a minute and try the refund again.', 'givepayments-for-woocommerce' );
        }

        return new WP_Error(
            'givepayments_refund_failed',
            sprintf(
                /* translators: 1: HTTP status code, 2: provider message */
                __( 'Refund failed (HTTP %1$s): %2$s%3$s', 'givepayments-for-woocommerce' ),
                (string) $last_status_code,
                $message,
                ( $provider_details ? ' (' . $provider_details . ')' : '' ) . $hint
            )
        );

        } catch ( GIVEPAYMENTS_Exception $e ) {
            return $e->to_wp_error();
        }
    }

    /**
     * Execute a single background refund attempt. Called by Action Scheduler
     * via the `givepayments_async_refund_retry` hook.
     *
     * @param array $args Keys: order_id, transaction_id, idempotency_key, attempt.
     * @return void
     */
    public static function execute_async_retry( $args ): void {
        $order_id       = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
        $transaction_id = isset( $args['transaction_id'] ) ? sanitize_text_field( (string) $args['transaction_id'] ) : '';
        $attempt        = isset( $args['attempt'] ) ? (int) $args['attempt'] : 1;

        $order = wc_get_order( $order_id );
        if ( ! $order || '' === $transaction_id ) {
            GIVEPAYMENTS_Logger::log( sprintf( 'Async refund retry skipped: invalid order %d or missing txn.', $order_id ), 'error' );
            return;
        }

        // M12: Prevent concurrent AS workers for the same order from issuing
        // duplicate API calls and duplicate order notes.
        $retry_lock_key = 'gp_retry_' . $order_id;
        if ( ! add_option( $retry_lock_key, '1', '', 'no' ) ) {
            GIVEPAYMENTS_Logger::log( sprintf( 'Async refund retry: skipping concurrent duplicate for order %d.', $order_id ), 'debug' );
            return;
        }

        // N14: Action Scheduler replays actions with the SAME $args on uncaught
        // exception, so $attempt never increments naturally. Persist the current
        // attempt to order meta and take the max on each invocation so the retry
        // chain progresses even when AS replays attempt=1 many times.
        $rec          = GIVEPAYMENTS_Payment_Record::for( $order );
        $meta_attempt = $rec->refund_attempt();
        $attempt      = max( $attempt, $meta_attempt );
        $rec->set_refund_attempt( $attempt );
        $rec->save();

        try {
        // Prefer key stored in order meta (written before the first attempt).
        // Fall back to Action Scheduler arg for actions queued before this feature shipped.
        // If neither is available, abort rather than risk a duplicate refund with an empty key.
        $idempotency_key = $rec->refund_idempotency_key();
        if ( '' === $idempotency_key ) {
            $idempotency_key = isset( $args['idempotency_key'] ) ? sanitize_text_field( (string) $args['idempotency_key'] ) : '';
        }
        if ( '' === $idempotency_key ) {
            GIVEPAYMENTS_Logger::log(
                sprintf( 'Async refund retry: no idempotency key for order %d, aborting to prevent duplicate refund.', $order_id ),
                'error'
            );
            $order->add_order_note( __( 'GivePayments async refund retry aborted: idempotency key missing. Please retry the refund manually.', 'givepayments-for-woocommerce' ) );
            GIVEPAYMENTS_Refund_Guard::clear_pending( $order );
            return;
        }

        // If refund completed in the meantime (e.g. webhook), skip.
        if ( $rec->is_refund_completed() || 'refunded' === $order->get_status() ) {
            GIVEPAYMENTS_Logger::log( sprintf( 'Async refund retry for order %d skipped: already refunded.', $order_id ), 'debug' );
            GIVEPAYMENTS_Refund_Guard::clear_pending( $order );
            return;
        }

        $environment = $rec->environment();
        if ( '' === $environment ) {
            // N9 fallback: orders created before per-order environment storage was
            // introduced fall back to the global option. Flag in logs for support.
            $environment = (string) get_option( 'givepayments_environment', 'test' );
            GIVEPAYMENTS_Logger::log(
                sprintf( 'Async refund retry for order %d: env read from global option (pre-N9 order); env=%s.', $order_id, $environment ),
                'warning'
            );
        }
        $api_key     = GIVEPAYMENTS_Request_Context::resolve_api_key( GIVEPAYMENTS_Request_Context::get_api_key_for_environment() );
        $api_key     = str_replace( array( "\r", "\n" ), '', $api_key );
        if ( '' === $api_key ) {
            GIVEPAYMENTS_Logger::log( sprintf( 'Async refund retry for order %d: API key missing.', $order_id ), 'error' );
            return;
        }

        $payload = array( 'payment' => $transaction_id );

        GIVEPAYMENTS_Logger::log( sprintf( 'Async refund retry #%d for order %d.', $attempt, $order_id ), 'debug' );

        $response = ( new GIVEPAYMENTS_Api_Client( $api_key, GIVEPAYMENTS_Request_Context::api_base_url( $environment ) ) )->post(
            '/refunds',
            $payload,
            array( 'Idempotency-Key' => $idempotency_key )
        );

        if ( is_wp_error( $response ) ) {
            GIVEPAYMENTS_Refund_Guard::schedule_retry( $order, $transaction_id, $idempotency_key, $attempt + 1 );
            return;
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        $raw_body    = wp_remote_retrieve_body( $response );
        $body        = json_decode( $raw_body );
        $log_body    = function_exists( 'givepayments_redact_sensitive_response_body' )
            ? givepayments_redact_sensitive_response_body( $raw_body )
            : $raw_body;

        GIVEPAYMENTS_Logger::log( sprintf( 'Async refund response HTTP %d for order %d (retry #%d): %s', $status_code, $order_id, $attempt, $log_body ), 'debug' );

        $reversal_state = '';
        if ( is_object( $body ) && ! empty( $body->data ) && is_array( $body->data ) && ! empty( $body->data[0] ) && is_object( $body->data[0] ) && isset( $body->data[0]->reversalState ) ) {
            $reversal_state = sanitize_text_field( (string) $body->data[0]->reversalState );
        }

        $is_refund_initiated = in_array( $status_code, array( 200, 201 ), true )
            && (
                'fully_reversed' === $reversal_state
                || 'partially_reversed' === $reversal_state
                || 'pending' === $reversal_state
                || 'available' === $reversal_state
                || ( 201 === $status_code && '' === $reversal_state )
            );

        if ( $is_refund_initiated ) {
            if ( 'fully_reversed' === $reversal_state ) {
                GIVEPAYMENTS_Refund_Guard::clear_pending( $order );
                GIVEPAYMENTS_Refund_Guard::mark_completed( $order );
                self::clear_refund_idempotency_key( $order ); // Terminal success, key no longer needed.
                $rec->clear_refund_attempt(); // N14: Clear attempt tracker on success.
                $rec->save();
            }
            $order->add_order_note(
                sprintf(
                    /* translators: 1: attempt number, 2: transaction ID, 3: reversal state */
                    __( 'GivePayments refund confirmed on background retry #%1$d. Transaction: %2$s. State: %3$s', 'givepayments-for-woocommerce' ),
                    $attempt,
                    $transaction_id,
                    $reversal_state ? $reversal_state : 'pending'
                )
            );
            return;
        }

        if ( GIVEPAYMENTS_Refund_Guard::should_retry_for_response( $status_code, $body, $reversal_state ) ) {
            GIVEPAYMENTS_Refund_Guard::schedule_retry( $order, $transaction_id, $idempotency_key, $attempt + 1 );
            // schedule_retry clears pending when retries are exhausted (attempt > 3).
            // Clear the idempotency key in that case so the merchant can start fresh.
            if ( $attempt >= 3 ) {
                self::clear_refund_idempotency_key( $order );
            }
        } else {
            GIVEPAYMENTS_Refund_Guard::clear_pending( $order );
            self::clear_refund_idempotency_key( $order ); // Permanent failure, clean up.
            $order->add_order_note(
                sprintf(
                    /* translators: 1: HTTP status code */
                    __( 'GivePayments automatic refund retry failed (HTTP %1$s). Please retry manually from the order screen.', 'givepayments-for-woocommerce' ),
                    (string) $status_code
                )
            );
        }
        } // end try
        finally {
            delete_option( $retry_lock_key ); // M12: Always release the per-order lock.
        }
    }

    // -------------------------------------------------------------------------
    // Refund idempotency key helpers
    // -------------------------------------------------------------------------

    /**
     * Return the refund idempotency key stored on the order, creating and
     * persisting one if none exists yet.
     *
     * Using order meta rather than a one-off random string means every retry
     * path (manual re-click, async Action Scheduler) sees the same key and
     * the provider deduplicates correctly.
     *
     * @param WC_Order $order
     * @return string
     */
    private static function get_or_create_refund_idempotency_key( WC_Order $order ): string {
        $rec      = GIVEPAYMENTS_Payment_Record::for( $order );
        $existing = $rec->refund_idempotency_key();
        if ( '' !== $existing ) {
            return $existing;
        }

        // Atomic mutex: two concurrent admin requests (rapid double-click, two browser tabs)
        // both start with empty meta. Only the lock winner generates the canonical key; the
        // loser waits briefly and re-reads the key the winner wrote. Without this, both would
        // generate distinct random keys and the provider would see two distinct idempotency
        // keys → two separate refund charges.
        $lock_option = 'gp_refund_key_lock_' . (int) $order->get_id();
        $lock_won    = (bool) add_option( $lock_option, '1', '', 'no' );
        if ( ! $lock_won ) {
            // Lock winner is generating the key. Retry with escalating back-off before
            // assuming the winner crashed without saving.
            $delays = array( 50000, 100000, 150000 ); // 50 ms, 100 ms, 150 ms
            foreach ( $delays as $delay ) {
                usleep( $delay );
                $order->read_meta_data( true );
                $refreshed = $rec->refund_idempotency_key();
                if ( '' !== $refreshed ) {
                    return $refreshed;
                }
            }
            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'RefundProcessor: lock loser waited 300 ms but order %d still has no refund key, generating own key (lock winner likely crashed before saving).',
                    (int) $order->get_id()
                ),
                'warning'
            );
            // Key still absent (winner may have crashed). Fall through and generate our own.
        }

        $key = sprintf( 'gp_wc_refund_%d_%s', (int) $order->get_id(), wp_generate_password( 20, false, false ) );
        $rec->set_refund_idempotency_key( $key );
        $rec->save();
        if ( $lock_won ) {
            delete_option( $lock_option );
        }
        return $key;
    }

    /**
     * Delete the persisted refund idempotency key.
     *
     * Call after a terminal success or a permanent (non-retryable) failure so
     * a future refund attempt starts with a fresh key.
     *
     * @param WC_Order $order
     * @return void
     */
    private static function clear_refund_idempotency_key( WC_Order $order ): void {
        $rec = GIVEPAYMENTS_Payment_Record::for( $order );
        $rec->clear_refund_idempotency_key();
        $rec->save();
    }
}
