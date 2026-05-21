<?php
/**
 * Translates incoming webhook event data into a canonical resolved state string.
 *
 * Three-tier resolution:
 *   1. Event type       , authoritative when present and recognised
 *   2. processingState , via GIVEPAYMENTS_Order_State table
 *   3. status field    , last-resort fallback for legacy payload shapes
 *
 * Canonical return values:
 *   'processing'          → WC order → 'processing'
 *   'settled'             → WC order → 'completed'
 *   'failure'             → WC order → 'failed'
 *   'voided'              → WC order → 'refunded' (payment.canceled / payment.cancelled)
 *   'refunded'            → run refund accounting flow
 *   'refund_failed'       → log, no WC status change
 *   'pending'             → WC order → 'pending'
 *   'chargeback'          → open chargeback case
 *   'chargeback_reversal' → close chargeback case
 *   'chargeback_refund'   → close chargeback + refund accounting
 *   'unknown'             → log and ignore
 *
 * @package GivePayments
 */

defined( 'ABSPATH' ) || exit;

class GIVEPAYMENTS_Webhook_State_Resolver {

    /**
     * @param string $event_type       e.g. 'payment.captured'
     * @param string $processing_state e.g. 'captured'
     * @param string $payment_status   Fallback status/reversalState field
     * @return string Canonical resolved-state string.
     */
    public static function resolve(
        string $event_type,
        string $processing_state,
        string $payment_status
    ): string {
        $event_type       = strtolower( trim( $event_type ) );
        $processing_state = strtolower( trim( $processing_state ) );
        $payment_status   = strtolower( trim( $payment_status ) );

        // Tier 1, event type.
        $from_event = self::resolve_from_event_type( $event_type, $processing_state, $payment_status );
        if ( null !== $from_event ) {
            return $from_event;
        }

        // Tier 2, processingState via Order_State table.
        if ( '' !== $processing_state ) {
            $wc_status = GIVEPAYMENTS_Order_State::wc_status_for( $processing_state );
            if ( null !== $wc_status ) {
                return self::wc_status_to_resolved( $wc_status );
            }
            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'Webhook state resolver: unrecognised processingState "%s" (event: %s). Falling through to status fallback.',
                    $processing_state,
                    $event_type
                ),
                'warning'
            );
        }

        // Tier 3, raw status / reversalState field.
        return self::resolve_from_status( $payment_status );
    }

    // -------------------------------------------------------------------------

    private static function resolve_from_event_type(
        string $event_type,
        string $processing_state,
        string $payment_status
    ): ?string {
        // Chargeback variants, most-specific first.
        if ( false !== strpos( $event_type, 'chargeback' ) ) {
            if ( false !== strpos( $event_type, 'reversal' ) ) {
                return 'chargeback_reversal';
            }
            if ( false !== strpos( $event_type, 'refund' ) ) {
                return 'chargeback_refund';
            }
            return 'chargeback';
        }

        // Refund lifecycle.
        if (
            in_array( $event_type, array(
                'refund.created', 'refund.pending', 'refund.approved',
                'refund.settled', 'refund.succeeded', 'refund.processing_issue',
                'payment.refunded',
            ), true )
            || false !== strpos( $event_type, 'refunded' )
        ) {
            return 'refunded';
        }
        if ( in_array( $event_type, array( 'refund.failed', 'refund.declined', 'refund.canceled' ), true ) ) {
            return 'refund_failed';
        }

        // payment.voided and any void-containing event type → 'refunded' (merchant policy:
        // a voided payment was charged and reversed, same accounting as a refund).
        if ( 'payment.voided' === $event_type || false !== strpos( $event_type, 'void' ) ) {
            return 'refunded';
        }

        // payment.canceled / payment.cancelled → 'voided' (authorization cancelled before
        // capture). Per merchant policy, voided orders are set to WC 'refunded' status in
        // handle_webhook_voided for consistent accounting, even when no funds moved.
        if (
            in_array( $event_type, array( 'payment.canceled', 'payment.cancelled' ), true )
            || false !== strpos( $event_type, 'canceled' )
            || false !== strpos( $event_type, 'cancelled' )
        ) {
            return 'voided';
        }

        // Payment lifecycle, created / authorized / captured / pending.
        // Critical guard: even for "accepted" event types, a terminal processingState
        // in the body wins. The provider can emit payment.created with
        // processingState=declined for instant hard-declines without a separate event.
        if ( in_array( $event_type, array(
            'payment.created', 'payment.authorized',
            'payment.captured', 'payment.pending',
        ), true ) ) {
            if ( self::is_decline_state( $processing_state ) || self::is_decline_state( $payment_status ) ) {
                return 'failure';
            }
            return in_array( $event_type, array(
                'payment.authorized', 'payment.captured',
            ), true ) ? 'processing' : 'pending';
        }

        if ( 'payment.settled' === $event_type ) {
            return 'settled';
        }

        if ( in_array( $event_type, array( 'payment.failed', 'payment.declined' ), true ) ) {
            return 'failure';
        }

        return null; // Unknown, fall through to Tier 2.
    }

    /**
     * Map a WC status slug (from Order_State table) to a resolved-state string.
     */
    private static function wc_status_to_resolved( string $wc_status ): string {
        $map = array(
            'processing' => 'processing',
            'completed'  => 'settled',
            'failed'     => 'failure',
            'cancelled'  => 'voided',
        );
        return $map[ $wc_status ] ?? 'unknown';
    }

    /**
     * Last-resort resolution from raw status / reversalState field.
     */
    private static function resolve_from_status( string $status_key ): string {
        // Normalise separators then collapse whitespace.
        $status_key = str_replace( array( '-', '_' ), ' ', $status_key );
        $status_key = trim( preg_replace( '/\s+/', ' ', $status_key ) );

        $map = array(
            'successful'          => 'processing',
            'captured'            => 'processing',
            'processing'          => 'processing',
            'settled'             => 'settled',
            'pending'             => 'processing',
            'voided'              => 'refunded',
            'canceled'            => 'voided',
            'declined'            => 'failure',
            'failed'              => 'failure',
            'rejected'            => 'failure',
            'refunded'            => 'refunded',
            'fully reversed'      => 'refunded',
            'partially refunded'  => 'refunded',
            'partially reversed'  => 'refunded',
            'chargeback'          => 'chargeback',
            'chargeback reversal' => 'chargeback_reversal',
            'chargeback refund'   => 'chargeback_refund',
        );

        return $map[ $status_key ] ?? 'unknown';
    }

    private static function is_decline_state( string $state ): bool {
        // Delegates to Processing_State so the definition stays in one place.
        // Note: this checks is_terminal() (any terminal failure) rather than
        // is_decline() (card-level decline only), the context here is "did
        // this payment lifecycle event carry a body that says the payment
        // has definitively failed?"  Any terminal state (declined, failed,
        // rejected, error, voided, cancelled) qualifies.
        $ps = GIVEPAYMENTS_Processing_State::tryFrom( $state );
        return $ps ? $ps->is_terminal() : false;
    }
}
