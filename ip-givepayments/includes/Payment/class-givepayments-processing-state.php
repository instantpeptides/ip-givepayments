<?php
/**
 * Typed wrapper for provider processingState values, single source of truth.
 *
 * Every classification (terminal, decline, accepted, can_void, can_refund,
 * wc_status mapping) lives here. Nothing else in the plugin should maintain
 * a separate list of state strings.
 *
 * PHP 7.4-compatible value-object pattern.  When the project minimum is
 * raised to PHP 8.1, this class can be replaced with a backed string enum
 * and the call sites remain unchanged (const values, tryFrom(), value(),
 * and instance methods all map 1:1 to PHP enum syntax).
 *
 * Extensibility:
 *   add_filter( 'givepayments_order_state_transitions', function( array $defs ) {
 *       $defs['custom_state'] = [
 *           'wc_status'   => 'on-hold',
 *           'can_void'    => false,
 *           'can_refund'  => false,
 *           'is_accepted' => false,
 *           'is_terminal' => false,
 *           'is_decline'  => false,
 *       ];
 *       return $defs;
 *   } );
 *
 * @package GivePayments
 */

defined( 'ABSPATH' ) || exit;

final class GIVEPAYMENTS_Processing_State {

	// ─── State string constants ───────────────────────────────────────────────
	// Use these everywhere instead of raw strings so typos surface at load time.

	const CREATED          = 'created';
	const AUTHORIZED       = 'authorized';
	const CAPTURED         = 'captured';
	const PROCESSING_ISSUE = 'processing_issue';
	const SETTLED          = 'settled';
	const DECLINED         = 'declined';
	const FAILED           = 'failed';
	const REJECTED         = 'rejected';
	const ERROR            = 'error';
	const VOIDED           = 'voided';
	const CANCELLED        = 'cancelled';
	const CANCELED         = 'canceled'; // US-spelling alias.

	// ─── Instance ────────────────────────────────────────────────────────────

	private string $value;

	private function __construct( string $value ) {
		$this->value = $value;
	}

	// ─── Static constructors (mirrors PHP 8.1 BackedEnum API) ─────────────────

	/**
	 * Resolve from a raw provider string.  Returns null for unrecognised states
	 * rather than throwing, callers must handle null defensively.
	 *
	 * @param string $state Provider processingState (case-insensitive, trimmed).
	 * @return static|null
	 */
	public static function tryFrom( string $state ): ?self {
		$key = strtolower( trim( $state ) );
		if ( '' !== $key && ! isset( self::definitions()[ $key ] ) ) {
			GIVEPAYMENTS_Logger::log(
				sprintf( 'Processing_State::tryFrom: unrecognised processingState "%s" \u2014 returned null.', $state ),
				'warning'
			);
		}
		return isset( self::definitions()[ $key ] ) ? new self( $key ) : null;
	}

	/**
	 * @param string $state
	 * @return static
	 * @throws \ValueError For unrecognised state strings.
	 */
	public static function from( string $state ): self {
		$obj = self::tryFrom( $state );
		if ( null === $obj ) {
			throw new \ValueError(
				sprintf( '"%s" is not a recognised GivePayments processingState.', $state )
			);
		}
		return $obj;
	}

	// ─── Value accessor ───────────────────────────────────────────────────────

	/** @return string Lower-cased canonical state string. */
	public function value(): string {
		return $this->value;
	}

	// ─── Classification methods ───────────────────────────────────────────────

	/**
	 * WooCommerce order status that should be applied when the provider
	 * transitions into this state.  Null means no automatic WC transition.
	 *
	 * @return string|null WC status slug (without 'wc-' prefix).
	 */
	public function wc_status(): ?string {
		return self::definitions()[ $this->value ]['wc_status'];
	}

	/** Whether this state permits a void operation. */
	public function can_void(): bool {
		return (bool) ( self::definitions()[ $this->value ]['can_void'] ?? false );
	}

	/** Whether this state permits a standard post-settlement refund. */
	public function can_refund(): bool {
		return (bool) ( self::definitions()[ $this->value ]['can_refund'] ?? false );
	}

	/**
	 * Whether this state represents a successfully accepted payment
	 * (authorization hold or capture confirmed, not yet settled).
	 * Used by PaymentProcessor to distinguish "advance order" from
	 * "keep at pending/failed".
	 */
	public function is_accepted(): bool {
		return (bool) ( self::definitions()[ $this->value ]['is_accepted'] ?? false );
	}

	/**
	 * Whether this state is a terminal payment failure.
	 * True for any state that means the payment will never succeed:
	 * card declines, technical failures, voids, and cancellations.
	 * Used by PaymentProcessor sync-decline guard and Api_Response helpers.
	 */
	public function is_terminal(): bool {
		return (bool) ( self::definitions()[ $this->value ]['is_terminal'] ?? false );
	}

	/**
	 * Whether this state is a card-level explicit decline or rejection.
	 * Distinct from is_terminal(), used to show actionable copy
	 * ("check your card details") vs generic copy ("payment failed, try again").
	 */
	public function is_decline(): bool {
		return (bool) ( self::definitions()[ $this->value ]['is_decline'] ?? false );
	}

	// ─── Cache reset (tests only) ─────────────────────────────────────────────

