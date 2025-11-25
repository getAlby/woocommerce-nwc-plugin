<?php
/**
 * WooCommerce Blocks support for MyWC Gateway
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_MyWC_Blocks_Support extends AbstractPaymentMethodType {

    // The gateway instance.
    private $gateway;

    // Payment method name/id/slug.
    protected $name = 'mywc';

    // Initializes the payment method type.
    public function initialize() {
        $this->settings = get_option( 'woocommerce_mywc_settings', [] );
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway  = isset( $gateways[$this->name] ) ? $gateways[$this->name] : null;
        
        // Debug logging
        if ( class_exists( 'WC_Logger' ) ) {
            $logger = new WC_Logger();
            $logger->add( 'mywc', 'WC_MyWC_Blocks_Support initialized' );
        }
    }

    // Returns if this payment method should be active.
    public function is_active() {
        return ! is_null( $this->gateway ) && $this->gateway->is_available();
    }

    // Returns an array of scripts/handles to be registered for this payment method.
    public function get_payment_method_script_handles() {
        $script_path = 'assets/js/mywc-blocks.js';
        $script_url = plugins_url( $script_path, dirname( __FILE__ ) );
        
        // Only register script if the file exists
        if ( file_exists( plugin_dir_path( dirname( __FILE__ ) ) . $script_path ) ) {
            wp_register_script(
                'mywc-gateway-blocks',
                $script_url,
                array(
                    'wc-blocks-registry',
                    'wc-settings',
                    'wp-element',
                    'wp-html-entities',
                    'wp-i18n',
                ),
                '0.1',
                true
            );
            
            return [ 'mywc-gateway-blocks' ];
        }
        
        return [];
    }

    // Returns an array of key=>value pairs of data made available to the payment methods script.
    public function get_payment_method_data() {
        return [
            'title' => $this->get_setting( 'title' ),
            'description' => $this->get_setting( 'description' ),
            'supports' => $this->gateway ? $this->gateway->supports : []
        ];
    }
}