<?php
/**
 * A small client for the parts of the Komerza API this plugin uses.
 *
 * @package Komerza\WooCommerce
 */

namespace Komerza\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Komerza API with a merchant's API key.
 *
 * Every method returns the response's data on success, or a WP_Error carrying Komerza's own
 * explanation of what went wrong.
 *
 * @see https://docs.komerza.com/api-reference/introduction
 */
final class Api {

	private const BASE_URL = 'https://api.komerza.com';

	/**
	 * The merchant's API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Creates a client for one API key.
	 *
	 * @param string $api_key The merchant's API key.
	 */
	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Fetches a store, which also confirms the key can see it.
	 *
	 * @param string $store_id The store's ID.
	 * @return array|\WP_Error
	 */
	public function get_store( string $store_id ) {
		return $this->request( 'GET', '/stores/' . rawurlencode( $store_id ) );
	}

	/**
	 * Creates an order for a fixed amount, ready to be paid on the hosted checkout.
	 *
	 * @param string $store_id The store's ID.
	 * @param array  $order    The order, as described by the Create Order endpoint.
	 * @return array|\WP_Error
	 */
	public function create_order( string $store_id, array $order ) {
		return $this->request( 'POST', '/stores/' . rawurlencode( $store_id ) . '/orders', $order );
	}

	/**
	 * Fetches an order.
	 *
	 * @param string $store_id The store's ID.
	 * @param string $order_id The order's ID.
	 * @return array|\WP_Error
	 */
	public function get_order( string $store_id, string $order_id ) {
		return $this->request( 'GET', '/stores/' . rawurlencode( $store_id ) . '/orders/' . rawurlencode( $order_id ) );
	}

	/**
	 * Registers a webhook for a store's order events.
	 *
	 * The response carries the signing secret, which Komerza returns only this once.
	 *
	 * @param string $url      Where Komerza should send the events.
	 * @param string $store_id The store whose orders to report.
	 * @return array|\WP_Error
	 */
	public function create_webhook( string $url, string $store_id ) {
		return $this->request(
			'POST',
			'/webhooks',
			array(
				'url'        => $url,
				'eventTypes' => array( 'order.pending', 'order.completed', 'order.cancelled', 'order.refunded' ),
				'storeIds'   => array( $store_id ),
			)
		);
	}

	/**
	 * Removes a webhook.
	 *
	 * @param string $webhook_id The webhook's ID.
	 * @return array|\WP_Error
	 */
	public function delete_webhook( string $webhook_id ) {
		return $this->request( 'DELETE', '/webhooks/' . rawurlencode( $webhook_id ) );
	}

	/**
	 * Sends a request and unwraps Komerza's response envelope.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path below the API's base URL.
	 * @param array|null $body   JSON body, if any.
	 * @return array|\WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null ) {
		$response = wp_remote_request(
			self::BASE_URL . $path,
			array(
				'method'  => $method,
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
					'User-Agent'    => 'KomerzaForWooCommerce/' . VERSION,
				),
				'body'    => null === $body ? null : wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status   = wp_remote_retrieve_response_code( $response );
		$envelope = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $envelope ) || empty( $envelope['success'] ) ) {
			$message = is_array( $envelope ) && ! empty( $envelope['message'] )
				? $envelope['message']
				/* translators: %d: HTTP status code. */
				: sprintf( __( 'Komerza responded with HTTP %d.', 'komerza-for-woocommerce' ), $status );

			return new \WP_Error( 'komerza_api_error', $message, array( 'status' => $status ) );
		}

		return is_array( $envelope['data'] ?? null ) ? $envelope['data'] : array();
	}
}
