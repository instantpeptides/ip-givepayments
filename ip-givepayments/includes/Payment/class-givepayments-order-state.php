<?php
/**
 * Order state mapping, public API delegating to GIVEPAYMENTS_Processing_State.
 *
 * All classification logic lives in GIVEPAYMENTS_Processing_State.  This class
 * is kept as the call-site façade so external code (adapters, filters) that
 * already references GIVEPAYMENTS_Order_State::method() continues to work
 * without changes.
 *
 * Do not add new logic here, add it to GIVEPAYMENTS_Processing_State and
 * expose a thin delegation method below.
 *
 * @package GivePayments
 */

defined( 'ABSPATH' ) || exit;

class GIVEPAYMENTS_Order_State {

	/**
	 * The WooCommerce order status that should be applied when the provider
	 * transitions into the given processing state. Returns null if no automatic
	 * WC status transition should be made.
	 *
	 * @param string $provider_state Provider processingState (case-insensitive).
	 * @return string|null WC order status slug (without 'wc-' prefix), or null.
	 */
	public static function wc_status_for( string $provider_state ): ?string {
		$state = GIVEPAYMENTS_Processing_State::tryFrom( $provider_state );
		return $state ? $state->wc_status() : null;
	}

	/**
	 * Whether this state permits a void operation.
	 *
	 * Authorized payments → POST /payments/{id}/void
	 * Captured payments   → POST /refunds (pre-settlement full reversal)
	 *
	 * @param string $provider_state
	 * @return bool
	 */
	public static function can_void( string $provider_state ): bool {
		$state = GIVEPAYMENTS_Processing_State::tryFrom( $provider_state );
		return $state ? $state->can_void() : false;
	}

	/**
	 * Whether this state permits a standard refund operation.
	 *
	 * @param string $provider_state
	 * @return bool
	 */
	public static function can_refund( string $provider_state ): bool {
		$state = GIVEPAYMENTS_Processing_State::tryFrom( $provider_state );
		return $state ? $state->can_refund() : false;
	}

	/**
	 * Whether the given state represents a successfully accepted payment
	 * (authorization hold or capture confirmed, not yet necessarily settled).
	 *
	 * @param string $provider_state
	 * @return bool
	 */
	public static function is_accepted_state( string $provider_state ): bool {
		$state = GIVEPAYMENTS_Processing_State::tryFrom( $provider_state );
		return $state ? $state->is_accepted() : false;
	}

	/**
	 * Whether the state is a card-level decline (as opposed to a technical failure).
	 * Used to select customer-facing decline vs generic-failure copy in notices.
	 *
	 * @param string $provider_state
	 * @return bool
	 */
	public static function is_decline_state( string $provider_state ): bool {
		$state = GIVEPAYMENTS_Processing_State::tryFrom( $provider_state );
		return $state ? $state->is_decline() : false;
	}
}
