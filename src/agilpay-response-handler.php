<?php
/*
Plugin Name: WooCommerce Agilpay Response Handler  
Description: Handles the payment response from Agilpay and updates the order status in WooCommerce.  
Version: 1.0  
Author: Agilisa Technologies  
*/

if (!defined("ABSPATH"))
{
    exit(); // Exit if accessed directly
    
}

// Hook to initialize the endpoint
add_action("init", "agilpay_add_endpoint");

function agilpay_add_endpoint()
{
    add_rewrite_rule('^wc-api/agilpay_response/?$', "index.php?wc-api=agilpay_response", "top");
    add_rewrite_tag("%wc-api%", "([^&]+)");
}

// Hook to handle the request
add_action("woocommerce_api_agilpay_response", "agilpay_handle_response");

function agilpay_handle_response()
{
    global $wp_query;

    $logger = wc_get_logger();
    $logger->info("Agilpay: Response handler called.");
    $logger->info("Agilpay: Request method - " . $_SERVER["REQUEST_METHOD"]);

    // Verify that the request is POST
    if ($_SERVER["REQUEST_METHOD"] === "POST")
    {
        if (isset($_POST["Detail"]))
        {
            $data = json_decode(stripslashes($_POST["Detail"]) , true);

            // Log the request data for debugging
            $logger->info("Agilpay: Request data - " . print_r($data, true));

            // Check if Detail and Transaction are present
            if (isset($data["Transaction"]))
            {
                $transaction = $data["Transaction"];

                // Check if all required fields are present
                if (isset($transaction["Invoice"], $transaction["ResponseCode"], $transaction["IdTransaction"], $transaction["Account"], $transaction["AuthNumber"], $transaction["ReferenceCode"]))
                {
                    $order_id = $transaction["Invoice"];
                    $response_code = $transaction["ResponseCode"];

                    // Get the WooCommerce order
                    $order = wc_get_order($order_id);

                    if ($order && $response_code === "00")
                    {
                        // Mark the order as paid
                        $order->payment_complete($transaction["IdTransaction"]);
                        $order->add_order_note("Payment completed via Agilpay. Transaction ID: " . $transaction["IdTransaction"]);

                        // Add payment receipt information
                        $order->update_meta_data("Payment Account", $transaction["Account"]);
                        $order->update_meta_data("Agilpay AuthNumber", $transaction["AuthNumber"]);
                        $order->update_meta_data("Agilpay ReferenceCode", $transaction["ReferenceCode"]);
                        $order->update_meta_data("Transaction ID", $transaction["IdTransaction"]);

                        $order->save();

                        // Log success
                        $logger->info("Agilpay: Order " . $order_id . " marked as paid.");

                        // Redirect to order receipt page
                        wp_redirect($order->get_checkout_order_received_url());
                        exit();
                                    
                    }
                    else
                    {
                        // Log error
                        $logger->error("Agilpay: Invalid order or response code for order " . $order_id . ".");

                        // Respond with error
                        wp_send_json_error("Invalid order or response code.");
                    }
                }
                else
                {
                    // Log error
                    $logger->error("Agilpay: Missing required transaction fields.", ["transaction" => $transaction]);

                    // Respond with error
                    wp_send_json_error("Missing required transaction fields.");
                }
            }
            else
            {
                // Log error
                $logger->error("Agilpay: Invalid transaction data.", ["data" => $data, ]);

                // Respond with error
                wp_send_json_error("Invalid transaction data.");
            }
        }
        else
        {
            // Log error
            $logger->error("Agilpay: Missing Detail in POST data.");

            // Respond with error
            wp_send_json_error("Missing Detail in POST data.");
        }
    }
    else
    {
        // Log error
        $logger->error("Agilpay: Invalid request method.");

        // Respond with error
        wp_send_json_error("Invalid request method.");
    }

    exit();
    
}

?>
