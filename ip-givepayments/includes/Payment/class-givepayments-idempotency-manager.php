<?php
/**
 * Idempotency key lifecycle manager for GivePayments payment attempts.
 *
 * Responsibilities:
 *  - Get or create a per-order idempotency key (with transient-based concurrency lock).
 *  - Rotate the key after a terminal decline so the next customer attempt is a fresh charge.
 *  - Promote the key to the "last success" sentinel after a confirmed successful payment.
 *  - Guard against triggering a second successful charge on an order that already succeeded.
 *
 * All order meta is accessed via HPOS-safe WC CRUD methods.
 *
 * Meta keys managed:
 *  _givepayments_idempotency_key
 *  _givepayments_idempotency_key_created_at
 *  _givepayments_last_success_idempotency_key
 *
 * @package GivePayments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Idempotency_Manager {

    /**
     * Key lifetime in seconds. Within this window the same key is reused so that
     * network retries and double-clicks hit the provider's idempotency cache.
     * 3600 s (1 hour) matches typical provider idempotency windows and avoids
     * generating a second key (and a potential second charge) for slow checkouts.
     */
    const KEY_TTL = 3600;

    /**
     * Return the active idempotency key for an order, creating one if needed.
     *
     * Uses a transient-based lock to prevent concurrent requests (double-click,
     * aggressive auto-retry) from generating different keys.
     *
     * @param WC_Order $order
     * @return string
     */
    public static function get_or_create( WC_Order $order ): string {
        $rec          = GIVEPAYMENTS_Payment_Record::for( $order );
        $existing_key = $rec->idempotency_key();
        $created_at   = $rec->idempotency_key_created_at();
        $now          = time();

        if ( $existing_key && $created_at > 0 && ( $now - $created_at ) <= self::KEY_TTL ) {
            return $existing_key;
        }

        // Atomic mutex via MySQL INSERT IGNORE: add_option() is backed by a unique
        // index and fails silently if the row already exists, giving true mutual
        // exclusion without a check-then-act race. Transients (get + set) are not
        // atomic and allow two concurrent processes to both read "no lock" and both
        // generate different keys, which would send two distinct charges.
        $lock_option = 'gp_idem_lock_' . (int) $order->get_id();
        $lock_won    = (bool) add_option( $lock_option, '1', '', 'no' );

        if ( ! $lock_won ) {
            // Lock winner is generating the key. Retry with escalating back-off before
            // assuming the winner crashed without saving.
            $delays = array( 50000, 100000, 150000 ); // 50 ms, 100 ms, 150 ms
            foreach ( $delays as $delay ) {
                usleep( $delay );
                $order->read_meta_data( true );
                $refreshed = $rec->idempotency_key();
                if ( '' !== $refreshed ) {
                    return $refreshed;
                }
            }
            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'IdempotencyManager: lock loser waited 300 ms but order %d still has no key, generating own key (lock winner likely crashed before saving).',
                    (int) $order->get_id()
                ),
                'warning'
            );
            // Lock holder crashed before saving, fall through to generate our own.
        }

        $new_key = sprintf(
            'gp_wc_%d_%s',
            (int) $order->get_id(),
            wp_generate_password( 24, false, false )
        );

        $rec->set_idempotency_key( $new_key, $now );
        $rec->save();

        if ( $lock_won ) {
            delete_option( $lock_option );
        }

        return $new_key;
    }

    /**
     * Discard the current key so the next customer attempt generates a fresh one,
     * ensuring the provider sees it as a new charge rather than a cached response.
     *
     * Call this after any terminal decline or when the payment flow must restart.
     *
     * @param WC_Order $order
     * @return void
     */
    public static function rotate( WC_Order $order ): void {
        $rec = GIVEPAYMENTS_Payment_Record::for( $order );
        $rec->clear_idempotency_key();
        $rec->save();
    }

    /**
     * Record that a payment was successfully initiated for this order.
     *
     * Stores the idempotency key used for the successful charge as a permanent
     * sentinel. This is the authoritative "this order already paid via GivePayments"
     * marker used by has_succeeded() to block duplicate charges.
     *
     * @param WC_Order $order
     * @param string   $key   The idempotency key that produced the successful response.
     * @return void
     */
    public static function promote( WC_Order $order, string $key ): void {
        $rec = GIVEPAYMENTS_Payment_Record::for( $order );
        $rec->set_last_success_idempotency_key( $key );
        $rec->save();
    }

    /**
     * Check whether this order already has a confirmed successful GivePayments charge.
     *
     * This guard is set at API-response time (before the order status transitions to
     * "processing"), so it closes the narrow race window where a concurrent request
     * could slip past the WooCommerce is_paid() / order-status checks.
     *
     * @param WC_Order $order
     * @return bool
     */
    public static function has_succeeded( WC_Order $order ): bool {
        return GIVEPAYMENTS_Payment_Record::for( $order )->has_succeeded();
    }
}
