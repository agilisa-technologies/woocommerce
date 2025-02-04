<?php
/*
Plugin Name: WooCommerce Agilpay Response Handler
Description: Handles the payment response from Agilpay and updates the order status in WooCommerce.
Version: 1.0
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Hook to initialize the endpoint
add_action('init', 'agilpay_add_endpoint');

function agilpay_add_endpoint() {
    add_rewrite_rule('^agilpay-response/?$', 'index.php?agilpay_response=1', 'top');
    add_rewrite_tag('%agilpay_response%', '([^&]+)');
}

// Hook to handle the request
add_action('template_redirect', 'agilpay_handle_response');

function agilpay_handle_response() {
    global $wp_query;

    if (isset($wp_query->query_vars['agilpay_response'])) {
        // Verify that the request is POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);

            if (isset($data['Transaction'])) {
                $transaction = $data['Transaction'];
                $order_id = $transaction['Invoice'];
                $response_code = $transaction['ResponseCode'];

                // Get the WooCommerce order
                $order = wc_get_order($order_id);

                if ($order && $response_code === '00') {
                    // Mark the order as paid
                    $order->payment_complete($transaction['IdTransaction']);
                    $order->add_order_note('Payment completed via Agilpay. Transaction ID: ' . $transaction['IdTransaction']);
                    $order->save();

                    // Respond with success
                    wp_send_json_success('Order marked as paid.');
                } else {
                    // Respond with error
                    wp_send_json_error('Invalid order or response code.');
                }
            } else {
                // Respond with error
                wp_send_json_error('Invalid transaction data.');
            }
        } else {
            // Respond with error
            wp_send_json_error('Invalid request method.');
        }

        exit;
    }
}
?>

