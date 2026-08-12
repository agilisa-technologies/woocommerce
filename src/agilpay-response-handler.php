<?php
/**
 * Agilpay payment response handling.
 *
 * Agilpay reports the outcome of a payment through two independent channels:
 *
 *   1. A server-to-server webhook, authenticated with an `x-api-key` header.
 *      This is the authoritative notification: it is issued by Agilpay's
 *      backend when the payment is actually approved.
 *
 *   2. A browser form POST from the hosted payment page, carrying a
 *      `MessageHash` over (Client_Secret + AccountToken + Invoice + Amount).
 *      This one only signals that the customer finished interacting with the
 *      page. It travels through the customer's browser, so the customer can
 *      alter it, and the hash does NOT cover `ResponseCode` — meaning a valid
 *      hash proves nothing about whether the payment was approved.
 *
 * Because of that, channel 2 is never allowed to mark an order as paid on its
 * own once the webhook is enabled; it validates the hash, then redirects.
 *
 * @see https://agilpay.readme.io/docs/validating-responses
 *
 * @package WooCommerce_Agilpay_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'agilpay_add_endpoint' );

/**
 * Registers the rewrite rules for both response channels.
 */
function agilpay_add_endpoint() {
	add_rewrite_rule( '^wc-api/agilpay_response/?$', 'index.php?wc-api=agilpay_response', 'top' );
	add_rewrite_rule( '^wc-api/agilpay_webhook/?$', 'index.php?wc-api=agilpay_webhook', 'top' );
	add_rewrite_tag( '%wc-api%', '([^&]+)' );
}

add_action( 'woocommerce_api_agilpay_response', 'agilpay_handle_response' );
add_action( 'woocommerce_api_agilpay_webhook', 'agilpay_handle_webhook' );

/**
 * Reads a single gateway setting.
 *
 * The handler is deliberately decoupled from WC_Gateway_Agilpay so it stays
 * usable even if the gateway class has not been instantiated for this request.
 *
 * @param string $key     Setting key, without the `woocommerce_agilpay_` prefix.
 * @param string $default Value returned when the setting is absent.
 * @return string
 */
function agilpay_get_setting( $key, $default = '' ) {
	$settings = get_option( 'woocommerce_agilpay_settings', array() );

	return ( is_array( $settings ) && isset( $settings[ $key ] ) ) ? $settings[ $key ] : $default;
}

/**
 * Returns the secret used to validate the hosted page's MessageHash.
 *
 * Agilpay documents this as `Client_Secret` — the same credential the gateway
 * already sends as `client_secret` when requesting an OAuth token. A dedicated
 * `hash_secret` setting overrides it for merchants whose hash secret differs.
 *
 * @return string
 */
function agilpay_get_hash_secret() {
	$override = agilpay_get_setting( 'hash_secret' );

	return '' !== $override ? $override : agilpay_get_setting( 'site_password' );
}

/**
 * Whether the server-to-server webhook can be relied on to settle orders.
 *
 * Both conditions must hold: the merchant has to have opted in AND an API key
 * has to be present, because without a key `agilpay_handle_webhook()` fails
 * closed and would never settle anything. Checking the key as well as the flag
 * is what stops a half-finished configuration from leaving orders in `pending`
 * forever.
 *
 * When this returns false the hosted page POST does the settlement work
 * itself — it is the only channel available in that configuration.
 *
 * @return bool
 */
function agilpay_webhook_is_authoritative() {
	return 'yes' === agilpay_get_setting( 'webhook_enabled', 'no' )
		&& '' !== agilpay_get_setting( 'webhook_api_key' );
}

/**
 * Builds the candidate string representations of an amount.
 *
 * On the Agilpay side the hashed amount comes from `string.Concat(..., Amount)`
 * where `Amount` is a .NET `double`. `double.ToString()` drops trailing zeros
 * (123.00 renders as "123", 123.50 as "123.5") and honours the server's
 * culture, so a comma may replace the decimal point. Reformatting the value in
 * PHP would therefore break the hash for round amounts. We reproduce every
 * plausible rendering and accept the payload if any of them matches.
 *
 * Trying several candidates does not weaken the check: forging any of them
 * still requires the shared secret.
 *
 * @param string $raw_literal Amount exactly as it appeared in the JSON payload.
 * @param float  $value       Parsed numeric amount.
 * @return string[] Unique candidate strings.
 */
