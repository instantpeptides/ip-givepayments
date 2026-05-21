<?php
/**
 * Plugin Name: GivePayments (Instant Peptides Fork)
 * Plugin URI:  https://github.com/instantpeptides/ip-givepayments
 * Description: Fork of the official GivePayments for WooCommerce plugin (https://givepayments.com) with three behavior patches: (1) the CREATED order state maps to pending instead of processing, so the WooCommerce "processing" email does not fire prematurely; (2) the SETTLED webhook does not auto-complete the order, leaving completion to the normal fulfillment flow; (3) the webhook resolver explicitly maps payment.created events to pending. Designed to pair with the Instant Payment Rotator, which delegates GivePayments credentials and webhook handling to this plugin.
 * Version:     1.0.0
 * Author:      Chris - Instant Peptides
 * Author URI:  https://instantpeptides.com
 * Text Domain: givepayments-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.2
 * Tested up to: 6.9
 * WC requires at least: 9.0
 * WC tested up to: 9.5
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires Plugins: woocommerce
 */

defined('ABSPATH') || exit;


// Define plugin constants
define('GIVEPAYMENTS_VERSION', '1.0.10');
define('GIVEPAYMENTS_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('GIVEPAYMENTS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Fingerprint constant used by companion plugins (Instant Payment Rotator)
// to distinguish this fork from upstream stock GivePayments. The fork
// carries behavior patches the rotator's GP adapter depends on.
define('IP_GIVEPAYMENTS_FORK_VERSION', '1.0.0');


// Activation/deactivation hooks
register_activation_hook(__FILE__, 'givepayments_activate');
register_deactivation_hook(__FILE__, 'givepayments_deactivate');
register_activation_hook(__FILE__,function (){
    // Use add_option (INSERT IGNORE semantics) so the secret key is generated
    // exactly once. update_option would overwrite the key on every reactivation
    // (plugin updates routinely trigger deactivate→activate cycles), invalidating
    // all stored encrypted API keys and silently breaking checkout until the
    // merchant re-enters their keys in the admin.
    if ( false === get_option( 'givepayments_secret_key', false ) ) {
        $secret_key = openssl_random_pseudo_bytes(32);
        add_option('givepayments_secret_key', base64_encode($secret_key), '', 'no');
    }
});


// Add HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
if ( ! function_exists( 'givepayments_enqueue_admin_styles' ) ) {
function givepayments_enqueue_admin_styles($hook) {
    if ($hook !== 'woocommerce_page_wc-settings') { // Adjust this to your settings page
        return;
    }
    wp_enqueue_style(
        'givepayments-admin-style',
        plugin_dir_url(__FILE__) . 'assets/styles.css',
        array(),  // No dependencies
        GIVEPAYMENTS_VERSION
    );}
}
add_action('admin_enqueue_scripts', 'givepayments_enqueue_admin_styles');



/**
 * Check for WooCommerce and PHP version on activation.
 */
if ( ! function_exists( 'givepayments_activate' ) ) {
function givepayments_activate() {
    // Check if WooCommerce is active
    if (!class_exists('WooCommerce')) {
        wp_die( esc_html__( 'WooCommerce must be activated to use the GivePayments Gateway.', 'givepayments-for-woocommerce' ) );
    }

    // Check PHP version
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        wp_die(esc_html__('This plugin requires PHP 7.4 or higher.', 'givepayments-for-woocommerce'));
    }
}
}

if ( ! function_exists( 'givepayments_deactivate' ) ) {
function givepayments_deactivate() {
    // Cancel any queued Action Scheduler retry rows so they don't fail
    // permanently after the plugin callback is no longer registered.
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( 'givepayments_async_refund_retry', array(), 'givepayments' );
    }
    // NOTE: do NOT delete credential / config options on deactivation.
    // The upstream plugin shipped this destructive block; when the original
    // givepayments-for-woocommerce was deactivated to install this fork on
    // 2026-05-12, the deactivation hook wiped the encrypted API key from
    // wp_options and broke checkout for live customers. Plugin toggles
    // should be safe, credential lifecycle is the merchant's call, not
    // a side-effect of activation state.
}
}

// Initialize the gateway after plugins loaded
add_action('plugins_loaded', 'givepayments_init');

// Raise the HTTP timeout for POST /payments to 45 s (default 20 s).
// The GivePayments acquiring network can be slow under load; cURL error 28
// fires on PHP side before the server finishes, leaving the charge state
// ambiguous and triggering the dedup sentinel which blocks retries.
add_filter( 'givepayments_api_timeout', function ( $timeout, $path, $method ) {
    if ( 'post' === $method && false !== strpos( $path, '/payments' ) ) {
        return 45;
    }
    return $timeout;
}, 10, 3 );

// Load textdomain at priority 1 so translations are available before the
// gateway class is constructed. Both givepayments_init_plugin (which triggers
// GIVEPAYMENTS_Gateway::__construct) and every __() call run at priority 10 on
// plugins_loaded. Without the early load, non-English locales see English
// strings on every request and object caches may freeze those values.
if ( ! function_exists( 'givepayments_load_textdomain_early' ) ) {
function givepayments_load_textdomain_early() {
    load_plugin_textdomain(
        'givepayments-for-woocommerce',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages/'
    );
}
}
add_action( 'plugins_loaded', 'givepayments_load_textdomain_early', 1 );

// Register Action Scheduler hook for async refund retries.
add_action( 'givepayments_async_refund_retry', 'givepayments_handle_async_refund_retry' );

// Prevent WooCommerce's cancel-unpaid-orders cron from auto-cancelling orders
// where a GivePayments payment attempt timed out. The API may have processed the
// charge before the TCP timeout arrived; the webhook will arrive and set the order
// to 'processing'. Without this guard the cron cancels the pending order first,
// producing the "sale went through then failed" email sequence reported by merchants.
add_filter( 'woocommerce_cancel_unpaid_order', function ( $cancel, $order ) {
    if ( $order instanceof WC_Abstract_Order && $order->get_meta( '_givepayments_pending_timeout' ) ) {
        return false;
    }
    return $cancel;
}, 10, 2 );

add_action( 'wp_ajax_woocommerce_refund_line_items', 'givepayments_normalize_admin_refund_request', 1 );
add_action( 'wc_ajax_woocommerce_refund_line_items', 'givepayments_normalize_admin_refund_request', 1 );

// Rename the native WooCommerce "Pending payment" status label to just "Pending".
// The slug ('wc-pending' / 'pending') is unchanged, only the human-readable label
// shown in admin order lists, status dropdowns, and the order detail screen.
// This is a global Woo change (affects every order, not just GivePayments orders),
// so it is filtered conservatively with priority 20 to win over the default but
// allow other plugins/themes to override it if they explicitly want to.
add_filter( 'wc_order_statuses', function ( $statuses ) {
    if ( isset( $statuses['wc-pending'] ) ) {
        $statuses['wc-pending'] = _x( 'Pending', 'Order status', 'givepayments-for-woocommerce' );
    }
    return $statuses;
}, 20 );
function givepayments_handle_async_refund_retry( $args ) {
    if ( ! class_exists( 'GIVEPAYMENTS_Refund_Processor' ) || ! function_exists( 'WC' ) ) {
        // Throw so Action Scheduler marks the action as FAILED and retries
        // it, rather than silently marking it complete and permanently dropping
        // the refund retry. Silent return was the previous behaviour, it meant
        // that a plugin-upgrade window or a mis-ordered autoload could cause a
        // refund attempt to be lost with no operator notification.
        throw new \RuntimeException(
            'givepayments_handle_async_refund_retry: GIVEPAYMENTS_Refund_Processor or WC() not available. Action Scheduler will retry.'
        );
    }
    // N13 cont.: bypass the gateway-instance wrapper (which required
    // GIVEPAYMENTS_Gateway to be instantiated) and call the domain class directly.
    GIVEPAYMENTS_Refund_Processor::execute_async_retry( $args );
}

function givepayments_normalize_admin_refund_request() {
    // Only shop managers / admins should be able to trigger a refund normalisation.
    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        return;
    }
    if ( empty( $_POST['order_id'] ) || empty( $_POST['api_refund'] ) ) {
        return;
    }

    if ( 'true' !== (string) wp_unslash( $_POST['api_refund'] ) ) {
        return;
    }

    $order_id = absint( wp_unslash( $_POST['order_id'] ) );
    if ( ! $order_id ) {
        return;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order || 'givepayments' !== $order->get_payment_method() ) {
        return;
    }

    $available = max( 0, (float) $order->get_total() - abs( (float) $order->get_total_refunded() ) );

    // Keep the request aligned with the gateway's full-refund-only policy.
    // WooCommerce counts refund_amount and line-item JSON independently.
    $_POST['refund_amount'] = number_format( $available, wc_get_price_decimals(), '.', '' );
    $_POST['line_item_qtys'] = '{}';
    $_POST['line_item_totals'] = '{}';
    $_POST['line_item_tax_totals'] = '{}';
}

