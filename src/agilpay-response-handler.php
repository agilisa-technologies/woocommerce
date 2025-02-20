<?php  
/*  
Plugin Name: WooCommerce Agilpay Response Handler  
Description: Handles the payment response from Agilpay and updates the order status in WooCommerce.  
Version: 1.0  
Author: Agilisa Technologies  
*/  

if (!defined("ABSPATH")) {  
   exit(); // Exit if accessed directly  
}  

// Hook to initialize the endpoint  
add_action("init", "agilpay_add_endpoint");  

function agilpay_add_endpoint() {  
   add_rewrite_rule('^wc-api/agilpay_response/?$', "index.php?wc-api=agilpay_response", "top");  
   add_rewrite_tag("%wc-api%", "([^&]+)");  
}  

// Hook to handle the request  
add_action("woocommerce_api_agilpay_response", "agilpay_handle_response");  

function agilpay_handle_response() {  
   $logger = wc_get_logger();  
   $logger->info("Agilpay: Response handler called.", array('source' => 'agilpay'));  
   $logger->info("Agilpay: Request method - " . $_SERVER["REQUEST_METHOD"], array('source' => 'agilpay'));  

   // Verify that the request is POST  
   if ($_SERVER["REQUEST_METHOD"] !== "POST") {  
       $logger->error("Agilpay: Invalid request method.", array('source' => 'agilpay'));  
       wp_send_json_error("Invalid request method.");  
       exit();  
   }  

   if (empty($_POST["Detail"])) {  
       $logger->error("Agilpay: Missing Detail in POST data.", array('source' => 'agilpay'));  
       wp_send_json_error("Missing Detail in POST data.");  
       exit();  
   }  

   $data = json_decode(stripslashes($_POST["Detail"]), true);  

   // Log the request data for debugging  
   $logger->info("Agilpay: Request data - " . print_r($data, true), array('source' => 'agilpay'));  

   // Check if Detail and Transaction are present  
   if (empty($data["Transaction"])) {  
       $logger->error("Agilpay: Invalid transaction data.", array('source' => 'agilpay', 'data' => $data));  
       wp_send_json_error("Invalid transaction data.");  
       exit();  
   }  

   $transaction = $data["Transaction"];  

   // Check if all required fields are present  
   $required_fields = ["Invoice", "ResponseCode", "IdTransaction", "Account", "AuthNumber", "ReferenceCode"];  
   foreach ($required_fields as $field) {  
       if (empty($transaction[$field])) {  
           $logger->error("Agilpay: Missing required transaction fields.", array('source' => 'agilpay', 'transaction' => $transaction));  
           wp_send_json_error("Missing required transaction fields.");  
           exit();  
       }  
   }  

   $order_id = $transaction["Invoice"];  
   $response_code = $transaction["ResponseCode"];  

   // Get the WooCommerce order  
   $order = wc_get_order($order_id);  

   if (!$order || $response_code !== "00") {  
       $logger->error("Agilpay: Invalid order or response code for order " . $order_id . ".", array('source' => 'agilpay'));  
       wp_send_json_error("Invalid order or response code.");  
       exit();  
   }  

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
   $logger->info("Agilpay: Order " . $order_id . " marked as paid.", array('source' => 'agilpay'));  

   // Redirect to order receipt page  
   wp_redirect($order->get_checkout_order_received_url());  
   exit();  
}  
?>