# WooCommerce Agilpay Gateway Plugin

This plugin allows you to integrate WooCommerce with the Agilpay payment gateway using a hosted payment page.

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- PHP 7.0 or higher

## Installation

1. **Download the plugin**:
   - Download the plugin file `woocommerce-agilpay.zip` from the repository or create a ZIP file of the plugin folder.

2. **Upload the plugin**:
   - Go to the WordPress admin panel.
   - Navigate to `Plugins` > `Add New`.
   - Click on `Upload Plugin` and select the `woocommerce-agilpay.zip` file.
   - Click on `Install Now` and then `Activate`.

3. **Configure the plugin**:
   - Go to `WooCommerce` > `Settings` > `Payments`.
   - Enable `Agilpay` and click on `Manage`.
   - Configure the following fields:
     - **Title**: The title that customers will see during checkout.
     - **Description**: The description that customers will see during checkout.
     - **Site ID**: Unique website identification provided by Agilpay.
     - **Merchant Key**: Merchant identification key provided by Agilpay.
     - **Merchant Name**: Name of the merchant.

## Usage

1. **Perform a test purchase**:
   - Add a product to the cart and proceed to checkout.
   - Select `Agilpay` as the payment method and complete the purchase.
   - You will be redirected to the Agilpay payment page with the necessary parameters, including `SuccessURL`.

2. **Verify the payment**:
   - Once the payment is completed on the Agilpay page, the customer will be redirected back to your WooCommerce site.
   - Verify that the order has been processed correctly in `WooCommerce` > `Orders`.

## Endpoint Configuration

1. **Create the response handler page**:
   - Create a file named `agilpay-response-handler.php` in your theme or plugin directory with the following content:


2. **Update the rewrite rules**:
   - Go to `Settings` > `Permalinks` in the WordPress admin panel.
   - Click on `Save Changes` to update the rewrite rules.

3. **Configure the response URL in Agilpay**:
   - Set the response URL in the Agilpay admin panel to `https://your-site.com/agilpay-response`.

## Support

If you have any questions or need assistance, please contact Agilpay technical support or refer to the official WooCommerce documentation.

## Contributions

Contributions are welcome. If you would like to contribute, please open an issue or submit a pull request in the plugin repository.

## License

This plugin is licensed under the [GPLv2 License](https://www.gnu.org/licenses/gpl-2.0.html).