if ( ! class_exists( 'GIVEPAYMENTS_Plugin' ) ) {
class GIVEPAYMENTS_Plugin {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'givepayments_init_plugin'));
        add_action('admin_notices', array($this, 'givepayments_check_requirements'));
        add_action('rest_api_init', array($this, 'givepayments_register_rest_routes'));
        add_action('plugins_loaded',array($this, 'givepayments_load_textdomain'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'givepayments_add_plugin_links'));
    }

    public function givepayments_init_plugin() {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $this->load_dependencies();
        $this->givepayments_init_gateway();
    }

    private function load_dependencies() {
        try {
            if ( ! class_exists( 'GIVEPAYMENTS_Logger' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/class-givepayments-logger.php';
            }
            if ( ! interface_exists( 'GIVEPAYMENTS_Subscription_Adapter_Interface' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/adapters/class-givepayments-subscription-adapter-interface.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Subscription_Adapter_WCS' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/adapters/class-givepayments-subscription-adapter-wcs.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Subscription_Adapter_WPS_SFW' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/adapters/class-givepayments-subscription-adapter-wps-sfw.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Subscription_Adapter_YITH' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/adapters/class-givepayments-subscription-adapter-yith.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Subscription_Adapter_FSB' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/adapters/class-givepayments-subscription-adapter-fsb.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Subscription_Adapter_Registry' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/adapters/class-givepayments-subscription-adapter-registry.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Exception' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Exceptions/class-givepayments-exception.php';
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Exceptions/class-givepayments-api-exception.php';
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Exceptions/class-givepayments-validation-exception.php';
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Exceptions/class-givepayments-idempotency-exception.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Encryptor' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Crypto/class-givepayments-encryptor.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Api_Client' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Http/class-givepayments-api-client.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Api_Response' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Http/class-givepayments-api-response.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Request_Context' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Http/class-givepayments-request-context.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Order_State' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Payment/class-givepayments-processing-state.php';
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Payment/class-givepayments-order-state.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Payment_Record' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Payment/class-givepayments-payment-record.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Webhook_State_Resolver' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Webhook/class-givepayments-webhook-state-resolver.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Idempotency_Manager' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Payment/class-givepayments-idempotency-manager.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Address_Resolver' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Payment/class-givepayments-address-resolver.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Payment_Processor' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Payment/class-givepayments-payment-processor.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Card_Input_Reader' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Checkout/class-givepayments-card-input-reader.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Checkout_Guard' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Checkout/class-givepayments-checkout-guard.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Classic_Checkout' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Checkout/class-givepayments-classic-checkout.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Refund_Guard' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Refund/class-givepayments-refund-guard.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Refund_Processor' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Refund/class-givepayments-refund-processor.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Subscription_Service' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Subscription/class-givepayments-subscription-service.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Renewal_Handler' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Subscription/class-givepayments-renewal-handler.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Void_Handler' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Admin/class-givepayments-void-handler.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Settings_Page' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/Admin/class-givepayments-settings-page.php';
            }
            if ( ! class_exists( 'GIVEPAYMENTS_Gateway' ) ) {
                require_once GIVEPAYMENTS_PLUGIN_PATH . 'includes/class-givepayments-gateway.php';
            }
        } catch (Exception $e) {
            GIVEPAYMENTS_Logger::log('GivePayments Gateway dependency loading error: ' . $e->getMessage(), 'error');
        }
    }

    private function givepayments_init_gateway() {
        add_filter('woocommerce_payment_gateways', array($this, 'givepayments_add_gateway'));
        add_filter('woocommerce_should_load_checkout_block_payment_gateways', '__return_true');
        add_filter('woocommerce_gateway_class_add_features', function($features, $gateway_id) {
            return $features;
        }, 10, 2);

    }

    public function givepayments_add_gateway($gateways) {
        $gateways[] = 'GIVEPAYMENTS_Gateway';
        return $gateways;
    }

    public function givepayments_check_requirements() {
        if (!class_exists('WooCommerce')) {
            $this->display_error(
                sprintf(
                // translators: %s: Install WooCommerce plugin link
                    esc_html__('GivePayments Gateway requires WooCommerce to be installed and active. %s', 'givepayments-for-woocommerce'),
                    '<a href="' . esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')) . '">' .
                    esc_html__('Install WooCommerce', 'givepayments-for-woocommerce') . '</a>'
                )
            );
        }
    }

    private function display_error($message) {
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message));
    }

    public function givepayments_add_plugin_links($links) {
        $plugin_links = array(
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=givepayments') . '">' . 
            esc_html__('Settings', 'givepayments-for-woocommerce') . '</a>',
        );
        return array_merge($plugin_links, $links);
    }
    public function givepayments_register_rest_routes() {
        register_rest_route('givepayments/v1', '/capture', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'givepayments_handle_webhook'),
            'permission_callback' => array($this, 'givepayments_check_security'),
        ));
    }

    public function givepayments_check_security( $request ) {
        GIVEPAYMENTS_Logger::log( 'Webhook security check started.', 'debug' );

        // Log all incoming headers once so we can identify what auth the provider sends.
        $headers = $request->get_headers();
        $loggable_headers = array();
        foreach ( $headers as $header_name => $header_values ) {
            // Redact any authorization / key values to keep logs safe.
            $lower = strtolower( $header_name );
            if (
                in_array( $lower, array( 'authorization', 'x_api_key', 'x_gp_api_key', 'cookie', 'set_cookie' ), true )
                || false !== strpos( $lower, 'api_key' )
                || false !== strpos( $lower, 'signature' )
                || false !== strpos( $lower, 'secret' )
                || false !== strpos( $lower, 'token' )
            ) {
                $loggable_headers[ $header_name ] = '[REDACTED]';
            } else {
                $loggable_headers[ $header_name ] = is_array( $header_values ) ? implode( ', ', $header_values ) : $header_values;
            }
        }
        GIVEPAYMENTS_Logger::log( 'Webhook headers: ' . wp_json_encode( $loggable_headers ), 'debug' );

        // Debug: surface the portal-reported environment vs the stored WP setting.
        $header_env    = (string) $request->get_header( 'gp_environment' );
        $stored_env    = (string) get_option( 'givepayments_environment', 'test' );
        GIVEPAYMENTS_Logger::log(
            sprintf( 'Webhook env check, header gp_environment: "%s", stored WP option: "%s".', $header_env, $stored_env ),
            'debug'
        );

        // Debug: surface which signature headers were actually received.
        $sig_candidates = array( 'gp_webhook_signature', 'x_gp_signature', 'x_givepayments_signature', 'x_signature', 'stripe_signature' );
        $sig_debug = array();
        foreach ( $sig_candidates as $h ) {
            $val = (string) $request->get_header( $h );
            if ( '' !== $val ) {
                $sig_debug[ $h ] = substr( $val, 0, 16 ) . '…';
            }
        }
        GIVEPAYMENTS_Logger::log(
            'Webhook signature headers present: ' . ( $sig_debug ? wp_json_encode( $sig_debug ) : 'none' ),
            'debug'
        );

        // Detect stale webhook registrations: compare the incoming gp_webhook_id against
        // the ID we most recently registered. If they differ, the event is arriving from an
        // orphaned registration created during a previous re-registration cycle. Its secret
        // is unknown, so HMAC will fail. Logging it clearly tells the admin which IDs to
        // delete from the GivePayments portal.
        $incoming_webhook_id = (string) $request->get_header( 'gp_webhook_id' );
        if ( '' !== $incoming_webhook_id ) {
            $wh_header_env_raw = strtolower( trim( (string) $request->get_header( 'gp_environment' ) ) );
            $wh_env_key        = in_array( $wh_header_env_raw, array( 'live', 'production' ), true ) ? 'production' : 'test';
            $stored_wh_id      = (string) get_option( 'givepayments_webhook_obj_id_' . $wh_env_key, '' );
            if ( '' !== $stored_wh_id && $incoming_webhook_id !== $stored_wh_id ) {
                GIVEPAYMENTS_Logger::log(
                    sprintf(
                        'Webhook from stale registration %s (active registration is %s, env=%s). HMAC will fail, delete this stale registration from the GivePayments portal.',
                        $incoming_webhook_id,
                        $stored_wh_id,
                        $wh_env_key
                    ),
                    'warning'
                );
            }
        }

        // --- Preferred path: HMAC signature validation ---
        // When the provider sends a signed header and a webhook secret is configured,
        // this is the strongest and fastest validation path. No outbound API call needed.
        $signature_check = $this->givepayments_verify_webhook_signature( $request );
        if ( true === $signature_check ) {
            GIVEPAYMENTS_Logger::log( 'Webhook signature validation passed.', 'debug' );
            return true;
        }
        if ( is_wp_error( $signature_check ) ) {
            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'Webhook signature validation failed: %s (%s).',
                    $signature_check->get_error_code(),
                    $signature_check->get_error_message()
                ),
                'warning'
            );
            return $signature_check;
        }

        // --- Fallback path: token validation via GET /events/{id} ---
        // This path is used when no webhook secret/signature is available.
        // It must fail closed because the webhook handler can complete orders.
        $data = $request->get_json_params();
        if ( empty( $data['id'] ) ) {
            // No event id means there is no fallback authentication material.
            GIVEPAYMENTS_Logger::log( 'Webhook rejected: missing id field and no valid signature was provided.', 'warning' );
            return new WP_Error(
                'givepayments_webhook_missing_event_id',
                __( 'Webhook authentication failed: missing event id.', 'givepayments-for-woocommerce' ),
                array( 'status' => 401 )
            );
        }
        $validation_token = sanitize_text_field( $data['id'] );

        // For the live-event fallback, always use the stored WP option to select the API key.
        // Using the incoming gp_environment header would allow an attacker to choose which
        // environment's API key authenticates their forged payload (H5). The HMAC path above
        // is already env-aware via its per-env secrets; this fallback does not need the header.
        $environment = (string) get_option( 'givepayments_environment', 'test' );
        GIVEPAYMENTS_Logger::log( sprintf( 'Webhook token validation using stored environment: %s.', $environment ), 'debug' );

        if ( 'test' === $environment ) {
            $encrypted_api_key = get_option( 'givepayments_sandbox_api_key' );
        } else {
            $encrypted_api_key = get_option( 'givepayments_production_api_key' );
        }

        $api_key = trim( GIVEPAYMENTS_Request_Context::resolve_api_key( (string) $encrypted_api_key ) );
        // givepayments_decrypt_data() was called here but never defined anywhere
        // in the codebase, fatal on every HMAC-fallback webhook validation.
        // Replaced with the canonical GIVEPAYMENTS_Request_Context::resolve_api_key().
        // Prevent CRLF header injection.
        $api_key = str_replace( array( "\r", "\n" ), '', $api_key );

        if ( '' === $api_key ) {
            // No API key means unsigned webhooks cannot be authenticated.
            GIVEPAYMENTS_Logger::log( 'Webhook rejected: API key missing, cannot validate unsigned webhook event.', 'error' );
            return new WP_Error(
                'givepayments_webhook_missing_api_key',
                __( 'Webhook authentication failed: API key is not configured.', 'givepayments-for-woocommerce' ),
                array( 'status' => 401 )
            );
        }

        $api_base = GIVEPAYMENTS_Request_Context::api_base_url( $environment );

        $api_url = rtrim( $api_base, '/' ) . '/events/' . rawurlencode( $validation_token );

        $response = wp_remote_get( $api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'X-Api-Key'     => $api_key,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            // Network-level error (DNS, timeout). Return 503 so legitimate webhooks retry.
            GIVEPAYMENTS_Logger::log(
                'Webhook token validation network error: ' . $response->get_error_message() . '. Rejecting with retryable status.',
                'warning'
            );
            return new WP_Error(
                'givepayments_webhook_validation_unavailable',
                __( 'Webhook authentication temporarily unavailable.', 'givepayments-for-woocommerce' ),
                array( 'status' => 503 )
            );
        }

        $response_code = (int) wp_remote_retrieve_response_code( $response );

        if ( 200 === $response_code ) {
            $event_response_body = wp_remote_retrieve_body( $response );
            $event_response_data = json_decode( $event_response_body, true );
            if ( is_array( $event_response_data ) ) {
                $provider_event_id = isset( $event_response_data['id'] ) ? sanitize_text_field( (string) $event_response_data['id'] ) : '';
                if ( '' !== $provider_event_id && $provider_event_id !== $validation_token ) {
                    GIVEPAYMENTS_Logger::log(
                        sprintf( 'Webhook rejected: provider returned event id %s while validating %s.', $provider_event_id, $validation_token ),
                        'warning'
                    );
                    return new WP_Error(
                        'givepayments_webhook_event_mismatch',
                        __( 'Webhook authentication failed: event payload mismatch.', 'givepayments-for-woocommerce' ),
                        array( 'status' => 401 )
                    );
                }

                $incoming_event_type = isset( $data['type'] ) ? sanitize_text_field( (string) $data['type'] ) : '';
                $provider_event_type = isset( $event_response_data['type'] ) ? sanitize_text_field( (string) $event_response_data['type'] ) : '';
                if ( '' !== $incoming_event_type && '' !== $provider_event_type && $incoming_event_type !== $provider_event_type ) {
                    GIVEPAYMENTS_Logger::log(
                        sprintf( 'Webhook rejected: event type mismatch for %s (incoming: %s, provider: %s).', $validation_token, $incoming_event_type, $provider_event_type ),
                        'warning'
                    );
                    return new WP_Error(
                        'givepayments_webhook_event_mismatch',
                        __( 'Webhook authentication failed: event payload mismatch.', 'givepayments-for-woocommerce' ),
                        array( 'status' => 401 )
                    );
                }

                $incoming_object = $this->givepayments_get_payment_object( $data );
                $provider_object = $this->givepayments_get_payment_object( $event_response_data );
                foreach ( array( 'id', 'payment', 'external_reference', 'externalReference' ) as $compare_key ) {
                    $incoming_value = isset( $incoming_object[ $compare_key ] ) && is_scalar( $incoming_object[ $compare_key ] )
                        ? sanitize_text_field( (string) $incoming_object[ $compare_key ] )
                        : '';
                    $provider_value = isset( $provider_object[ $compare_key ] ) && is_scalar( $provider_object[ $compare_key ] )
                        ? sanitize_text_field( (string) $provider_object[ $compare_key ] )
                        : '';
                    if ( '' !== $incoming_value && '' !== $provider_value && $incoming_value !== $provider_value ) {
                        GIVEPAYMENTS_Logger::log(
                            sprintf( 'Webhook rejected: %s mismatch for event %s.', $compare_key, $validation_token ),
                            'warning'
                        );
                        return new WP_Error(
                            'givepayments_webhook_event_mismatch',
                            __( 'Webhook authentication failed: event payload mismatch.', 'givepayments-for-woocommerce' ),
                            array( 'status' => 401 )
                        );
                    }
                }
            }
            GIVEPAYMENTS_Logger::log( 'Webhook token validation passed (HTTP 200 from /events).', 'debug' );
            return true;
        }

        if ( 404 === $response_code ) {
            // Provider returned 404, event may not be retrievable by this ID,
            // or the /events endpoint does not support direct lookup.
            // Reject instead of trusting an unsigned payload.
            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'Webhook rejected: token validation received HTTP 404 from %s.',
                    $api_url
                ),
                'warning'
            );
            return new WP_Error(
                'givepayments_webhook_unknown_event',
                __( 'Webhook authentication failed: event was not found.', 'givepayments-for-woocommerce' ),
                array( 'status' => 401 )
            );
        }

        if ( $response_code >= 500 || 0 === $response_code ) {
            // Provider-side validation error. Return 503 so legitimate webhooks retry.
            GIVEPAYMENTS_Logger::log(
                sprintf( 'Webhook token validation received HTTP %d (server error). Rejecting with retryable status.', $response_code ),
                'warning'
            );
            return new WP_Error(
                'givepayments_webhook_validation_unavailable',
                __( 'Webhook authentication temporarily unavailable.', 'givepayments-for-woocommerce' ),
                array( 'status' => 503 )
            );
        }

        if ( 401 === $response_code || 403 === $response_code ) {
            // Our API key is wrong or revoked, this is a configuration error,
            // not a webhook authenticity problem. Reject and log
            // loudly so admins notice the misconfiguration.
            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'Webhook rejected: token validation received HTTP %d (auth error against /events). Check API key configuration.',
                    $response_code
                ),
                'error'
            );
            return new WP_Error(
                'givepayments_webhook_validation_auth_error',
                __( 'Webhook authentication failed: event validation credentials were rejected.', 'givepayments-for-woocommerce' ),
                array( 'status' => 503 )
            );
        }

        // Any other unexpected status: reject and log for investigation.
        GIVEPAYMENTS_Logger::log(
            sprintf( 'Webhook rejected: token validation received unexpected HTTP %d.', $response_code ),
            'warning'
        );
        return new WP_Error(
            'givepayments_webhook_validation_failed',
            __( 'Webhook authentication failed.', 'givepayments-for-woocommerce' ),
            array( 'status' => 401 )
        );
    }

    /**
     * Verify webhook HMAC signature (when available).
     *
     * Returns:
     * - true when signature is present and valid.
     * - false when signature mode is unavailable (missing secret/header), allowing legacy fallback.
     * - WP_Error when signature is present but invalid/expired.
     *
     * Header support:
     * - X-GP-Signature / X-GivePayments-Signature / X-Signature / Stripe-Signature
     * - X-GP-Timestamp / X-GivePayments-Timestamp / X-Timestamp / t= (Stripe style)
     */
    private function givepayments_verify_webhook_signature( WP_REST_Request $request ) {
        // Accept additional env header names that the portal may use now or in the
        // future (x-gp-environment, gp-env), so a header rename never silently breaks env
        // resolution and causes a cross-environment secret mismatch.
        $header_env = strtolower( trim( $this->first_non_empty_header( $request, array(
            'gp-environment', 'gp_environment', 'x-gp-environment', 'gp-env',
        ) ) ) );

        // Track whether the env came from the webhook header or fell back to the WP
        // admin setting. When it fell back we also keep an alt option so we can retry HMAC
        // with the other env's secret (see alt-secret retry below). This prevents a prod
        // webhook from failing just because the admin is currently viewing the test env.
        $resolved_from_header = true;
        if ( in_array( $header_env, array( 'live', 'production' ), true ) ) {
            $secret_option     = 'givepayments_webhook_secret_production';
            $secret_option_alt = 'givepayments_webhook_secret_test';
        } elseif ( in_array( $header_env, array( 'sandbox', 'test' ), true ) ) {
            $secret_option     = 'givepayments_webhook_secret_test';
            $secret_option_alt = 'givepayments_webhook_secret_production';
        } else {
            // No env header, fall back to the WP admin setting but remember the alt
            // option so we can retry if the primary secret does not match.
            $resolved_from_header = false;
            $wp_env              = (string) get_option( 'givepayments_environment', 'test' );
            $secret_option       = ( 'production' === $wp_env )
                ? 'givepayments_webhook_secret_production'
                : 'givepayments_webhook_secret_test';
            $secret_option_alt   = ( 'production' === $wp_env )
                ? 'givepayments_webhook_secret_test'
                : 'givepayments_webhook_secret_production';
        }

        $secret = trim( (string) get_option( $secret_option, '' ) );
        if ( '' === $secret ) {
            // Read the legacy shared-secret option as READ-ONLY. We no longer write
            // to it during registration, so it cannot corrupt the other env's secret. It is
            // kept here only for back-compat with sites that were set up before per-env
            // options existed.
            $secret = trim( (string) get_option( 'givepayments_webhook_secret', '' ) );
        }

        // Log a secret fingerprint (NOT the secret itself) so we can triage
        // mismatches in prod without exposing sensitive values. Compare this fingerprint
        // against the one printed during registration to confirm they match.
        $secret_fp = $secret ? substr( hash( 'sha256', $secret ), 0, 8 ) : 'none';
        GIVEPAYMENTS_Logger::log(
            sprintf(
                'Webhook verify: env_header="%s", resolved_from_header=%s, secret_option=%s, secret_len=%d, secret_fp=%s, body_len=%d',
                $header_env,
                $resolved_from_header ? 'yes' : 'no',
                $secret_option,
                strlen( $secret ),
                $secret_fp,
                strlen( (string) $request->get_body() )
            ),
            'debug'
        );

        if ( '' === $secret ) {
            return false;
        }

        $signature_header = $this->first_non_empty_header( $request, array(
            'gp-webhook-signature', 'x-gp-signature', 'x-givepayments-signature', 'x-signature', 'stripe-signature',
        ) );

        if ( '' === $signature_header ) {
            return false;
        }

        $timestamp_header = $this->first_non_empty_header( $request, array(
            'x-gp-timestamp', 'x-givepayments-timestamp', 'x-timestamp',
        ) );

        $raw_body = (string) $request->get_body();
        $signature_candidates = array();
        $timestamp = '';

        // Parse Stripe-style signature header: "t=123,v1=abc,..."
        if ( strpos( $signature_header, '=' ) !== false && strpos( $signature_header, ',' ) !== false ) {
            $parts = explode( ',', $signature_header );
            foreach ( $parts as $part ) {
                $kv = explode( '=', trim( $part ), 2 );
                if ( count( $kv ) !== 2 ) {
                    continue;
                }
                $key = trim( $kv[0] );
                $val = trim( $kv[1] );
                if ( 't' === $key && '' === $timestamp ) {
                    $timestamp = $val;
                }
                if ( 'v1' === $key || 'sig' === $key || 'signature' === $key ) {
                    $signature_candidates[] = $val;
                }
            }
        } else {
            // Always add the full raw header as-is first.
            // The portal sends a raw base64url value ending with "=" padding
            // (e.g. "yZ339_Of...A="). Treating that trailing "=" as a key=value
            // separator would produce an empty $kv[1] and discard the real value.
            $signature_candidates[] = trim( $signature_header );
            // Also try key=value split for explicit formats like "sha256=abc123" or "v1=abc",
            // but only add the right-hand side when it's non-empty.
            if ( strpos( $signature_header, '=' ) !== false ) {
                $kv       = explode( '=', trim( $signature_header ), 2 );
                $split_val = trim( $kv[1] );
                if ( '' !== $split_val ) {
                    $signature_candidates[] = $split_val;
                }
            }
        }

        if ( '' === $timestamp && '' !== $timestamp_header ) {
            $timestamp = trim( $timestamp_header );
        }

        // Replay protection (5-minute window) when timestamp is provided.
        if ( '' !== $timestamp && ctype_digit( $timestamp ) ) {
            $delta = abs( time() - (int) $timestamp );
            if ( $delta > 300 ) {
                return new WP_Error(
                    'expired_signature',
                    __( 'Webhook signature timestamp is outside replay window.', 'givepayments-for-woocommerce' ),
                    array( 'status' => 403 )
                );
            }
        }

        // Compute expected signatures for common schemes.
        // Portal sends base64url-encoded HMAC-SHA256 (e.g. "FLQjx..._...=").
        // PHP hash_hmac() returns hex by default, so we must also produce
        // base64 and base64url variants to match.
        $raw_plain          = hash_hmac( 'sha256', $raw_body, $secret, true );
        $expected_plain_hex = bin2hex( $raw_plain );
        $expected_plain_b64 = base64_encode( $raw_plain );
        // base64url: replace +/ with -_ (with or without = padding)
        $expected_plain_b64url = rtrim( strtr( $expected_plain_b64, '+/', '-_' ), '=' );

        $raw_timed          = ( '' !== $timestamp ) ? hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret, true ) : '';
        $expected_timed_hex    = '' !== $raw_timed ? bin2hex( $raw_timed ) : '';
        $expected_timed_b64    = '' !== $raw_timed ? base64_encode( $raw_timed ) : '';
        $expected_timed_b64url = '' !== $raw_timed ? rtrim( strtr( $expected_timed_b64, '+/', '-_' ), '=' ) : '';

        foreach ( $signature_candidates as $candidate ) {
            $candidate = trim( (string) $candidate );
            if ( '' === $candidate ) {
                continue;
            }
            // Normalise: strip base64url padding for comparison so both
            // padded ("abc=") and unpadded ("abc") forms are accepted.
            $candidate_no_pad = rtrim( $candidate, '=' );

            if ( hash_equals( $expected_plain_hex, $candidate ) ) {
                return true;
            }
            if ( hash_equals( $expected_plain_b64, $candidate ) ) {
                return true;
            }
            if ( hash_equals( $expected_plain_b64url, $candidate_no_pad ) ) {
                return true;
            }
            if ( '' !== $expected_timed_hex && hash_equals( $expected_timed_hex, $candidate ) ) {
                return true;
            }
            if ( '' !== $expected_timed_b64 && hash_equals( $expected_timed_b64, $candidate ) ) {
                return true;
            }
            if ( '' !== $expected_timed_b64url && hash_equals( $expected_timed_b64url, $candidate_no_pad ) ) {
                return true;
            }
        }

        // When env was not resolved from the webhook header (no gp-environment header
        // arrived), retry HMAC against the alternate environment's secret before treating this
        // as a hard failure. This makes the verifier independent of which env the admin is
        // currently viewing in WP settings: a prod webhook arriving while the admin is on
        // the test tab (or vice versa) will still pass if its HMAC matches the correct secret.
        if ( ! $resolved_from_header ) {
            $alt_secret = trim( (string) get_option( $secret_option_alt, '' ) );
            if ( '' !== $alt_secret && $alt_secret !== $secret ) {
                $alt_fp = substr( hash( 'sha256', $alt_secret ), 0, 8 );
                GIVEPAYMENTS_Logger::log(
                    sprintf( 'Webhook verify: primary secret did not match; retrying with alt (option=%s, fp=%s).', $secret_option_alt, $alt_fp ),
                    'debug'
                );

                $alt_raw_plain             = hash_hmac( 'sha256', $raw_body, $alt_secret, true );
                $alt_expected_plain_hex    = bin2hex( $alt_raw_plain );
                $alt_expected_plain_b64    = base64_encode( $alt_raw_plain );
                $alt_expected_plain_b64url = rtrim( strtr( $alt_expected_plain_b64, '+/', '-_' ), '=' );

                $alt_raw_timed             = ( '' !== $timestamp ) ? hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $alt_secret, true ) : '';
                $alt_expected_timed_hex    = '' !== $alt_raw_timed ? bin2hex( $alt_raw_timed ) : '';
                $alt_expected_timed_b64    = '' !== $alt_raw_timed ? base64_encode( $alt_raw_timed ) : '';
                $alt_expected_timed_b64url = '' !== $alt_raw_timed ? rtrim( strtr( $alt_expected_timed_b64, '+/', '-_' ), '=' ) : '';

                foreach ( $signature_candidates as $candidate ) {
                    $candidate = trim( (string) $candidate );
                    if ( '' === $candidate ) {
                        continue;
                    }
                    $candidate_no_pad = rtrim( $candidate, '=' );

                    if ( hash_equals( $alt_expected_plain_hex, $candidate ) ) {
                        return true;
                    }
                    if ( hash_equals( $alt_expected_plain_b64, $candidate ) ) {
                        return true;
                    }
                    if ( hash_equals( $alt_expected_plain_b64url, $candidate_no_pad ) ) {
                        return true;
                    }
                    if ( '' !== $alt_expected_timed_hex && hash_equals( $alt_expected_timed_hex, $candidate ) ) {
                        return true;
                    }
                    if ( '' !== $alt_expected_timed_b64 && hash_equals( $alt_expected_timed_b64, $candidate ) ) {
                        return true;
                    }
                    if ( '' !== $alt_expected_timed_b64url && hash_equals( $alt_expected_timed_b64url, $candidate_no_pad ) ) {
                        return true;
                    }
                }
            }
        }

        return new WP_Error(
            'invalid_signature',
            __( 'Webhook signature validation failed.', 'givepayments-for-woocommerce' ),
            array( 'status' => 403 )
        );
    }

    function givepayments_handle_webhook(WP_REST_Request $request): WP_REST_Response {
        $data = $request->get_json_params();
        if ( empty( $data ) || ! is_array( $data ) ) {
            return new WP_REST_Response( array( 'message' => 'Invalid webhook payload' ), 400 );
        }

        $event_id = isset( $data['id'] ) ? sanitize_text_field( (string) $data['id'] ) : '';
        $event_type = isset( $data['type'] ) ? sanitize_text_field( (string) $data['type'] ) : '';

        GIVEPAYMENTS_Logger::log(
            sprintf( 'Webhook received, event_id: %s, event_type: %s.', $event_id ? $event_id : 'none', $event_type ? $event_type : 'none' ),
            'debug'
        );

        $payment_object = $this->givepayments_get_payment_object( $data );
        $external_reference = isset( $payment_object['external_reference'] )
            ? sanitize_text_field( (string) $payment_object['external_reference'] )
            : '';
        if ( '' === $external_reference && isset( $payment_object['externalReference'] ) ) {
            $external_reference = sanitize_text_field( (string) $payment_object['externalReference'] );
        }
        $payment_status   = $this->givepayments_extract_payment_status( $payment_object );
        $processing_state = $this->givepayments_extract_payment_processing_state( $payment_object );
        GIVEPAYMENTS_Logger::log(
            sprintf( 'Webhook payload fields, processingState: "%s", status(fallback): "%s".', $processing_state, $payment_status ),
            'debug'
        );

        if ( '' === $external_reference ) {
            // Void payloads: data.object IS the payment object, its id matches _givepayments_transaction_id directly.
            // Refund payloads: data.object IS the refund object, its own id is a refund txn never stored on the
            // order, but it carries a 'payment' field pointing to the parent payment txn id which IS stored.
            // Try both in order so voids (first candidate) and refunds (second candidate) are both resolved.
            $candidates_txn = array_filter( array(
                isset( $payment_object['id'] )      ? sanitize_text_field( (string) $payment_object['id'] )      : '',
                isset( $payment_object['payment'] ) ? sanitize_text_field( (string) $payment_object['payment'] ) : '',
            ) );
            foreach ( $candidates_txn as $candidate_txn ) {
                $order = $this->givepayments_find_order_by_transaction_id( $candidate_txn );
                if ( $order ) {
                    $external_reference = (string) $order->get_id();
                    GIVEPAYMENTS_Logger::log(
                        sprintf( 'Webhook resolved order %d by transaction id fallback (%s).', $order->get_id(), $candidate_txn ),
                        'debug'
                    );
                    break;
                }
            }
        }

        if ( '' === $external_reference ) {
            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'Webhook ignored: missing external_reference (event: %s, status: %s).',
                    $event_type ? $event_type : 'unknown',
                    $payment_status ? $payment_status : 'unknown'
                ),
                'warning'
            );
            return new WP_REST_Response( array( 'message' => 'Webhook missing external_reference' ), 200 );
        }

        $order = wc_get_order( $external_reference );
        if ( ! $order ) {
            // external_reference was present but wc_get_order() returned nothing
            // (order deleted, wrong store, or reference is not a WC order ID).
            // Attempt transaction ID fallback before giving up.
            $candidates_txn = array_filter( array(
                isset( $payment_object['id'] )      ? sanitize_text_field( (string) $payment_object['id'] )      : '',
                isset( $payment_object['payment'] ) ? sanitize_text_field( (string) $payment_object['payment'] ) : '',
            ) );

            if ( empty( $candidates_txn ) ) {
                GIVEPAYMENTS_Logger::log(
                    sprintf(
                        'Webhook ignored: order not found for external_reference %s and no transaction IDs available for fallback (event: %s, status: %s).',
                        $external_reference,
                        $event_type ? $event_type : 'unknown',
                        $payment_status ? $payment_status : 'unknown'
                    ),
                    'warning'
                );
                return new WP_REST_Response( array( 'message' => 'Order not found for external_reference' ), 200 );
            }

            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'Webhook: wc_get_order() returned nothing for external_reference %s, trying txn ID fallback with candidates: %s (event: %s).',
                    $external_reference,
                    implode( ', ', $candidates_txn ),
                    $event_type ? $event_type : 'unknown'
                ),
                'debug'
            );

            foreach ( $candidates_txn as $candidate_txn ) {
                $fallback_order = $this->givepayments_find_order_by_transaction_id( $candidate_txn );
                if ( $fallback_order ) {
                    GIVEPAYMENTS_Logger::log(
                        sprintf(
                            'Webhook resolved order %d by txn ID fallback (%s) after external_reference %s miss.',
                            $fallback_order->get_id(),
                            $candidate_txn,
                            $external_reference
                        ),
                        'debug'
                    );
                    $order = $fallback_order;
                    break;
                }
                GIVEPAYMENTS_Logger::log(
                    sprintf(
                        'Webhook txn ID fallback: no order found for candidate txn %s (external_reference: %s).',
                        $candidate_txn,
                        $external_reference
                    ),
                    'debug'
                );
            }
        }

        if ( ! $order ) {
            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'Webhook ignored: order not found for external_reference %s after txn ID fallback exhausted (tried: %s, event: %s, status: %s).',
                    $external_reference,
                    isset( $candidates_txn ) ? implode( ', ', $candidates_txn ) : 'none',
                    $event_type ? $event_type : 'unknown',
                    $payment_status ? $payment_status : 'unknown'
                ),
                'warning'
            );
            return new WP_REST_Response( array( 'message' => 'Order not found for external_reference' ), 200 );
        }

        // Guard against adversarial test events whose external_reference is a
        // WC_Order_Refund ID. wc_get_order() returns WC_Order_Refund for those, and
        // calling payment_complete() on a refund object is a fatal error.
        if ( ! ( $order instanceof WC_Order ) ) {
            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'Webhook rejected: external_reference %s resolved to a non-shop-order object (%s).',
                    $external_reference,
                    get_class( $order )
                ),
                'warning'
            );
            return new WP_REST_Response( array( 'message' => 'Object type mismatch, not a shop order' ), 200 );
        }

        // Only process webhooks for orders that belong to this gateway.
        if ( 'givepayments' !== $order->get_payment_method() ) {
            GIVEPAYMENTS_Logger::log(
                sprintf( 'Webhook rejected: order %d payment method is "%s", not givepayments.', $order->get_id(), $order->get_payment_method() ),
                'warning'
            );
            return new WP_REST_Response( array( 'message' => 'Order payment method mismatch' ), 200 );
        }

        // When the order already has a stored transaction ID, cross-check it
        // against the webhook payload to prevent a forged event from manipulating
        // an unrelated order.
        //
        // For refund payloads, data.object.id is the refund transaction ID (never stored
        // on the Woo order), while data.object.payment holds the parent payment transaction
        // ID which IS stored. Accept either as a valid match so refund webhooks are not
        // incorrectly rejected at this gate after being correctly resolved above.
        $webhook_payment_id = isset( $payment_object['id'] )
            ? sanitize_text_field( (string) $payment_object['id'] )
            : '';
        $webhook_parent_payment_id = isset( $payment_object['payment'] )
            ? sanitize_text_field( (string) $payment_object['payment'] )
            : '';
        $stored_txn_id = (string) $order->get_transaction_id();
        if ( '' === $stored_txn_id ) {
            $stored_txn_id = GIVEPAYMENTS_Payment_Record::for( $order )->transaction_id();
        }
        $txn_id_matches = '' === $stored_txn_id
            || '' === $webhook_payment_id
            || $stored_txn_id === $webhook_payment_id
            || ( '' !== $webhook_parent_payment_id && $stored_txn_id === $webhook_parent_payment_id );
        if ( ! $txn_id_matches ) {
            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'Webhook rejected: transaction ID mismatch for order %d (stored: %s, webhook id: %s, webhook payment: %s).',
                    $order->get_id(),
                    $stored_txn_id,
                    $webhook_payment_id,
                    $webhook_parent_payment_id ? $webhook_parent_payment_id : 'none'
                ),
                'warning'
            );
            return new WP_REST_Response( array( 'message' => 'Transaction ID mismatch' ), 200 );
        }

        // If order has no transaction ID yet (on-hold from async flow), persist
        // the one from the webhook so refunds and future lookups work.
        if ( '' === $stored_txn_id && '' !== $webhook_payment_id ) {
            $order->set_transaction_id( $webhook_payment_id );
            GIVEPAYMENTS_Payment_Record::for( $order )->set_transaction_id( $webhook_payment_id );
            $order->save();
        }

        // Two-layer dedup: (1) persistent order-meta list survives server restarts;
        // (2) atomic add_option mutex covers the concurrent-delivery race window where
        // two PHP processes both pass the meta check before either has written its mark.
        if ( $event_id && $this->givepayments_was_event_processed( $order, $event_id ) ) {
            GIVEPAYMENTS_Logger::log(
                sprintf( 'Duplicate webhook ignored for order %d (event id: %s, dedup: meta).', $order->get_id(), $event_id ),
                'debug'
            );
            return new WP_REST_Response( array( 'message' => 'Duplicate webhook ignored' ), 200 );
        }
        if ( $event_id ) {
            $event_dedup_key = 'gp_event_' . md5( (int) $order->get_id() . '_' . $event_id );
            if ( ! add_option( $event_dedup_key, '1', '', 'no' ) ) {
                GIVEPAYMENTS_Logger::log(
                    sprintf( 'Duplicate webhook ignored for order %d (event id: %s, dedup: concurrent lock).', $order->get_id(), $event_id ),
                    'debug'
                );
                return new WP_REST_Response( array( 'message' => 'Duplicate webhook ignored' ), 200 );
            }
        }

        $updated = false;
        $resolved_state = $this->givepayments_resolve_portal_state( $event_type, $processing_state, $payment_status );
        $current_status = $order->get_status();

        // Keep a small reconciliation trail for support/debugging.
        $rec = GIVEPAYMENTS_Payment_Record::for( $order );
        $rec->set_last_portal_state( $resolved_state );
        $rec->set_last_portal_event_type( (string) $event_type );
        if ( $event_id ) {
            $rec->set_last_portal_event_id( (string) $event_id );
        }
        // Persist the raw provider processingState so other parts of the plugin
        // woo status, actions can rely on it
        if ( '' !== $processing_state ) {
            $rec->set_processing_state( $processing_state );
        }

        $updated = $this->dispatch_webhook_state(
            $resolved_state, $order, $event_type, $payment_status, $processing_state, $current_status
        );
        if ( $event_id ) {
            $this->givepayments_mark_event_processed( $order, $event_id );
        }
        $order->save();

        // The gp_event_* add_option row served only as a concurrent-delivery
        // mutex. Now that mark_event_processed() has durably written the event ID to
        // order meta (the persistent dedup layer), the option row is no longer needed
        // and must be deleted to prevent unbounded wp_options growth on high-volume stores.
        if ( isset( $event_dedup_key ) ) {
            delete_option( $event_dedup_key );
        }

        return new WP_REST_Response(
            array(
                'message' => $updated ? 'Order reconciled' : 'Webhook received',
                'order_id' => $order->get_id(),
                'event_type' => $event_type,
                'payment_status' => $payment_status,
            ),
            200
        );
    }

    // =========================================================================
    // Webhook state dispatch, routes $resolved_state to dedicated handlers.
    // Each handler returns true when the WC order state was mutated (used to
    // set the "Order reconciled" vs "Webhook received" response message).
    // =========================================================================

    /**
     * @param string   $resolved_state   Canonical state from givepayments_resolve_portal_state().
     * @param WC_Order $order
     * @param string   $event_type       Raw portal event type string.
     * @param string   $payment_status   Raw portal payment status string.
     * @param string   $processing_state Raw portal processingState string.
     * @param string   $current_status   WC order status snapshot taken before any mutations.
     * @return bool
     */
    private function dispatch_webhook_state(
        string $resolved_state,
        WC_Order $order,
        string $event_type,
        string $payment_status,
        string $processing_state,
        string $current_status
    ): bool {
        $handlers = array(
            'captured'            => 'handle_webhook_captured',
            'settled'             => 'handle_webhook_settled',
            'processing'          => 'handle_webhook_processing',
            'pending'             => 'handle_webhook_pending',
            'voided'              => 'handle_webhook_voided',
            'failure'             => 'handle_webhook_failure',
            'refund_created'      => 'handle_webhook_refund_created',
            'refund_failed'       => 'handle_webhook_refund_failed',
            'refunded'            => 'handle_webhook_refunded',
            'chargeback'          => 'handle_webhook_chargeback',
            'chargeback_reversal' => 'handle_webhook_chargeback_reversal',
            'chargeback_refund'   => 'handle_webhook_chargeback_refund',
        );
        $method = $handlers[ $resolved_state ] ?? 'handle_webhook_unknown';
        return $this->$method( $order, $event_type, $payment_status, $processing_state, $current_status );
    }

    private function handle_webhook_captured(
        WC_Order $order,
        string $event_type,
        string $payment_status,
        string $processing_state,
        string $current_status
    ): bool {
        // Funds debited from card but not yet settled. Move order to processing
        // regardless of the admin's preferred order status setting, because the
        // payment is not terminal until a separate 'settled' event arrives.
        //
        // IMPORTANT: short-circuit if the order is already in a terminal
        // refund/cancel state. WooCommerce's $order->is_paid() returns false
        // for 'refunded' (only 'processing'/'completed' are considered paid),
        // so without this guard payment_complete() would lift a refunded order
        // back to 'completed' when a late-arriving capture event lands after a
        // refund webhook.
        if ( in_array( $current_status, array( 'refunded', 'cancelled' ), true ) ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: event type, 2: payment status */
                    __( 'GivePayments Webhook ignored capture event for terminal order: %1$s (status: %2$s)', 'givepayments-for-woocommerce' ),
                    $event_type ? $event_type : 'unknown',
                    $payment_status ? $payment_status : 'unknown'
                )
            );
            return false;
        }

        $updated = false;
        if ( ! $order->is_paid() ) {
            $order->payment_complete();
            $updated = true;
        }
        $post_capture_status = $order->get_status();
        if ( 'processing' !== $post_capture_status && ! in_array( $post_capture_status, array( 'refunded', 'cancelled', 'completed' ), true ) ) {
            $order->update_status(
                'processing',
                __( 'Updated by GivePayments Webhook - Payment captured', 'givepayments-for-woocommerce' )
            );
            $updated = true;
        } elseif ( 'processing' === $post_capture_status ) {
            $order->add_order_note( __( 'Updated by GivePayments Webhook - Payment captured', 'givepayments-for-woocommerce' ) );
            $updated = true;
        }
        $order->delete_meta_data( GIVEPAYMENTS_Payment_Record::META_CHARGEBACK_OPEN );
        return $updated;
    }

    private function handle_webhook_settled(
        WC_Order $order,
        string $event_type,
        string $payment_status,
        string $processing_state,
        string $current_status
    ): bool {
        // IP fork: settlement is INFORMATIONAL ONLY. Funds have moved to the
        // merchant account, but in our shop semantics "completed" means
        // "shipped", the fulfillment center marks orders complete after they
        // ship. We do NOT auto-complete on settlement.
        //
        // Original plugin moved orders to 'completed' here; that auto-promotion
        // forced our mu-plugin to run a detect-and-revert band-aid on every
        // settled webhook. Removing the promotion eliminates the band-aid.
        if ( in_array( $current_status, array( 'refunded', 'cancelled' ), true ) ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: event type, 2: payment status */
                    __( 'GivePayments Webhook ignored settle event for terminal order: %1$s (status: %2$s)', 'givepayments-for-woocommerce' ),
                    $event_type ? $event_type : 'unknown',
                    $payment_status ? $payment_status : 'unknown'
                )
            );
            return false;
        }

        // Defensive: if no intermediate webhook fired payment_complete(), do
        // it now so WC's _paid_date and woocommerce_payment_complete hooks
        // run. payment_complete advances pending → processing on its own.
        if ( ! $order->is_paid() ) {
            $order->payment_complete();
        }

        // Informational note. Status intentionally unchanged.
        $order->add_order_note(
            __( 'GivePayments Webhook, funds settled. Status unchanged (fulfillment marks completed after shipping).', 'givepayments-for-woocommerce' )
        );
        $order->delete_meta_data( GIVEPAYMENTS_Payment_Record::META_CHARGEBACK_OPEN );
        return true;
    }

    private function handle_webhook_processing(
        WC_Order $order,
        string $event_type,
        string $payment_status,
        string $processing_state,
        string $current_status
    ): bool {
        // payment.created / payment.authorized / payment.captured all move to WC
        // 'processing'. The _givepayments_processing_state meta retains the exact
        // provider state so VoidHandler/RefundGuard make correct eligibility calls.
        if ( in_array( $current_status, array( 'failed', 'cancelled', 'refunded', 'completed' ), true ) ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: event type, 2: current WC status */
                    __( 'GivePayments Webhook ignored, order already in terminal status %2$s (event: %1$s).', 'givepayments-for-woocommerce' ),
                    $event_type ?: 'unknown',
                    $current_status
                )
            );
            return false;
        }

        // When funds have actually been captured (processingState=captured),
        // call payment_complete() so WooCommerce sets _paid_date and fires
        // the woocommerce_payment_complete hooks. For created/authorized the
        // money has not moved yet so we advance the status only.
        if ( 'captured' === $processing_state && ! $order->is_paid() ) {
            $order->payment_complete();
        }

        if ( 'processing' !== $order->get_status() ) {
            $order->update_status(
                'processing',
                sprintf(
                    /* translators: %s: provider processingState value, e.g. "captured" */
                    __( 'GivePayments Webhook, payment processingState: %s.', 'givepayments-for-woocommerce' ),
                    $processing_state ?: $event_type ?: 'unknown'
                )
            );
        } else {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: provider processingState value */
                    __( 'GivePayments Webhook, order already processing (processingState: %s).', 'givepayments-for-woocommerce' ),
                    $processing_state ?: $event_type ?: 'unknown'
                )
            );
        }
        return true;
    }

    private function handle_webhook_pending(
        WC_Order $order,
        string $event_type,
        string $payment_status,
        string $processing_state,
        string $current_status
    ): bool {
        // Keep generic provider pending/in-review states in WC pending.
        // Per merchant policy 'on-hold' is never used by this gateway.
        // Never downgrade already-finalized orders.
        if ( ! $order->is_paid() && ! in_array( $current_status, array( 'failed', 'cancelled', 'refunded' ), true ) ) {
            if ( 'pending' !== $current_status ) {
                $order->update_status(
                    'pending',
                    __( 'Updated by GivePayments Webhook - Payment pending', 'givepayments-for-woocommerce' )
                );
                return true;
            }
            return false;
        }
        $order->add_order_note(
            sprintf(
                /* translators: 1: event type, 2: payment status */
                __( 'GivePayments Webhook ignored pending update for finalized order: %1$s (status: %2$s)', 'givepayments-for-woocommerce' ),
                $event_type ? $event_type : 'unknown',
                $payment_status ? $payment_status : 'unknown'
            )
        );
        return false;
    }

    private function handle_webhook_voided(
        WC_Order $order,
        string $event_type,
        string $payment_status,
        string $processing_state,
        string $current_status
    ): bool {
        // Void can happen at any stage: authorized-but-not-captured, captured,
        // or even settled (reversed before merchant payout). Mark the Woo order
        // as refunded (not cancelled), per merchant policy, voided = refunded.
        GIVEPAYMENTS_Payment_Record::for( $order )->mark_refund_completed();

        if ( in_array( $current_status, array( 'cancelled', 'refunded' ), true ) ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: event type, 2: payment status */
                    __( 'GivePayments Webhook received voided event for already-cancelled/refunded order: %1$s (status: %2$s)', 'givepayments-for-woocommerce' ),
                    $event_type ? $event_type : 'unknown',
                    $payment_status ? $payment_status : 'unknown'
                )
            );
            return false;
        }

        $updated                = false;
        // H3: status-transition lock wrapped in try/finally so a crash between acquire
        // and update_status() does not leave the lock permanently (making future
        // terminal-refund webhooks silently skip the status transition).
        $refund_status_lock_key = 'gp_refund_status_' . (int) $order->get_id();
        if ( add_option( $refund_status_lock_key, '1', '', 'no' ) ) {
            $removed_fully_refunded_hook = false;
            try {
                if ( function_exists( 'wc_order_fully_refunded' )
                    && false !== has_action( 'woocommerce_order_status_refunded', 'wc_order_fully_refunded' )
                ) {
                    remove_action( 'woocommerce_order_status_refunded', 'wc_order_fully_refunded' );
                    $removed_fully_refunded_hook = true;
                }
                $order->update_status(
                    'refunded',
                    __( 'Updated by GivePayments Webhook - Payment voided', 'givepayments-for-woocommerce' )
                );
                $updated = true;
            } finally {
                if ( $removed_fully_refunded_hook ) {
                    add_action( 'woocommerce_order_status_refunded', 'wc_order_fully_refunded' );
                }
                delete_option( $refund_status_lock_key );
            }
        } else {
            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'Webhook: refunded status transition skipped for voided order %d because another terminal refund event already handled it.',
                    (int) $order->get_id()
                ),
                'debug'
            );
        }

        // Create a WooCommerce refund accounting entry for the full order amount
        // so the orders table displays $0.00 instead of the original charged amount.
        // api_refund=false prevents a second refund API call, the void was already
        // processed on the platform side.
        // H4: wc_create_refund() fires WC hooks that can throw; wrap in try/finally
        // so delete_option() always runs and the lock is never permanently orphaned.
        global $wpdb;
        $void_lock_key = 'gp_void_acct_' . (int) $order->get_id();
        $lock_won      = (bool) $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                $void_lock_key,
                '1'
            )
        );
        if ( $lock_won ) {
            try {
                $order_total      = (float) $order->get_total();
                $already_refunded = abs( (float) $order->get_total_refunded() );
                $remaining        = max( 0, $order_total - $already_refunded );
                if ( $order_total > 0 && $remaining > 0.0001 ) {
                    $void_refund_result = wc_create_refund(
                        array(
                            'amount'        => $remaining,
                            'reason'        => __( 'Payment voided via GivePayments.', 'givepayments-for-woocommerce' ),
                            'order_id'      => $order->get_id(),
                            'api_refund'    => false,
                            'restock_items' => false,
                        )
                    );
                    if ( is_wp_error( $void_refund_result ) ) {
                        GIVEPAYMENTS_Logger::log(
                            sprintf(
                                'Webhook: wc_create_refund() failed for voided order %d. Error: %s',
                                (int) $order->get_id(),
                                $void_refund_result->get_error_message()
                            ),
                            'error'
                        );
                    }
                }
            } finally {
                delete_option( $void_lock_key );
            }
        } else {
            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'Webhook: wc_create_refund() skipped for voided order %d, concurrent webhook already holds the void accounting lock.',
                    (int) $order->get_id()
                ),
                'debug'
            );
        }
        return $updated;
    }

    private function handle_webhook_failure(
        WC_Order $order,
        string $event_type,
        string $payment_status,
        string $processing_state,
        string $current_status
    ): bool {
        // The original guard was ($order->is_paid() && 'processing' !== $current_status).
        // For 'processing' orders: is_paid()=true, 'processing'!==current=false → guard did NOT
        // fire, fell through to !in_array(['failed','cancelled','refunded','completed']) which
        // also passed 'processing' → update_status('failed') ran on a paid order.
        // A late payment.failed event after payment.captured was reverting collected orders.
        // Fix: protect all statuses that represent a resolved/paid lifecycle.
        if ( in_array( $current_status, array( 'processing', 'completed', 'refunded', 'cancelled', 'failed' ), true ) ) {
            $order->add_order_note(
                sprintf(
                    /* translators: 1: event type, 2: payment status */
                    __( 'GivePayments Webhook ignored failure event for already-resolved order: %1$s (status: %2$s)', 'givepayments-for-woocommerce' ),
                    $event_type ? $event_type : 'unknown',
                    $payment_status ? $payment_status : 'unknown'
                )
            );
            return false;
        }
        $order->update_status(
            'failed',
            __( 'Updated by GivePayments Webhook - Payment failed/declined', 'givepayments-for-woocommerce' )
        );
        return true;
    }

    private function handle_webhook_refund_created(
        WC_Order $order,
        string $event_type,
        string $payment_status,
        string $processing_state,
        string $current_status
    ): bool {
        // Keep Woo status unchanged, but expose a clear pending-refund marker in admin.
        // Use RefundGuard (which saves immediately) for consistency with every other
        // place that sets the pending flag.
        GIVEPAYMENTS_Refund_Guard::mark_pending( $order );
        $refund_pending_note_key = 'gp_refund_pending_note_' . (int) $order->get_id();
        if ( add_option( $refund_pending_note_key, '1', '', 'no' ) ) {
            $order->add_order_note( __( 'Updated by GivePayments Webhook - Refund created (pending settlement)', 'givepayments-for-woocommerce' ) );
        } else {
            GIVEPAYMENTS_Logger::log(
                sprintf(
                    'Webhook: duplicate pending refund note skipped for order %d (event: %s).',
                    (int) $order->get_id(),
                    $event_type ? $event_type : 'unknown'
                ),
                'debug'
            );
        }
        return false;
    }

    private function handle_webhook_refund_failed(
        WC_Order $order,
        string $event_type,
        string $payment_status,
        string $processing_state,
        string $current_status
    ): bool {
        // Refund attempt failed, declined, or canceled at the portal.
        // The original payment is still valid, do not change order status.
        // Clear the pending guard so the merchant can retry the refund.
        GIVEPAYMENTS_Refund_Guard::clear_pending( $order );
        GIVEPAYMENTS_Refund_Guard::clear_origin( $order );
        delete_option( 'gp_refund_pending_note_' . (int) $order->get_id() );
        $order->add_order_note(
            sprintf(
                /* translators: 1: event type, 2: payment status */
                __( 'GivePayments Webhook, Refund attempt did not complete (%1$s, status: %2$s). The original payment remains valid. You may retry the refund.', 'givepayments-for-woocommerce' ),
                $event_type ? $event_type : 'unknown',
                $payment_status ? $payment_status : 'unknown'
            )
        );
        return false;
    }

    private function handle_webhook_refunded(
        WC_Order $order,
        string $event_type,
        string $payment_status,
        string $processing_state,
        string $current_status
    ): bool {
        $rec_refunded  = GIVEPAYMENTS_Payment_Record::for( $order );
        $refund_origin = $rec_refunded->refund_origin();
        $rec_refunded->mark_refund_completed();
        $refund_status_note = ( 'woocommerce_void' === $refund_origin || false !== strpos( $event_type, 'void' ) )
            ? __( 'Updated by GivePayments Webhook - Payment voided', 'givepayments-for-woocommerce' )
            : __( 'Updated by GivePayments Webhook - Payment refunded', 'givepayments-for-woocommerce' );

        // M6: clear the pending-note dedup option so future refund cycles can add the note.
        delete_option( 'gp_refund_pending_note_' . (int) $order->get_id() );

        $updated = false;
        if ( 'refunded' !== $current_status ) {
            // H3: status-transition lock wrapped in try/finally so a crash between acquire
            // and update_status() does not leave the lock permanently.
            $refund_status_lock_key = 'gp_refund_status_' . (int) $order->get_id();
            if ( add_option( $refund_status_lock_key, '1', '', 'no' ) ) {
                $removed_fully_refunded_hook = false;
                try {
                    if ( function_exists( 'wc_order_fully_refunded' )
                        && false !== has_action( 'woocommerce_order_status_refunded', 'wc_order_fully_refunded' )
                    ) {
                        remove_action( 'woocommerce_order_status_refunded', 'wc_order_fully_refunded' );
                        $removed_fully_refunded_hook = true;
                    }
                    $order->update_status( 'refunded', $refund_status_note );
                    $updated = true;
                } finally {
                    if ( $removed_fully_refunded_hook ) {
                        add_action( 'woocommerce_order_status_refunded', 'wc_order_fully_refunded' );
                    }
                    delete_option( $refund_status_lock_key );
                }
            } else {
                GIVEPAYMENTS_Logger::log(
                    sprintf(
                        'Webhook: refunded status transition skipped for order %d because another terminal refund event already handled it.',
                        (int) $order->get_id()
                    ),
                    'debug'
                );
            }
        }

        // Mirror completed platform refunds in WooCommerce accounting without
        // calling the gateway refund API a second time.
        //
        // Skip native Woo-originated refunds (origin = 'woocommerce_refund'):
        // those already produced a WC refund record before process_refund()
        // called the provider API, so creating another would double-count.
        //
        // Platform-started refunds ($refund_origin is '') and custom plugin
        // Void actions ($refund_origin = 'woocommerce_void') do not create
        // a WooCommerce refund record up front, so terminal refund webhooks mirror the
        // accounting here. Use MySQL INSERT IGNORE as an atomic mutex so only
        // one concurrent webhook delivery can create that refund record.
        // H4: wc_create_refund() fires WC hooks that can throw; try/finally ensures
        // delete_option() always runs so the accounting lock is never permanently orphaned.
        if ( '' === $refund_origin || 'woocommerce_void' === $refund_origin ) {
            global $wpdb;
            $refund_lock_key = 'gp_refund_acct_' . (int) $order->get_id();
            $lock_won        = (bool) $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
                    $refund_lock_key,
                    '1'
                )
            );
            if ( $lock_won ) {
                try {
                    $order_total        = (float) $order->get_total();
                    $already_refunded   = abs( (float) $order->get_total_refunded() );
                    $remaining_refunded = max( 0, $order_total - $already_refunded );
                    if ( $order_total > 0 && $remaining_refunded > 0.0001 ) {
                        $refund_result = wc_create_refund(
                            array(
                                'amount'        => $remaining_refunded,
                                'reason'        => ( 'woocommerce_void' === $refund_origin )
                                    ? __( 'Payment reversal processed via GivePayments.', 'givepayments-for-woocommerce' )
                                    : __( 'Refund processed via GivePayments platform.', 'givepayments-for-woocommerce' ),
                                'order_id'      => $order->get_id(),
                                'api_refund'    => false,
                                'restock_items' => false,
                            )
                        );
                        if ( is_wp_error( $refund_result ) ) {
                            GIVEPAYMENTS_Logger::log(
                                sprintf(
                                    'Webhook: wc_create_refund() failed for order %d. Error: %s',
                                    (int) $order->get_id(),
                                    $refund_result->get_error_message()
                                ),
                                'error'
                            );
                        }
                    }
                } finally {
                    // Release lock, future terminal events (refund.settled etc.) are
                    // safe no-ops because $remaining_refunded will be 0 after commit.
                    delete_option( $refund_lock_key );
                }
            } else {
                GIVEPAYMENTS_Logger::log(
                    sprintf(
                        'Webhook: wc_create_refund() skipped for order %d, concurrent webhook already holds the refund accounting lock (event: %s).',
                        (int) $order->get_id(),
                        $event_type ? $event_type : 'unknown'
                    ),
                    'debug'
                );
            }
        }

        $order->delete_meta_data( GIVEPAYMENTS_Payment_Record::META_CHARGEBACK_OPEN );
        return $updated;
    }

    private function handle_webhook_chargeback(
        WC_Order $order,
        string $event_type,
        string $payment_status,
        string $processing_state,
        string $current_status
    ): bool {
        $order->update_meta_data( GIVEPAYMENTS_Payment_Record::META_CHARGEBACK_OPEN, 'yes' );
        // Per merchant policy: never use 'on-hold'. The chargeback meta flag still
        // surfaces the dispute to the merchant via order-list filtering / admin UI.
        if ( ! in_array( $current_status, array( 'refunded', 'cancelled' ), true ) && 'pending' !== $current_status ) {
            $order->update_status(
                'pending',
                __( 'Updated by GivePayments Webhook - Chargeback opened', 'givepayments-for-woocommerce' )
            );
            return true;
        }
        $order->add_order_note( __( 'GivePayments Webhook - Chargeback opened', 'givepayments-for-woocommerce' ) );
        return false;
    }

    private function handle_webhook_chargeback_reversal(
        WC_Order $order,
        string $event_type,
        string $payment_status,
        string $processing_state,
        string $current_status
    ): bool {
        $order->delete_meta_data( GIVEPAYMENTS_Payment_Record::META_CHARGEBACK_OPEN );
        if ( $order->is_paid() && ! in_array( $current_status, array( 'refunded', 'cancelled', 'completed' ), true ) ) {
            $order->update_status(
                'completed',
                __( 'Updated by GivePayments Webhook - Chargeback reversed', 'givepayments-for-woocommerce' )
            );
            return true;
        }
        $order->add_order_note( __( 'GivePayments Webhook - Chargeback reversed', 'givepayments-for-woocommerce' ) );
        return false;
    }

    private function handle_webhook_chargeback_refund(
        WC_Order $order,
        string $event_type,
        string $payment_status,
        string $processing_state,
        string $current_status
    ): bool {
        $rec_cb_refund = GIVEPAYMENTS_Payment_Record::for( $order );
        $rec_cb_refund->clear_refund_pending();
        $rec_cb_refund->mark_refund_completed();
        $updated = false;
        if ( 'refunded' !== $current_status ) {
            // Replicate the remove_action/try-finally pattern from
            // handle_webhook_voided and handle_webhook_refunded. Without
            // the hook guard, woocommerce_order_status_refunded fires
            // wc_order_fully_refunded which creates a duplicate WC refund
            // accounting record. The status-transition lock prevents
            // concurrent chargeback-refund deliveries from both racing
            // through and creating multiple records.
            $refund_status_lock_key = 'gp_refund_status_' . (int) $order->get_id();
            if ( add_option( $refund_status_lock_key, '1', '', 'no' ) ) {
                $removed_fully_refunded_hook = false;
                try {
                    if ( function_exists( 'wc_order_fully_refunded' )
                        && false !== has_action( 'woocommerce_order_status_refunded', 'wc_order_fully_refunded' )
                    ) {
                        remove_action( 'woocommerce_order_status_refunded', 'wc_order_fully_refunded' );
                        $removed_fully_refunded_hook = true;
                    }
                    $order->update_status(
                        'refunded',
                        __( 'Updated by GivePayments Webhook - Chargeback refunded', 'givepayments-for-woocommerce' )
                    );
                    $updated = true;
                } finally {
                    if ( $removed_fully_refunded_hook ) {
                        add_action( 'woocommerce_order_status_refunded', 'wc_order_fully_refunded' );
                    }
                    delete_option( $refund_status_lock_key );
                }
            } else {
                GIVEPAYMENTS_Logger::log(
                    sprintf(
                        'Webhook: refunded status transition skipped for chargeback-refunded order %d - another terminal refund event already handled it.',
                        (int) $order->get_id()
                    ),
                    'debug'
                );
            }
        }
        $order->delete_meta_data( GIVEPAYMENTS_Payment_Record::META_CHARGEBACK_OPEN );
        return $updated;
    }

    private function handle_webhook_unknown(
        WC_Order $order,
        string $event_type,
        string $payment_status,
        string $processing_state,
        string $current_status
    ): bool {
        $order->add_order_note(
            sprintf(
                /* translators: 1: event type, 2: payment status */
                __( 'GivePayments Webhook received unhandled event: %1$s (status: %2$s)', 'givepayments-for-woocommerce' ),
                $event_type ? $event_type : 'unknown',
                $payment_status ? $payment_status : 'unknown'
            )
        );
        return false;
    }

    /**
     * Return the first non-empty value from an ordered list of header names.
     * WP_REST_Request normalises header names (lowercase; hyphens and underscores
     * are treated as equivalent), so callers may pass either form.
     *
     * @param WP_REST_Request $request
     * @param string[]        $names   Candidate header names in priority order.
     * @return string Empty string when none of the headers carry a value.
     */
    private function first_non_empty_header( WP_REST_Request $request, array $names ): string {
        foreach ( $names as $name ) {
            $val = (string) $request->get_header( $name );
            if ( '' !== $val ) {
                return $val;
            }
        }
        return '';
    }

    private function givepayments_get_payment_object( array $data ) {
        if ( ! empty( $data['data'] ) && is_array( $data['data'] ) && ! empty( $data['data']['object'] ) && is_array( $data['data']['object'] ) ) {
            return $data['data']['object'];
        }
        // Some provider payloads send data as an array of objects.
        if ( ! empty( $data['data'] ) && is_array( $data['data'] ) && isset( $data['data'][0] ) && is_array( $data['data'][0] ) ) {
            return $data['data'][0];
        }
        return array();
    }

    /**
     * Extract processingState, the canonical payment lifecycle field per the GivePayments API.
     * Checks both camelCase ('processingState') and snake_case ('processing_state') so the
     * extraction is robust to any future API serialisation changes.
     *
     * @param array $payment_object
     * @return string
     */
    private function givepayments_extract_payment_processing_state( array $payment_object ) {
        $candidates = array( 'processingState', 'processing_state' );
        foreach ( $candidates as $key ) {
            if ( isset( $payment_object[ $key ] ) && '' !== trim( (string) $payment_object[ $key ] ) ) {
                return sanitize_text_field( (string) $payment_object[ $key ] );
            }
        }
        return '';
    }

    /**
     * Extract fallback payment state from heterogeneous payload shapes.
     * Only used when processingState is absent or unrecognised, reads
     * status, displayStatus, and reversalState fields.
     *
     * @param array $payment_object
     * @return string
     */
    private function givepayments_extract_payment_status( array $payment_object ) {
        $candidates = array( 'status', 'displayStatus', '_', 'reversalState' );
        foreach ( $candidates as $key ) {
            if ( isset( $payment_object[ $key ] ) && '' !== trim( (string) $payment_object[ $key ] ) ) {
                return sanitize_text_field( (string) $payment_object[ $key ] );
            }
        }
        return '';
    }

    /**
     * Fallback lookup when webhook payload lacks external_reference.
     *
     * @param string $transaction_id
     * @return WC_Order|false
     */
    private function givepayments_find_order_by_transaction_id( $transaction_id ) {
        $transaction_id = sanitize_text_field( (string) $transaction_id );
        if ( '' === $transaction_id ) {
            return false;
        }

        $orders = wc_get_orders( array(
            'limit'        => 1,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'payment_method' => 'givepayments',
            'meta_key'     => '_givepayments_transaction_id',
            'meta_value'   => $transaction_id,
            'return'       => 'objects',
        ) );
        if ( ! empty( $orders ) ) {
            return $orders[0];
        }

        // Secondary fallback to native transaction_id.
        $orders = wc_get_orders( array(
            'limit'          => 1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'payment_method' => 'givepayments',
            'transaction_id' => $transaction_id,
            'return'         => 'objects',
        ) );
        return ! empty( $orders ) ? $orders[0] : false;
    }

    private function givepayments_is_failure_event( $event_type, $payment_status ) {
        $event_type     = strtolower( (string) $event_type );
        $payment_status = strtolower( (string) $payment_status );

        $failure_types = array( 'payment.failed', 'payment.declined', 'payment.canceled', 'payment.cancelled', 'payment.voided' );

        // Use the canonical deny-list from GIVEPAYMENTS_Api_Response.
        return in_array( $event_type, $failure_types, true )
            || GIVEPAYMENTS_Api_Response::is_terminal_failure_state( $payment_status );
    }

    /**
     * Resolve provider event/status into one normalized state for Woo reconciliation.
     *
     * Resolution order (highest → lowest priority):
     *  1. event_type      , definitive signal; checked first so an in-flight
     *                        processingState value never overrides the declared intent.
     *  2. processingState , canonical payment lifecycle (always present per API contract).
     *                        Exact values: created, authorized, pending, approved, captured,
     *                        processing, processing_issue, settled, failed, declined,
     *                        voided, canceled.
     *  3. status / reversalState, last-resort fallback for unrecognised/absent processingState.
     *
     * @param string $event_type        e.g. "payment.captured"
     * @param string $processing_state  Value of data.object.processingState.
     * @param string $payment_status    Fallback: first non-empty of status/displayStatus/reversalState.
     * @return string
     */
    private function givepayments_resolve_portal_state( $event_type, $processing_state, $payment_status ): string {
        return GIVEPAYMENTS_Webhook_State_Resolver::resolve(
            (string) $event_type,
            (string) $processing_state,
            (string) $payment_status
        );
    }

    private function givepayments_was_event_processed( WC_Order $order, $event_id ) {
        return GIVEPAYMENTS_Payment_Record::for( $order )->has_processed_event( (string) $event_id );
    }

    private function givepayments_mark_event_processed( WC_Order $order, $event_id ) {
        GIVEPAYMENTS_Payment_Record::for( $order )->record_processed_event( (string) $event_id );
    }
    public function givepayments_load_textdomain() {
        load_plugin_textdomain(
            'givepayments-for-woocommerce',
            false, 
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }
}
}

