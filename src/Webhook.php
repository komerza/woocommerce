<?php
/**
 * Receives order events from Komerza.
 *
 * @package Komerza\WooCommerce
 */

namespace Komerza\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the webhook the gateway registers with Komerza.
 *
 * Komerza posts here, at ?wc-api=komerza, whenever an order on the store changes.
 *
 * @see https://docs.komerza.com/guides/webhooks
 */
final class Webhook {

	/**
	 * Verifies an event and applies the order it carries.
	 */
	public static function handle(): void {
		$body      = (string) file_get_contents( 'php://input' );
		$signature = isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SIGNATURE'] ) ) : '';
		$secret    = Gateway::connection()['webhook_secret'] ?? '';

		if ( '' === $secret || ! self::is_signed( $body, $signature, $secret ) ) {
			Gateway::log( 'Rejected a webhook request without a valid signature.' );
			status_header( 401 );
			exit;
		}

		$event = json_decode( $body, true );

		if ( is_array( $event['data'] ?? null ) ) {
			OrderSync::apply( $event['data'] );
		}

		status_header( 200 );
		exit;
	}

	/**
	 * Checks the signature Komerza sends in X-Signature: an HMAC SHA256 of the raw body, in hex.
	 *
	 * @param string $body      The raw request body.
	 * @param string $signature The signature that came with it.
	 * @param string $secret    The webhook's signing secret.
	 * @return bool
	 */
	private static function is_signed( string $body, string $signature, string $secret ): bool {
		return '' !== $signature && hash_equals( hash_hmac( 'sha256', $body, $secret ), strtolower( $signature ) );
	}
}
