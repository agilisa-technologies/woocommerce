<?php
/*
Plugin Name: WooCommerce Agilpay Gateway
Description: Conector para WooCommerce para el gateway de pago Agilpay.
Version: 1.2.0
Author: Agilisa Technologies
Requires Plugins: woocommerce
WC requires at least: 8.2
WC tested up to: 10.9.4
Requires at least: 6.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// The response handler used to carry its own Plugin Name header, which made
// WordPress list it as a second plugin. A merchant who activated only the
// gateway got a checkout that charged the card but never confirmed the order.
require_once __DIR__ . '/agilpay-response-handler.php';

// Declarar compatibilidad con HPOS y Block Checkout
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
});

// Incluir la clase del gateway de pago
add_action('plugins_loaded', 'init_agilpay_gateway');

function init_agilpay_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Agilpay extends WC_Payment_Gateway {
        private $logger;

        public function __construct() {
            $this->id = 'agilpay';
            $this->icon = 'https://agilisa.wpenginepowered.com/wp-content/uploads/2024/01/favicon-150x150.png'; // URL del icono del método de pago
            $this->has_fields = false;
            $this->method_title = 'Agilpay';
            $this->method_description = 'Paga con Agilpay';

            // Initialize logger
            $this->logger = wc_get_logger();

            // Cargar la configuración
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->site_id = $this->get_option('site_id');
            $this->site_password = $this->get_option('site_password');
            $this->merchant_key = $this->get_option('merchant_key');
            $this->merchant_name = $this->get_option('merchant_name');
            $this->payment_url = $this->get_option('payment_url');
            $this->token_url = $this->get_option('token_url');

            // Acciones
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable Agilpay Payment Gateway',
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'Agilpay',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default' => 'Paga con Agilpay',
                ),
                'site_id' => array(
                    'title' => 'Site ID',
                    'type' => 'text',
                    'description' => 'Unique Website identification',
                    'default' => '',
                    'desc_tip' => true,
                ),
                'site_password' => array(
                    'title' => 'Site Password',
                    'type' => 'password',
                    'description' => 'Password for the Site ID',
                    'default' => '',
                    'desc_tip' => true,
                ),
                'merchant_key' => array(
                    'title' => 'Merchant Key',
                    'type' => 'text',
                    'description' => 'Merchant identification key',
                    'default' => '',
                    'desc_tip' => true,
                ),
                'merchant_name' => array(
                    'title' => 'Merchant Name',
                    'type' => 'text',
                    'description' => 'Name of the merchant',
                    'default' => '',
                    'desc_tip' => true,
                ),
                'payment_url' => array(
                    'title' => 'Payment URL',
                    'type' => 'text',
                    'description' => 'URL for the payment request',
                    'default' => 'https://sandbox-webpay.agilpay.net/Payment/',
                    'desc_tip' => true,
                ),
                'token_url' => array(
                    'title' => 'Token URL',
                    'type' => 'text',
                    'description' => 'URL for the token request',
                    'default' => 'https://sandbox-webapi.agilpay.net/oauth/paymenttoken',
                    'desc_tip' => true,
                ),
                'hash_secret' => array(
                    'title' => 'Hash Secret (optional)',
                    'type' => 'password',
                    'description' => 'Client_Secret used to validate the MessageHash returned by the hosted page. Leave empty to reuse the Site Password.',
                    'default' => '',
                    'desc_tip' => true,
                ),
                'webhook_enabled' => array(
                    'title' => 'Authoritative webhook',
                    'type' => 'checkbox',
                    'label' => 'Settle orders from the server-to-server webhook only',
                    'description' => 'Recommended. The hosted page POST travels through the customer browser and its hash does not cover the response code, so it cannot prove a payment was approved. Enable this only after Agilpay is configured to call ' . home_url( '/wc-api/agilpay_webhook' ) . ' with your API key.',
                    'default' => 'no',
                ),
                'webhook_api_key' => array(
                    'title' => 'Webhook API Key',
                    'type' => 'password',
                    'description' => 'Pre-shared key Agilpay sends in the x-api-key header of the server-to-server webhook.',
                    'default' => '',
                    'desc_tip' => true,
                ),
            );
        }

        public function process_payment($order_id) {
            $this->logger->info('Processing payment for order ' . $order_id, array('source' => 'agilpay'));
            $order = wc_get_order($order_id);

            // Generate the Agilpay form
            $form = $this->generate_agilpay_form($order_id);

            // Check if form generation was successful
            if (!$form) {
                wc_add_notice('Unable to generate Agilpay form. Please try again.', 'error');
                $this->logger->error('Failed to generate Agilpay form for order ' . $order_id, array('source' => 'agilpay'));
                return array(
                    'result' => 'failure',
                    'redirect' => ''
                );
            }

            // Nothing is echoed here on purpose. WooCommerce calls
            // process_payment() over AJAX and expects a JSON body, so any
            // output corrupts the response. The form above was built only to
            // confirm a token can be obtained before sending the customer on;
            // receipt_page() renders and submits the real one.
            $this->logger->info('Redirecting to Agilpay for order ' . $order_id, array('source' => 'agilpay'));

            // Return success and redirect to the receipt page
            return array(
                'result' => 'success',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        public function receipt_page($order_id) {
            $order = wc_get_order($order_id);
            $this->logger->info('Displaying receipt page for order ' . $order->get_id(), array('source' => 'agilpay'));

            // Check if the order has already been paid
            if ($order->get_status() !== 'pending') {
                $this->logger->info('Order ' . $order->get_id() . ' is not pending. Redirecting to order received page.', array('source' => 'agilpay'));
                wp_redirect($order->get_checkout_order_received_url());
                exit;
            }

            // The form is always rebuilt server-side. It was previously also
            // accepted base64-encoded from $_GET, which nothing ever set and
            // which echoed attacker-controlled markup straight to the page.
            // The form auto-submits below; the button is the no-JavaScript path.
            echo '<p>Thank you for your order, please wait while we redirect you to Agilpay.</p>';
            echo $this->generate_agilpay_form($order->get_id());
            echo '<script type="text/javascript">document.getElementById("agilpay_payment_form").submit();</script>';
        }

        private function get_oauth_token($order) {
            $this->logger->info('Requesting OAuth token for order ' . $order->get_id(), array('source' => 'agilpay'));
            $body = json_encode(array(
                'grant_type' => 'client_credentials',
                'client_id' => $this->site_id,
                'client_secret' => $this->site_password,
                'orderId' => $order->get_id(),
                'customerId' => $order->get_user_id() ? $order->get_user_id() : $order->get_billing_email(),
                'amount' => $order->get_total()
            ));
            // Never log $body: it carries client_secret in cleartext, and
            // WooCommerce logs are plain files under wp-content/uploads.
            $this->logger->info('OAuth token request URL: ' . $this->token_url, array('source' => 'agilpay'));
            $response = wp_remote_post($this->token_url, array(
                'body' => $body,
                'timeout' => 45,
                'headers' => array(
                    'Content-Type' => 'application/json'
                )
            ));

            if (is_wp_error($response)) {
                $this->logger->error('OAuth token request failed: ' . $response->get_error_message(), array('source' => 'agilpay'));
                return null;
            }

            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);

            if (isset($response_data['access_token'])) {
                $this->logger->info('OAuth token obtained successfully for order ' . $order->get_id(), array('source' => 'agilpay'));
                return $response_data['access_token'];
            }

            $this->logger->error('OAuth token not found in response for order ' . $order->get_id(), array('source' => 'agilpay'));
            return null;
        }

        public function generate_agilpay_form($order_id) {
            $this->logger->info('Generating Agilpay form for order ' . $order_id, array('source' => 'agilpay'));
            $order = wc_get_order($order_id);
            $success_url = add_query_arg('wc-api', 'agilpay_response', home_url('/'));
            $return_url = wc_get_cart_url();

            $user_id = $order->get_user_id() ? $order->get_user_id() : $order->get_billing_email();

            $items = array();
            foreach ($order->get_items() as $item_id => $item) {
                $product = $item->get_product();
                $items[] = array(
                    'Description' => $product->get_name(),
                    'Quantity' => (string)$item->get_quantity(),
                    'Amount' => (float)$item->get_total(),
                    'Tax' => 0
                );
            }

            $payment_details = array(
                'MerchantKey' => $this->merchant_key,
                'Service' => $order->get_order_number(),
                'MerchantName' => $this->merchant_name,
                'Description' => 'Order ' . $order->get_order_number(),
                'Amount' => (float)$order->get_total(), // Cast to float to ensure numeric value
                'Tax' => 0,
                'Currency' => '840',
                'Items' => $items
            );

            $token = $this->get_oauth_token($order);

            if (!$token) {
                wc_add_notice('Unable to obtain OAuth token. Please try again.', 'error');
                return;
            }

            $agilpay_args = array(  
               'SiteId' => $this->site_id,  
               'UserId' => $user_id,  
               'Names' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),  
               'Email' => $order->get_billing_email(),  
               'PhoneNumber' => $order->get_billing_phone(),  
               'Address' => $order->get_billing_address_1(),  
               'Detail' => json_encode(array('Payments' => array($payment_details))),  
               'SuccessURL' => $success_url,  
               'ReturnUrl'=> $return_url,
               'token' => $token  
            );

            $form = '<form action="' . esc_url($this->payment_url) . '" method="post" id="agilpay_payment_form">';
            foreach ($agilpay_args as $key => $value) {
                // esc_attr() encodes the quotes inside the JSON Detail payload;
                // the browser decodes them back before POSTing, so the value
                // Agilpay receives is unchanged.
                $form .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
            }
            $form .= '<input type="submit" class="button alt" id="submit_agilpay_payment_form" value="Pay via Agilpay" />';
            $form .= '</form>';

            return $form;
        }
    }

    function add_agilpay_gateway($methods) {
        $methods[] = 'WC_Gateway_Agilpay';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_agilpay_gateway');

    // Registrar integración con WooCommerce Block Checkout
    add_action('woocommerce_blocks_payment_method_type_registration', function($registry) {
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }

        class WC_Agilpay_Blocks_Support extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
            protected $name = 'agilpay';

            public function initialize() {
                $this->settings = get_option('woocommerce_agilpay_settings', []);
            }

            public function is_active() {
                return filter_var($this->settings['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            }

            public function get_payment_method_script_handles() {
                wp_register_script(
                    'wc-agilpay-blocks',
                    plugin_dir_url(__FILE__) . 'assets/js/agilpay-block.js',
                    ['wc-blocks-registry', 'wc-settings', 'wp-element'],
                    '1.2.0',
                    true
                );
                return ['wc-agilpay-blocks'];
            }

            public function get_payment_method_data() {
                return [
                    'title'       => $this->settings['title'] ?? 'Agilpay',
                    'description' => $this->settings['description'] ?? 'Paga con Agilpay',
                    'icon'        => 'https://agilisa.wpenginepowered.com/wp-content/uploads/2024/01/favicon-150x150.png',
                    'supports'    => ['products'],
                ];
            }
        }

        $registry->register(new WC_Agilpay_Blocks_Support());
    });
}
