<?php
/**
 * Keeps WooCommerce orders in step with the Komerza orders that pay for them.
 *
 * @package Komerza\WooCommerce
 */

namespace Komerza\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the state of a Komerza order to the WooCommerce order it pays for.
 *
 * Every change is decided from the Komerza order's current status rather than from the event that
 * carried it, so events arriving twice or out of order leave the WooCommerce order where it should be.
 */
final class OrderSync {

	/**
	 * Order meta holding the most recent Komerza order created for a WooCommerce order.
	 */
	public const META_KEY = '_komerza_order_id';

	/**
	 * Order meta recording which Komerza order has already been flagged for review, so the note
	 * explaining why is added once.
	 */
	private const REVIEW_META_KEY = '_komerza_review_order_id';

	/**
	 * Brings a WooCommerce order in line with a Komerza order.
	 *
	 * Orders that were not created by this site are ignored, which includes the store's other sales.
	 *
	 * @param array $komerza_order The Komerza order, as the API returns it.
	 */
	public static function apply( array $komerza_order ): void {
		$order = self::find_order( $komerza_order );

		if ( ! $order ) {
			return;
		}

		$komerza_order_id = (string) ( $komerza_order['id'] ?? '' );
		$was_paid         = (bool) $order->get_date_paid();

		switch ( $komerza_order['status'] ?? '' ) {
			case 'Delivered':
				self::complete( $order, $komerza_order );
				break;

			case 'Pending':
			case 'Delivering':
				self::hold( $order, __( 'Payment detected. Waiting for Komerza to confirm it.', 'komerza-for-woocommerce' ) );
				break;

			case 'Verifying':
				self::hold( $order, __( 'Komerza is verifying this payment.', 'komerza-for-woocommerce' ) );
				break;

			case 'PartiallyPaid':
				self::hold( $order, __( 'Part of the payment has arrived. Waiting for the rest.', 'komerza-for-woocommerce' ) );
				break;

			case 'Cancelled':
				// Only the latest attempt decides. An older one expiring says nothing about the payment
				// the customer went on to make.
				if ( ! $was_paid && $order->get_meta( self::META_KEY ) === $komerza_order_id ) {
					$order->update_status( 'failed', __( 'The Komerza payment was cancelled or expired.', 'komerza-for-woocommerce' ) );
				}
				break;

			case 'Refunded':
				if ( $was_paid && ! $order->has_status( 'refunded' ) ) {
					$order->update_status( 'refunded', __( 'Refunded in Komerza.', 'komerza-for-woocommerce' ) );
				}
				break;

			case 'Disputed':
				if ( $was_paid && ! $order->has_status( array( 'on-hold', 'refunded' ) ) ) {
					$order->update_status( 'on-hold', __( 'The customer disputed this payment. See the order in your Komerza dashboard.', 'komerza-for-woocommerce' ) );
				}
				break;
		}
	}

	/**
	 * Checks the payment with Komerza when a customer lands on the order received page.
	 *
	 * The webhook usually arrives first, but this means a customer never looks at an unpaid order
	 * just because it was a moment late.
	 */
	public static function sync_on_return(): void {
		if ( ! is_order_received_page() ) {
			return;
		}

		$order = wc_get_order( absint( get_query_var( 'order-received' ) ) );
		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The order key is the credential here.

		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) || $order->is_paid() ) {
			return;
		}

		$komerza_order_id = (string) $order->get_meta( self::META_KEY );
		$gateway          = Gateway::instance();

		if ( '' === $komerza_order_id || ! $gateway ) {
			return;
		}

		$komerza_order = $gateway->fetch_order( $komerza_order_id );

		if ( ! is_wp_error( $komerza_order ) ) {
			self::apply( $komerza_order );
		}
	}

	/**
	 * Marks the order paid, provided Komerza took at least the order's total in its currency.
	 *
	 * Anything else is held for the merchant to check rather than trusted, so a payment that does
	 * not cover the order can never mark it paid.
	 *
	 * @param \WC_Order $order         The WooCommerce order.
	 * @param array     $komerza_order The Komerza order.
	 */
	private static function complete( \WC_Order $order, array $komerza_order ): void {
		if ( $order->get_date_paid() ) {
			return;
		}

		$komerza_order_id = (string) ( $komerza_order['id'] ?? '' );
		$currency         = strtoupper( (string) ( $komerza_order['currencyCode'] ?? '' ) );
		$amount           = (float) ( $komerza_order['amount'] ?? 0 );

		if ( $currency === $order->get_currency() && self::minor_units( $amount ) >= self::minor_units( (float) $order->get_total() ) ) {
			$order->payment_complete( $komerza_order_id );
			/* translators: %s: Komerza order ID. */
			$order->add_order_note( sprintf( __( 'Paid through Komerza. Komerza order %s.', 'komerza-for-woocommerce' ), $komerza_order_id ) );
			return;
		}

		if ( $order->get_meta( self::REVIEW_META_KEY ) === $komerza_order_id ) {
			return;
		}

		$order->update_meta_data( self::REVIEW_META_KEY, $komerza_order_id );
		$order->update_status( 'on-hold' );
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount Komerza took, 2: the order total, 3: Komerza order ID. */
				__( 'Komerza took %1$s for this order of %2$s, so it has not been marked paid. Check Komerza order %3$s before fulfilling it.', 'komerza-for-woocommerce' ),
				self::format_amount( $amount, $currency ),
				self::format_amount( (float) $order->get_total(), $order->get_currency() ),
				$komerza_order_id
			)
		);
	}

	/**
	 * Holds an order that is waiting on Komerza, so WooCommerce does not cancel it as unpaid.
	 *
	 * @param \WC_Order $order The WooCommerce order.
	 * @param string    $note  Why it is waiting.
	 */
	private static function hold( \WC_Order $order, string $note ): void {
		if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
			$order->update_status( 'on-hold', $note );
		}
	}

	/**
	 * Finds the WooCommerce order a Komerza order was created for.
	 *
	 * The order key must match too. Komerza sends every order on the store to every webhook, so an
	 * ID alone could belong to another site selling through the same store.
	 *
	 * @param array $komerza_order The Komerza order.
	 * @return \WC_Order|null
	 */
	private static function find_order( array $komerza_order ): ?\WC_Order {
		$metadata = $komerza_order['metadata'] ?? null;

		if ( ! is_array( $metadata ) || empty( $metadata['woocommerce_order_id'] ) || empty( $metadata['woocommerce_order_key'] ) ) {
			return null;
		}

		$order = wc_get_order( absint( $metadata['woocommerce_order_id'] ) );

		if ( ! $order instanceof \WC_Order || ! hash_equals( $order->get_order_key(), (string) $metadata['woocommerce_order_key'] ) ) {
			return null;
		}

		return $order;
	}

	/**
	 * Converts an amount to whole minor units, so totals compare exactly.
	 *
	 * @param float $amount The amount.
	 * @return int
	 */
	private static function minor_units( float $amount ): int {
		return (int) round( $amount * ( 10 ** wc_get_price_decimals() ) );
	}

	/**
	 * Formats an amount for an order note, such as "49.99 EUR".
	 *
	 * @param float  $amount   The amount.
	 * @param string $currency The currency code.
	 * @return string
	 */
	private static function format_amount( float $amount, string $currency ): string {
		return trim( wc_format_decimal( $amount, wc_get_price_decimals() ) . ' ' . $currency );
	}
}
