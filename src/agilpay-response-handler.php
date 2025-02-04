<?php
/*
Plugin Name: WooCommerce Agilpay Response Handler
Description: Maneja la respuesta de pago de Agilpay y actualiza el estado de la orden en WooCommerce.
Version: 1.0
Author: Tu Nombre
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Hook para inicializar el endpoint
add_action('init', 'agilpay_add_endpoint');

function agilpay_add_endpoint() {
    add_rewrite_rule('^agilpay-response/?$', 'index.php?agilpay_response=1', 'top');
    add_rewrite_tag('%agilpay_response%', '([^&]+)');
}

// Hook para manejar la solicitud
add_action('template_redirect', 'agilpay_handle_response');

function agilpay_handle_response() {
    global $wp_query;

    if (isset($wp_query->query_vars['agilpay_response'])) {
        // Verificar que la solicitud sea POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);

            if (isset($data['Transaction'])) {
                $transaction = $data['Transaction'];
                $order_id = $transaction['Invoice'];
                $response_code = $transaction['ResponseCode'];

                // Obtener la orden de WooCommerce
                $order = wc_get_order($order_id);

                if ($order && $response_code === '00') {
                    // Marcar la orden como pagada
                    $order->payment_complete($transaction['IdTransaction']);
                    $order->add_order_note('Pago completado a través de Agilpay. ID de transacción: ' . $transaction['IdTransaction']);
                    $order->save();

                    // Responder con éxito
                    wp_send_json_success('Order marked as paid.');
                } else {
                    // Responder con error
                    wp_send_json_error('Invalid order or response code.');
                }
            } else {
                // Responder con error
                wp_send_json_error('Invalid transaction data.');
            }
        } else {
            // Responder con error
            wp_send_json_error('Invalid request method.');
        }

        exit;
    }
}
?>