// Initialize the plugin
if ( ! function_exists( 'givepayments_init' ) ) {
function givepayments_init() {
    if ( ! class_exists( 'GIVEPAYMENTS_Plugin' ) ) {
        return null;
    }
    return GIVEPAYMENTS_Plugin::get_instance();
}
}

givepayments_init();




if ( ! function_exists( 'givepayments_redact_sensitive_log_value' ) ) {
/**
 * Recursively redact sensitive values before they are written to logs or AJAX debug responses.
 * If $value is an array, this function calls itself for each child value so nested keys like
 * data.secret_key or customer.card_number are also replaced with [REDACTED].
 */
function givepayments_redact_sensitive_log_value( $value, $key = '' ) {
    $key = strtolower( (string) $key );
    if (
        '' !== $key
        && (
            false !== strpos( $key, 'authorization' )
            || false !== strpos( $key, 'api_key' )
            || false !== strpos( $key, 'apikey' )
            || false !== strpos( $key, 'secret' )
            || false !== strpos( $key, 'signature' )
            || false !== strpos( $key, 'token' )
            || false !== strpos( $key, 'cvv' )
            || false !== strpos( $key, 'card_number' )
        )
    ) {
        return '[REDACTED]';
    }

    if ( is_array( $value ) ) {
        $redacted = array();
        foreach ( $value as $child_key => $child_value ) {
            $redacted[ $child_key ] = givepayments_redact_sensitive_log_value( $child_value, (string) $child_key );
        }
        return $redacted;
    }

    return $value;
}
}

