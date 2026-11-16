=== Komerza for WooCommerce ===
Contributors: komerza
Tags: payments, checkout, crypto, paypal, komerza
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take payments through Komerza and keep your WooCommerce orders in step with every sale.

== Description ==

Keep your WooCommerce shop exactly as it is and let Komerza take the payment.

When a customer chooses Komerza at checkout, they are sent to the Komerza checkout to pay for their order. As soon as the payment arrives, the WooCommerce order is marked paid. Refunds, disputes and expired payments are reflected on the order too, and every sale appears in your Komerza dashboard.

* Works with the classic checkout and the block checkout.
* No webhook to set up by hand. The plugin registers its own when you save your settings.
* Orders are only marked paid when Komerza has taken the full order total.

== Installation ==

1. Upload the plugin and activate it. WooCommerce must be active.
2. In Komerza, create an API key under Developers, API Keys with these permissions: View Stores, View Orders, Create Orders, Create Webhooks and Delete Webhooks.
3. In WooCommerce, go to Settings, Payments, Komerza. Paste the API key, enter your store ID, choose a default payment method and save.
4. Turn on Offer Komerza at checkout.

Your site must be reachable over HTTPS from the internet so Komerza can report payments to it.

== Frequently Asked Questions ==

= Which payment methods can customers use? =

Any method enabled on your Komerza store. Customers start on the default method you choose in the settings and can switch on the Komerza checkout.

= Why does Komerza not appear at checkout? =

Komerza charges in your Komerza store's currency, so it only appears when your shop uses the same currency. The settings page tells you which store you are connected to and whether the currencies match.

= How are taxes handled? =

Komerza charges the total WooCommerce calculates, taxes included. If your Komerza store adds tax on top of its prices, turn that off so customers are not taxed twice.

= What happens if Komerza takes less than the order total? =

The order is put on hold with a note instead of being marked paid, so you can check the payment in Komerza before fulfilling it.

= How do I refund an order? =

Refund it in your Komerza dashboard. The WooCommerce order is marked refunded automatically.

= I moved my site to a new address. =

Save the Komerza settings again. The plugin registers its webhook at the new address.

== Changelog ==

= 1.0.0 =
* First release.
