<?php
/**
 * Typed meta-access façade for a GivePayments WC_Order.
 *
 * This is the single source of truth for every `_givepayments_*` order-meta key.
 * All reads and writes from domain services should go through this class so that:
 *  - The literal key strings exist in exactly one place.
 *  - Callers receive typed values instead of raw cast-strings.
 *  - Invariants (e.g. lowercase-trimmed processing state) are enforced here.
 *
 * Usage:
 *   $rec = GIVEPAYMENTS_Payment_Record::for( $order );
 *   $rec->transaction_id();            // string
 *   $rec->set_transaction_id( $id );   // void (no auto-save)
 *   $rec->save();                      // delegates to $order->save()
 *
 * Note: setters do NOT call $order->save() automatically, the caller is
 * responsible for flushing to the DB (same contract as raw update_meta_data).
 * The only exception is record_processed_event(), which saves immediately
 * because the dedup contract requires an atomic commit before returning.
 *
 * Meta keys managed, see AGENTS.md for semantic descriptions.
 *
 * @package GivePayments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Payment_Record {

    // -------------------------------------------------------------------------
    // Meta key constants, the ONLY place these literal strings should appear.
    // -------------------------------------------------------------------------

    const META_TRANSACTION_ID               = '_givepayments_transaction_id';
    const META_PROCESSING_STATE             = '_givepayments_processing_state';
    const META_ENVIRONMENT                  = '_givepayments_environment';
    const META_CARD_TOKEN_ID                = '_givepayments_card_token_id';

    const META_REFUND_COMPLETED             = '_givepayments_refund_completed';
    const META_REFUND_PENDING               = '_givepayments_refund_pending';
    const META_REFUND_PENDING_AT            = '_givepayments_refund_pending_at';
    const META_REFUND_ORIGIN                = '_givepayments_refund_origin';
    const META_REFUND_IDEMPOTENCY_KEY       = '_givepayments_refund_idempotency_key';
    const META_REFUND_ATTEMPT               = '_givepayments_refund_attempt';

    const META_VOID_IDEMPOTENCY_KEY         = '_givepayments_void_idempotency_key';

    const META_IDEMPOTENCY_KEY              = '_givepayments_idempotency_key';
    const META_IDEMPOTENCY_KEY_CREATED_AT   = '_givepayments_idempotency_key_created_at';
    const META_LAST_SUCCESS_IDEMPOTENCY_KEY = '_givepayments_last_success_idempotency_key';

    const META_CHARGEBACK_OPEN              = '_givepayments_chargeback_open';
    const META_PAYMENT_INITIATED            = '_givepayments_payment_initiated';
    const META_PAYMENT_FAILED               = '_givepayments_payment_failed';

    const META_PROCESSED_EVENT_IDS          = '_givepayments_processed_event_ids';
    const META_LAST_PORTAL_EVENT_ID         = '_givepayments_last_portal_event_id';
    const META_LAST_PORTAL_EVENT_TYPE       = '_givepayments_last_portal_event_type';
    const META_LAST_PORTAL_STATE            = '_givepayments_last_portal_state';

    // Maximum stored processed-event IDs before the list is trimmed.
    const PROCESSED_EVENT_IDS_LIMIT = 50;

    // -------------------------------------------------------------------------
    // Instance state
    // -------------------------------------------------------------------------

    /** @var WC_Order */
    private $order;

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    private function __construct( WC_Order $order ) {
        $this->order = $order;
    }

    /**
     * Wrap a WC_Order in a PaymentRecord.
     *
     * @param WC_Order $order
     * @return static
     */
    public static function for( WC_Order $order ) {
        return new static( $order );
    }

    // -------------------------------------------------------------------------
    // Order passthrough
    // -------------------------------------------------------------------------

    /**
     * Return the underlying order (for operations outside this class scope).
     *
     * @return WC_Order
     */
    public function order(): WC_Order {
        return $this->order;
    }

    /**
     * Flush pending meta changes to the database.
     *
     * @return void
     */
    public function save(): void {
        $this->order->save();
    }

    // =========================================================================
    // Transaction
    // =========================================================================

    /**
     * The provider-side payment/transaction ID, or '' if not yet set.
     *
     * Reads the custom meta key. For the WC-native transaction id use
     * $record->order()->get_transaction_id() directly.
     *
     * @return string
     */
    public function transaction_id(): string {
        return $this->get_string( self::META_TRANSACTION_ID );
    }

    /**
     * @param string $id
     * @return void
     */
    public function set_transaction_id( string $id ): void {
        $this->set( self::META_TRANSACTION_ID, $id );
    }

    // =========================================================================
    // Processing state
    // =========================================================================

    /**
     * The provider processingState for this order, already lowercased + trimmed.
     * Returns '' if not yet recorded.
     *
     * @return string
     */
    public function processing_state(): string {
        return strtolower( trim( $this->get_string( self::META_PROCESSING_STATE ) ) );
    }

    /**
     * @param string $state  Raw provider processingState value.
     * @return void
     */
    public function set_processing_state( string $state ): void {
        $this->set( self::META_PROCESSING_STATE, $state );
    }

    // =========================================================================
    // Environment
    // =========================================================================

    /**
     * The environment ('production' or 'test') under which this order was placed.
     * Falls back to '' (caller should then use the global option with a warning).
     *
     * @return string
     */
    public function environment(): string {
        return $this->get_string( self::META_ENVIRONMENT );
    }

    /**
     * @param string $env  'production' or 'test'
     * @return void
     */
    public function set_environment( string $env ): void {
        $this->set( self::META_ENVIRONMENT, $env );
    }

    // =========================================================================
    // Card token
    // =========================================================================

    /**
     * The subscription card-token ID stored on this order, or ''.
     *
     * @return string
     */
    public function card_token_id(): string {
        return $this->get_string( self::META_CARD_TOKEN_ID );
    }

    /**
     * @param string $token_id
     * @return void
     */
    public function set_card_token_id( string $token_id ): void {
        $this->set( self::META_CARD_TOKEN_ID, $token_id );
    }

    // =========================================================================
    // Refund: completion
    // =========================================================================

    /**
     * Whether a refund (or void) has been confirmed for this order.
     *
     * @return bool
     */
    public function is_refund_completed(): bool {
        return $this->get_bool( self::META_REFUND_COMPLETED );
    }

    /**
     * Permanently mark this order as refund-completed and clear pending state.
     *
     * Does NOT save, caller must call save() or the order save chain.
     *
     * @return void
     */
    public function mark_refund_completed(): void {
        $this->set( self::META_REFUND_COMPLETED, 'yes' );
        $this->delete( self::META_REFUND_PENDING );
        $this->delete( self::META_REFUND_PENDING_AT );
        $this->delete( self::META_REFUND_ORIGIN );
    }

    // =========================================================================
    // Refund: pending window
    // =========================================================================

    /**
     * Whether a refund request is currently in-flight.
     *
     * @return bool
     */
    public function is_refund_pending(): bool {
        return $this->get_bool( self::META_REFUND_PENDING );
    }

    /**
     * @param int $timestamp  Unix timestamp when the refund was enqueued.
     * @return void
     */
    public function set_refund_pending( int $timestamp ): void {
        $this->set( self::META_REFUND_PENDING, 'yes' );
        $this->set( self::META_REFUND_PENDING_AT, $timestamp );
    }

    /**
     * Clear the in-flight pending flag (e.g. after a terminal failure).
     *
     * @return void
     */
    public function clear_refund_pending(): void {
        $this->delete( self::META_REFUND_PENDING );
        $this->delete( self::META_REFUND_PENDING_AT );
    }

    /**
     * When the refund was enqueued (0 if not set).
     *
     * @return int
     */
    public function refund_pending_at(): int {
        return $this->get_int( self::META_REFUND_PENDING_AT );
    }

    // =========================================================================
    // Refund: origin
    // =========================================================================

    /**
     * Sanitized refund-origin tag (e.g. 'admin', 'webhook'), or ''.
     *
     * @return string
     */
    public function refund_origin(): string {
        return sanitize_key( $this->get_string( self::META_REFUND_ORIGIN ) );
    }

    /**
     * @param string $origin
     * @return void
     */
    public function set_refund_origin( string $origin ): void {
        $this->set( self::META_REFUND_ORIGIN, sanitize_key( $origin ) );
    }

    /**
     * @return void
     */
    public function clear_refund_origin(): void {
        $this->delete( self::META_REFUND_ORIGIN );
    }

    // =========================================================================
    // Refund: idempotency key
    // =========================================================================

    /**
     * The stable idempotency key for the in-flight refund call, or ''.
     *
     * @return string
     */
    public function refund_idempotency_key(): string {
        return $this->get_string( self::META_REFUND_IDEMPOTENCY_KEY );
    }

    /**
     * @param string $key
     * @return void
     */
    public function set_refund_idempotency_key( string $key ): void {
        $this->set( self::META_REFUND_IDEMPOTENCY_KEY, $key );
    }

    /**
     * @return void
     */
    public function clear_refund_idempotency_key(): void {
        $this->delete( self::META_REFUND_IDEMPOTENCY_KEY );
    }

    // =========================================================================
    // Refund: attempt counter
    // =========================================================================

    /**
     * The highest retry-attempt number seen for this order (0 if never retried).
     *
     * @return int
     */
    public function refund_attempt(): int {
        return $this->get_int( self::META_REFUND_ATTEMPT );
    }

    /**
     * @param int $attempt
     * @return void
     */
    public function set_refund_attempt( int $attempt ): void {
        $this->set( self::META_REFUND_ATTEMPT, $attempt );
    }

    /**
     * @return void
     */
    public function clear_refund_attempt(): void {
        $this->delete( self::META_REFUND_ATTEMPT );
    }

    // =========================================================================
    // Void: idempotency key
    // =========================================================================

    /**
     * The stable idempotency key for the in-flight void call, or ''.
     *
     * @return string
     */
    public function void_idempotency_key(): string {
        return $this->get_string( self::META_VOID_IDEMPOTENCY_KEY );
    }

    /**
     * @param string $key
     * @return void
     */
    public function set_void_idempotency_key( string $key ): void {
        $this->set( self::META_VOID_IDEMPOTENCY_KEY, $key );
    }

    /**
     * @return void
     */
    public function clear_void_idempotency_key(): void {
        $this->delete( self::META_VOID_IDEMPOTENCY_KEY );
    }

    // =========================================================================
    // Payment idempotency key
    // =========================================================================

    /**
     * The current payment-request idempotency key, or ''.
     *
     * @return string
     */
    public function idempotency_key(): string {
        return $this->get_string( self::META_IDEMPOTENCY_KEY );
    }

    /**
     * @return int  Unix timestamp when the current key was created (0 if not set).
     */
    public function idempotency_key_created_at(): int {
        return $this->get_int( self::META_IDEMPOTENCY_KEY_CREATED_AT );
    }

    /**
     * @param string $key
     * @param int    $created_at  Unix timestamp.
     * @return void
     */
    public function set_idempotency_key( string $key, int $created_at ): void {
        $this->set( self::META_IDEMPOTENCY_KEY, $key );
        $this->set( self::META_IDEMPOTENCY_KEY_CREATED_AT, $created_at );
    }

    /**
     * @return void
     */
    public function clear_idempotency_key(): void {
        $this->delete( self::META_IDEMPOTENCY_KEY );
        $this->delete( self::META_IDEMPOTENCY_KEY_CREATED_AT );
    }

    /**
     * The idempotency key used by the last confirmed successful payment, or ''.
     *
     * @return string
     */
    public function last_success_idempotency_key(): string {
        return $this->get_string( self::META_LAST_SUCCESS_IDEMPOTENCY_KEY );
    }

    /**
     * @param string $key
     * @return void
     */
    public function set_last_success_idempotency_key( string $key ): void {
        $this->set( self::META_LAST_SUCCESS_IDEMPOTENCY_KEY, $key );
    }

    /**
     * Whether this order already has a confirmed successful GivePayments charge.
     *
     * @return bool
     */
    public function has_succeeded(): bool {
        return '' !== $this->last_success_idempotency_key();
    }

    // =========================================================================
    // Chargeback
    // =========================================================================

    /**
     * @return bool
     */
    public function is_chargeback_open(): bool {
        return $this->get_bool( self::META_CHARGEBACK_OPEN );
    }

    /**
     * @return void
     */
    public function open_chargeback(): void {
        $this->set( self::META_CHARGEBACK_OPEN, 'yes' );
    }

    /**
     * @return void
     */
    public function close_chargeback(): void {
        $this->delete( self::META_CHARGEBACK_OPEN );
    }

    // =========================================================================
    // Payment lifecycle flags
    // =========================================================================

    /**
     * @return bool
     */
    public function is_payment_initiated(): bool {
        return $this->get_bool( self::META_PAYMENT_INITIATED );
    }

    /**
     * @return void
     */
    public function mark_payment_initiated(): void {
        $this->set( self::META_PAYMENT_INITIATED, 'yes' );
    }

    /**
     * @return bool
     */
    public function is_payment_failed(): bool {
        return $this->get_bool( self::META_PAYMENT_FAILED );
    }

    /**
     * @return void
     */
    public function mark_payment_failed(): void {
        $this->set( self::META_PAYMENT_FAILED, 'yes' );
    }

    // =========================================================================
    // Webhook event dedup
    // =========================================================================

    /**
     * Check whether an event ID has already been processed for this order.
     *
     * @param string $event_id  Provider event UUID. Returns false for empty string.
     * @return bool
     */
    public function has_processed_event( string $event_id ): bool {
        $event_id = sanitize_text_field( $event_id );
        if ( '' === $event_id ) {
            return false;
        }

        $ids = $this->order->get_meta( self::META_PROCESSED_EVENT_IDS, true );
        if ( ! is_array( $ids ) ) {
            $ids = array();
        }

        return in_array( $event_id, $ids, true );
    }

    /**
     * Mark an event ID as processed and immediately persist to DB.
     *
     * The immediate save is intentional, this is the durable dedup layer that
     * survives server restarts. A bounded list (PROCESSED_EVENT_IDS_LIMIT) is
     * maintained to prevent unbounded meta growth.
     *
     * @param string $event_id  Provider event UUID. No-op for empty string.
     * @return void
     */
    public function record_processed_event( string $event_id ): void {
        $event_id = sanitize_text_field( $event_id );
        if ( '' === $event_id ) {
            return;
        }

        $ids = $this->order->get_meta( self::META_PROCESSED_EVENT_IDS, true );
        if ( ! is_array( $ids ) ) {
            $ids = array();
        }

        if ( in_array( $event_id, $ids, true ) ) {
            return;
        }

        $ids[] = $event_id;
        if ( count( $ids ) > self::PROCESSED_EVENT_IDS_LIMIT ) {
            $ids = array_slice( $ids, -self::PROCESSED_EVENT_IDS_LIMIT );
        }

        $this->order->update_meta_data( self::META_PROCESSED_EVENT_IDS, $ids );
        $this->order->save();
    }

    // =========================================================================
    // Webhook reconciliation trail
    // =========================================================================

    /**
     * @return string
     */
    public function last_portal_event_id(): string {
        return $this->get_string( self::META_LAST_PORTAL_EVENT_ID );
    }

    /**
     * @param string $event_id
     * @return void
     */
    public function set_last_portal_event_id( string $event_id ): void {
        $this->set( self::META_LAST_PORTAL_EVENT_ID, $event_id );
    }

    /**
     * @return string
     */
    public function last_portal_event_type(): string {
        return $this->get_string( self::META_LAST_PORTAL_EVENT_TYPE );
    }

    /**
     * @param string $type
     * @return void
     */
    public function set_last_portal_event_type( string $type ): void {
        $this->set( self::META_LAST_PORTAL_EVENT_TYPE, $type );
    }

    /**
     * @return string
     */
    public function last_portal_state(): string {
        return $this->get_string( self::META_LAST_PORTAL_STATE );
    }

    /**
     * @param string $state
     * @return void
     */
    public function set_last_portal_state( string $state ): void {
        $this->set( self::META_LAST_PORTAL_STATE, $state );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * @param string $key
     * @return string
     */
    private function get_string( string $key ): string {
        return (string) $this->order->get_meta( $key, true );
    }

    /**
     * @param string $key
     * @return int
     */
    private function get_int( string $key ): int {
        return (int) $this->order->get_meta( $key, true );
    }

    /**
     * @param string $key
     * @return bool  True when the stored value equals the string 'yes'.
     */
    private function get_bool( string $key ): bool {
        return 'yes' === $this->get_string( $key );
    }

    /**
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    private function set( string $key, $value ): void {
        $this->order->update_meta_data( $key, $value );
    }

    /**
     * @param string $key
     * @return void
     */
    private function delete( string $key ): void {
        $this->order->delete_meta_data( $key );
    }
}