if ( ! function_exists( 'givepayments_redact_sensitive_response_body' ) ) {
function givepayments_redact_sensitive_response_body( $raw_body ) {
    $raw_body = (string) $raw_body;
    if ( '' === $raw_body ) {
        return '';
    }

    $decoded = json_decode( $raw_body, true );
    if ( is_array( $decoded ) ) {
        $encoded = wp_json_encode( givepayments_redact_sensitive_log_value( $decoded ) );
        return false !== $encoded ? $encoded : $raw_body;
    }

    $redacted = preg_replace(
        '/("?(?:secret_key|api_key|apikey|authorization|signature|token|cvv|card_number)"?\s*[:=]\s*")([^"]*)(")/i',
        '$1[REDACTED]$3',
        $raw_body
    );

    return preg_replace(
        '/("?(?:secret_key|api_key|apikey|authorization|signature|token|cvv|card_number)"?\s*[:=]\s*)([^\s,&}]+)/i',
        '$1[REDACTED]',
        (string) $redacted
    );
}
}

// Force a clean webhook re-registration for the selected environment.
// This is the self-heal hatch when the stored webhook secret has drifted
// from what the portal holds (which causes every webhook to fail HMAC
// validation). It only deletes the env-scoped webhook bookkeeping options
// so the next "Test Connection" call goes down the full registration path
// and saves a fresh, in-sync secret.
add_action( 'wp_ajax_givepayments_reregister_webhook', function () {
    // Same nonce as Test Connection, both buttons are admin-only and use
    // the same trust boundary, so we don't need a separate nonce surface.
    check_ajax_referer( 'test_connection_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
    }

    $environment = isset( $_POST['environment'] )
        ? sanitize_text_field( wp_unslash( $_POST['environment'] ) )
        : 'test';
    if ( ! in_array( $environment, array( 'test', 'production' ), true ) ) {
        $environment = 'test';
    }

    // Clear all env-scoped webhook state so the next Test Connection call
    // does POST (not PATCH) to the portal. This is intentional: the portal
    // only sets and returns secret_key on a POST (initial creation). On PATCH
    // it silently ignores secret_key and returns null, meaning a PATCH-based
    // re-registration would leave the portal's stored secret unchanged while
    // we save a different local value, causing permanent HMAC mismatch.
    //
    // By deleting webhook_obj_id we force a fresh POST, the portal creates a
    // new registration with the new secret and echoes it back, and we persist
    // it correctly. Events for ALL orders (old and new) will be delivered to
    // every registered webhook URL; the new registration's events will pass
    // HMAC verification. Old orphaned registrations will log HMAC warnings
    // but will not block processing, they can be deleted from the portal
    // dashboard at any time as housekeeping.
    //
    // We deliberately do NOT touch the API key, merchant id, or the OTHER
    // environment's options, re-registering sandbox must never affect production.
    $deleted = array(
        'webhook_set'        => delete_option( 'givepayments_webhook_set_' . $environment ),
        'webhook_obj_id'     => delete_option( 'givepayments_webhook_obj_id_' . $environment ),
        'webhook_secret'     => delete_option( 'givepayments_webhook_secret_' . $environment ),
        'webhook_base_url'   => delete_option( 'givepayments_webhook_base_url_' . $environment ),
        'subscription_ver'   => delete_option( 'givepayments_webhook_subscription_version_' . $environment ),
    );

    GIVEPAYMENTS_Logger::log(
        sprintf(
            'Webhook re-registration requested for environment "%s". Cleared options: %s',
            $environment,
            wp_json_encode( $deleted )
        ),
        'info'
    );

    wp_send_json_success( array(
        'message'     => sprintf( 'Webhook state cleared for "%s". Triggering re-registration…', $environment ),
        'environment' => $environment,
    ) );
} );

