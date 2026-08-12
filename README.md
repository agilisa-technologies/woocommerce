# WooCommerce Agilpay Gateway Plugin

This plugin integrates WooCommerce with the Agilpay payment gateway using a hosted payment page. Customers are redirected to Agilpay to complete payment, and Agilpay reports the outcome back to your store.

## Requirements

- WordPress 6.0 or higher
- WooCommerce 8.2 or higher (tested up to 10.9.4)
- PHP 7.4 or higher

Compatible with WooCommerce **HPOS** (High-Performance Order Storage) and the **Cart/Checkout Blocks**.

## Installation

1. **Build the plugin package**:
   - Create a ZIP file of the `src/` folder.

2. **Upload the plugin**:
   - Go to the WordPress admin panel.
   - Navigate to `Plugins` > `Add New`.
   - Click on `Upload Plugin` and select the ZIP file.
   - Click on `Install Now` and then `Activate`.

   The response handler is loaded automatically by the main plugin file. There is only one plugin to activate.

3. **Configure the plugin**:
   - Go to `WooCommerce` > `Settings` > `Payments`.
   - Enable `Agilpay` and click on `Manage`.

### Settings

| Setting | Purpose |
|---------|---------|
| **Title** | The title customers see during checkout. |
| **Description** | The description customers see during checkout. |
| **Site ID** | Unique website identification provided by Agilpay. |
| **Site Password** | Password for the Site ID. Also used as the `Client_Secret` when validating responses. |
| **Merchant Key** | Merchant identification key provided by Agilpay. |
| **Merchant Name** | Name of the merchant, shown on Agilpay's hosted page. |
| **Payment URL** | Hosted payment page URL (default: `https://sandbox-webpay.agilpay.net/Payment/`). |
| **Token URL** | OAuth token endpoint (default: `https://sandbox-webapi.agilpay.net/oauth/paymenttoken`). |
| **Hash Secret** | Optional. Overrides the `Client_Secret` used to validate the hosted page response. Leave empty to reuse the Site Password. |
| **Authoritative webhook** | Settle orders only from Agilpay's server-to-server webhook. Recommended — see [Security](#security). |
| **Webhook API Key** | Pre-shared key Agilpay sends in the `x-api-key` header of the server-to-server webhook. |

> The default URLs point at Agilpay's **sandbox**. Replace them with the production endpoints before going live.

## Endpoint Configuration

After activating the plugin, flush the rewrite rules:

- Go to `Settings` > `Permalinks` in the WordPress admin panel.
- Click on `Save Changes`.

The plugin exposes two endpoints:

| Endpoint | Caller |
|----------|--------|
| `{site}/wc-api/agilpay_response` | Agilpay's hosted payment page, via the customer's browser |
| `{site}/wc-api/agilpay_webhook` | Agilpay's backend, server-to-server |

## Security

Agilpay reports a payment outcome through two independent channels, and the plugin treats them differently on purpose.

**1. Hosted page response** (`wc-api/agilpay_response`)

A form POST that travels through the customer's browser. It carries a `MessageHash` which the plugin validates as:

```
MessageHash = base64( sha256( Client_Secret + AccountToken + Invoice + Amount ) )
```

See Agilpay's [Validating Responses](https://agilpay.readme.io/docs/validating-responses) guide. A request with a hash that does not validate is rejected.

Because the signed field set does **not** include `ResponseCode`, a valid hash proves the invoice and amount were not tampered with, but it does not prove the payment was approved. And since the message passes through the customer's browser, the customer can alter it.

**2. Server-to-server webhook** (`wc-api/agilpay_webhook`)

Issued by Agilpay's backend when the payment is actually approved, authenticated with a pre-shared `x-api-key` header. It never passes through the customer's browser, which makes it the trustworthy channel.

### Recommended configuration

Ask Agilpay to configure the webhook to POST to `{your-site}/wc-api/agilpay_webhook` with an API key. Then set **Webhook API Key** and enable **Authoritative webhook**. Orders will only be settled from the webhook.

Until the webhook is configured, the plugin falls back to settling orders from the hosted page response so that orders do not remain stuck in `pending`. This fallback still requires a valid `MessageHash`, a matching amount, and `ResponseCode` `00` — but it is the weaker of the two modes. Enable the webhook when you can.

In both modes the plugin also:

- verifies the reported amount matches the order total before settling;
- ignores duplicate notifications for an order that is already paid, so retries and replays are safe;
- refuses to act on orders that belong to a different payment method;
- compares secrets with a timing-safe comparison.

### Reporting a vulnerability

Please do **not** open a public issue for security problems. Contact Agilpay technical support directly.

## Usage

1. **Perform a test purchase**:
   - Add a product to the cart and proceed to checkout.
   - Select `Agilpay` as the payment method and complete the purchase.
   - You will be redirected to the Agilpay payment page.

2. **Verify the payment**:
   - Once payment completes, the customer is redirected back to your store.
   - Confirm the order in `WooCommerce` > `Orders`.

## Logging

The plugin logs to the `agilpay` source, viewable under `WooCommerce` > `Status` > `Logs`. Credentials are never written to the log.

If a payment is rejected, the log states why — an invalid `MessageHash`, an amount mismatch, or a missing API key each produce a distinct message.

## Known limitations

- The currency sent to Agilpay is hardcoded to `840` (USD) regardless of the store's configured currency.
- Interface strings are not internationalized.

## Support

If you have any questions or need assistance, please contact Agilpay technical support or refer to the official WooCommerce documentation.

## Contributions

Contributions are welcome. Please open an issue or submit a pull request in the plugin repository.

## License

This plugin is licensed under the [GPLv2 License](https://www.gnu.org/licenses/gpl-2.0.html).
