<?php
/**
 * Card-input reader and validator for GivePayments checkout.
 *
 * Centralises all logic for reading card field values from the three possible
 * checkout transports (classic POST, Blocks/Store API payment_data, raw JSON
 * body from php://input) and validating the collected data before it is sent
 * to the provider API.
 *
 * All methods are static, no instance state is required.
 *
 * @package GivePayments
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Card_Input_Reader {

    // -------------------------------------------------------------------------
    // Transport normalization
    // -------------------------------------------------------------------------

    /**
     * Normalize a raw payment_data value into a plain PHP array regardless of
     * the transport shape (array, object, or JSON string).
     *
     * @param mixed $payment_data
     * @return array
     */
    public static function normalize_payment_data( $payment_data ): array {
        if ( is_array( $payment_data ) ) {
            return $payment_data;
        }

        if ( is_object( $payment_data ) ) {
            return (array) $payment_data;
        }

        if ( is_string( $payment_data ) && '' !== trim( $payment_data ) ) {
            $decoded = json_decode( $payment_data, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        return array();
    }

    /**
     * Extract a single field value from a payment_data array.
     *
     * Handles both WooCommerce Blocks / Store API shape (numeric list of
     * `{ key, value }` objects) and the classic associative shape.
     *
     * @param array  $payment_data Normalized payment_data array.
     * @param string $field        Field name, e.g. 'givepayments-card-number'.
     * @return string
     */
    public static function get_value_from_payment_data( $payment_data, string $field ): string {
        if ( ! is_array( $payment_data ) || '' === $field ) {
            return '';
        }

        // Store API / Blocks shape: numeric list of { key, value }.
        if (
            isset( $payment_data[0] )
            && (
                ( is_array( $payment_data[0] ) && array_key_exists( 'key', $payment_data[0] ) )
                || ( is_object( $payment_data[0] ) && isset( $payment_data[0]->key ) )
            )
        ) {
            foreach ( $payment_data as $entry ) {
                $entry_key   = '';
                $entry_value = '';

                if ( is_array( $entry ) && isset( $entry['key'], $entry['value'] ) ) {
                    $entry_key   = (string) $entry['key'];
                    $entry_value = (string) $entry['value'];
                } elseif ( is_object( $entry ) && isset( $entry->key, $entry->value ) ) {
                    $entry_key   = (string) $entry->key;
                    $entry_value = (string) $entry->value;
                }

                if ( '' === $entry_key ) {
                    continue;
                }
                if ( $entry_key === $field ) {
                    return (string) wp_unslash( $entry_value );
                }
            }
            return '';
        }

        if ( isset( $payment_data[ $field ] ) ) {
            return (string) wp_unslash( $payment_data[ $field ] );
        }

        return '';
    }

    /**
     * Read a single card field value from whichever checkout transport is active:
     *  1. Classic POST: $_POST[$field]
     *  2. Blocks/Store API via $_POST['payment_data']
     *  3. Blocks/Store API via $_REQUEST['payment_data']
     *  4. Raw JSON body from php://input (consumed stream fallback)
     *
     * @param string $field Field name, e.g. 'givepayments-card-name'.
     * @return string
     */
    public static function get_field( string $field ): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST[ $field ] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return (string) wp_unslash( $_POST[ $field ] );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( isset( $_POST['payment_data'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $post_payment_data = self::normalize_payment_data( $_POST['payment_data'] );
            $from_post         = self::get_value_from_payment_data( $post_payment_data, $field );
            if ( '' !== $from_post ) {
                return $from_post;
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_REQUEST['payment_data'] ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $request_payment_data = self::normalize_payment_data( $_REQUEST['payment_data'] );
            $from_req             = self::get_value_from_payment_data( $request_payment_data, $field );
            if ( '' !== $from_req ) {
                return $from_req;
            }
        }

        static $decoded_input = null;
        if ( null === $decoded_input ) {
            $decoded_input = array();
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $raw_input = file_get_contents( 'php://input' );
            if ( is_string( $raw_input ) && '' !== $raw_input ) {
                $json = json_decode( $raw_input, true );
                if ( is_array( $json ) ) {
                    $decoded_input = $json;
                }
            }
        }

        if ( isset( $decoded_input['payment_data'] ) && is_array( $decoded_input['payment_data'] ) ) {
            return self::get_value_from_payment_data( $decoded_input['payment_data'], $field );
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Card data collection
    // -------------------------------------------------------------------------

    /**
     * Collect and sanitise all card fields from the current request.
     *
     * Handles the combined expiry field ('givepayments-card-expiration', format
     * MM/YY or MM/YYYY) as well as the legacy split fields for back-compat.
     *
     * @return array{card_name:string,card_number:string,card_cvv:string,exp_month:string,exp_year:string,card_brand:string}
     */
    public static function get_sanitized_card_data(): array {
        $card_name   = self::get_field( 'givepayments-card-name' );
        $card_number = self::get_field( 'givepayments-card-number' );
        $card_cvv    = self::get_field( 'givepayments-card-cvv' );
        $exp_input   = self::get_field( 'givepayments-card-expiration' );
        $exp_month   = '';
        $exp_year    = '';

        if ( preg_match( '/^\s*(\d{2})\s*\/\s*(\d{2,4})\s*$/', (string) $exp_input, $matches ) ) {
            $exp_month = $matches[1];
            $exp_year  = $matches[2];
        } else {
            // Backward compatibility with previous split fields.
            $exp_month = self::get_field( 'givepayments-card-expiry-month' );
            $exp_year  = self::get_field( 'givepayments-card-expiry-year' );
        }

        $digits = preg_replace( '/\D+/', '', (string) $card_number );

        return array(
            'card_name'   => sanitize_text_field( (string) $card_name ),
            'card_number' => $digits,
            'card_cvv'    => preg_replace( '/\D+/', '', (string) $card_cvv ),
            'exp_month'   => preg_replace( '/\D+/', '', (string) $exp_month ),
            'exp_year'    => preg_replace( '/\D+/', '', (string) $exp_year ),
            'card_brand'  => self::detect_card_brand( $digits ),
        );
    }

    /**
     * Detect the card network from the card number using IIN ranges.
     *
     * Returns a lowercase ASCII brand slug ('visa', 'mastercard', 'amex',
     * 'discover', 'jcb', 'diners', or 'unknown'). Used only for the
     * cross-order dedup key, never displayed to customers.
     *
     * @param string $digits Digits-only card number.
     * @return string
     */
    private static function detect_card_brand( string $digits ): string {
        if ( '' === $digits ) {
            return 'unknown';
        }

        // Amex: 34, 37.
        if ( preg_match( '/^3[47]/', $digits ) ) {
            return 'amex';
        }

        // Mastercard: 51–55 or 2221–2720.
        if ( preg_match( '/^5[1-5]/', $digits ) ) {
            return 'mastercard';
        }
        $first4 = (int) substr( $digits, 0, 4 );
        if ( $first4 >= 2221 && $first4 <= 2720 ) {
            return 'mastercard';
        }

        // Discover: 6011, 622126–622925, 644–649, 65.
        if ( preg_match( '/^6011/', $digits ) || preg_match( '/^65/', $digits ) ) {
            return 'discover';
        }
        $first3 = (int) substr( $digits, 0, 3 );
        if ( $first3 >= 644 && $first3 <= 649 ) {
            return 'discover';
        }
        $first6 = (int) substr( $digits, 0, 6 );
        if ( $first6 >= 622126 && $first6 <= 622925 ) {
            return 'discover';
        }

        // JCB: 3528–3589.
        if ( $first4 >= 3528 && $first4 <= 3589 ) {
            return 'jcb';
        }

        // Diners: 300–305, 36, 38.
        if ( preg_match( '/^30[0-5]/', $digits ) || preg_match( '/^3[68]/', $digits ) ) {
            return 'diners';
        }

        // Visa: 4 (must come after Diners 4-digit 36xx check).
        if ( preg_match( '/^4/', $digits ) ) {
            return 'visa';
        }

        return 'unknown';
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    /**
     * Validate card data collected from the current request.
     *
     * Returns an empty string on success, or a translated error message on the
     * first failing field. Callers should treat any non-empty return as a
     * checkout validation failure.
     *
     * @return string Error message, or '' on success.
     */
    public static function validate(): string {
        $card_data   = self::get_sanitized_card_data();
        $card_name   = $card_data['card_name'];
        $card_number = $card_data['card_number'];
        $card_cvv    = $card_data['card_cvv'];
        $exp_month   = $card_data['exp_month'];
        $exp_year    = $card_data['exp_year'];

        if ( empty( $card_name ) || strlen( $card_name ) < 2 ) {
            return __( 'Please enter the name as it appears on the card.', 'givepayments-for-woocommerce' );
        }

        if ( empty( $card_number ) || strlen( $card_number ) < 12 || strlen( $card_number ) > 19 ) {
            return __( 'Please enter a valid card number.', 'givepayments-for-woocommerce' );
        }

        if ( ! self::luhn_check( $card_number ) ) {
            return __( 'Please enter a valid card number.', 'givepayments-for-woocommerce' );
        }

        if ( empty( $exp_month ) || (int) $exp_month < 1 || (int) $exp_month > 12 ) {
            return __( 'Please enter a valid expiry month (MM).', 'givepayments-for-woocommerce' );
        }

        if ( empty( $exp_year ) || ! preg_match( '/^\d{2}(\d{2})?$/', $exp_year ) ) {
            return __( 'Please enter a valid expiry year (YY or YYYY).', 'givepayments-for-woocommerce' );
        }

        // Normalize expiry year to four digits and ensure it is not in the past.
        $current_year  = (int) gmdate( 'Y' );
        $current_month = (int) gmdate( 'n' );
        $exp_year_int  = (int) $exp_year;

        if ( strlen( $exp_year ) === 2 ) {
            $current_century  = (int) floor( $current_year / 100 ) * 100;
            $current_year_two = $current_year % 100;
            $exp_year_full    = $current_century + $exp_year_int;
            if ( $exp_year_int < $current_year_two ) {
                $exp_year_full += 100;
            }
        } else {
            $exp_year_full = $exp_year_int;
        }

        if ( $exp_year_full < $current_year
            || ( $exp_year_full === $current_year && (int) $exp_month < $current_month )
        ) {
            return __( 'The card expiry date has already passed.', 'givepayments-for-woocommerce' );
        }

        if ( empty( $card_cvv ) || strlen( $card_cvv ) < 3 || strlen( $card_cvv ) > 4 ) {
            return __( 'Please enter a valid CVV.', 'givepayments-for-woocommerce' );
        }

        return '';
    }

    /**
     * Luhn algorithm check for card number validity.
     *
     * @param string $number Digits-only card number string.
     * @return bool
     */
    public static function luhn_check( string $number ): bool {
        $number = preg_replace( '/\D+/', '', $number );
        if ( '' === $number ) {
            return false;
        }

        $sum = 0;
        $alt = false;
        for ( $i = strlen( $number ) - 1; $i >= 0; $i-- ) {
            $n = (int) $number[ $i ];
            if ( $alt ) {
                $n *= 2;
                if ( $n > 9 ) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt  = ! $alt;
        }

        return ( $sum % 10 ) === 0;
    }
}