add_action('wp_ajax_test_connection', function() {
    check_ajax_referer('test_connection_nonce', 'nonce');
    // N8: Without this check any logged-in user (subscriber, customer) who
    // obtains the nonce can decrypt the production API key and probe the
    // merchant API. Mirrors the check in wp_ajax_givepayments_reregister_webhook.
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'givepayments-for-woocommerce' ) ), 403 );
    }
    $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
    $merchant_id = isset($_POST['merchant_id']) ? sanitize_text_field(wp_unslash($_POST['merchant_id'])) : '';
    $environment = isset($_POST['environment']) ? sanitize_text_field(wp_unslash($_POST['environment'])) : 'test';

    // resolve_api_key handles both encrypted and plaintext values; the
    // local $looks_like_encrypted closure and direct givepayments_decrypt_data()
    // calls (which were never defined) are replaced by the canonical helper.
    if ( '' !== $api_key ) {
        $resolved = GIVEPAYMENTS_Request_Context::resolve_api_key( $api_key );
        if ( '' !== $resolved ) {
            $api_key = $resolved;
        }
    }
    if ( empty( $api_key ) ) {
        $encrypted = ( 'test' === $environment )
            ? get_option( 'givepayments_sandbox_api_key' )
            : get_option( 'givepayments_production_api_key' );
        if ( ! empty( $encrypted ) ) {
            $api_key = GIVEPAYMENTS_Request_Context::resolve_api_key( (string) $encrypted );
        }
    }
    $api_key = trim( str_replace( array( "\r", "\n" ), '', (string) $api_key ) );

    if (empty($api_key) || empty($merchant_id)) {
        wp_send_json_error(array('message' => 'API key and Merchant ID cannot be empty.'));
    }

    $api_base = GIVEPAYMENTS_Request_Context::api_base_url( $environment );
    $api_url  = rtrim( $api_base, '/' ) . '/merchants/' . rawurlencode( $merchant_id );

    $request_headers = array(
        'Authorization' => 'Bearer ' . $api_key,
        'X-Api-Key'     => $api_key,
        'Content-Type'  => 'application/json',
    );

    $response = wp_remote_get( $api_url, array(
        'headers'     => $request_headers,
        'timeout'     => 20,
        'redirection' => 0,
    ) );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( array(
            'message'   => 'Error communicating with GivePayments API: ' . $response->get_error_message(),
            'debug_url' => $api_url,
        ) );
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = wp_remote_retrieve_body( $response );

    if ( $response_code >= 300 && $response_code < 400 ) {
        $location = wp_remote_retrieve_header( $response, 'location' );
        wp_send_json_error( array(
            'message'      => sprintf( 'GivePayments API redirected (HTTP %d), Authorization header would be lost.', $response_code ),
            'redirect_to'  => $location,
            'debug_url'    => $api_url,
        ) );
    }

    if ( $response_code !== 200 ) {
        wp_send_json_error( array(
            'message'      => sprintf( 'GivePayments API returned HTTP %s', intval( $response_code ) ),
            'raw_response' => givepayments_redact_sensitive_response_body( $response_body ),
            'debug_url'    => $api_url,
        ) );
    }

    $data = json_decode( $response_body, true );
    if ( ! is_array( $data ) || empty( $data['status'] ) ) {
        wp_send_json_error( array(
            'message'      => 'Unexpected response from GivePayments API.',
            'raw_response' => givepayments_redact_sensitive_response_body( $response_body ),
            'debug_url'    => $api_url,
        ) );
    }

    if ( $data['status'] === 'approved' ) {
        $option_name = 'givepayments_webhook_set_' . $environment;
        $stored_webhook_url = get_option($option_name);
        $current_webhook_url = givepayments_get_correct_webhook_url();
        $subscription_version_option = 'givepayments_webhook_subscription_version_' . $environment;
        $target_subscription_version = 4;
        $current_subscription_version = (int) get_option( $subscription_version_option, 0 );

        // Send wildcard "*" so the portal sets event_types_all=true on the webhook row.
        // This is the only way to set that boolean, the API does not accept an
        // event_types_all field directly. With event_types_all=true the portal DB view
        // WHERE (w.event_types_all OR t.webhook_id IS NOT NULL) always evaluates to TRUE,
        // meaning every event type is delivered regardless of the webhook_event_types join
        // table. We filter events on our side in the webhook handler.
        //
        // Confirmed portal event names (from BE codebase):
        //   payment.pending, payment.captured, payment.settled, payment.voided, payment.void
        //   payment.failed, payment.declined
        //   refund.created, refund.approved, refund.settled  (NOT refund.succeeded)
        //   chargeback.created, chargeback.reversed, chargeback.refunded
        $event_types = array( '*' );

        $webhook_obj_id_option = 'givepayments_webhook_obj_id_' . $environment;
        $stored_webhook_obj_id = (string) get_option( $webhook_obj_id_option, '' );

        $webhooks_base_url = GIVEPAYMENTS_Request_Context::api_base_url( $environment ) . '/webhooks';
        $webhook_base_option = 'givepayments_webhook_base_url_' . $environment;
        $stored_webhook_base = (string) get_option( $webhook_base_option, '' );

        $needs_registration = (
            empty( $stored_webhook_url ) ||
            $stored_webhook_url !== $current_webhook_url ||
            $current_subscription_version < $target_subscription_version ||
            $stored_webhook_base !== $webhooks_base_url
        );

        if ( $needs_registration ) {
            $webhook_secret_option = ( 'production' === $environment )
                ? 'givepayments_webhook_secret_production'
                : 'givepayments_webhook_secret_test';
            $webhook_secret = trim( (string) get_option( $webhook_secret_option, '' ) );

            // Determine $is_new_secret BEFORE any legacy fallback. When the env-specific
            // option is empty (e.g. just cleared by the Re-register button), we must
            // generate a genuinely fresh secret, reading the legacy shared option here
            // would recycle the old 60fadfe2-era value, send it to the portal, and leave
            // HMAC permanently broken because the portal may store a different secret.
            $is_new_secret = ( '' === $webhook_secret );
            if ( $is_new_secret ) {
                // Fresh secret: 40 alphanumeric chars, no special chars, safe for all
                // HMAC implementations and passes the portal's secret_key validation.
                $webhook_secret = wp_generate_password( 40, false, false );
            }

            $request_headers = array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Api-Key'     => $api_key,
            );

            $registration_body = array(
                'url'         => $current_webhook_url,
                'description' => 'Webhook From WooCommerce',
                'event_types' => $event_types,
            );

            // If we already have a webhook ID, PATCH to update (avoids duplicate
            // webhook registrations accumulating in the portal on each re-test).
            $use_patch  = ( '' !== $stored_webhook_obj_id );

            // Only send secret_key when creating a new registration (POST) or
            // when a fresh secret was generated. Sending it on every PATCH silently
            // overwrites whatever the portal currently holds, if the merchant
            // rotated the secret directly in the portal, the PATCH would replace it
            // with the locally-stored old key, breaking HMAC on every subsequent
            // webhook delivery.
            if ( ! $use_patch || $is_new_secret ) {
                $registration_body['secret_key'] = $webhook_secret;
            }

            $remote_url = $use_patch
                ? rtrim( $webhooks_base_url, '/' ) . '/' . rawurlencode( $stored_webhook_obj_id )
                : $webhooks_base_url;

            $response = wp_remote_request( $remote_url, array(
                'method'  => $use_patch ? 'PATCH' : 'POST',
                'body'    => wp_json_encode( $registration_body ),
                'headers' => $request_headers,
                'timeout' => 15,
            ) );

            if ( is_wp_error( $response ) ) {
                GIVEPAYMENTS_Logger::log( 'Webhook registration failed with error: ' . $response->get_error_message(), 'error' );
            } else {
                $reg_code = (int) wp_remote_retrieve_response_code( $response );
                $reg_body_raw = wp_remote_retrieve_body( $response );
                GIVEPAYMENTS_Logger::log(
                    sprintf( 'Webhook registration response HTTP %d: %s', $reg_code, givepayments_redact_sensitive_response_body( $reg_body_raw ) ),
                    $reg_code < 300 ? 'debug' : 'warning'
                );

                if ( $reg_code < 300 ) {
                    // Store the webhook obj_id from the response so future
                    // re-registrations PATCH instead of POST (avoids duplicates).
                    $reg_body = json_decode( $reg_body_raw, true );
                    if ( ! empty( $reg_body['obj_id'] ) ) {
                        update_option( $webhook_obj_id_option, sanitize_text_field( $reg_body['obj_id'] ) );
                    } elseif ( ! empty( $reg_body['id'] ) ) {
                        update_option( $webhook_obj_id_option, sanitize_text_field( $reg_body['id'] ) );
                    }
                    
                    // Write ONLY to the env-specific option. Never write to the shared
                    // legacy 'givepayments_webhook_secret' option; doing so would
                    // overwrite the other environment's secret and break its HMAC checks.
                    // Persist only here, after a confirmed successful response, so the
                    // local DB secret always matches what the portal accepted.
                    // Use a strict regex instead of sanitize_text_field() to preserve
                    // every valid HMAC key character (base64, hex, alphanumeric, symbols).
                    // sanitize_text_field strips octets < 0x20 and HTML entities which
                    // can silently corrupt secrets that include those characters.
                    if ( ! empty( $reg_body['secret_key'] ) ) {
                        $candidate = (string) $reg_body['secret_key'];
                        // Portal is source of truth; use its echoed secret when it's sane.
                        if ( preg_match( '/^[A-Za-z0-9_\-+\/=]{16,256}$/', $candidate ) ) {
                            update_option( $webhook_secret_option, $candidate );
                        } else {
                            // Portal returned an unexpected format; fall back to the locally
                            // generated secret that was sent in the registration body.
                            update_option( $webhook_secret_option, $webhook_secret );
                        }
                    } else {
                        // Portal did not echo the secret back (PATCH responses return
                        // secret_key: null by design). The secret we sent in the request
                        // body IS the active secret on the portal, persist it locally
                        // now that HTTP < 300 confirms the portal accepted it.
                        update_option( $webhook_secret_option, $webhook_secret );
                    }
                    // Log the fingerprint of the secret now stored so it can be
                    // compared against the secret_fp printed in verify logs.
                    $stored_secret_fp = substr( hash( 'sha256', (string) get_option( $webhook_secret_option, '' ) ), 0, 8 );
                    GIVEPAYMENTS_Logger::log(
                        sprintf(
                            'Webhook registration secret stored: env=%s, option=%s, method=%s, fp=%s',
                            $environment,
                            $webhook_secret_option,
                            $use_patch ? 'PATCH' : 'POST',
                            $stored_secret_fp
                        ),
                        'info'
                    );
                    update_option( $webhook_base_option, $webhooks_base_url );
                    update_option( $option_name, $current_webhook_url );
                    update_option( $subscription_version_option, $target_subscription_version );
                } elseif ( $use_patch && $reg_code >= 400 && $reg_code < 500 ) {
                    // Stored webhook id may be stale or PATCH may be unsupported.
                    // For any client error on PATCH, clear the stored id and retry
                    // with POST to create a fresh registration immediately.
                    delete_option( $webhook_obj_id_option );
                    $fallback = wp_remote_post( $webhooks_base_url, array(
                        'method'  => 'POST',
                        'body'    => wp_json_encode( $registration_body ),
                        'headers' => $request_headers,
                        'timeout' => 15,
                    ) );
                    if ( is_wp_error( $fallback ) ) {
                        GIVEPAYMENTS_Logger::log(
                            'Webhook registration fallback POST failed with error: ' . $fallback->get_error_message(),
                            'error'
                        );
                    } else {
                        $fb_code = (int) wp_remote_retrieve_response_code( $fallback );
                        $fb_body_raw = wp_remote_retrieve_body( $fallback );
                        GIVEPAYMENTS_Logger::log(
                            sprintf( 'Webhook registration fallback POST HTTP %d: %s', $fb_code, givepayments_redact_sensitive_response_body( $fb_body_raw ) ),
                            $fb_code < 300 ? 'debug' : 'warning'
                        );
                        if ( $fb_code < 300 ) {
                            $fb_body = json_decode( $fb_body_raw, true );
                            if ( ! empty( $fb_body['obj_id'] ) ) {
                                update_option( $webhook_obj_id_option, sanitize_text_field( $fb_body['obj_id'] ) );
                            } elseif ( ! empty( $fb_body['id'] ) ) {
                                update_option( $webhook_obj_id_option, sanitize_text_field( $fb_body['id'] ) );
                            }
                            // Same pattern as primary success path.
                            // Persist only after confirmed success, write only to the
                            // env-specific option, and use a strict regex instead of
                            // sanitize_text_field to preserve all valid HMAC key characters.
                            if ( ! empty( $fb_body['secret_key'] ) ) {
                                $candidate = (string) $fb_body['secret_key'];
                                if ( preg_match( '/^[A-Za-z0-9_\-+\/=]{16,256}$/', $candidate ) ) {
                                    update_option( $webhook_secret_option, $candidate );
                                } else {
                                    update_option( $webhook_secret_option, $webhook_secret );
                                }
                            } else {
                                // Portal did not echo the secret back, persist the secret
                                // we sent, which the portal accepted (HTTP < 300 confirmed).
                                update_option( $webhook_secret_option, $webhook_secret );
                            }
                            // Log the fingerprint of the secret now stored so it can be
                            // compared against the secret_fp printed in verify logs.
                            $stored_secret_fp = substr( hash( 'sha256', (string) get_option( $webhook_secret_option, '' ) ), 0, 8 );
                            GIVEPAYMENTS_Logger::log(
                                sprintf(
                                    'Webhook registration secret stored: env=%s, option=%s, method=POST(fallback), fp=%s',
                                    $environment,
                                    $webhook_secret_option,
                                    $stored_secret_fp
                                ),
                                'info'
                            );
                            update_option( $webhook_base_option, $webhooks_base_url );
                            update_option( $option_name, $current_webhook_url );
                            update_option( $subscription_version_option, $target_subscription_version );

                            // Delete the superseded registration from the portal so it stops
                            // firing events signed with its own (now-unknown) secret. Without
                            // this, every PATCH-failure/POST-fallback cycle orphans another
                            // webhook registration and creates a new source of HMAC failures.
                            if ( '' !== $stored_webhook_obj_id ) {
                                $stale_delete_url = rtrim( $webhooks_base_url, '/' ) . '/' . rawurlencode( $stored_webhook_obj_id );
                                $stale_del_resp   = wp_remote_request( $stale_delete_url, array(
                                    'method'  => 'DELETE',
                                    'headers' => $request_headers,
                                    'timeout' => 10,
                                ) );
                                if ( is_wp_error( $stale_del_resp ) ) {
                                    GIVEPAYMENTS_Logger::log(
                                        'Failed to delete superseded webhook registration ' . $stored_webhook_obj_id . ': ' . $stale_del_resp->get_error_message(),
                                        'warning'
                                    );
                                } else {
                                    $del_code = (int) wp_remote_retrieve_response_code( $stale_del_resp );
                                    GIVEPAYMENTS_Logger::log(
                                        sprintf(
                                            'Deleted superseded webhook registration %s: HTTP %d',
                                            $stored_webhook_obj_id,
                                            $del_code
                                        ),
                                        ($del_code < 300 || 404 === $del_code) ? 'info' : 'warning'
                                    );
                                }
                            }
                        }
                    }
                }
            }
        }

        // Guard against provider responses that omit image_url or name fields.
        // Direct array access without isset() causes undefined-index PHP notice
        // that aborts wp_send_json_success before the capability options are updated.
        $image_url     = isset( $data['image_url'] ) ? (string) $data['image_url'] : '';
        $merchant_name = isset( $data['name'] )       ? (string) $data['name']       : '';
        update_option( 'givepayments_image_url', esc_url_raw( $image_url ) );
        update_option( 'givepayments_merchant_name', sanitize_text_field( $merchant_name ) );
        // Guard against providers returning status=approved without a capabilities key.
        // Direct array access without isset() causes undefined-index PHP error -> 500
        // response -> capability option never updated in WP.
        $capabilities   = isset( $data['capabilities'] ) ? $data['capabilities'] : array();
        $cap_payments   = isset( $capabilities['payments'] ) ? $capabilities['payments'] : array();
        $cap_payouts    = isset( $capabilities['payouts'] )  ? $capabilities['payouts']  : array();
        $cap_pay_status = isset( $cap_payments['status'] )   ? (string) $cap_payments['status'] : 'unknown';
        $cap_out_status = isset( $cap_payouts['status'] )    ? (string) $cap_payouts['status']  : 'unknown';
        $cap_status     = sanitize_text_field( $cap_pay_status );
        // Per-environment: sandbox connection test must not block production keys (and vice versa).
        $cap_option = ( 'production' === $environment )
            ? 'givepayments_can_process_money_production'
            : 'givepayments_can_process_money_test';
        update_option( $cap_option, $cap_status );
        update_option( 'givepayments_can_process_money', $cap_status );
        wp_send_json_success(array(
            'message'          => 'Connection successful',
            'status'           => ( $data['status'] ),
            'canTransferMoney' => ( 'enabled' === $cap_out_status ),
            'canProcessMoney'  => ( 'enabled' === $cap_pay_status ),
        ));
    }

    wp_send_json_error( array(
        'message'      => sprintf( 'Merchant status is "%s", expected "approved".', $data['status'] ),
        'raw_response' => givepayments_redact_sensitive_response_body( $response_body ),
    ) );
});

