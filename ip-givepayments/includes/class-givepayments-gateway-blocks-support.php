<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class GIVEPAYMENTS_Gateway_Blocks_Support extends AbstractPaymentMethodType {

    private $gateway;

    protected $name = 'givepayments';

    public function initialize() {
        $this->settings = get_option( "woocommerce_givepayments_settings", array() );
        $gateways = array();
        if ( function_exists( 'WC' ) && WC() && WC()->payment_gateways() ) {
            $gateways = WC()->payment_gateways()->payment_gateways();
        }
        $this->gateway = isset( $gateways[ $this->name ] ) ? $gateways[ $this->name ] : null;
    }

    public function is_active() {
        if ( empty( $this->settings['enabled'] ) || 'yes' !== $this->settings['enabled'] ) {
            return false;
        }

        // This integration class is only registered for GivePayments Blocks support,
        // so if the gateway is enabled we can safely expose it in Blocks.
        // Avoid strict runtime checks against instantiated gateway internals because
        // those objects can be unavailable at this stage in some checkout requests.
        return true;
    }

    public function get_payment_method_script_handles() {
        wp_register_script(
            'givepayments-blocks-script',
            plugin_dir_url( __FILE__ ) . 'block/checkout.js',
            array(
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ),
            GIVEPAYMENTS_VERSION,
            true
        );

        return array( 'givepayments-blocks-script' );

    }

    public function get_payment_method_data() {
        $supports = array( 'products' );
        if ( $this->gateway && ! empty( $this->gateway->supports ) && is_array( $this->gateway->supports ) ) {
            $supports = $this->gateway->supports;
        }

        return array(
            'title'        => $this->get_setting( 'title' ),
            'description'  => $this->get_setting( 'description' ),
            'supports'     => $supports,
        );
    }
}