function agilpay_amount_hash_candidates( $raw_literal, $value ) {
	$candidates = array();

	// The literal as transmitted is the closest thing to what .NET serialized.
	if ( '' !== $raw_literal ) {
		$candidates[] = $raw_literal;
	}

	// PHP's shortest round-trip rendering, which matches .NET for most values.
	$candidates[] = (string) $value;

	// Explicit two-decimal form, in case the amount was formatted before hashing.
	$candidates[] = number_format( $value, 2, '.', '' );

	// Trailing zeros trimmed, e.g. "123.50" -> "123.5", "123.00" -> "123".
	$trimmed = rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	$candidates[] = '' === $trimmed ? '0' : $trimmed;

	// Comma decimal separator, for a webpay host running under an es-* culture.
	foreach ( $candidates as $candidate ) {
		if ( false !== strpos( $candidate, '.' ) ) {
			$candidates[] = str_replace( '.', ',', $candidate );
		}
	}

	return array_values( array_unique( $candidates ) );
}

/**
 * Validates the hosted page's MessageHash.
 *
 * MessageHash = base64( sha256_raw( Client_Secret + AccountToken + Invoice + Amount ) )
 *
 * @param string $account_token Transaction AccountToken.
 * @param string $invoice       Transaction Invoice.
 * @param string $raw_literal   Amount as it appeared in the raw JSON.
 * @param float  $amount        Parsed numeric amount.
 * @param string $received      Hash received from Agilpay.
 * @param string $secret        Shared Client_Secret.
 * @return bool
 */
