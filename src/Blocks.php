<?php
/**
 * Block checkout support.
 *
 * @package Komerza\WooCommerce
 */

namespace Komerza\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the gateway to the block checkout. Payment itself still runs through the gateway.
 */
final class Blocks extends AbstractPaymentMethodType {

	/**
	 * The payment method's name, matching the gateway's ID.
	 *
	 * @var string
	 */
	protected $name = Gateway::ID;

	/**
	 * Loads the gateway's settings.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_' . Gateway::ID . '_settings', array() );
	}

	/**
	 * Whether the merchant has turned the gateway on.
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled' );
	}

	/**
	 * Registers the script that adds Komerza to the checkout.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'komerza-for-woocommerce-checkout',
			plugins_url( 'assets/checkout.js', PLUGIN_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			VERSION,
			true
		);

		return array( 'komerza-for-woocommerce-checkout' );
	}

	/**
	 * What the checkout script needs to show the payment method, taken from the gateway so both
	 * checkouts show the same title and description, defaults included.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = Gateway::instance();

		return array(
			'title'       => $gateway ? $gateway->get_title() : '',
			'description' => $gateway ? $gateway->get_description() : '',
		);
	}
}
