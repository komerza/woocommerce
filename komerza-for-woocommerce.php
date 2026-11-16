<?php
/**
 * Plugin Name:          Komerza for WooCommerce
 * Plugin URI:           https://komerza.com
 * Description:          Take payments through Komerza and keep your WooCommerce orders in step with every sale.
 * Version:              1.0.0
 * Author:               Komerza
 * Author URI:           https://komerza.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          komerza-for-woocommerce
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Update URI:           https://github.com/komerza/woocommerce
 * WC requires at least: 8.0
 *
 * @package Komerza\WooCommerce
 */

namespace Komerza\WooCommerce;

defined( 'ABSPATH' ) || exit;

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;

/**
 * Declares support for WooCommerce's order tables and block checkout.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', PLUGIN_FILE, true );
		}
	}
);

/**
 * Loads the plugin once WooCommerce is available.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( \WC_Payment_Gateway::class ) ) {
			return;
		}

		require_once __DIR__ . '/src/Api.php';
		require_once __DIR__ . '/src/Gateway.php';
		require_once __DIR__ . '/src/OrderSync.php';
		require_once __DIR__ . '/src/Webhook.php';

		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ) {
				$gateways[] = Gateway::class;
				return $gateways;
			}
		);

		add_action( 'woocommerce_api_' . Gateway::ID, array( Webhook::class, 'handle' ) );
		add_action( 'template_redirect', array( OrderSync::class, 'sync_on_return' ) );
	}
);

/**
 * Offers Komerza on the block checkout.
 */
add_action(
	'woocommerce_blocks_payment_method_type_registration',
	static function ( $registry ) {
		require_once __DIR__ . '/src/Blocks.php';
		$registry->register( new Blocks() );
	}
);

/**
 * Adds a Settings link beside the plugin on the Plugins screen.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( PLUGIN_FILE ),
	static function ( array $links ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=komerza' );

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'komerza-for-woocommerce' ) )
		);

		return $links;
	}
);
