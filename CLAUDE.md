# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

This is a WooCommerce payment gateway plugin that integrates Agilpay (a Payroc product) as a hosted payment page solution. Customers are redirected to Agilpay's hosted page to complete payment, then Agilpay POSTs a response back via webhook.

**Requirements**: WordPress 5.0+, WooCommerce 3.0+, PHP 7.0+

## Repository Structure

```
src/
  woocommerce-agilpay.php        — Main plugin file; gateway class and payment form generation
  agilpay-response-handler.php   — Webhook handler for Agilpay payment callbacks
```

No build system, composer, or test framework is configured — this is a pure PHP plugin deployed as a WordPress ZIP upload.

## Architecture

### Payment Flow

1. Customer selects Agilpay at WooCommerce checkout
2. `WC_Gateway_Agilpay::process_payment()` fetches an OAuth token from Agilpay's token endpoint, builds a hidden HTML form with order details, and auto-submits it via JavaScript
3. Customer completes payment on Agilpay's hosted page
4. Agilpay POSTs a JSON `Detail` payload to `{site}/wc-api/agilpay_response`
5. `agilpay_handle_response()` validates the payload, checks `ResponseCode === "00"`, marks the order paid via `payment_complete()`, stores transaction metadata, and redirects the customer

### Key Classes and Functions

- **`WC_Gateway_Agilpay`** (`src/woocommerce-agilpay.php`) — extends `WC_Payment_Gateway`; registered via `woocommerce_payment_gateways` filter on `plugins_loaded`
  - `get_oauth_token($order)` — POSTs to `token_url` with client credentials grant; returns bearer token
  - `generate_agilpay_form($order_id)` — builds the redirect form; currency is hardcoded to code `840` (USD)
  - `process_payment($order_id)` — returns WooCommerce `success`/`failure` result array
- **`agilpay_handle_response()`** (`src/agilpay-response-handler.php`) — hooked to `woocommerce_api_agilpay_response`; validates required fields (`Invoice`, `ResponseCode`, `IdTransaction`, `Account`, `AuthNumber`, `ReferenceCode`) before marking the order paid

### WooCommerce Hooks

| Hook | Handler |
|------|---------|
| `woocommerce_payment_gateways` | `add_agilpay_gateway()` |
| `woocommerce_receipt_agilpay` | `WC_Gateway_Agilpay::receipt_page()` |
| `woocommerce_thankyou_agilpay` | `WC_Gateway_Agilpay::receipt_page()` |
| `woocommerce_api_agilpay_response` | `agilpay_handle_response()` |
| `init` | `agilpay_add_endpoint()` (registers rewrite rule) |

### Configuration (stored in `wp_options`)

All settings are prefixed `woocommerce_agilpay_`. Key settings:

| Setting | Purpose |
|---------|---------|
| `site_id` / `site_password` / `merchant_key` | Agilpay credentials for OAuth token request |
| `merchant_name` | Displayed on Agilpay's hosted page |
| `token_url` | OAuth token endpoint (default: sandbox) |
| `payment_url` | Hosted payment page base URL (default: sandbox) |

### Order Metadata Stored on Success

- `Payment Account`
- `Agilpay AuthNumber`
- `Agilpay ReferenceCode`
- `Transaction ID`

## Logging

Both files use `wc_get_logger()` with context `['source' => 'agilpay']`. Logs are viewable in WooCommerce > Status > Logs.

## Deployment

Install by uploading the `src/` directory (or a ZIP of it) via WordPress Admin > Plugins > Add New > Upload Plugin. There is no compilation step.
