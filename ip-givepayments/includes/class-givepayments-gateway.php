<?php
if (!defined('ABSPATH')) {
    exit;
}

class GIVEPAYMENTS_Gateway extends WC_Payment_Gateway
{

    /**
     * @var GIVEPAYMENTS_Subscription_Adapter_Registry|null
     */
    private $subscription_adapter_registry = null;

    public function __construct()
    {
        $this->id = 'givepayments';
        $this->method_title = __('GivePayments', 'givepayments-for-woocommerce');
        $this->method_description = __('Accept payments using GivePayments.', 'givepayments-for-woocommerce');
        $this->title = __('Pay with Credit and Debit Cards', 'givepayments-for-woocommerce');
        $this->description = '';

        // Direct card checkout is available in both classic and Blocks checkout.
        // Refunds are handled natively from WooCommerce order actions.
        $this->supports = array(
            'products',
            'woo_payments',
            'refunds',
            'block-based-checkout',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            // YITH WooCommerce Subscriptions (YWCAS/YWSBS) gateway availability flags.
            // YITH filters payment methods on carts containing a YITH subscription
            // and only keeps gateways that declare these features.
            // See YWSBS_Subscription_Cart::disable_gateways().
            'yith_subscriptions',
            'yith_subscriptions_multiple',
            // Flexible Subscriptions by WP Desk requires both flags.
            // Single subscription in cart → 'subscriptions' (already declared above).
            // Multiple subscriptions in cart → 'multiple_subscriptions'.
            'multiple_subscriptions',
        );

        $this->init_form_fields();
        $this->init_settings();

        // Hardcoded 'no' instead of $this->get_option('enabled') because
        // the customer-facing toggle has been removed from the settings
        // page (Instant Payment Rotator owns checkout, and this gateway
        // must never appear directly to customers). WC's is_available()
        // returns false unless enabled === 'yes', so this guarantees the
        // gateway stays hidden from the cart regardless of any stale
        // option value left over from before the fork.
        $this->enabled = 'no';

        // Title and description fields were removed from the settings page
        // for the same reason. The constructor defaults set above (method
        // and customer-facing labels) remain so admin pages that list
        // gateway names still have something to render.

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_styles'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_scripts'));
        add_action( 'woocommerce_scheduled_subscription_payment_givepayments', array( $this, 'process_subscription_renewal' ), 10, 2 );
        add_action( 'wps_sfw_other_payment_gateway_renewal', array( $this, 'process_wps_sfw_subscription_renewal' ), 10, 3 );
        add_filter( 'wps_sfw_supported_payment_gateway_for_woocommerce', array( $this, 'givepayments_add_wps_sfw_supported_gateway' ), 10, 2 );
        // YITH WooCommerce Subscription (YWSBS free plugin) renewal.
        // YWSBS fires ywsbs_pay_renew_order_with_{gateway_id} with a single $renew_order arg.
        add_action( 'ywsbs_pay_renew_order_with_givepayments', array( $this, 'process_ywsbs_subscription_renewal' ), 10, 1 );
        // Flexible Subscriptions by WP Desk: store token immediately when FSB creates the
        // subscription record. Belt-and-suspenders, post-payment registry storage also runs.
        // fsub/subscription/new passes ($subscription, $order, $subscription_candidate).
        add_action( 'fsub/subscription/new', array( $this, 'givepayments_on_fsb_subscription_new' ), 10, 3 );
        // Blocks (Store API) checkout: bridge payment_data from PaymentContext into $_POST before
        // process_payment() runs, because php://input is consumed by the REST router beforehand.
        add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( $this, 'givepayments_store_blocks_payment_data' ) );

        // Per merchant policy: a WooCommerce-initiated refund flips the order to
        // "refunded" immediately when process_refund() returns true. WC core handles
        // that transition by default, so we intentionally do NOT filter
        // 'woocommerce_order_fully_refunded_status' here.

        // "Void Payment" button, shown next to the native Refund button on
        // captured-not-settled (processing) orders.
        add_action( 'woocommerce_order_item_add_action_buttons', array( $this, 'givepayments_render_void_button' ) );
        add_action( 'wp_ajax_givepayments_void_payment', array( $this, 'givepayments_ajax_void_payment' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'givepayments_enqueue_order_scripts' ), 20 );
    }

    /**
     * Keep refund button disabled briefly after a refund has been initiated and is pending.
     *
     * @param WC_Order|false $order
     * @return bool
     */
    public function can_refund_order( $order ) {
        if ( ! $order || ! $this->supports( 'refunds' ) ) {
            return false;
        }

        if ( ! method_exists( 'WC_Payment_Gateway', 'can_refund_order' ) || ! parent::can_refund_order( $order ) ) {
            return false;
        }

        return GIVEPAYMENTS_Refund_Guard::can_refund( $order );
    }

    /**
     * Render native card fields for classic WooCommerce checkout.
     */
    public function payment_fields() {
        GIVEPAYMENTS_Classic_Checkout::render_payment_fields();
    }

    /**
     * Validate raw card fields before attempting the direct API payment.
     *
     * @return bool
     */
    public function validate_fields() {
        $error = GIVEPAYMENTS_Card_Input_Reader::validate();
        if ( '' !== $error ) {
            wc_add_notice( $error, 'error' );
            GIVEPAYMENTS_Checkout_Guard::fail_if_pending(
                sprintf(
                    /* translators: %s: validation error message */
                    __( 'GivePayments: payment declined at validation, %s', 'givepayments-for-woocommerce' ),
                    $error
                )
            );
            return false;
        }
        return true;
    }

    public function enqueue_checkout_styles() {
        GIVEPAYMENTS_Classic_Checkout::enqueue_styles();
    }

    public function enqueue_checkout_scripts() {
        GIVEPAYMENTS_Classic_Checkout::enqueue_scripts();
    }

    /**
     * Mark GivePayments as a supported recurring gateway for
     * Subscriptions for WooCommerce checkout filtering.
     *
     * @param array  $gateways
     * @param string $gateway_id
     * @return array
     */
    public function givepayments_add_wps_sfw_supported_gateway( $gateways, $gateway_id ) {
        return GIVEPAYMENTS_Renewal_Handler::add_wps_sfw_gateway( is_array( $gateways ) ? $gateways : array() );
    }

    public function init_form_fields() {
        $this->form_fields = GIVEPAYMENTS_Settings_Page::form_fields();
    }

    public function enqueue_admin_scripts( $hook ) {
        GIVEPAYMENTS_Settings_Page::enqueue_scripts( $hook, $this->id );
    }


    /**
     * Encrypt API key before saving.
     */
    public function process_admin_options() {
        return GIVEPAYMENTS_Settings_Page::save_options( $this, parent::process_admin_options() );
    }
    /**
     * Process the payment
     */

    public function process_payment( $order_id ) {
        return GIVEPAYMENTS_Payment_Processor::process( (int) $order_id );
    }

    /**
     * Process a WooCommerce Subscriptions renewal payment using a stored card token.
     *
     * @param float    $amount_to_charge
     * @param WC_Order $renewal_order
     * @return void
     */
    public function process_subscription_renewal( $amount_to_charge, $renewal_order ) {
        GIVEPAYMENTS_Renewal_Handler::process_wcs_renewal( (float) $amount_to_charge, $renewal_order );
    }

    /**
     * Process Subscriptions for WooCommerce renewal payment using a stored token.
     *
     * @param WC_Order $renewal_order
     * @param int      $subscription_id
     * @param string   $payment_method
     * @return void
     */
    public function process_wps_sfw_subscription_renewal( $renewal_order, $subscription_id, $payment_method ) {
        GIVEPAYMENTS_Renewal_Handler::process_wps_sfw_renewal( $renewal_order, $subscription_id, (string) $payment_method );
    }

    /**
     * Process YITH WooCommerce Subscription (YWSBS) renewal payment.
     *
     * YWSBS fires ywsbs_pay_renew_order_with_{gateway_id} with a single $renew_order arg.
     * Amount is read from the renewal order total.
     *
     * @param WC_Order $renewal_order
     * @return void
     */
    public function process_ywsbs_subscription_renewal( $renewal_order ) {
        GIVEPAYMENTS_Renewal_Handler::process_ywsbs_renewal( $renewal_order );
    }

    /**
     * Store card token on a Flexible Subscriptions subscription record immediately after
     * FSB creates it (fsub/subscription/new hook).
     *
     * Belt-and-suspenders: the post-payment registry path already calls store_token_for_order,
     * but that runs before FSB has created the subscription object. This hook fires after
     * FSB has persisted the subscription, ensuring the token is always present.
     *
     * @param object   $subscription         FSB Subscription object (extends WC_Order).
     * @param WC_Order $order                Parent order from checkout.
     * @param object   $subscription_candidate  Cart line item (not used here).
     * @return void
     */
    public function givepayments_on_fsb_subscription_new( $subscription, $order, $subscription_candidate ) {
        GIVEPAYMENTS_Subscription_Service::on_fsb_subscription_new( $subscription, $order, $subscription_candidate );
    }

    /**
     * Process native WooCommerce refunds via GivePayments /refunds endpoint.
     *
     * @param int    $order_id
     * @param float  $amount
     * @param string $reason
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        return GIVEPAYMENTS_Refund_Processor::process( $order_id, $amount );
    }

    /**
     * Render a "Void Payment" button in the order items action row, next to the
     * native Refund button. Only shown for GivePayments processing orders.
     *
     * @param WC_Order $order
     */
    public function givepayments_render_void_button( WC_Order $order ): void {
        GIVEPAYMENTS_Void_Handler::render_void_button( $order );
    }

    /**
     * AJAX handler for the Void Payment button.
     * - authorized state → POST /payments/{id}/void  (payment not yet captured)
     * - captured state   → POST /refunds             (captured but not yet settled)
     */
    public function givepayments_ajax_void_payment(): void {
        GIVEPAYMENTS_Void_Handler::ajax_void_payment();
    }

    /**
     * Enqueue the void-button JS on the order edit screen (both classic CPT and HPOS).
     *
     * @param string $hook
     */
    public function givepayments_enqueue_order_scripts( $hook ): void {
        GIVEPAYMENTS_Void_Handler::enqueue_order_scripts( $hook, [ $this, 'can_refund_order' ] );
    }

    /**
     * Execute a single background refund attempt. Called by Action Scheduler.
     */
    public function givepayments_execute_async_refund_retry( $args ): void {
        GIVEPAYMENTS_Refund_Processor::execute_async_retry( $args );
    }

    /**
     * Bridge WooCommerce Blocks (Store API) payment data into $_POST so that
     * card field values are available when process_payment() runs.
     *
     * Hook: woocommerce_rest_checkout_process_payment_with_context
     *
     * @param \Automattic\WooCommerce\StoreApi\Routes\V1\CartCheckout\PaymentContext $payment_context
     * @return void
     */
    public function givepayments_store_blocks_payment_data( $payment_context ) {
        GIVEPAYMENTS_Classic_Checkout::store_blocks_payment_data( $payment_context );
    }

}
