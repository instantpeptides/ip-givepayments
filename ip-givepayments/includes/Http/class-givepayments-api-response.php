<?php
/**
 * GivePayments API Response Parser.
 *
 * Static utilities for extracting structured data from GivePayments API
 * responses. Extracted from GIVEPAYMENTS_Gateway to keep parsing logic
 * separate from transport and gateway glue code.
 */

defined( 'ABSPATH' ) || exit;

class GIVEPAYMENTS_Api_Response {

	/**
	 * Provider processingState values that indicate a terminal payment failure.
		 * Delegates to GIVEPAYMENTS_Processing_State, the single source of truth.
		 *
		 * @return array
		 */
		public static function terminal_states() {
			// Derive from Processing_State definitions rather than maintaining a
			// separate list that could drift from the canonical table.
			$terminal = array();
			foreach (
				array(
					GIVEPAYMENTS_Processing_State::DECLINED,
					GIVEPAYMENTS_Processing_State::FAILED,
					GIVEPAYMENTS_Processing_State::REJECTED,
					GIVEPAYMENTS_Processing_State::ERROR,
					GIVEPAYMENTS_Processing_State::VOIDED,
					GIVEPAYMENTS_Processing_State::CANCELLED,
					GIVEPAYMENTS_Processing_State::CANCELED,
				) as $value
			) {
				$state = GIVEPAYMENTS_Processing_State::tryFrom( $value );
				if ( $state && $state->is_terminal() ) {
					$terminal[] = $value;
				}
			}
			return $terminal;
		}

		/**
		 * @param string $state processingState value (will be lowercased for comparison).
		 * @return bool
		 */
		public static function is_terminal_failure_state( $state ) {
			$ps = GIVEPAYMENTS_Processing_State::tryFrom( (string) $state );
			return $ps && $ps->is_terminal();
		}

	/**
	 * HTTP status codes that are transient, the customer or system may safely retry.
	 *
	 * @param int $code
	 * @return bool
	 */
	public static function is_retryable_http_error( $code ) {
		$code = (int) $code;
		if ( $code >= 500 ) {
			return true;
		}
		return in_array( $code, array( 408, 409, 425, 429 ), true );
	}

	/**
	 * Pull transaction ID from a POST /payments JSON response (top-level or nested).
	 *
	 * @param mixed $data Decoded JSON object.
	 * @return string
	 */
	public static function extract_transaction_id( $data ) {
		if ( ! is_object( $data ) ) {
			return '';
		}
		$candidates = array();
		if ( ! empty( $data->id ) ) {
			$candidates[] = $data->id;
		}
		if ( ! empty( $data->payment ) && is_object( $data->payment ) && ! empty( $data->payment->id ) ) {
			$candidates[] = $data->payment->id;
		}
		if ( ! empty( $data->data ) && is_object( $data->data ) ) {
			if ( ! empty( $data->data->id ) ) {
				$candidates[] = $data->data->id;
			}
			if ( ! empty( $data->data->object ) && is_object( $data->data->object ) && ! empty( $data->data->object->id ) ) {
				$candidates[] = $data->data->object->id;
			}
		}
		foreach ( $candidates as $id ) {
			$id = sanitize_text_field( (string) $id );
			if ( '' !== $id ) {
				return $id;
			}
		}
		return '';
	}

	/**
	 * Extract processingState from a POST /payments JSON response object.
	 *
	 * Mirrors the same multi-level nesting that extract_transaction_id handles:
	 * top-level, data wrapper, and data.object webhook-style shape.
	 * Returns lower-cased value so callers can compare without further normalisation.
	 *
	 * @param mixed $data Decoded JSON object.
	 * @return string Lower-cased processingState value or ''.
	 */
	public static function extract_processing_state( $data ) {
		if ( ! is_object( $data ) ) {
			return '';
		}

		$objects_to_check = array( $data );
		if ( isset( $data->payment ) && is_object( $data->payment ) ) {
			$objects_to_check[] = $data->payment;
		}
		if ( isset( $data->data ) && is_object( $data->data ) ) {
			$objects_to_check[] = $data->data;
			if ( isset( $data->data->object ) && is_object( $data->data->object ) ) {
				$objects_to_check[] = $data->data->object;
			}
		}

		// 1. Primary: processingState / processing_state.
		foreach ( $objects_to_check as $obj ) {
			foreach ( array( 'processingState', 'processing_state' ) as $key ) {
				if ( isset( $obj->$key ) && '' !== trim( (string) $obj->$key ) ) {
					return strtolower( trim( (string) $obj->$key ) );
				}
			}
		}

		// 2. Fallback: status / displayStatus, covers hard-decline responses that
		// return only a status field and no processingState. Without this, declined
		// cards whose API response omits processingState are silently left as "pending".
		foreach ( $objects_to_check as $obj ) {
			foreach ( array( 'status', 'displayStatus' ) as $key ) {
				if ( isset( $obj->$key ) && '' !== trim( (string) $obj->$key ) ) {
					return strtolower( trim( (string) $obj->$key ) );
				}
			}
		}

		return '';
	}

