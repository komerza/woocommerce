# Komerza for WooCommerce

A WooCommerce payment gateway that takes payments through [Komerza](https://komerza.com). The shop keeps its own products, cart and checkout. Komerza takes the payment and reports it back.

## How it works

1. The customer chooses Komerza at checkout.
2. The plugin creates a Komerza order for the WooCommerce order's total and sends the customer to the Komerza checkout to pay it.
3. Komerza reports the payment to the plugin's webhook, and the WooCommerce order is marked paid.

The plugin registers that webhook itself when the merchant saves their API key and store ID, and verifies every event against the signing secret Komerza returns. Orders are matched by ID and order key, so the store's other sales and other sites selling through the same store are ignored. When the customer returns from Komerza, the plugin also checks the payment directly, so a late webhook never leaves a paid order looking unpaid.

| Komerza status | WooCommerce order |
| --- | --- |
| Delivered | Paid, if Komerza took at least the order total. Otherwise on hold for review. |
| Pending, Verifying, PartiallyPaid | On hold |
| Cancelled | Failed, so the customer can try again |
| Refunded | Refunded |
| Disputed | On hold |

## Layout

```
komerza-for-woocommerce.php   Plugin header and hooks
src/Api.php                   Komerza API client
src/Gateway.php               Payment gateway, settings and connection
src/OrderSync.php             Applies Komerza order states to WooCommerce orders
src/Webhook.php               Verifies and receives webhook events
src/Blocks.php                Block checkout integration
assets/checkout.js            Block checkout payment method
uninstall.php                 Removes the webhook and settings
readme.txt                    WordPress plugin readme
dev/                          Local test shop
```

## Requirements

WordPress 6.2, WooCommerce 8.0 and PHP 7.4 or newer.

## Development

`dev/up.sh` starts WordPress with WooCommerce and this plugin in Docker, behind a Cloudflare quick tunnel so Komerza can deliver webhooks to it. Run it again to copy in your changes. The script lists the other commands.

Every push is checked on PHP 7.4 and 8.3 and against the WordPress coding standards in `phpcs.xml.dist`.

## Release

1. Bump the version in `komerza-for-woocommerce.php` (the header and `VERSION`) and in `readme.txt` (`Stable tag`, plus a changelog entry).
2. Commit, then push a matching tag such as `v1.0.1`, or publish a release with that tag on GitHub.

The release workflow checks that the tag and the version agree, builds `komerza-for-woocommerce.zip` and attaches it to the release, creating the release if it does not exist yet. The latest one is always at:

```
https://github.com/komerza/woocommerce/releases/latest/download/komerza-for-woocommerce.zip
```

## License

GPL 2.0 or later, like WordPress and WooCommerce.