/**
 * Legacy return handler, kept only for backward compatibility with orders
 * placed before the v1.0.1 direct-checkout migration. Since v1.0.1 all
 * payments go through process_payment() and never redirect to the hosted
 * portal, so this code path is effectively dead for new orders.
 *
 * Disabled by default. To re-enable for sites that still have in-flight
 * legacy orders, set the 'givepayments_enable_legacy_return' option to 'yes'.
 */
add_action( 'template_redirect', 'givepayments_handle_return' );

if ( ! function_exists( 'givepayments_handle_return' ) ) {
function givepayments_handle_return() {
    if ( 'yes' !== get_option( 'givepayments_enable_legacy_return', 'no' ) ) {
        return;
    }

    if ( ! isset( $_GET['order_id'] ) || ! isset( $_GET['status'] ) ) {
        return;
    }

    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'givepayments_return' ) ) {
        wp_die(
            esc_html__( 'Security check failed.', 'givepayments-for-woocommerce' ),
            esc_html__( 'Security Error', 'givepayments-for-woocommerce' ),
            array( 'response' => 403 )
        );
    }

    $order_id = absint( wp_unslash( $_GET['order_id'] ) );
    $status   = sanitize_text_field( wp_unslash( $_GET['status'] ) );

    $gateways = WC()->payment_gateways->payment_gateways();
    if ( ! isset( $gateways['givepayments'] ) ) {
        return;
    }

    /** @var GIVEPAYMENTS_Gateway $gateway */
    $gateway = $gateways['givepayments'];

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    if ( 'givepayments' !== $order->get_payment_method() ) {
        return;
    }
    if ( ! isset( $gateway->enabled ) || 'yes' !== $gateway->enabled ) {
        return;
    }
    $order_key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
    if ( '' !== $order_key && $order_key !== $order->get_order_key() ) {
        return;
    }

    // Reject if the order already has a transaction (prevents replay).
    if ( '' !== (string) $order->get_transaction_id() || '' !== GIVEPAYMENTS_Payment_Record::for( $order )->transaction_id() ) {
        return;
    }

    if ( 'success' === $status ) {
        if ( in_array( $order->get_status(), array( 'pending' ), true ) ) {
            $rec_legacy = GIVEPAYMENTS_Payment_Record::for( $order );
            $rec_legacy->mark_payment_initiated();

            $order->payment_complete();
            // payment_complete() already calls wc_reduce_stock_levels() internally.
            $order->update_status( 'processing', __( 'Payment captured via GivePayments (legacy return URL).', 'givepayments-for-woocommerce' ) );

            if ( WC()->cart ) {
                WC()->cart->empty_cart();
            }

            wp_redirect( $gateway->get_return_url( $order ) );
            exit;
        }
    } else {
        if ( 'pending' === $order->get_status() ) {
            GIVEPAYMENTS_Payment_Record::for( $order )->mark_payment_failed();
            $order->save();
        }
        wc_add_notice( __( 'Payment unsuccessful. Please retry.', 'givepayments-for-woocommerce' ), 'error' );
        wp_redirect( wc_get_checkout_url() );
        exit;
    }
}
}