	/**
	 * Clear the definitions cache so that apply_filters mocks take effect.
	 * Call this in TestCase::setUp() if a test exercises the filter.
	 */
	public static function reset_cache(): void {
		self::$definitions_cache = null;
	}

	// ─── Definitions table ────────────────────────────────────────────────────

	/** @var array<string, array{wc_status: string|null, can_void: bool, can_refund: bool, is_accepted: bool, is_terminal: bool, is_decline: bool}>|null */
	private static ?array $definitions_cache = null;

	/**
	 * Returns the filter-augmented definitions table.
	 * Result is cached per process for performance; call reset_cache() in tests.
	 *
	 * Third-party code extends this via the 'givepayments_order_state_transitions'
	 * filter.  Added rows must supply all six keys; missing keys default to false/null.
	 *
	 * @return array<string, array{wc_status: string|null, can_void: bool, can_refund: bool, is_accepted: bool, is_terminal: bool, is_decline: bool}>
	 */
	private static function definitions(): array {
		if ( null !== self::$definitions_cache ) {
			return self::$definitions_cache;
		}

		// ── Base definitions ──────────────────────────────────────────────────
		// Keys are canonical lowercase state strings.
		// is_accepted: payment was accepted (authorized/captured) but not yet settled.
		// is_terminal: payment has definitively ended in failure (cannot recover).
		// is_decline:  card/account explicitly declined or rejected, show card-check copy.
		$base = array(
			// ── Accepted / in-flight ──────────────────────────────────────────
			self::CREATED          => array(
				'wc_status'   => 'pending',
				'can_void'    => true,
				'can_refund'  => false,
				'is_accepted' => true,
				'is_terminal' => false,
				'is_decline'  => false,
			),
			self::AUTHORIZED       => array(
				'wc_status'   => 'processing',
				'can_void'    => true,
				'can_refund'  => false,
				'is_accepted' => true,
				'is_terminal' => false,
				'is_decline'  => false,
			),
			self::CAPTURED         => array(
				// can_void routes to POST /refunds (pre-settlement reversal).
				// can_refund is false, Void button is the single admin control.
				'wc_status'   => 'processing',
				'can_void'    => true,
				'can_refund'  => false,
				'is_accepted' => true,
				'is_terminal' => false,
				'is_decline'  => false,
			),
			self::PROCESSING_ISSUE => array(
				// Provider-side issue after capture, both void and refund are available.
				'wc_status'   => 'processing',
				'can_void'    => true,
				'can_refund'  => true,
				'is_accepted' => false,
				'is_terminal' => false,
				'is_decline'  => false,
			),
			self::SETTLED          => array(
				// Funds moved to merchant account, post-settlement refund only.
				'wc_status'   => null,
				'can_void'    => false,
				'can_refund'  => true,
				'is_accepted' => true,
				'is_terminal' => false,
				'is_decline'  => false,
			),
			// ── Terminal failures ─────────────────────────────────────────────
			self::DECLINED         => array(
				// Explicit card-level decline (wrong details, insufficient funds, fraud).
				'wc_status'   => 'failed',
				'can_void'    => false,
				'can_refund'  => false,
				'is_accepted' => false,
				'is_terminal' => true,
				'is_decline'  => true,
			),
			self::FAILED           => array(
				// Technical payment failure (provider-side processing error).
				'wc_status'   => 'failed',
				'can_void'    => false,
				'can_refund'  => false,
				'is_accepted' => false,
				'is_terminal' => true,
				'is_decline'  => false,
			),
			self::REJECTED         => array(
				// Explicit rejection (risk rules, velocity, compliance).
				'wc_status'   => 'failed',
				'can_void'    => false,
				'can_refund'  => false,
				'is_accepted' => false,
				'is_terminal' => true,
				'is_decline'  => true,
			),
			self::ERROR            => array(
				// Unclassified provider-side error.
				'wc_status'   => 'failed',
				'can_void'    => false,
				'can_refund'  => false,
				'is_accepted' => false,
				'is_terminal' => true,
				'is_decline'  => false,
			),
			self::VOIDED           => array(
				// Payment reversed before settlement (void confirmed).
				'wc_status'   => 'cancelled',
				'can_void'    => false,
				'can_refund'  => false,
				'is_accepted' => false,
				'is_terminal' => true,
				'is_decline'  => false,
			),
			self::CANCELLED        => array(
				// Provider-side cancellation.
				'wc_status'   => 'cancelled',
				'can_void'    => false,
				'can_refund'  => false,
				'is_accepted' => false,
				'is_terminal' => true,
				'is_decline'  => false,
			),
			self::CANCELED         => array(
				// US-spelling alias, identical behaviour.
				'wc_status'   => 'cancelled',
				'can_void'    => false,
				'can_refund'  => false,
				'is_accepted' => false,
				'is_terminal' => true,
				'is_decline'  => false,
			),
		);

		/**
		 * Filters the complete processing-state definitions table.
		 *
		 * Use this to register custom provider states or override existing ones.
		 * Each row must be an array with keys:
		 *   wc_status   (string|null), can_void (bool), can_refund (bool),
		 *   is_accepted (bool), is_terminal (bool), is_decline (bool).
		 *
		 * @param array $definitions Map of state string → definition array.
		 */
		self::$definitions_cache = apply_filters( 'givepayments_order_state_transitions', $base );
		return self::$definitions_cache;
	}
}
