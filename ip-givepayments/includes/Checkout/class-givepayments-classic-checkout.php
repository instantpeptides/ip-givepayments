<?php
/**
 * Classic checkout UI: card field HTML and Store API payment data bridging.
 *
 * Extracted from GIVEPAYMENTS_Gateway. All methods are static.
 *
 * @package GivePayments_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Classic_Checkout {

    /**
     * Render the native card input fields for the classic WooCommerce checkout.
     *
     * Called by GIVEPAYMENTS_Gateway::payment_fields().
     *
     * @return void
     */
    public static function render_payment_fields(): void {
        echo '<div class="givepayments-card-ui">';
        echo '  <div class="givepayments-card-ui__body">';

        echo '<div class="givepayments-card-fields">';

        echo '<p class="form-row form-row-wide givepayments-field">';
        echo '<label for="givepayments-card-number">' . esc_html__( 'Card Number', 'givepayments-for-woocommerce' ) . '</label>';
        echo '<input id="givepayments-card-number" name="givepayments-card-number" type="tel" placeholder="1234 5678 9123 4567" autocomplete="cc-number" inputmode="numeric" pattern="[0-9 ]*" maxlength="23" required />';
        echo '</p>';

        echo '<div class="givepayments-card-row">';
        echo '<p class="form-row form-row-first givepayments-field">';
        echo '<label for="givepayments-card-expiration">' . esc_html__( 'Expiration Date (MM/YY)', 'givepayments-for-woocommerce' ) . '</label>';
        echo '<input id="givepayments-card-expiration" name="givepayments-card-expiration" type="tel" placeholder="08/27" autocomplete="cc-exp" inputmode="numeric" pattern="[0-9/]*" maxlength="7" required />';
        echo '</p>';

        echo '<p class="form-row form-row-last givepayments-field">';
        echo '<label for="givepayments-card-cvv">' . esc_html__( 'Security Code', 'givepayments-for-woocommerce' ) . '</label>';
        echo '<input id="givepayments-card-cvv" name="givepayments-card-cvv" type="tel" placeholder="424" autocomplete="cc-csc" inputmode="numeric" pattern="[0-9]*" maxlength="4" required />';
        echo '</p>';
        echo '</div>';

        echo '<p class="form-row form-row-wide givepayments-field">';
        echo '<label for="givepayments-card-name">' . esc_html__( 'Name on Card', 'givepayments-for-woocommerce' ) . '</label>';
        echo '<input id="givepayments-card-name" name="givepayments-card-name" type="text" placeholder="John Doe" autocomplete="cc-name" inputmode="text" required />';
        echo '</p>';

        echo '</div>';
        echo '  </div>';
        echo '</div>';
    }

    /**
     * Bridge WooCommerce Blocks (Store API) payment data into $_POST so the existing
     * card-reading logic works unchanged.
     *
     * The Store API decodes the Blocks checkout payload via
     * json_decode(file_get_contents('php://input')) during request initialisation.
     * By the time process_payment() runs, the input stream is already consumed, so
     * the php://input fallback in givepayments_get_card_field_from_request() returns
     * empty strings. This hook fires with the parsed PaymentContext object, which
     * holds the already-decoded payment_data, before process_payment() is called.
     * Populating $_POST here means the existing $_POST[$field] check succeeds without
     * any changes to the card-reading logic.
     *
     * Hook: woocommerce_rest_checkout_process_payment_with_context
     *
     * @param \Automattic\WooCommerce\StoreApi\Routes\V1\CartCheckout\PaymentContext $payment_context
     * @return void
     */
    public static function store_blocks_payment_data( $payment_context ): void {
        if ( ! is_object( $payment_context )
            || ! method_exists( $payment_context, 'get_payment_data' ) ) {
            return;
        }

        $method_id = '';
        if ( method_exists( $payment_context, 'get_payment_method' ) ) {
            $method = $payment_context->get_payment_method();
            if ( is_string( $method ) ) {
                $method_id = $method;
            } elseif ( is_object( $method ) && isset( $method->id ) ) {
                $method_id = (string) $method->id;
            }
        }

        $normalized_method_id = sanitize_key( (string) $method_id );

        $payment_data = $payment_context->get_payment_data();
        if ( ! is_array( $payment_data ) ) {
            return;
        }

        $mapped_count = 0;

        // payment_data may be a list of { key, value } entries or an associative array.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        foreach ( $payment_data as $entry ) {
            if ( is_array( $entry ) && isset( $entry['key'], $entry['value'] ) ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $_POST[ (string) $entry['key'] ] = $entry['value'];
                $mapped_count++;
                continue;
            }

            if ( is_object( $entry ) && isset( $entry->key, $entry->value ) ) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $_POST[ (string) $entry->key ] = $entry->value;
                $mapped_count++;
            }
        }

        if ( 0 === $mapped_count ) {
            foreach ( $payment_data as $key => $value ) {
                if ( ! is_string( $key ) || '' === $key ) {
                    continue;
                }
                // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $_POST[ $key ] = $value;
                $mapped_count++;
            }
        }

        // If method ID is unavailable on this Woo version, rely on mapped GivePayments keys.
        // When method ID is available, only keep data mapped for this gateway.
        // Store API may return title-case method labels (e.g. "GivePayments"), so normalize first.
        if ( '' !== $normalized_method_id && 'givepayments' !== $normalized_method_id ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            unset( $_POST['givepayments-card-name'], $_POST['givepayments-card-number'], $_POST['givepayments-card-expiration'], $_POST['givepayments-card-cvv'] );
            return;
        }
    }

    /**
     * Enqueue classic checkout card styles when GivePayments is the active gateway.
     *
     * @return void
     */
    public static function enqueue_styles(): void {
        if ( ! GIVEPAYMENTS_Checkout_Guard::should_enqueue_assets() ) {
            return;
        }

        wp_enqueue_style(
            'givepayments-checkout-style',
            GIVEPAYMENTS_PLUGIN_URL . 'assets/checkout.css',
            array(),
            GIVEPAYMENTS_VERSION
        );
    }

    /**
     * Enqueue classic checkout card scripts when GivePayments is the active gateway.
     *
     * @return void
     */
    public static function enqueue_scripts(): void {
        if ( ! GIVEPAYMENTS_Checkout_Guard::should_enqueue_assets() ) {
            return;
        }

        wp_enqueue_script(
            'givepayments-checkout-fields',
            GIVEPAYMENTS_PLUGIN_URL . 'assets/checkout-fields.js',
            array(),
            GIVEPAYMENTS_VERSION,
            true
        );
    }
}
