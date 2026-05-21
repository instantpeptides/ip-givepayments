<?php
/**
 * Refund state-tracking and retry-eligibility helpers.
 *
 * Centralises all order-meta writes that govern whether a refund can be initiated,
 * retried, or must be blocked as a duplicate.  Also owns the scheduling of
 * background refund retries via Action Scheduler (with wp_schedule_single_event
 * as a fallback) and the two retry-eligibility predicates.
 *
 * Meta keys managed:
 *  _givepayments_refund_pending
 *  _givepayments_refund_pending_at
 *  _givepayments_refund_completed
 *  _givepayments_refund_origin
 *
 * @package GivePayments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Refund_Guard {

    /**
     * Pending window in seconds, duplicate refund attempts are blocked while
     * the provider is still processing the original request.
     */
    const PENDING_WINDOW = 900; // 15 minutes

    // -------------------------------------------------------------------------
    // State markers
    // -------------------------------------------------------------------------

    /**
     * Mark a refund as in-flight.
     *
     * @param WC_Order $order
     * @return void
     */
    public static function mark_pending( WC_Order $order ): void {
        $rec = GIVEPAYMENTS_Payment_Record::for( $order );
        $rec->set_refund_pending( time() );
        $rec->save();
    }

    /**
     * Clear the in-flight marker without marking the refund as completed.
     * Use when a retry is scheduled or the operation is abandoned.
     *
     * @param WC_Order $order
     * @return void
     */
    public static function clear_pending( WC_Order $order ): void {
        $rec = GIVEPAYMENTS_Payment_Record::for( $order );
        $rec->clear_refund_pending();
        $rec->save();
    }

    /**
     * Mark the refund as definitively completed and clear all transient markers.
     * After this, can_refund_order() will permanently return false.
     *
     * @param WC_Order $order
     * @return void
     */
    public static function mark_completed( WC_Order $order ): void {
        $rec = GIVEPAYMENTS_Payment_Record::for( $order );
        $rec->mark_refund_completed();
        $rec->save();
    }

    /**
     * Record where a refund was initiated (e.g. 'woocommerce_refund', 'woocommerce_void').
     *
     * @param WC_Order $order
     * @param string   $origin Sanitised origin slug.
     * @return void
     */
    public static function mark_origin( WC_Order $order, string $origin ): void {
        $rec = GIVEPAYMENTS_Payment_Record::for( $order );
        $rec->set_refund_origin( $origin );
        $rec->save();
    }

    /**
     * Clear the origin marker (e.g. when the refund request fails).
     *
     * @param WC_Order $order
     * @return void
     */
    public static function clear_origin( WC_Order $order ): void {
        $rec = GIVEPAYMENTS_Payment_Record::for( $order );
        $rec->clear_refund_origin();
        $rec->save();
    }

    // -------------------------------------------------------------------------
    // Duplicate-refund guard
    // -------------------------------------------------------------------------

    /**
     * Whether a refund is already in-flight and within the pending window.
     *
     * Pure query, no side effects.
     *
     * @param WC_Order $order
     * @return bool
     */
    public static function is_pending_window_active( WC_Order $order ): bool {
        $rec        = GIVEPAYMENTS_Payment_Record::for( $order );
        $is_pending = $rec->is_refund_pending();
        if ( ! $is_pending ) {
            return false;
        }

        $pending_at = $rec->refund_pending_at();
        return $pending_at > 0 && ( time() - $pending_at ) <= self::PENDING_WINDOW;
    }

    /**
     * Command: clear the pending lock if it exists but has passed the PENDING_WINDOW.
     *
     * Call this before checking is_pending_window_active() at refund-initiation
     * boundaries to evict stale locks left by crashed or timed-out requests.
     *
     * @param WC_Order $order
     * @return bool True if a stale lock was found and cleared; false otherwise.
     */
    public static function release_stale_pending_lock( WC_Order $order ): bool {
        $rec = GIVEPAYMENTS_Payment_Record::for( $order );
        if ( ! $rec->is_refund_pending() ) {
            return false;
        }

        $pending_at = $rec->refund_pending_at();
        if ( $pending_at > 0 && ( time() - $pending_at ) <= self::PENDING_WINDOW ) {
            return false; // Still within window, not stale.
        }

        self::clear_pending( $order );
        return true;
    }

    // -------------------------------------------------------------------------
    // Retry eligibility
    // -------------------------------------------------------------------------

    /**
     * Whether a WP_Error from a refund HTTP call warrants an automatic retry.
     * Covers transport-level failures (DNS, TCP timeout, curl timeout).
     *
     * @param mixed $error
     * @return bool
     */
    public static function should_retry_for_transport_error( $error ): bool {
        if ( ! is_wp_error( $error ) ) {
            return false;
        }
        $code = (string) $error->get_error_code();
        return in_array( $code, array( 'http_request_failed', 'curl_error_28' ), true )
            || false !== stripos( $error->get_error_message(), 'timeout' )
            || false !== stripos( $error->get_error_message(), 'timed out' );
    }

    /**
     * Whether a provider refund response means "try again shortly"
     * (capture still settling, rate-limited, conflict, etc.).
     *
     * @param int         $status_code
     * @param object|null $body
     * @param string      $reversal_state
     * @return bool
     */
    public static function should_retry_for_response( $status_code, $body, string $reversal_state ): bool {
        $status_code    = (int) $status_code;
        $reversal_state = strtolower( $reversal_state );

        // Any 5xx is a server-side transient error, retry, consistent with
        // GIVEPAYMENTS_Api_Response::is_retryable_http_error() on the payment side.
        // Omitting 500 caused provider transient 500s to be treated as permanent
        // failures, clearing the idempotency key and leaving an orphaned WC refund
        // record with no corresponding provider transaction. 408 (request timeout)
        // is also inherently retryable.
        if ( $status_code >= 500 || 408 === $status_code ) {
            return true;
        }
        if ( in_array( $status_code, array( 429, 409, 425 ), true ) ) {
            return true;
        }

        if ( 200 === $status_code ) {
            if ( 'fully_reversed' === $reversal_state ) {
                return false;
            }
            if ( '' === $reversal_state || 'pending' === $reversal_state || 'available' === $reversal_state ) {
                return true;
            }
        }

        if ( ! in_array( $status_code, array( 400, 422 ), true ) ) {
            return false;
        }

        $haystack = '';
        if ( is_object( $body ) && ! empty( $body->message ) ) {
            $haystack .= ' ' . strtolower( (string) $body->message );
        }
        if ( is_object( $body ) && ! empty( $body->code ) ) {
            $haystack .= ' ' . strtolower( (string) $body->code );
        }
        if ( is_object( $body ) && ! empty( $body->input ) && is_array( $body->input ) ) {
            foreach ( $body->input as $err ) {
                if ( is_object( $err ) && ! empty( $err->message ) ) {
                    $haystack .= ' ' . strtolower( (string) $err->message );
                }
            }
        }

        $needles = array(
            'pending', 'processing', 'settlement', 'settling',
            'finalize', 'finalizing', 'not eligible', 'cannot',
            'unavailable', 'try again', 'too soon', 'state', 'in progress',
        );
        foreach ( $needles as $needle ) {
            if ( false !== strpos( $haystack, $needle ) ) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Background retry scheduling
    // -------------------------------------------------------------------------

    /**
     * Schedule a background refund retry via Action Scheduler (or WP cron fallback).
     *
     * Exponential back-off: 30 s, 60 s, 120 s between attempts. Max 3 async retries after the initial synchronous attempt (4 total API calls).
     *
     * @param WC_Order $order
     * @param string   $transaction_id
     * @param string   $idempotency_key
     * @param int      $attempt  1-based attempt number.
     * @return void
     */
    public static function schedule_retry( WC_Order $order, string $transaction_id, string $idempotency_key, int $attempt ): void {
        if ( $attempt > 3 ) {
            $order->add_order_note(
                __( 'GivePayments refund: all automatic retries exhausted. Please retry the refund manually or contact GivePayments support.', 'givepayments-for-woocommerce' )
            );
            self::clear_pending( $order );
            return;
        }

        // Add ±5 s jitter to spread thundering-herd load during provider outages.
        // Without jitter all simultaneous refunds hit the API at identical intervals.
        $delay  = 30 * (int) pow( 2, $attempt - 1 ); // 30 s, 60 s, 120 s
        $delay += function_exists( 'wp_rand' ) ? wp_rand( 0, 5 ) : 0;

        // Both Action Scheduler and wp_schedule_single_event spread the outer array
        // as positional args, so wrap in a single-element array so the callback
        // receives one associative $args parameter.
        $retry_args = array(
            array(
                'order_id'        => $order->get_id(),
                'transaction_id'  => $transaction_id,
                'idempotency_key' => $idempotency_key,
                'attempt'         => $attempt,
            ),
        );

        if ( function_exists( 'as_schedule_single_action' ) ) {
            as_schedule_single_action(
                time() + $delay,
                'givepayments_async_refund_retry',
                $retry_args,
                'givepayments'
            );
            GIVEPAYMENTS_Logger::log(
                sprintf( 'Scheduled refund retry #%d for order %d in %ds.', $attempt, $order->get_id(), $delay ),
                'debug'
            );
        } else {
            wp_schedule_single_event(
                time() + $delay,
                'givepayments_async_refund_retry',
                $retry_args
            );
        }
    }

    // -------------------------------------------------------------------------
    // Refund eligibility
    // -------------------------------------------------------------------------

    /**
     * Determine whether an order is eligible for a provider refund.
     *
     * Called by GIVEPAYMENTS_Gateway::can_refund_order() after the WC gateway
     * checks (supports 'refunds', parent::can_refund_order) have passed.
     *
     * @param WC_Order $order
     * @return bool
     */
    public static function can_refund( WC_Order $order ): bool {
        // Block refunds for any terminal or uncharged WC order status.
        // - refunded:  already fully refunded
        // - failed:    payment was declined or never completed
        // - cancelled: order was cancelled (payment either never taken or voided)
        // - pending:   customer never completed checkout, nothing was charged
        $non_refundable_statuses = array( 'refunded', 'failed', 'cancelled', 'pending' );
        if ( in_array( $order->get_status(), $non_refundable_statuses, true ) ) {
            return false;
        }

        $order_total    = (float) $order->get_total();
        $refunded_total = abs( (float) $order->get_total_refunded() );
        if ( $order_total > 0 && $refunded_total >= ( $order_total - 0.0001 ) ) {
            return false;
        }

        $rec = GIVEPAYMENTS_Payment_Record::for( $order );

        // Hard stop once provider confirms terminal refund success.
        if ( $rec->is_refund_completed() ) {
            return false;
        }

        // Refund is only permitted when the canonical Order_State table says so.
        // Currently only 'settled' payments are refundable; any other known state
        // (e.g. 'captured', 'authorized') routes to Void instead.
        // Fallback for orders that pre-date processing_state meta: block refund on
        // processing orders with a txn ID when no refund is already in flight.
        $processing_state = $rec->processing_state();
        if ( '' !== $processing_state ) {
            if ( ! GIVEPAYMENTS_Order_State::can_refund( $processing_state ) ) {
                return false;
            }
        } else {
            if ( 'processing' === $order->get_status()
                && ! $rec->is_refund_pending()
            ) {
                $txn = (string) $order->get_transaction_id();
                if ( '' === $txn ) {
                    $txn = $rec->transaction_id();
                }
                if ( '' !== $txn ) {
                    return false;
                }
            }
        }

        self::release_stale_pending_lock( $order );
        return ! self::is_pending_window_active( $order );
    }
}
