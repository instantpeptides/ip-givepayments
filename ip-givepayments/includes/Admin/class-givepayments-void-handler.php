<?php
/**
 * Admin void-payment handler: render button, AJAX void, order-page script enqueue.
 *
 * Extracted from GIVEPAYMENTS_Gateway. All methods are static.
 *
 * @package GivePayments_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Void_Handler {

    /**
     * Render the "Void" button on the order detail screen.
     *
     * Only shown for GivePayments orders in a captured/authorized state that
     * have not already been voided/refunded.
     *
     * @param WC_Order $order
     * @return void
     */
    public static function render_void_button( WC_Order $order ): void {
        if ( 'givepayments' !== $order->get_payment_method() ) {
            return;
        }

        $rec              = GIVEPAYMENTS_Payment_Record::for( $order );
        $processing_state = $rec->processing_state();
        if ( ! GIVEPAYMENTS_Order_State::can_void( $processing_state ) ) {
            return;
        }

        $txn_id = (string) $order->get_transaction_id();
        if ( '' === $txn_id ) {
            $txn_id = $rec->transaction_id();
        }
        if ( '' === $txn_id ) {
            return;
        }

        if ( $rec->is_refund_pending() ) {
            return;
        }
        if ( $rec->is_refund_completed() ) {
            return;
        }

        // M9: Only suppress the native WC Refund button when the processing state
        // cannot use the standard WC refund path (authorized/captured/created).
        // processing_issue has can_refund:true AND can_void:true per Order_State;
        // hiding the button unconditionally blocks merchants from issuing a
        // standard refund on processing_issue orders.
        if ( ! GIVEPAYMENTS_Order_State::can_refund( $processing_state ) ) {
            echo '<style>.refund-items{display:none!important}</style>';
        }
        // authorized / created: true void (no funds captured). captured / processing_issue:
        // pre-settlement reversal via POST /refunds, shown distinctly so merchants know
        // which API operation will run.
        $button_label = in_array( $processing_state, array( 'authorized', 'created' ), true )
            ? __( 'Void Payment', 'givepayments-for-woocommerce' )
            : __( 'Void', 'givepayments-for-woocommerce' );
        ?>
        <button type="button"
                class="button givepayments-void-payment"
                data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
                data-nonce="<?php echo esc_attr( wp_create_nonce( 'givepayments_void_nonce' ) ); ?>">
            <?php echo esc_html( $button_label ); ?>
        </button>
        <?php
    }

    /**
     * AJAX handler for the Void Payment button.
     *
     * - authorized state → POST /payments/{id}/void  (payment not yet captured)
     * - captured state   → POST /refunds             (captured but not yet settled)
     *
     * @return void
     */
    public static function ajax_void_payment(): void {
        check_ajax_referer( 'givepayments_void_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'givepayments-for-woocommerce' ) ), 403 );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $order    = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Order not found.', 'givepayments-for-woocommerce' ) ) );
        }

        if ( 'givepayments' !== $order->get_payment_method() ) {
            wp_send_json_error( array( 'message' => __( 'Order does not belong to GivePayments.', 'givepayments-for-woocommerce' ) ) );
        }

        $rec = GIVEPAYMENTS_Payment_Record::for( $order );

        if ( $rec->is_refund_pending() ) {
            wp_send_json_error( array( 'message' => __( 'A GivePayments void/refund is already being processed for this order.', 'givepayments-for-woocommerce' ) ) );
        }

        if ( $rec->is_refund_completed() || 'refunded' === $order->get_status() ) {
            wp_send_json_error( array( 'message' => __( 'This order has already been voided/refunded via GivePayments.', 'givepayments-for-woocommerce' ) ) );
        }

        $processing_state = $rec->processing_state();
        if ( ! GIVEPAYMENTS_Order_State::can_void( $processing_state ) ) {
            wp_send_json_error( array( 'message' => __( 'Order is not in a voidable state.', 'givepayments-for-woocommerce' ) ) );
        }

        $transaction_id = (string) $order->get_transaction_id();
        if ( '' === $transaction_id ) {
            $transaction_id = $rec->transaction_id();
        }
        $transaction_id = sanitize_text_field( $transaction_id );
        if ( '' === $transaction_id ) {
            wp_send_json_error( array( 'message' => __( 'GivePayments void failed: missing transaction ID.', 'givepayments-for-woocommerce' ) ) );
        }

        // Read the environment the order was created under, not the current
        // global setting. Switching env dropdown after an order is placed would
        // otherwise point voids at the wrong API, causing 404 errors and
        // potentially double-void risk when the merchant switches back.
        $environment = $rec->environment();
        if ( '' === $environment ) {
            $environment = (string) get_option( 'givepayments_environment', 'test' );
        }
        $api_base    = rtrim( GIVEPAYMENTS_Request_Context::api_base_url( $environment ), '/' );
        $api_key     = GIVEPAYMENTS_Request_Context::resolve_api_key( GIVEPAYMENTS_Request_Context::get_api_key_for_environment() );
        $api_key     = str_replace( array( "\r", "\n" ), '', $api_key );
        if ( '' === $api_key ) {
            wp_send_json_error( array( 'message' => __( 'GivePayments void failed: API key is missing.', 'givepayments-for-woocommerce' ) ) );
        }

        $requestor_ip = (string) GIVEPAYMENTS_Request_Context::get_client_ip();
        $requestor_ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 1024 ) : '';

        // Use a void-specific idempotency key meta to prevent key collision.
        // Using _givepayments_refund_idempotency_key for both voids and WC-native
        // refunds means a concurrent Action Scheduler retry on a processing_issue
        // order and an admin Void click could send the same key to two different
        // provider endpoints. Void operations use _givepayments_void_idempotency_key.
        $idempotency_key = $rec->void_idempotency_key();
        if ( '' === $idempotency_key ) {
            $idempotency_key = sprintf( 'gp_wc_void_%d_%s', (int) $order->get_id(), wp_generate_password( 20, false, false ) );
            $rec->set_void_idempotency_key( $idempotency_key );
            $rec->save();
        }

        // Route to the correct endpoint based on processing state.
        if ( 'authorized' === $processing_state ) {
            // Payment is authorized but not yet captured, use the dedicated void endpoint.
            $path    = '/payments/' . rawurlencode( $transaction_id ) . '/void';
            $payload = array(
                'reason'          => __( 'Voided from WooCommerce admin.', 'givepayments-for-woocommerce' ),
                'notify_customer' => false,
            );
        } else {
            // Payment is captured but not yet settled, POST /refunds handles it.
            $path    = '/refunds';
            $payload = array( 'payment' => $transaction_id );
        }

        GIVEPAYMENTS_Logger::log(
            sprintf( 'Void request for order %d to %s: %s', (int) $order->get_id(), $api_base . $path, wp_json_encode( $payload ) ),
            'debug'
        );

        GIVEPAYMENTS_Refund_Guard::mark_origin( $order, 'woocommerce_void' );

        $response = ( new GIVEPAYMENTS_Api_Client( $api_key, $api_base ) )->post(
            $path,
            $payload,
            array(
                'GP-Requestor-IP' => $requestor_ip,
                'GP-Requestor-UA' => $requestor_ua,
                'Idempotency-Key' => $idempotency_key,
            )
        );

        if ( is_wp_error( $response ) ) {
            GIVEPAYMENTS_Refund_Guard::clear_origin( $order );
            $rec->clear_void_idempotency_key(); // Hard failure, clear so next click gets a fresh key.
            $order->add_order_note(
                sprintf(
                    /* translators: %s: error message */
                    __( 'GivePayments void request failed: %s', 'givepayments-for-woocommerce' ),
                    $response->get_error_message()
                )
            );
            $order->save();
            // M18: Return a generic message to the admin UI, the raw cURL error is
            // recorded in the order note above (admin-accessible) and in WC logs.
            // Exposing the raw transport error to the browser is unnecessary and
            // may surface internal network topology details.
            wp_send_json_error( array( 'message' => __( 'The void request could not be sent due to a network error. Please try again.', 'givepayments-for-woocommerce' ) ) );
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        $raw_body    = wp_remote_retrieve_body( $response );
        $body        = json_decode( $raw_body );

        GIVEPAYMENTS_Logger::log(
            sprintf(
                'Void response HTTP %d for order %d: %s',
                $status_code,
                (int) $order->get_id(),
                function_exists( 'givepayments_redact_sensitive_response_body' )
                    ? givepayments_redact_sensitive_response_body( $raw_body )
                    : $raw_body
            ),
            in_array( $status_code, array( 200, 201 ), true ) ? 'debug' : 'error'
        );

        if ( in_array( $status_code, array( 200, 201 ), true ) ) {
            // Do not transition the Woo order to refunded here. Let the webhook be
            // the single source of truth for the terminal void/refund state so we
            // do not locally create bookkeeping that the later webhook also creates.
            //
            // Re-read the order from the DB before writing any meta. The GP API call
            // above blocks for up to 20 s; the void webhook can arrive and fully
            // resolve the order (status=refunded, _refund_completed=yes) in that
            // window. Writing _refund_pending=yes on top of an already-resolved order
            // would leave stale meta that permanently blocks future void/refund guards.
            $order = wc_get_order( $order->get_id() );
            // Void is not retried, the webhook drives the terminal state from here.
            $rec = GIVEPAYMENTS_Payment_Record::for( $order );
            $rec->clear_void_idempotency_key();
            if ( ! $rec->is_refund_completed() && 'refunded' !== $order->get_status() ) {
                GIVEPAYMENTS_Refund_Guard::mark_pending( $order );
                $order->add_order_note( __( 'GivePayments void/refund request accepted. Waiting for webhook confirmation.', 'givepayments-for-woocommerce' ) );
            }
            $order->save();
            wp_send_json_success( array( 'message' => __( 'Void request accepted. The order will update when GivePayments confirms it.', 'givepayments-for-woocommerce' ) ) );
        }

        $message = __( 'Void was not confirmed by GivePayments.', 'givepayments-for-woocommerce' );
        if ( is_object( $body ) && ! empty( $body->message ) ) {
            $message = sanitize_text_field( (string) $body->message );
        }
        GIVEPAYMENTS_Refund_Guard::clear_origin( $order );
        $rec->clear_void_idempotency_key(); // API call done, clear for next attempt.
        $order->add_order_note(
            sprintf(
                /* translators: 1: HTTP status code, 2: provider message */
                __( 'GivePayments void failed. HTTP: %1$s. Message: %2$s', 'givepayments-for-woocommerce' ),
                (string) $status_code,
                $message
            )
        );
        $order->save();
        wp_send_json_error( array( 'message' => $message ) );
    }

    /**
     * Enqueue the void-button JS on the order edit screen (both classic CPT and HPOS).
     *
     * @param string   $hook          Current admin page hook.
     * @param callable $can_refund_fn Callable that accepts a WC_Order and returns bool.
     * @return void
     */
    public static function enqueue_order_scripts( string $hook, callable $can_refund_fn ): void {
        $is_order_page = ( 'post.php' === $hook && isset( $GLOBALS['typenow'] ) && 'shop_order' === $GLOBALS['typenow'] )
            || false !== strpos( $hook, 'wc-orders' );

        if ( ! $is_order_page ) {
            return;
        }

        wp_enqueue_script(
            'givepayments-admin-void',
            plugins_url( 'assets/js/admin-void.js', GIVEPAYMENTS_PLUGIN_PATH . 'givepayments.php' ),
            array( 'jquery' ),
            GIVEPAYMENTS_VERSION,
            true
        );
        wp_localize_script( 'givepayments-admin-void', 'givepayments_void_params', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'label'    => __( 'Void', 'givepayments-for-woocommerce' ),
            'confirm'  => __( 'Are you sure you want to void this payment? This cannot be undone.', 'givepayments-for-woocommerce' ),
            'voiding'  => __( 'Voiding…', 'givepayments-for-woocommerce' ),
        ) );

        // Hide the native WC "Refund" button for GivePayments orders where a
        // refund is not possible. can_refund_order() blocks the server-side
        // attempt but WC always renders the button regardless, so admins see
        // a button that fails silently. Suppress it in the UI instead.
        $order_id = 0;
        if ( 'post.php' === $hook && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $order_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        } elseif ( false !== strpos( $hook, 'wc-orders' ) && isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $order_id = absint( $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
        if ( $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order && 'givepayments' === $order->get_payment_method()
                && ! $can_refund_fn( $order )
            ) {
                wp_add_inline_script(
                    'givepayments-admin-void',
                    'jQuery(function($){ $(".refund-items").hide(); });'
                );
            }
        }
    }
}
