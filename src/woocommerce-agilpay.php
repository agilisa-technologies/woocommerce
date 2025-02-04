<?php
/*
Plugin Name: WooCommerce Agilpay Gateway
Description: Conector para WooCommerce para el gateway de pago Agilpay.
Version: 1.0
Author: Tu Nombre
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Incluir la clase del gateway de pago
add_action('plugins_loaded', 'init_agilpay_gateway');

function init_agilpay_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Agilpay extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'agilpay';
            $this->icon = ''; // URL del icono del método de pago
            $this->has_fields = false;
            $this->method_title = 'Agilpay';
            $this->method_description = 'Paga con Agilpay';

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
            );
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        }

        public function receipt_page($order) {
            echo '<p>Thank you for your order, please click the button below to pay with Agilpay.</p>';
            echo $this->generate_agilpay_form($order);
        }

        private function get_oauth_token($order) {
            $body = json_encode(array(
                'grant_type' => 'client_credentials',
                'client_id' => $this->site_id,
                'client_secret' => $this->site_password,
                'orderId' => $order->get_id(),
                'customerId' => $order->get_user_id() ? $order->get_user_id() : $order->get_billing_email(),
                'amount' => $order->get_total()
            ));

            $response = wp_remote_post($this->token_url, array(
                'body' => $body,
                'timeout' => 45,
                'headers' => array(
                    'Content-Type' => 'application/json'
                )
            ));

            if (is_wp_error($response)) {
                return null;
            }

            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);

            if (isset($response_data['access_token'])) {
                return $response_data['access_token'];
            }

            return null;
        }

        public function generate_agilpay_form($order_id) {
            $order = wc_get_order($order_id);
            $success_url = add_query_arg('wc-api', 'agilpay_response', home_url('/'));

            $user_id = $order->get_user_id() ? $order->get_user_id() : $order->get_billing_email();

            $payment_details = array(
                'MerchantKey' => $this->merchant_key,
                'Service' => $order->get_order_number(),
                'MerchantName' => $this->merchant_name,
                'Description' => 'Order ' . $order->get_order_number(),
                'Amount' => $order->get_total(),
                'Tax' => 0,
                'Currency' => 840
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
                'Address' => $order->get_billing_address_1(),
                'Detail' => json_encode(array('Payments' => array($payment_details))),
                'SuccessURL' => $success_url,
                'token' => $token
            );

            $form = '<form action="' . esc_url($this->payment_url) . '" method="post" id="agilpay_payment_form">';
            foreach ($agilpay_args as $key => $value) {
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
}
?>