	/**
	 * Pull reusable card token ID from a POST /payments response JSON.
	 *
	 * @param mixed $data Decoded JSON object.
	 * @return string
	 */
	public static function extract_card_token_id( $data ) {
		$paths = array(
			array( 'paymethod_token', 'id' ),
			array( 'paymethodToken', 'id' ),
			array( 'paymethod', 'token', 'id' ),
			array( 'token', 'id' ),
			array( 'payment', 'paymethod_token', 'id' ),
			array( 'payment', 'paymethodToken', 'id' ),
			array( 'payment', 'paymethod', 'token', 'id' ),
			array( 'payment', 'token', 'id' ),
			array( 'data', 'paymethod_token', 'id' ),
			array( 'data', 'paymethodToken', 'id' ),
			array( 'data', 'paymethod', 'token', 'id' ),
			array( 'data', 'token', 'id' ),
			array( 'data', 'object', 'paymethod_token', 'id' ),
			array( 'data', 'object', 'paymethodToken', 'id' ),
			array( 'data', 'object', 'paymethod', 'token', 'id' ),
			array( 'data', 'object', 'token', 'id' ),
		);

		foreach ( $paths as $path ) {
			$candidate = self::get_nested( $data, $path );
			if ( null === $candidate ) {
				continue;
			}
			$candidate = sanitize_text_field( (string) $candidate );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Parse API or transport errors into a consistent shape.
	 *
	 * Accepts either:
	 * - A WP_Error (transport failure)     , pass null for $response_body
	 * - A raw WP HTTP response array       , pass the decoded JSON object for $response_body
	 *
	 * @param WP_Error|array $response      WP HTTP response array or WP_Error.
	 * @param object|null    $response_body Decoded JSON body (may be null).
	 * @return array{ status_code: int, provider_code: string, provider_message: string, user_message: string }
	 */
	public static function parse_error( $response, $response_body ) {
		$status_code      = 0;
		$provider_code    = '';
		$provider_message = '';
		$user_message     = __( 'Transaction could not be completed. Please ensure your payment details are correct, then try a different card or payment method.', 'givepayments-for-woocommerce' );

		if ( is_wp_error( $response ) ) {
			$provider_code    = (string) $response->get_error_code();
			$provider_message = (string) $response->get_error_message();
			$user_message     = __( 'Unable to reach payment provider. Please try again.', 'givepayments-for-woocommerce' );

			return array(
				'status_code'      => $status_code,
				'provider_code'    => $provider_code,
				'provider_message' => $provider_message ?: $user_message,
				'user_message'     => $user_message,
			);
		}

		$status_code  = (int) wp_remote_retrieve_response_code( $response );
		$field_labels = self::field_labels();

		if ( is_object( $response_body ) ) {
			if ( isset( $response_body->code ) ) {
				$provider_code = (string) $response_body->code;
			} elseif ( isset( $response_body->error ) && is_object( $response_body->error ) && isset( $response_body->error->code ) ) {
				$provider_code = (string) $response_body->error->code;
			}

			if ( isset( $response_body->message ) ) {
				$provider_message = (string) $response_body->message;
			} elseif ( isset( $response_body->error ) && is_object( $response_body->error ) && isset( $response_body->error->message ) ) {
				$provider_message = (string) $response_body->error->message;
			}

			if ( $provider_code === 'invalid_input' && ! empty( $response_body->input ) && is_array( $response_body->input ) ) {
				$field_errors = array();
				foreach ( $response_body->input as $err ) {
					if ( ! is_object( $err ) || empty( $err->param ) ) {
						continue;
					}
					$param          = (string) $err->param;
					$label          = isset( $field_labels[ $param ] ) ? $field_labels[ $param ] : $param;
					$msg            = ! empty( $err->message ) ? (string) $err->message : __( 'Invalid value.', 'givepayments-for-woocommerce' );
					$field_errors[] = sprintf( '<strong>%s</strong>: %s', esc_html( $label ), esc_html( $msg ) );
				}
				if ( ! empty( $field_errors ) ) {
					$user_message     = __( 'Please fix the following errors:', 'givepayments-for-woocommerce' )
						. '<br>' . implode( '<br>', $field_errors );
					$provider_message = implode( ' | ', array_map( 'wp_strip_all_tags', $field_errors ) );

					return array(
						'status_code'      => $status_code,
						'provider_code'    => $provider_code,
						'provider_message' => $provider_message,
						'user_message'     => $user_message,
					);
				}
			}

			// Merge field-level errors into provider_message for non-invalid_input codes.
			if ( $provider_code !== 'invalid_input' && ! empty( $response_body->input ) && is_array( $response_body->input ) ) {
				$parts = array();
				foreach ( $response_body->input as $err ) {
					if ( ! is_object( $err ) || empty( $err->param ) ) {
						continue;
					}
					$param   = (string) $err->param;
					$label   = isset( $field_labels[ $param ] ) ? $field_labels[ $param ] : $param;
					$msg     = ! empty( $err->message ) ? (string) $err->message : __( 'Invalid value.', 'givepayments-for-woocommerce' );
					$parts[] = $label . ': ' . $msg;
				}
				if ( ! empty( $parts ) ) {
					$provider_message = trim( (string) $provider_message . ( $provider_message ? ' | ' : '' ) . implode( ' | ', $parts ) );
				}
			}
		}

		if ( ! $provider_message ) {
			$provider_message = __( 'Unknown payment error.', 'givepayments-for-woocommerce' );
		}

		$unknown_err = __( 'Unknown payment error.', 'givepayments-for-woocommerce' );

		if ( $status_code >= 500 ) {
			$user_message = __( 'Payment provider is temporarily unavailable. Please try again later.', 'givepayments-for-woocommerce' );
		} elseif ( $status_code >= 400 ) {
			// Only surface field-validation errors (invalid_input) to customers,
			// they describe actionable card/form problems. All other 4xx messages from
			// the provider may leak fraud scores, merchant account flags, or internal
			// rule names and must NOT reach customer-facing notices. The raw provider
			// message is still returned in 'provider_message' for order notes and logs.
			if ( 429 === $status_code ) {
				$user_message = __( 'Too many requests. Please wait a moment and try again.', 'givepayments-for-woocommerce' );
			} elseif ( 'invalid_input' === $provider_code ) {
				// Field-validation errors are safe to show (card number, expiry, email…)
				$trimmed      = trim( wp_strip_all_tags( (string) $provider_message ) );
				$user_message = ( '' !== $trimmed && $unknown_err !== $trimmed )
					? $trimmed
					: __( 'Transaction could not be completed. Please ensure your payment details are correct, then try a different card or payment method.', 'givepayments-for-woocommerce' );
			} else {
				// All other 4xx: try the customer-safe allowlist first so actionable
				// provider messages ("Address cannot be a PO Box", "Email is disposable")
				// reach the customer. If the message doesn't match a safe pattern,
				// fall back to the generic copy. Raw provider message still goes to
				// order notes regardless.
				$safe = self::customer_safe_message( $provider_message );
				if ( $safe ) {
					$user_message = $safe;
				} else {
					$user_message = __( 'Transaction could not be completed. Please ensure your payment details are correct, then try a different card or payment method.', 'givepayments-for-woocommerce' );
				}
			}
		}

		return array(
			'status_code'      => $status_code,
			'provider_code'    => $provider_code,
			'provider_message' => $provider_message,
			'user_message'     => $user_message,
		);
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Resolve a nested value from an object/array payload using a key-path array.
	 *
	 * @param mixed $data
	 * @param array $path
	 * @return mixed|null
	 */
	private static function get_nested( $data, array $path ) {
		$current = $data;
		foreach ( $path as $segment ) {
			if ( is_object( $current ) && property_exists( $current, $segment ) ) {
				$current = $current->{$segment};
				continue;
			}
			if ( is_array( $current ) && array_key_exists( $segment, $current ) ) {
				$current = $current[ $segment ];
				continue;
			}
			return null;
		}
		return $current;
	}

	/**
	 * Format a human-readable order note for a terminal payment failure.
	 *
	 * Includes the provider error code when present.
	 *
	 * @param array $parsed Error array from self::parse_error().
	 * @return string
	 */
	public static function format_decline_note( array $parsed ): string {
		$status = isset( $parsed['status_code'] ) ? (string) (int) $parsed['status_code'] : '0';
		$msg    = isset( $parsed['provider_message'] ) ? $parsed['provider_message'] : '';
		$code   = isset( $parsed['provider_code'] ) ? trim( (string) $parsed['provider_code'] ) : '';

		if ( '' !== $code ) {
			return sprintf(
				/* translators: 1: HTTP status, 2: provider error code, 3: message */
				__( 'GivePayments payment declined. HTTP %1$s. Code: %2$s. Message: %3$s', 'givepayments-for-woocommerce' ),
				$status,
				$code,
				$msg
			);
		}

		return sprintf(
			/* translators: 1: API status code, 2: API error message */
			__( 'GivePayments payment declined. Status: %1$s. Message: %2$s', 'givepayments-for-woocommerce' ),
			$status,
			$msg
		);
	}

	/** @return array */
	private static function field_labels() {
		// All values wrapped in __() so wp i18n make-pot extracts them
		// and non-English checkouts see translated field names in error notices.
		return array(
			'customer.email'                    => __( 'Email', 'givepayments-for-woocommerce' ),
			'customer.phone'                    => __( 'Phone', 'givepayments-for-woocommerce' ),
			'customer.first_name'               => __( 'First name', 'givepayments-for-woocommerce' ),
			'customer.last_name'                => __( 'Last name', 'givepayments-for-woocommerce' ),
			'customer.company_name'             => __( 'Company name', 'givepayments-for-woocommerce' ),
			'paymethod.card.name'               => __( 'Name on card', 'givepayments-for-woocommerce' ),
			'paymethod.card.number'             => __( 'Card number', 'givepayments-for-woocommerce' ),
			'paymethod.card.cvv'                => __( 'CVV', 'givepayments-for-woocommerce' ),
			'paymethod.card.exp_month'          => __( 'Expiration month', 'givepayments-for-woocommerce' ),
			'paymethod.card.exp_year'           => __( 'Expiration year', 'givepayments-for-woocommerce' ),
			'paymethod.billing_address.line1'   => __( 'Address line 1', 'givepayments-for-woocommerce' ),
			'paymethod.billing_address.line2'   => __( 'Address line 2', 'givepayments-for-woocommerce' ),
			'paymethod.billing_address.city'    => __( 'City', 'givepayments-for-woocommerce' ),
			'paymethod.billing_address.state'   => __( 'State', 'givepayments-for-woocommerce' ),
			'paymethod.billing_address.zip'     => __( 'ZIP code', 'givepayments-for-woocommerce' ),
			'paymethod.billing_address.country' => __( 'Country', 'givepayments-for-woocommerce' ),
			'amount'                            => __( 'Amount', 'givepayments-for-woocommerce' ),
			'external_reference'                => __( 'Order reference', 'givepayments-for-woocommerce' ),
		);
	}
	/**
	 * Return the provider message as-is when it looks like a customer-actionable
	 * instruction (address / email / ZIP / etc.) and doesn't contain anything that
	 * would leak fraud-rule internals. Returns null when the message should be
	 * suppressed in favor of the generic fallback.
	 */
	private static function customer_safe_message( $provider_message ) {
		if ( empty( $provider_message ) ) {
			return null;
		}
		$msg = trim( wp_strip_all_tags( (string) $provider_message ) );
		if ( $msg === '' || $msg === __( 'Unknown payment error.', 'givepayments-for-woocommerce' ) ) {
			return null;
		}
		// Block anything that hints at internal risk/fraud signals, never leak.
		$blocked = array(
			'/fraud/i',
			'/risk score/i',
			'/blacklist/i',
			'/velocity/i',
			'/internal/i',
			'/threshold/i',
			'/rule (id|name)/i',
			'/decline reason code/i',
			'/merchant flag/i',
		);
		foreach ( $blocked as $pattern ) {
			if ( preg_match( $pattern, $msg ) ) {
				return null;
			}
		}
		// Allow if the message looks like a fix-it-yourself instruction. Order
		// matters: more-specific patterns first.
		$allowed = array(
			'/post office box|p\.?o\.? box|pob|private mailbox|pmb/i',
			'/disposable.*email|unreachable.*email/i',
			'/invalid\s+(zip|postal|email|phone|address|state|country)/i',
			'/^address /i',
			'/^email/i',
			'/^phone/i',
			'/^name/i',
			'/^zip/i',
			'/^postal/i',
			'/^state/i',
			'/^country/i',
			'/^please /i',
		);
		foreach ( $allowed as $pattern ) {
			if ( preg_match( $pattern, $msg ) ) {
				return $msg;
			}
		}
		return null;
	}

}
