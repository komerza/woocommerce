<?php
/**
 * The Komerza payment gateway.
 *
 * @package Komerza\WooCommerce
 */

namespace Komerza\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Sends customers to the Komerza checkout to pay for their WooCommerce orders.
 *
 * At checkout the gateway creates a Komerza order for the WooCommerce order's total and redirects
 * the customer to pay it. Komerza reports the payment back through a webhook, which the gateway
 * registers for itself when the merchant saves their API key and store ID.
 */
final class Gateway extends \WC_Payment_Gateway {

	public const ID = 'komerza';

	private const CHECKOUT_URL = 'https://checkout.komerza.com/checkout/';

	private const API_KEYS_URL = 'https://dashboard.komerza.com/account?apikeys';

	/**
	 * Holds what the plugin learns when it connects: the store's name and currency, and the
	 * webhook's ID and signing secret. Kept apart from the settings so it never appears in the form.
	 */
	private const CONNECTION_OPTION = 'komerza_for_woocommerce_connection';

	/**
	 * Sets up the gateway and its settings.
	 */
	public function __construct() {
		$this->id                   = self::ID;
		$this->method_title         = __( 'Komerza', 'komerza-for-woocommerce' );
		$this->method_description   = __( 'Customers pay on the Komerza checkout, and their orders here update as soon as the payment arrives.', 'komerza-for-woocommerce' );
		$this->has_fields           = false;
		$this->supports             = array( 'products' );
		$this->view_transaction_url = 'https://dashboard.komerza.com/orders/%s';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Returns the gateway instance WooCommerce is using, if it has loaded one.
	 *
	 * @return self|null
	 */
	public static function instance(): ?self {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$gateway  = $gateways[ self::ID ] ?? null;

		return $gateway instanceof self ? $gateway : null;
	}

	/**
	 * Returns what the plugin stored when it last connected to Komerza.
	 *
	 * @return array
	 */
	public static function connection(): array {
		$connection = get_option( self::CONNECTION_OPTION, array() );

		return is_array( $connection ) ? $connection : array();
	}

	/**
	 * Writes a message to the WooCommerce log, under Komerza.
	 *
	 * @param string $message What happened.
	 */
	public static function log( string $message ): void {
		wc_get_logger()->error( $message, array( 'source' => self::ID ) );
	}

	/**
	 * Defines the settings form.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'        => array(
				'title'   => __( 'Enable', 'komerza-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer Komerza at checkout', 'komerza-for-woocommerce' ),
				'default' => 'no',
			),
			'title'          => array(
				'title'       => __( 'Title', 'komerza-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'The name customers see at checkout.', 'komerza-for-woocommerce' ),
				'default'     => __( 'Pay online', 'komerza-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'    => array(
				'title'       => __( 'Description', 'komerza-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown to customers when they choose this payment method.', 'komerza-for-woocommerce' ),
				'default'     => __( 'You will be taken to Komerza to pay securely.', 'komerza-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'api_key'        => array(
				'title'       => __( 'API key', 'komerza-for-woocommerce' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: 1: link to the API keys page, 2: list of permissions. */
					__( 'Create a key under <a href="%1$s" target="_blank" rel="noopener">Developers, API Keys</a> with these permissions: %2$s.', 'komerza-for-woocommerce' ),
					esc_url( self::API_KEYS_URL ),
					'View Stores, View Orders, Create Orders, Create Webhooks, Delete Webhooks'
				),
			),
			'store_id'       => array(
				'title'       => __( 'Store ID', 'komerza-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'The Komerza store these sales belong to.', 'komerza-for-woocommerce' ),
			),
			'payment_method' => array(
				'title'       => __( 'Default payment method', 'komerza-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'The method customers start on at the Komerza checkout. They can switch to any other method enabled on your Komerza store.', 'komerza-for-woocommerce' ),
				'default'     => 'stripe',
				'options'     => self::payment_methods(),
			),
			'send_receipt'   => array(
				'title'   => __( 'Receipt', 'komerza-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Email customers the Komerza receipt as well as the WooCommerce one', 'komerza-for-woocommerce' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Hides the gateway until the store is connected and sells in the same currency as this shop.
	 *
	 * Komerza charges in the store's own currency, so a shop pricing in anything else would be
	 * charged the wrong amount.
	 *
	 * @return bool
	 */
	public function is_available() {
		$connection = self::connection();

		return parent::is_available()
			&& ! empty( $connection['webhook_secret'] )
			&& get_woocommerce_currency() === ( $connection['currency'] ?? '' );
	}

	/**
	 * Creates the Komerza order and sends the customer to pay it.
	 *
	 * @param int $order_id The WooCommerce order's ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$komerza_order = $this->api()->create_order(
			$this->store_id(),
			array(
				'cost'            => (float) $order->get_total(),
				'gateway'         => $this->get_option( 'payment_method' ),
				'emailAddress'    => $order->get_billing_email(),
				'countryCode'     => $order->get_billing_country(),
				'ipAddress'       => $order->get_customer_ip_address(),
				'userAgent'       => $order->get_customer_user_agent(),
				'returnUrl'       => $this->get_return_url( $order ),
				'sendReceipt'     => 'yes' === $this->get_option( 'send_receipt' ),
				'buyerProtection' => false,
				'metadata'        => array(
					'woocommerce_order_id'  => (string) $order->get_id(),
					'woocommerce_order_key' => $order->get_order_key(),
					'woocommerce_site'      => home_url(),
				),
			)
		);

		if ( is_wp_error( $komerza_order ) || empty( $komerza_order['id'] ) ) {
			$reason = is_wp_error( $komerza_order )
				? $komerza_order->get_error_message()
				: __( 'Komerza did not return an order.', 'komerza-for-woocommerce' );

			self::log( sprintf( 'Could not create a Komerza order for order %d: %s', $order->get_id(), $reason ) );

			/* translators: %s: the reason Komerza gave. */
			wc_add_notice( sprintf( __( 'We could not start your payment. %s', 'komerza-for-woocommerce' ), esc_html( $reason ) ), 'error' );

			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( OrderSync::META_KEY, $komerza_order['id'] );
		$order->save();

		/* translators: %s: Komerza order ID. */
		$order->add_order_note( sprintf( __( 'Customer sent to Komerza to pay. Komerza order %s.', 'komerza-for-woocommerce' ), $komerza_order['id'] ) );

		return array(
			'result'   => 'success',
			'redirect' => self::CHECKOUT_URL . rawurlencode( $komerza_order['id'] ),
		);
	}

	/**
	 * Fetches a Komerza order through this gateway's connection.
	 *
	 * @param string $komerza_order_id The Komerza order's ID.
	 * @return array|\WP_Error
	 */
	public function fetch_order( string $komerza_order_id ) {
		return $this->api()->get_order( $this->store_id(), $komerza_order_id );
	}

	/**
	 * Saves the settings, then connects to Komerza with them.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		$this->connect();

		return $saved;
	}

	/**
	 * Shows the settings, with the state of the connection above them.
	 */
	public function admin_options() {
		echo '<h2>' . esc_html( $this->get_method_title() );
		wc_back_link( __( 'Return to payments', 'komerza-for-woocommerce' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) );
		echo '</h2>';
		echo wp_kses_post( wpautop( $this->get_method_description() ) );

		$this->connection_notice();

		echo '<table class="form-table">' . $this->generate_settings_html( $this->get_form_fields(), false ) . '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by WooCommerce.
	}

	/**
	 * Confirms the API key and store, and makes sure Komerza knows where to send order events.
	 *
	 * The webhook is registered again only when the key, the store or this site's address changes,
	 * because Komerza reveals a webhook's signing secret just once, when it is created.
	 */
	private function connect(): void {
		$api_key  = trim( $this->get_option( 'api_key' ) );
		$store_id = $this->store_id();

		if ( '' === $api_key || '' === $store_id ) {
			delete_option( self::CONNECTION_OPTION );
			return;
		}

		$api   = new Api( $api_key );
		$store = $api->get_store( $store_id );

		if ( is_wp_error( $store ) ) {
			/* translators: %s: the reason Komerza gave. */
			\WC_Admin_Settings::add_error( sprintf( __( 'Could not connect to Komerza. %s', 'komerza-for-woocommerce' ), $store->get_error_message() ) );
			return;
		}

		$connection  = self::connection();
		$webhook_url = WC()->api_request_url( self::ID );
		$fingerprint = hash( 'sha256', $api_key . '|' . $store_id . '|' . $webhook_url );

		if ( ( $connection['fingerprint'] ?? '' ) !== $fingerprint || empty( $connection['webhook_secret'] ) ) {
			if ( ! empty( $connection['webhook_id'] ) ) {
				$api->delete_webhook( $connection['webhook_id'] );
			}

			$webhook = $api->create_webhook( $webhook_url, $store_id );

			if ( is_wp_error( $webhook ) || empty( $webhook['secret'] ) ) {
				delete_option( self::CONNECTION_OPTION );

				$reason = is_wp_error( $webhook ) ? $webhook->get_error_message() : '';
				/* translators: %s: the reason Komerza gave. */
				\WC_Admin_Settings::add_error( sprintf( __( 'Connected to Komerza, but could not register the webhook that reports payments. %s', 'komerza-for-woocommerce' ), $reason ) );
				return;
			}

			$connection = array(
				'fingerprint'    => $fingerprint,
				'webhook_id'     => $webhook['webhook']['id'] ?? '',
				'webhook_secret' => $webhook['secret'],
			);
		}

		$connection['store_name'] = $store['name'] ?? '';
		$connection['currency']   = strtoupper( $store['currencyCode'] ?? '' );

		update_option( self::CONNECTION_OPTION, $connection, false );
	}

	/**
	 * Tells the merchant which store they are connected to, and whether checkout can offer it.
	 */
	private function connection_notice(): void {
		$connection = self::connection();

		if ( empty( $connection['webhook_secret'] ) ) {
			return;
		}

		$store    = empty( $connection['store_name'] ) ? __( 'your Komerza store', 'komerza-for-woocommerce' ) : $connection['store_name'];
		$currency = $connection['currency'] ?? '';

		if ( get_woocommerce_currency() === $currency ) {
			$type = 'success';
			/* translators: 1: store name, 2: currency code. */
			$message = sprintf( __( 'Connected to %1$s. Payments are taken in %2$s.', 'komerza-for-woocommerce' ), $store, $currency );
		} else {
			$type = 'warning';
			/* translators: 1: store name, 2: the store's currency code, 3: this shop's currency code. */
			$message = sprintf( __( 'Connected to %1$s, which sells in %2$s while this shop sells in %3$s. Komerza stays hidden at checkout until the two match.', 'komerza-for-woocommerce' ), $store, $currency, get_woocommerce_currency() );
		}

		printf( '<div class="notice notice-%s inline"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	/**
	 * Returns an API client for the saved key.
	 *
	 * @return Api
	 */
	private function api(): Api {
		return new Api( trim( $this->get_option( 'api_key' ) ) );
	}

	/**
	 * Returns the saved store ID.
	 *
	 * @return string
	 */
	private function store_id(): string {
		return trim( $this->get_option( 'store_id' ) );
	}

	/**
	 * The payment methods a Komerza store can take.
	 *
	 * @return array
	 */
	private static function payment_methods(): array {
		$cards  = __( 'Cards and wallets', 'komerza-for-woocommerce' );
		$crypto = __( 'Crypto', 'komerza-for-woocommerce' );

		return array(
			$cards   => array(
				'kmrza'        => 'Komerza Pay',
				'stripe'       => 'Stripe',
				'tagadapay'    => 'TagadaPay',
				'nmi'          => 'NMI',
				'authorizenet' => 'Authorize.net',
				'square'       => 'Square',
				'sumup'        => 'SumUp',
				'mollie'       => 'Mollie',
				'paddle'       => 'Paddle',
				'fastspring'   => 'FastSpring',
				'shopify'      => 'Shopify',
				'skinloop'     => 'Skinloop',
				'storrik'      => 'Storrik',
				'moneymotion'  => 'MoneyMotion',
				'pandabase'    => 'Pandabase',
				'venpayr'      => 'VenPayr',
				'cashapp'      => 'Cash App',
			),
			'PayPal' => array(
				'paypal'    => 'PayPal',
				'paypalfaf' => __( 'PayPal Friends and Family', 'komerza-for-woocommerce' ),
			),
			$crypto  => array(
				'btc'       => 'Bitcoin',
				'eth'       => 'Ethereum',
				'ltc'       => 'Litecoin',
				'sol'       => 'Solana',
				'xmr'       => 'Monero',
				'doge'      => 'Dogecoin',
				'bch'       => 'Bitcoin Cash',
				'trx'       => 'Tron',
				'usdterc20' => __( 'Tether on Ethereum', 'komerza-for-woocommerce' ),
				'usdtsol'   => __( 'Tether on Solana', 'komerza-for-woocommerce' ),
				'usdtbsc'   => __( 'Tether on BNB Smart Chain', 'komerza-for-woocommerce' ),
				'usdcsol'   => __( 'USD Coin on Solana', 'komerza-for-woocommerce' ),
				'usdcbsc'   => __( 'USD Coin on BNB Smart Chain', 'komerza-for-woocommerce' ),
			),
		);
	}
}