//
add_action( 'woocommerce_blocks_loaded', 'givepayments_gateway_block_support' );
if ( ! function_exists( 'givepayments_gateway_block_support' ) ) {
function givepayments_gateway_block_support() {
    if( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
    	return;
    }

   // here we're including our "gateway block support class"
   if ( ! class_exists( 'GIVEPAYMENTS_Gateway_Blocks_Support' ) ) {
       require_once __DIR__ . '/includes/class-givepayments-gateway-blocks-support.php';
   }

   // registering the PHP class we have just included
   add_action(
       'woocommerce_blocks_payment_method_type_registration',
       function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
           $payment_method_registry->register( new GIVEPAYMENTS_Gateway_Blocks_Support );
       }
   );

}
}


add_action( 'before_woocommerce_init', 'givepayments_cart_checkout_blocks_compatibility' );

if ( ! function_exists( 'givepayments_cart_checkout_blocks_compatibility' ) ) {
function givepayments_cart_checkout_blocks_compatibility() {

   if( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
       \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
           'cart_checkout_blocks',
           __FILE__,
           true // true (compatible, default) or false (not compatible)
       );
   }
// Ensure WooCommerce loads payment gateways for checkout blocks
add_filter('woocommerce_should_load_checkout_block_payment_gateways', '__return_true');

}
}

