<?php

declare(strict_types=1);

namespace ifthenpay\FluentForm\Api;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

use RuntimeException;

/**
 * Thin HTTP client for the ifthenpay API.
 * No catalogs are cached. The per-form snapshot saved in
 * `ifthenpay_ff_form_{form_id}` is the only persistent piece of gateway data
 * the rest of the plugin reads from.
 */
final class IfthenpayApiClient {

	private const API_BASE = 'https://api.ifthenpay.com';

	private string $backoffice_key;

	public function __construct( string $backoffice_key ) {
		$this->backoffice_key = sanitize_text_field( $backoffice_key );
	}



	/**
	 * One-shot key-validity probe used by the Connect button.
	 * Treats any non-empty 2xx response as valid. Errors → false.
	 */
	public static function validate_backoffice_key( string $backoffice_key ): bool {
		$backoffice_key = sanitize_text_field( $backoffice_key );
		if ( $backoffice_key === '' ) {
			return false;
		}

		$url = add_query_arg( array( 'boKey' => $backoffice_key ), self::API_BASE . '/gateway/get' );

		try {
			$data = self::request( 'GET', $url );
			return ! empty( $data );
		} catch ( RuntimeException $e ) {
			return false;
		}
	}



	/**
	 * Returns the gateway-key rows for this backoffice key, scoped to the
	 * given gateway type. Each row contains the gateway alias and the
	 * per-method account columns (Multibanco, MBWAY, CCARD, etc.).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_gateway_keys( string $type = 'fluentforms' ): array {
		$args = array( 'boKey' => $this->backoffice_key );

		$type = sanitize_text_field( $type );
		if ( $type !== '' ) {
			$args['type'] = $type;
		}

		return self::request( 'GET', add_query_arg( $args, self::API_BASE . '/gateway/get' ) );
	}

	/**
	 * Returns the list of all payment methods supported by ifthenpay.
	 * The caller is responsible for filtering by IsVisible.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_available_methods(): array {
		return self::request( 'GET', self::API_BASE . '/gateway/methods/available' );
	}



	/**
	 * Converts an integer amount in cents to a decimal string with two
	 * decimal places (e.g. 1999 → "19.99").
	 */
	public static function formatAmount( int $amountCents ): string {
		return number_format( $amountCents / 100, 2, '.', '' );
	}



	/**
	 * POSTs the Pay-By-Link payload to the Pinpay endpoint.
	 *
	 * @param array<string, mixed> $payload
	 * @return array{redirect_url: string, pin_code: string}
	 * @throws \RuntimeException When the API does not return a payment URL.
	 */
	public static function create_pay_by_link( string $gateway_key, array $payload ): array {
		$url         = rtrim( self::API_BASE, '/' ) . '/gateway/pinpay/' . rawurlencode( $gateway_key );
		$data        = self::request(
			'POST',
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		$redirectUrl = (string) ( $data['RedirectUrl'] ?? $data['PinpayUrl'] ?? '' );
		if ( $redirectUrl === '' ) {
			throw new \RuntimeException( 'ifthenpay API did not return a payment URL.' );
		}
		return array(
			'redirect_url' => $redirectUrl,
			'pin_code'     => (string) ( $data['PinCode'] ?? '' ),
		);
	}



	/**
	 * Registers the server-to-server callback URL for a gateway key.
	 *
	 * POSTs to the ifthenpay activation endpoint so the platform knows where
	 * to deliver payment notifications. The urlCb template includes API
	 * placeholders that ifthenpay fills in when firing each callback:
	 *  [ORDER_ID]          → entry ID (the `id` field from the pay-by-link payload)
	 *  [ANTI_PHISHING_KEY] → base64-encoded gateway key (used to validate authenticity)
	 *  [AMOUNT]            → payment amount
	 *  [PAYMENT_METHOD]    → payment method used
	 *  [REQUEST_ID]        → ifthenpay request identifier
	 *
	 * Returns true when the API responds with "OK", false otherwise.
	 * Throws RuntimeException only on transport / non-2xx errors.
	 */
	public static function activate_callback( string $gateway_key, string $webhook_base_url ): bool {
		$url = self::API_BASE . '/endpoint/callback/activation/?cms=fluentforms';

		$payload = array(
			'apKey' => base64_encode( $gateway_key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by ifthenpay anti-phishing specification (verified by IfthenpayProcessor::processWebhook on callback)
			'chave' => $gateway_key,
			'urlCb' => $webhook_base_url
				. '?ref=[ORDER_ID]&apk=[ANTI_PHISHING_KEY]&val=[AMOUNT]&mtd=[PAYMENT_METHOD]&req=[REQUEST_ID]',
		);

		try {
			$res = self::request(
				'POST',
				$url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( $payload ),
				)
			);
			return (string) ( $res['data'] ?? '' ) === 'OK';
		} catch ( \RuntimeException $e ) {
			return false;
		}
	}



	/**
	 * @param array<string, mixed> $args
	 * @return array<string, mixed>
	 * @throws RuntimeException
	 */
	private static function request(
		string $method,
		string $url,
		array $args = array(),
		int $timeout = 20
	): array {
		$args = wp_parse_args(
			$args,
			array(
				'timeout'   => $timeout,
				'sslverify' => true,
			)
		);

		$response = strtoupper( $method ) === 'POST'
			? wp_remote_post( $url, $args )
			: wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			throw new RuntimeException(
				sprintf( 'ifthenpay API error (%s): %s', esc_html( (string) $code ), esc_html( mb_substr( $body, 0, 300 ) ) ),
				(int) $code
			);
		}

		return self::decode( $body );
	}

	/**
	 * @return array<string, mixed>
	 * @throws RuntimeException
	 */
	private static function decode( string $body ): array {
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			throw new RuntimeException( 'Invalid JSON response from ifthenpay API.' );
		}

		if ( isset( $data['d'] ) ) {
			$data = is_string( $data['d'] ) ? json_decode( $data['d'], true ) : $data['d'];
		}

		if ( ! is_array( $data ) ) {
			return array( 'data' => $data );
		}

		return $data;
	}
}
