<?php
/**
 * Admin settings page helpers: form field definitions, script enqueue, option saving.
 *
 * Extracted from GIVEPAYMENTS_Gateway. All methods are static.
 *
 * @package GivePayments_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GIVEPAYMENTS_Settings_Page {

    /**
     * Return the WooCommerce settings form_fields array for the GivePayments gateway.
     *
     * Called by GIVEPAYMENTS_Gateway::init_form_fields(), assigns result to
     * $this->form_fields.
     *
     * @return array
     */
    public static function form_fields(): array {
        return array(
            // This fork is paired with the Instant Payment Rotator. The
            // rotator owns the customer-facing checkout flow and routes
            // approved charges through this plugin's API layer. As a
            // result, the customer-facing settings (enable/disable, title,
            // description) are removed from this page. What remains is
            // the credential pair the rotator needs, plus the two
            // diagnostic buttons (connection test, webhook re-register).
            'general_info' => array(
                'title'       => '',
                'type'        => 'title',
                'description' => sprintf(
                    '<div class="general-info-wrapper">
                    <p class="general-info-title">%1$s</p>
                    <div class="general-info-description-container">
                        <p class="general-info-description">%2$s</p>
                        <a href="%3$s" target="_blank">%4$s</a>
                    </div>
                </div>',
                    esc_html__( 'Managed by Instant Payment Rotator', 'givepayments-for-woocommerce' ),
                    esc_html__( 'This gateway does not appear at checkout. The Instant Payment Rotator routes traffic to GivePayments using the credentials below. Use the buttons further down to verify connectivity and re-register the webhook if signature validation starts failing.', 'givepayments-for-woocommerce' ),
                    esc_url( 'https://github.com/instantpeptides/instant-payment-rotator' ),
                    esc_html__( 'Rotator repository', 'givepayments-for-woocommerce' )
                ),
                'default'  => '',
                'desc_tip' => false,
                'class'    => 'general-info',
            ),
            'environment' => array(
                'title'       => __( 'Environment', 'givepayments-for-woocommerce' ),
                'type'        => 'select',
                'options'     => array(
                    'test'       => __( 'Sandbox (Testing)', 'givepayments-for-woocommerce' ),
                    'production' => __( 'Production', 'givepayments-for-woocommerce' ),
                ),
                'label'       => __( 'Select environment for the payments', 'givepayments-for-woocommerce' ),
                'default'     => 'test',
                'description' => __( 'Test Mode allows you to test transactions and purchases before switching to Production. Please ensure to use the correct API keys for your selected environment', 'givepayments-for-woocommerce' ),
                'desc_tip'    => false,
            ),
            'api_key' => array(
                'title'       => __( 'Production API Key', 'givepayments-for-woocommerce' ),
                'type'        => 'text',
                'class'       => 'api-key-field',
                'description' => __( 'Enter your API key for production use.', 'givepayments-for-woocommerce' ),
                'value'       => GIVEPAYMENTS_Request_Context::get_api_key_for_environment(),
            ),
            'merchant_id' => array(
                'title'       => __( 'Merchant ID', 'givepayments-for-woocommerce' ),
                'type'        => 'text',
                'class'       => 'merchant-id-field',
                'description' => __( 'Enter your Merchant ID', 'givepayments-for-woocommerce' ),
                'value'       => GIVEPAYMENTS_Request_Context::get_merchant_id(),
            ),
            'connection' => array(
                'title'       => __( 'Connection Test', 'givepayments-for-woocommerce' ),
                'type'        => 'button',
                'label'       => __( 'Connection Test', 'givepayments-for-woocommerce' ),
                'default'     => 'Connection Test',
                'description' => sprintf(
                    '<div class="connection-test-description-container">
                    <p class="connection-test-description-text">%1$s</p>
                    <div class="connection-limit-row-container">
                        <p id="connection-status-text"> %2$s <span></span></p>
                        <p id="able-to-process-text"> %3$s <span></span></p>
                        <p id="able-to-transfer-text"> %4$s <span></span></p>
                    </div>
                    <a href="%5$s" target="_blank">%6$s</a>
                </div>',
                    esc_html__( 'Click this button to verify the connection. If successful, your site will be linked to GivePayments after entering the correct keys. The results below indicate the status of your connection, processing, and transfer capabilities.', 'givepayments-for-woocommerce' ),
                    esc_html__( 'Connection:', 'givepayments-for-woocommerce' ),
                    esc_html__( 'Able to process:', 'givepayments-for-woocommerce' ),
                    esc_html__( 'Able to transfer:', 'givepayments-for-woocommerce' ),
                    esc_url( 'https://portal.givepayments.com/merchant/welcome' ),
                    esc_html__( 'GivePayment Merchant Portal', 'givepayments-for-woocommerce' )
                ),
                'class' => 'connection-test-button',
            ),
            // Force a clean webhook re-registration with the portal for the
            // currently-selected environment. Use this when HMAC validation is
            // failing because the locally-stored webhook secret has drifted from
            // what the portal holds (e.g. after a DB restore, a partially-failed
            // registration, or a manual portal edit). The handler clears the
            // env-scoped webhook options so the next "Test Connection" call
            // re-runs the full registration flow and persists a fresh secret.
            'reregister_webhook' => array(
                'title'       => __( 'Re-register Webhook', 'givepayments-for-woocommerce' ),
                'type'        => 'button',
                'label'       => __( 'Re-register Webhook', 'givepayments-for-woocommerce' ),
                'default'     => 'Re-register Webhook',
                'description' => sprintf(
                    '<p class="givepayments-reregister-description">%s</p><p id="givepayments-reregister-status"></p>',
                    esc_html__( 'Use this if webhook signature validation is failing. It will clear the stored webhook secret for the currently-selected environment and re-register with GivePayments using a fresh secret. Safe to run any time, existing payments are not affected.', 'givepayments-for-woocommerce' )
                ),
                'class' => 'reregister-webhook-button',
            ),
        );
    }

    /**
     * Enqueue admin scripts on the WooCommerce settings page.
     *
     * @param string $hook      Current admin page hook.
     * @param string $gateway_id Gateway ID (e.g. 'givepayments').
     * @return void
     */
    public static function enqueue_scripts( string $hook, string $gateway_id ): void {
        if ( 'woocommerce_page_wc-settings' !== $hook ) {
            return;
        }

        wp_register_script(
            'givepayments-admin-toggle',
            plugins_url( 'assets/js/admin-toggle.js', GIVEPAYMENTS_PLUGIN_PATH . 'givepayments.php' ),
            array( 'jquery' ),
            GIVEPAYMENTS_VERSION,
            true
        );

        wp_localize_script(
            'givepayments-admin-toggle',
            'givepayments_admin_params',
            array(
                'ajax_url'           => admin_url( 'admin-ajax.php' ),
                'nonce'              => wp_create_nonce( 'test_connection_nonce' ),
                'plugin_id'          => esc_js( $gateway_id ),
                // Keep encrypted-at-rest representation in admin UI as requested.
                'production_api_key' => esc_js( (string) get_option( 'givepayments_production_api_key' ) ),
                'sandbox_api_key'    => esc_js( (string) get_option( 'givepayments_sandbox_api_key' ) ),
                'merchant_id'        => esc_js( get_option( 'givepayments_merchant_id' ) ),
                'i18n'               => array(
                    'connection_test'      => esc_js( __( 'Connection Test', 'givepayments-for-woocommerce' ) ),
                    'testing'              => esc_js( __( 'Testing...', 'givepayments-for-woocommerce' ) ),
                    'prod_api_key_label'   => esc_js( __( 'Production API Key', 'givepayments-for-woocommerce' ) ),
                    'prod_api_key_desc'    => esc_js( __( 'Enter your live API key for production transactions.', 'givepayments-for-woocommerce' ) ),
                    'sandbox_api_key_label' => esc_js( __( 'Sandbox API Key', 'givepayments-for-woocommerce' ) ),
                    'sandbox_api_key_desc' => esc_js( __( 'Enter your test API key for sandbox transactions.', 'givepayments-for-woocommerce' ) ),
                    'error_message'        => esc_js( __( 'Error occurred during the connection test.', 'givepayments-for-woocommerce' ) ),
                    'reregister_webhook'   => esc_js( __( 'Re-register Webhook', 'givepayments-for-woocommerce' ) ),
                    'reregistering'        => esc_js( __( 'Clearing stored secret…', 'givepayments-for-woocommerce' ) ),
                    'reregister_then_test' => esc_js( __( 'Cleared. Re-running Test Connection…', 'givepayments-for-woocommerce' ) ),
                    'reregister_done'      => esc_js( __( 'Webhook re-registered successfully.', 'givepayments-for-woocommerce' ) ),
                    'reregister_failed'    => esc_js( __( 'Webhook re-registration failed. Check the WooCommerce logs (source: givepayments).', 'givepayments-for-woocommerce' ) ),
                ),
            )
        );

        wp_enqueue_script( 'givepayments-admin-toggle' );
    }

    /**
     * Handle custom option-saving logic after parent::process_admin_options() has run.
     *
     * The gateway's process_admin_options() must:
     *   1. call parent::process_admin_options() itself and pass the result here
     *   2. return whatever this method returns
     *
     * @param WC_Payment_Gateway $gateway       The gateway instance (for get_option / plugin_id / id).
     * @param bool               $parent_result Return value of parent::process_admin_options().
     * @return bool
     */
    public static function save_options( WC_Payment_Gateway $gateway, bool $parent_result ): bool {
        $environment = $gateway->get_option( 'environment', 'test' );
        update_option( 'givepayments_environment', $environment );

        // Read submitted API key directly from POST so we don't depend on the
        // persisted Woo settings value (which we intentionally clear below).
        $posted_key_field = $gateway->plugin_id . $gateway->id . '_api_key';
        $api_key = isset( $_POST[ $posted_key_field ] ) ? wp_unslash( $_POST[ $posted_key_field ] ) : '';
        $api_key = is_string( $api_key ) ? trim( $api_key ) : '';

        // Backward-compatibility safety:
        // if admin submitted an already encrypted blob (previous UI behavior),
        // decode it first so we do not encrypt ciphertext again.
        if ( '' !== $api_key && GIVEPAYMENTS_Encryptor::looks_like_encrypted( $api_key ) ) {
            $decrypted_submitted = GIVEPAYMENTS_Encryptor::decrypt( $api_key );
            if ( '' !== $decrypted_submitted ) {
                $api_key = $decrypted_submitted;
            }
        }

        // Handle API key encryption based on environment.
        if ( ! empty( $api_key ) ) {
            if ( 'test' === $environment ) {
                update_option( 'givepayments_sandbox_api_key', GIVEPAYMENTS_Encryptor::encrypt( $api_key ) );
            } else {
                update_option( 'givepayments_production_api_key', GIVEPAYMENTS_Encryptor::encrypt( $api_key ) );
            }
        }

        // Do not keep plaintext API key inside Woo settings payload.
        $settings_key = 'woocommerce_' . $gateway->id . '_settings';
        $settings     = get_option( $settings_key, array() );
        if ( is_array( $settings ) ) {
            $settings['api_key'] = '';
            update_option( $settings_key, $settings );
        }

        // Save merchant ID.
        $merchant_id = $gateway->get_option( 'merchant_id', '' );
        update_option( 'givepayments_merchant_id', $merchant_id );

        return $parent_result;
    }
}