/**
 * Determine correct webhook URL path based on permalink structure
 *
 * @return string The correctly formatted webhook URL
 */
if ( ! function_exists( 'givepayments_get_correct_webhook_url' ) ) {
function givepayments_get_correct_webhook_url() {
    // Base endpoint
    $endpoint = 'wp-json/givepayments/v1/capture';

    // Test if pretty permalinks are working by checking for redirects
    $test_url = get_home_url() . '/' . $endpoint;
    $response = wp_remote_head($test_url, array('timeout' => 5));

    // If we get a successful status code, pretty permalinks are working
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) < 400) {
        return $test_url;
    }

    // Fallback to including index.php in the path
    return get_home_url() . '/index.php/' . $endpoint;
}
}

/**
 * Resolve current admin order ID (legacy + HPOS screens).
 *
 * @return int
 */
function givepayments_get_current_admin_order_id() {
    if ( isset( $_GET['id'] ) ) {
        return absint( wp_unslash( $_GET['id'] ) );
    }
    if ( isset( $_GET['post'] ) ) {
        return absint( wp_unslash( $_GET['post'] ) );
    }
    return 0;
}

/**
 * Determine if refund actions should be hidden on this admin order screen.
 *
 * @return bool
 */
function givepayments_should_hide_refund_actions() {
    if ( ! is_admin() ) {
        return false;
    }

    $order_id = givepayments_get_current_admin_order_id();
    if ( $order_id <= 0 ) {
        return false;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order || 'givepayments' !== $order->get_payment_method() ) {
        return false;
    }

    $rec_guard        = GIVEPAYMENTS_Payment_Record::for( $order );
    $is_refund_completed = $rec_guard->is_refund_completed();
    $is_refund_pending   = $rec_guard->is_refund_pending();
    $is_refunded_status  = 'refunded' === $order->get_status();

    $order_total = (float) $order->get_total();
    $refunded_total = abs( (float) $order->get_total_refunded() );
    $is_fully_refunded = $order_total > 0 && $refunded_total >= ( $order_total - 0.0001 );

    return $is_refund_completed || $is_refund_pending || $is_refunded_status || $is_fully_refunded;
}

/**
 * Whether current admin order screen is a GivePayments order.
 *
 * @return bool
 */
function givepayments_is_current_admin_givepayments_order() {
    if ( ! is_admin() ) {
        return false;
    }
    $order_id = givepayments_get_current_admin_order_id();
    if ( $order_id <= 0 ) {
        return false;
    }
    $order = wc_get_order( $order_id );
    return $order && 'givepayments' === $order->get_payment_method();
}

/**
 * Hide refund actions for GivePayments orders after provider refund flow starts/completes.
 */
add_action( 'admin_footer', function() {
    if ( ! givepayments_should_hide_refund_actions() ) {
        return;
    }
    ?>
    <script>
        (function() {
            function hideRefundActions() {
                var selectors = [
                    '.do-manual-refund',
                    '.do-api-refund',
                    'button[name="refund_amount_manual"]',
                    'button[name="refund_amount"]',
                    '.refund-actions .button'
                ];

                selectors.forEach(function(selector) {
                    document.querySelectorAll(selector).forEach(function(node) {
                        if (!node) {
                            return;
                        }
                        var text = (node.textContent || '').toLowerCase();
                        if (
                            node.classList.contains('do-manual-refund') ||
                            node.classList.contains('do-api-refund') ||
                            /manual/.test(text) ||
                            /via givepayments/.test(text)
                        ) {
                            node.style.display = 'none';
                            node.disabled = true;
                        }
                    });
                });
            }

            hideRefundActions();
            // Run a few bounded retries for late-rendered admin nodes without
            // attaching a long-lived body observer (avoids admin render loops).
            var tries = 0;
            var maxTries = 8;
            var retry = function() {
                tries++;
                hideRefundActions();
                if (tries < maxTries) {
                    setTimeout(retry, 250);
                }
            };
            setTimeout(retry, 250);
        }());
    </script>
    <?php
} );

/**
 * Enforce full-refund-only UX for GivePayments orders in Woo admin.
 * (Plugin supports full transaction refunds only.)
 */
add_action( 'admin_footer', function() {
    if ( ! givepayments_is_current_admin_givepayments_order() || givepayments_should_hide_refund_actions() ) {
        return;
    }

    $order_id = givepayments_get_current_admin_order_id();
    $order    = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    $available = (float) $order->get_total() - abs( (float) $order->get_total_refunded() );
    if ( $available < 0.01 ) {
        return;
    }
    $available_str = number_format( $available, 2, '.', '' );
    ?>
    <script>
        (function($) {
            var GP_REFUND_AMOUNT = <?php echo wp_json_encode( $available_str ); ?>;

            function lockInput(el) {
                el.setAttribute('readonly', 'readonly');
                el.setAttribute('tabindex', '-1');
                el.style.backgroundColor = '#f6f7f7';
                el.style.cursor = 'not-allowed';
            }

            function applyFullRefundOnlyUI() {
                var amountInput = document.getElementById('refund_amount');
                if (!amountInput) return;

                amountInput.value = GP_REFUND_AMOUNT;
                lockInput(amountInput);
                amountInput.style.opacity = '1';
                amountInput.title = 'GivePayments supports full-order refunds only.';

                var reasonField = document.getElementById('refund_reason');

                var lineInputs = document.querySelectorAll(
                    '.wc-order-refund-items input[type="number"],' +
                    '.wc-order-refund-items input[type="text"],' +
                    '.refund input.text,' +
                    'td.quantity input,' +
                    'td.line_cost input,' +
                    'td.line_tax input,' +
                    '.refund_line_total,' +
                    '.refund_line_tax,' +
                    '.refund_order_item_qty'
                );
                for (var i = 0; i < lineInputs.length; i++) {
                    if (lineInputs[i] !== amountInput && lineInputs[i] !== reasonField) {
                        lineInputs[i].value = '';
                        lockInput(lineInputs[i]);
                    }
                }

                var apiBtn = document.querySelector('.do-api-refund');
                if (apiBtn) {
                    apiBtn.textContent = 'Refund full order via GivePayments';
                }

                var manualBtn = document.querySelector('.do-manual-refund');
                if (manualBtn) {
                    manualBtn.style.display = 'none';
                }
            }

            // Intercept Woo's refund AJAX to force the PHP-computed amount.
            if ($ && $.ajaxPrefilter) {
                $.ajaxPrefilter(function(options) {
                    if (!options.data || typeof options.data !== 'string') return;
                    if (options.data.indexOf('action=woocommerce_refund_line_items') === -1) return;
                    if (options.data.indexOf('api_refund=true') === -1) return;

                    if (typeof URLSearchParams === 'function') {
                        var params = new URLSearchParams(options.data);

                        params.set('refund_amount', GP_REFUND_AMOUNT);

                        // WooCommerce sends refund line allocations as JSON strings.
                        // If they stay populated while we also force a full refund_amount,
                        // wc_create_refund() can count both inputs in the same refund.
                        params.set('line_item_qtys', '{}');
                        params.set('line_item_totals', '{}');
                        params.set('line_item_tax_totals', '{}');

                        options.data = params.toString();
                        return;
                    }

                    options.data = options.data
                        .replace(/refund_amount=[^&]*/, 'refund_amount=' + encodeURIComponent(GP_REFUND_AMOUNT))
                        .replace(/line_item_qtys=[^&]*/, 'line_item_qtys=' + encodeURIComponent('{}'))
                        .replace(/line_item_totals=[^&]*/, 'line_item_totals=' + encodeURIComponent('{}'))
                        .replace(/line_item_tax_totals=[^&]*/, 'line_item_tax_totals=' + encodeURIComponent('{}'));
                });
            }

            // Override the input's value setter so WooCommerce's own JS
            // cannot silently overwrite the amount after the panel opens.
            var amountEl = document.getElementById('refund_amount');
            if (amountEl) {
                var desc = Object.getOwnPropertyDescriptor(
                    HTMLInputElement.prototype, 'value'
                );
                if (desc && desc.set) {
                    Object.defineProperty(amountEl, 'value', {
                        get: function() { return desc.get.call(this); },
                        set: function(v) {
                            desc.set.call(this, GP_REFUND_AMOUNT);
                        },
                        configurable: true
                    });
                }
            }

            applyFullRefundOnlyUI();
            var tries = 0;
            var retry = function() {
                if (++tries > 8) return;
                applyFullRefundOnlyUI();
                setTimeout(retry, 250);
            };
            setTimeout(retry, 250);
        }(window.jQuery));
    </script>
    <?php
} );


