<?php
/**
 * Cleans up when the plugin is deleted: removes its webhook from Komerza and its settings from WordPress.
 *
 * Orders keep their Komerza references, so their history still makes sense afterwards.
 *
 * @package Komerza\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$komerza_settings   = get_option( 'woocommerce_komerza_settings', array() );
$komerza_connection = get_option( 'komerza_for_woocommerce_connection', array() );

if ( ! empty( $komerza_settings['api_key'] ) && ! empty( $komerza_connection['webhook_id'] ) ) {
	wp_remote_request(
		'https://api.komerza.com/webhooks/' . rawurlencode( $komerza_connection['webhook_id'] ),
		array(
			'method'  => 'DELETE',
			'timeout' => 10,
			'headers' => array( 'Authorization' => 'Bearer ' . trim( $komerza_settings['api_key'] ) ),
		)
	);
}

delete_option( 'woocommerce_komerza_settings' );
delete_option( 'komerza_for_woocommerce_connection' );