function agilpay_validate_message_hash( $account_token, $invoice, $raw_literal, $amount, $received, $secret ) {
	if ( '' === $received || '' === $secret ) {
		return false;
	}

	foreach ( agilpay_amount_hash_candidates( $raw_literal, $amount ) as $amount_string ) {
		$expected = base64_encode(
			hash( 'sha256', $secret . $account_token . $invoice . $amount_string, true )
		);

		if ( hash_equals( $expected, $received ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Extracts the Amount literal exactly as it was serialized in the payload.
 *
 * json_decode turns the value into a PHP float, losing the original rendering
 * that the hash was computed over. This recovers the untouched token.
 *
 * @param string $raw_detail Raw `Detail` payload.
 * @return string Empty string when no Amount token is present.
 */
function agilpay_extract_amount_literal( $raw_detail ) {
	if ( preg_match( '/"Amount"\s*:\s*"?(-?\d+(?:[.,]\d+)?)"?/', $raw_detail, $matches ) ) {
		return $matches[1];
	}

	return '';
}

/**
 * Resolves and sanity-checks the order referenced by a transaction.
 *
 * @param string $invoice Invoice value from the payload.
 * @return WC_Order|false
 */
function agilpay_resolve_order( $invoice ) {
	$order = wc_get_order( absint( $invoice ) );

	if ( ! $order ) {
		return false;
	}

	// Refuse to act on orders that belong to a different payment method.
	if ( 'agilpay' !== $order->get_payment_method() ) {
		return false;
	}

	return $order;
}

/**
 * Confirms the reported amount matches what the order actually costs.
 *
 * The hash binds Amount, so combining hash validation with this check gives
 * real integrity: the payload cannot claim a smaller amount than was charged.
 *
 * @param WC_Order $order  Order being settled.
 * @param float    $amount Amount reported by Agilpay.
 * @return bool
 */
function agilpay_amount_matches_order( $order, $amount ) {
	$expected = (float) $order->get_total();

	// One cent of tolerance absorbs floating point representation error.
	return abs( $expected - (float) $amount ) < 0.01;
}

/**
 * Marks an order as paid and records the Agilpay transaction metadata.
 *
 * Idempotent: a payload replayed for an already-paid order is a no-op, so
 * Agilpay can safely retry without double-processing.
 *
 * @param WC_Order $order       Order to settle.
 * @param array    $transaction Validated transaction data.
 * @param string   $channel     Channel that authorized the settlement.
 * @return bool True when this call transitioned the order.
 */
function agilpay_settle_order( $order, $transaction, $channel ) {
	$logger = wc_get_logger();

	if ( $order->is_paid() ) {
		$logger->info(
			sprintf( 'Agilpay: order %d is already paid, ignoring duplicate %s notification.', $order->get_id(), $channel ),
			array( 'source' => 'agilpay' )
		);

		return false;
	}

	$order->payment_complete( $transaction['IdTransaction'] );
	$order->add_order_note(
		sprintf(
			'Payment completed via Agilpay (%s). Transaction ID: %s',
			$channel,
			$transaction['IdTransaction']
		)
	);

	$order->update_meta_data( 'Payment Account', $transaction['Account'] );
	$order->update_meta_data( 'Agilpay AuthNumber', $transaction['AuthNumber'] );
	$order->update_meta_data( 'Agilpay ReferenceCode', $transaction['ReferenceCode'] );
	$order->update_meta_data( 'Transaction ID', $transaction['IdTransaction'] );
	$order->save();

	$logger->info(
		sprintf( 'Agilpay: order %d marked as paid via %s.', $order->get_id(), $channel ),
		array( 'source' => 'agilpay' )
	);

	return true;
}

/**
 * Decodes and structurally validates a `Detail` payload.
 *
 * @param string $raw_detail Raw payload.
 * @return array|WP_Error Transaction array on success.
 */
function agilpay_parse_transaction( $raw_detail ) {
	$data = json_decode( $raw_detail, true );

	if ( ! is_array( $data ) || empty( $data['Transaction'] ) || ! is_array( $data['Transaction'] ) ) {
		return new WP_Error( 'agilpay_invalid_payload', 'Invalid transaction data.' );
	}

	$transaction = $data['Transaction'];

	$required = array( 'Invoice', 'ResponseCode', 'IdTransaction', 'Account', 'AuthNumber', 'ReferenceCode' );
	foreach ( $required as $field ) {
		if ( ! isset( $transaction[ $field ] ) || '' === $transaction[ $field ] ) {
			return new WP_Error( 'agilpay_missing_field', 'Missing required transaction fields.' );
		}
	}

	return $transaction;
}

/**
 * Handles the browser form POST from Agilpay's hosted payment page.
 *
 * This channel authenticates the message but cannot prove the payment was
 * approved, because the signed value set is (AccountToken + Invoice + Amount)
 * and excludes ResponseCode. Its only job is to validate the hash and send the
 * customer somewhere sensible; settlement is left to the webhook whenever the
 * webhook is configured.
 */
function agilpay_handle_response() {
	$logger = wc_get_logger();

	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
		$logger->error( 'Agilpay: response channel called with a non-POST request.', array( 'source' => 'agilpay' ) );
		wp_send_json_error( 'Invalid request method.', 405 );
	}

	if ( empty( $_POST['Detail'] ) ) {
		$logger->error( 'Agilpay: response channel called without a Detail payload.', array( 'source' => 'agilpay' ) );
		wp_send_json_error( 'Missing Detail in POST data.', 400 );
	}

	// Keep the payload byte-for-byte as sent: the hash was computed over it.
	$raw_detail  = wp_unslash( $_POST['Detail'] );
	$transaction = agilpay_parse_transaction( $raw_detail );

	if ( is_wp_error( $transaction ) ) {
		$logger->error(
			'Agilpay: ' . $transaction->get_error_message(),
			array( 'source' => 'agilpay' )
		);
		wp_send_json_error( $transaction->get_error_message(), 400 );
	}

	$order = agilpay_resolve_order( $transaction['Invoice'] );

	if ( ! $order ) {
		$logger->error(
			sprintf( 'Agilpay: no Agilpay order matches invoice %s.', $transaction['Invoice'] ),
			array( 'source' => 'agilpay' )
		);
		wp_send_json_error( 'Unknown order.', 404 );
	}

	// The hash covers AccountToken + Invoice + Amount, so validating it also
	// proves the amount and invoice were not tampered with in transit.
	$account_token = isset( $transaction['AccountToken'] ) ? $transaction['AccountToken'] : '';
	$amount        = isset( $transaction['Amount'] ) ? (float) $transaction['Amount'] : 0.0;
	$received_hash = isset( $transaction['MessageHash'] ) ? $transaction['MessageHash'] : '';

	if ( '' === $received_hash && ! empty( $_POST['Authentication'] ) ) {
		// The hosted page also sends the hash as a top-level form field.
		$received_hash = wp_unslash( $_POST['Authentication'] );
	}

	// Without these the hash is not reproducible and validation below would
	// fail as though the message were forged. Say so explicitly instead, so the
	// log points at a payload/configuration problem rather than an attack.
	$missing = array();
	foreach ( array( 'AccountToken', 'Amount', 'MessageHash' ) as $field ) {
		if ( empty( $transaction[ $field ] ) ) {
			$missing[] = $field;
		}
	}

	if ( ! empty( $missing ) && '' === $received_hash ) {
		$logger->critical(
			sprintf(
				'Agilpay: cannot validate the response for order %d — the payload is missing %s. Confirm Agilpay is sending the signed response fields.',
				$order->get_id(),
				implode( ', ', $missing )
			),
			array( 'source' => 'agilpay' )
		);
		wp_send_json_error( 'Unverifiable response payload.', 400 );
	}

	$hash_valid = agilpay_validate_message_hash(
		$account_token,
		$transaction['Invoice'],
		agilpay_extract_amount_literal( $raw_detail ),
		$amount,
		$received_hash,
		agilpay_get_hash_secret()
	);

	if ( ! $hash_valid ) {
		$logger->critical(
			sprintf(
				'Agilpay: MessageHash validation FAILED for order %d — rejecting. Verify the Client_Secret setting.',
				$order->get_id()
			),
			array( 'source' => 'agilpay' )
		);
		wp_send_json_error( 'Message authentication failed.', 403 );
	}

	$logger->info(
		sprintf( 'Agilpay: MessageHash validated for order %d (response code %s).', $order->get_id(), $transaction['ResponseCode'] ),
		array( 'source' => 'agilpay' )
	);

	// With no usable webhook configured, this channel is the only one that will
	// ever report the outcome, so it has to do the settlement work itself.
	// `agilpay_settle_order()` is idempotent, so if a webhook did land first
	// this simply falls through to the redirect.
	if ( ! agilpay_webhook_is_authoritative() ) {
		$logger->info(
			sprintf(
				'Agilpay: no authoritative webhook configured — settling order %d from the hosted page response.',
				$order->get_id()
			),
			array( 'source' => 'agilpay' )
		);

		if ( '00' !== $transaction['ResponseCode'] ) {
			$logger->error(
				sprintf( 'Agilpay: order %d not approved (response code %s).', $order->get_id(), $transaction['ResponseCode'] ),
				array( 'source' => 'agilpay' )
			);
			wc_add_notice( 'Your payment was not approved. Please try again.', 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		if ( ! agilpay_amount_matches_order( $order, $amount ) ) {
			$logger->critical(
				sprintf(
					'Agilpay: amount mismatch for order %d — reported %s, expected %s. Refusing to settle.',
					$order->get_id(),
					(string) $amount,
					(string) $order->get_total()
				),
				array( 'source' => 'agilpay' )
			);
			wp_send_json_error( 'Amount mismatch.', 409 );
		}

		agilpay_settle_order( $order, $transaction, 'hosted page' );
	} elseif ( ! $order->is_paid() ) {
		// Strict mode: the webhook is the authority and has not arrived yet.
		// The order stays pending on purpose and settles when it does.
		$logger->info(
			sprintf(
				'Agilpay: order %d left pending — awaiting the authoritative webhook.',
				$order->get_id()
			),
			array( 'source' => 'agilpay' )
		);
	}

	wp_safe_redirect( $order->get_checkout_order_received_url() );
	exit;
}

/**
 * Reads the `x-api-key` request header.
 *
 * @return string
 */
function agilpay_get_request_api_key() {
	if ( ! empty( $_SERVER['HTTP_X_API_KEY'] ) ) {
		return trim( wp_unslash( $_SERVER['HTTP_X_API_KEY'] ) );
	}

	// Some SAPI configurations only expose the header through getallheaders().
	if ( function_exists( 'getallheaders' ) ) {
		foreach ( (array) getallheaders() as $name => $value ) {
			if ( 'x-api-key' === strtolower( $name ) ) {
				return trim( $value );
			}
		}
	}

	return '';
}

/**
 * Handles Agilpay's server-to-server webhook.
 *
 * This is the authoritative channel: it is issued by Agilpay's backend on
 * approval and authenticated with a pre-shared `x-api-key`, so unlike the
 * hosted page POST it never passes through the customer's browser.
 */
function agilpay_handle_webhook() {
	$logger = wc_get_logger();

	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
		wp_send_json_error( 'Invalid request method.', 405 );
	}

	$configured_key = agilpay_get_setting( 'webhook_api_key' );

	// Fail closed. An unconfigured key must never mean "accept anything".
	if ( '' === $configured_key ) {
		$logger->critical(
			'Agilpay: webhook called but no API key is configured — rejecting. Set the Webhook API Key in the gateway settings.',
			array( 'source' => 'agilpay' )
		);
		wp_send_json_error( 'Webhook not configured.', 503 );
	}

	if ( ! hash_equals( $configured_key, agilpay_get_request_api_key() ) ) {
		$logger->critical( 'Agilpay: webhook rejected — invalid or missing x-api-key.', array( 'source' => 'agilpay' ) );
		wp_send_json_error( 'Unauthorized.', 401 );
	}

	if ( empty( $_POST['Detail'] ) ) {
		wp_send_json_error( 'Missing Detail in POST data.', 400 );
	}

	$transaction = agilpay_parse_transaction( wp_unslash( $_POST['Detail'] ) );

	if ( is_wp_error( $transaction ) ) {
		$logger->error( 'Agilpay webhook: ' . $transaction->get_error_message(), array( 'source' => 'agilpay' ) );
		wp_send_json_error( $transaction->get_error_message(), 400 );
	}

	$order = agilpay_resolve_order( $transaction['Invoice'] );

	if ( ! $order ) {
		$logger->error(
			sprintf( 'Agilpay webhook: no Agilpay order matches invoice %s.', $transaction['Invoice'] ),
			array( 'source' => 'agilpay' )
		);
		wp_send_json_error( 'Unknown order.', 404 );
	}

	if ( '00' !== $transaction['ResponseCode'] ) {
		$logger->info(
			sprintf( 'Agilpay webhook: order %d reported as not approved (code %s).', $order->get_id(), $transaction['ResponseCode'] ),
			array( 'source' => 'agilpay' )
		);
		wp_send_json_success( 'Acknowledged.' );
	}

	// Authentication proves the sender, not the contents. Reconcile the amount.
	if ( isset( $transaction['Amount'] ) && ! agilpay_amount_matches_order( $order, (float) $transaction['Amount'] ) ) {
		$logger->critical(
			sprintf(
				'Agilpay webhook: amount mismatch for order %d — reported %s, expected %s. Refusing to settle.',
				$order->get_id(),
				(string) $transaction['Amount'],
				(string) $order->get_total()
			),
			array( 'source' => 'agilpay' )
		);
		wp_send_json_error( 'Amount mismatch.', 409 );
	}

	agilpay_settle_order( $order, $transaction, 'webhook' );

	wp_send_json_success( 'Acknowledged.' );
}
