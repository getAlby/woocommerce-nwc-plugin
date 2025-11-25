
<?php
/**
 * Plugin Name: WooCommerce Nostr Wallet Connect (NWC) Bitcoin Lightning Payment Gateway
 * Description: Accept lightning payments directly to your own wallet using NWC
 * Version: 0.1.0
 * Author: Alby Team
 */

require_once __DIR__ . '/vendor/autoload.php';
use NWC\Client;
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Initialize plugin on plugins_loaded
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>WooCommerce is required for My WC Payment Gateway.</p></div>';
        } );
        return;
    }

    class WC_Gateway_MyWC extends WC_Payment_Gateway {
    public function __construct() {
        $this->id                 = 'mywc';
        $this->method_title       = __( 'MyWC Gateway', 'my-wc-plugin' );
        $this->method_description = __( 'A local/dev payment gateway scaffold.', 'my-wc-plugin' );
        $this->has_fields         = false;

        $this->supports = array(
            'products',
            'blocks'
        );

        // Load settings.
        $this->init_form_fields();
        $this->init_settings();

        // Get settings values.
        $this->title       = $this->get_option( 'title', 'Credit Card (MyWC)' );
        $this->description = $this->get_option( 'description', '' );
        $this->enabled     = $this->get_option( 'enabled', 'no' );
        $this->testmode    = $this->get_option( 'testmode', 'no' );
        $this->api_key     = $this->get_option( 'api_key', '' );

        

        // Hooks.
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        //add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

        // REST API endpoints
        add_action( 'rest_api_init', function() {
            // Invoice display endpoint
            register_rest_route( 'mywc/v1', '/invoice/(?P<order_key>[a-zA-Z0-9_-]+)', array(
                'methods'  => 'GET',
                'callback' => array( $this, 'invoice_display_handler' ),
                'permission_callback' => '__return_true',
            ) );
            
            // Payment status polling endpoint
            register_rest_route( 'mywc/v1', '/payment-status/(?P<order_key>[a-zA-Z0-9_-]+)', array(
                'methods'  => 'GET',
                'callback' => array( $this, 'payment_status_handler' ),
                'permission_callback' => '__return_true',
            ) );
        } );
    }

    public function validate_api_key_field( $key, $value ) {
        // don't change the value (otherwise the NWC url is incorrectly encoded)
        return $value;
    }

    public function payment_scripts() {

    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'my-wc-plugin' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable MyWC Payment', 'my-wc-plugin' ),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __( 'Title', 'my-wc-plugin' ),
                'type'        => 'text',
                'description' => __( 'Title shown at checkout.', 'my-wc-plugin' ),
                'default'     => __( 'Credit Card (MyWC)', 'my-wc-plugin' ),
            ),
            'description' => array(
                'title'       => __( 'Description', 'my-wc-plugin' ),
                'type'        => 'textarea',
                'default'     => '',
            ),
            'testmode' => array(
                'title'       => __( 'Test mode', 'my-wc-plugin' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable test mode', 'my-wc-plugin' ),
                'default'     => 'yes',
            ),
            'api_key' => array(
                'title'       => __( 'API Key', 'my-wc-plugin' ),
                'type'        => 'text',
                'description' => __( 'API key for the payment provider.', 'my-wc-plugin' ),
                'default'     => '',
            ),
        );
    }

    public function admin_options() {
        echo '<h2>' . esc_html( $this->method_title ) . '</h2>';
        echo '<table class="form-table">';
        $this->generate_settings_html();
        echo '</table>';
    }

    public function payment_fields() {
        if ( $this->description ) {
            echo wpautop( wp_kses_post( $this->description ) );
        }
        // If you need card fields, build them here or integrate Elements/Stripe.js.
    }

    public function validate_fields() {
        return true;
    }

    private function get_satoshi_amount( $amount, $currency ) {
        $url = 'https://getalby.com/api/rates/' . strtolower( $currency ) . '.json';
        $response = wp_remote_get( $url );

        if ( is_wp_error( $response ) ) {
            throw new Exception( 'Failed to fetch rate: ' . $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! isset( $data['rate_float'] ) ) {
            throw new Exception( 'Invalid rate data from API' );
        }

        $rate = $data['rate_float'];
        return (int) ( ( $amount / $rate ) * 100000000 );
    }

    public function process_payment( $order_id ) {
        try {
        $order = wc_get_order( $order_id );
        $satoshi_amount = $this->get_satoshi_amount( $order->get_total(), get_woocommerce_currency() );

        $client = new NWC\Client($this->api_key);
        $client->init();
        $invoiceResponse = $client->addInvoice([
            'value' => $satoshi_amount,
            'memo' => 'Order #' . $order->get_order_number(),
        ]);
        $invoice = $invoiceResponse['payment_request'];
        $payment_hash = $invoiceResponse['r_hash'];
        
        // Store invoice and payment details in order metadata
        $order->update_meta_data( '_mywc_invoice', $invoice );
        $order->update_meta_data( '_mywc_payment_hash', $payment_hash );
        $order->update_meta_data( '_mywc_invoice_timestamp', time() );
        
        // Set order to pending (waiting for payment)
        $order->update_status( 'pending', __( 'Awaiting Lightning payment.', 'my-wc-plugin' ) );
        wc_reduce_stock_levels( $order_id );
        $order->save();

        // Log
        $this->log( sprintf( 'Order %d created with Lightning invoice, hash=%s', $order_id, $payment_hash ) );

        // Redirect to invoice display page instead of thank you page
        $invoice_url = rest_url( 'mywc/v1/invoice/' . $order->get_order_key() );
        return array(
            'result'   => 'success',
            'redirect' => $invoice_url,
        );
        } catch (\Exception $e) {
            $this->log('Failed to process payment: ' . $e->getMessage());
            throw $e;
        }
    }

    public function invoice_display_handler( $request ) {
        $order_key = $request->get_param( 'order_key' );
        $order = wc_get_order( wc_get_order_id_by_order_key( $order_key ) );
        
        if ( ! $order ) {
            wp_die( 'Invalid order', 'Order Not Found', array( 'response' => 404 ) );
            return;
        }
        
        $invoice = $order->get_meta( '_mywc_invoice' );
        $order_id = $order->get_id();
        $order_total = $order->get_total();
        $status_url = rest_url( 'mywc/v1/payment-status/' . $order_key );
        
        // Output HTML directly
        header( 'Content-Type: text/html; charset=utf-8' );
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lightning Payment - Order #' . esc_html( $order_id ) . '</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .invoice-container {  }
        .invoice-string { word-break: break-all; background: white; padding: 15px; border: 1px solid #ccc; border-radius: 4px; font-family: monospace; font-size: 12px; }
        .status { margin-top: 20px; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; text-align: center; }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #f3f3f3; border-top: 2px solid #3498db; border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
    <script type="module">
        import "https://esm.sh/@getalby/bitcoin-connect@^3.11.4";
        setInterval(async () => {
            try {
                const orderKey = "' . esc_js( $order_key ) . '";
                const statusUrl = "' . esc_url( $status_url ) . '";
                fetch(statusUrl)
                    .then(response => response.json())
                    .then(data => {
                        if (data.paid) {
                            document.getElementById("payment")?.setAttribute("paid", true);
                            setTimeout(() => {
                                window.location.href = data.redirect;
                            }, 3000);
                        }
                    });
                
            } catch (error) {
                console.error("Verification error:", error);
            }
        }, 3000);
    </script>
</head>
<body>
    <div class="invoice-container">
        <bc-payment id="payment" payment-methods="external" invoice=' . esc_html( $invoice ) . '></bc-payment>
    </div>
    
    <script>
        
</body>
</html>';
        exit;
    }
    
    public function payment_status_handler( $request ) {
        $order_key = $request->get_param( 'order_key' );
        $order = wc_get_order( wc_get_order_id_by_order_key( $order_key ) );
        
        if ( ! $order ) {
            return new WP_REST_Response( array( 'error' => 'Invalid order' ), 404 );
        }
        
        $payment_hash = $order->get_meta( '_mywc_payment_hash' );
        $is_paid = false;

        try {
            $client = new NWC\Client($this->api_key);
            $client->init();
            $invoice = $client->getInvoice($payment_hash);
            $settled = !empty($invoice["settled_at"]);

            if ($settled) {
                $is_paid = true;
                $order->payment_complete();
                $order->add_order_note('Payment completed via NWC.');
            }
        } catch (\Exception $e) {
            $this->log('Failed to check payment status: ' . $e->getMessage());
        }
        
        $response = array(
            'paid' => $is_paid,
        );
        
        if ( $is_paid ) {
            $response['redirect'] = $order->get_checkout_order_received_url();
        }
        
        return new WP_REST_Response( $response, 200 );
    }

    protected function log( $message ) {
        if ( class_exists( 'WC_Logger' ) ) {
            $logger = new WC_Logger();
            $logger->add( 'mywc', $message );
        } else {
            error_log( $message );
        }
    }

    public function is_available() {
        $this->log( 'is_available() called. Enabled: ' . $this->enabled );
        
        // Check if enabled
        if ( 'no' === $this->enabled ) {
            $this->log( 'Gateway disabled, returning false' );
            return false;
        }
        
        // Log the context we're being called from
        if ( is_checkout() ) {
            $this->log( 'Called from checkout page' );
        }
        
        if ( is_admin() ) {
            $this->log( 'Called from admin' );
        }
        
        $this->log( 'Gateway is available, returning true' );
        return true;
    }
    }

    // Register gateway with WooCommerce
    add_filter( 'woocommerce_payment_gateways', function( $methods ) {
        $methods[] = 'WC_Gateway_MyWC';
        return $methods;
    } );
} );

// Declare WooCommerce HPOS and Blocks compatibility
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );

// Register WooCommerce Blocks support
add_action( 'woocommerce_blocks_loaded', function() {
    if ( class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-mywc-blocks-support.php';
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                $payment_method_registry->register( new WC_MyWC_Blocks_Support() );
            }
        );
    }
} );



