#!/bin/sh
# Starts a WordPress shop with WooCommerce and this plugin installed, for testing.
#
# Run it again after changing the plugin to copy the changes in. The first run installs everything;
# later runs only refresh the plugin and the shop's address.
#
#   dev/up.sh                       start, or update the plugin
#   CURRENCY=EUR dev/up.sh          first run only: the shop's currency, to match your Komerza store
#   docker compose -f dev/compose.yaml down       stop
#   docker compose -f dev/compose.yaml down -v    stop and delete everything

set -eu
cd "$(dirname "$0")"

compose() { docker compose --progress quiet -f compose.yaml "$@"; }
wp() { compose run --rm -T cli wp "$@"; }

compose up -d db wordpress tunnel

printf 'Waiting for the shop to start'
until compose exec -T db mariadb-admin ping -uroot -pwordpress --silent >/dev/null 2>&1 &&
	compose exec -T wordpress test -f wp-config.php >/dev/null 2>&1; do
	printf '.'
	sleep 1
done

# A quick tunnel gets a new address every time it starts, so read the current one from its log.
url=''
while [ -z "$url" ]; do
	printf '.'
	sleep 1
	url=$(compose logs tunnel 2>/dev/null | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | tail -n 1 || true)
done
echo

# Copy the plugin in as the release zip would have it, owned by WordPress so it can update it too.
plugin=wp-content/plugins/komerza-for-woocommerce
compose exec -T wordpress sh -c "rm -rf $plugin && mkdir -p $plugin"
(cd .. && COPYFILE_DISABLE=1 tar -c --exclude=.git --exclude=.github --exclude=dev --exclude=README.md \
	--exclude=.gitattributes --exclude=.gitignore --exclude=phpcs.xml.dist .) |
	compose exec -T wordpress tar -x --no-same-owner --warning=no-unknown-keyword -C "$plugin"
compose exec -T wordpress chown -R www-data:www-data "$plugin"

if [ ! -f .admin-password ]; then
	LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 20 >.admin-password
fi
password=$(cat .admin-password)

if wp core is-installed >/dev/null 2>&1; then
	wp option update home "$url" >/dev/null
	wp option update siteurl "$url" >/dev/null
else
	wp core install --url="$url" --title='Komerza test shop' --admin_user=admin \
		--admin_password="$password" --admin_email=admin@example.com --skip-email
	wp rewrite structure '/%postname%/'
	wp plugin install woocommerce --activate
	wp option update woocommerce_currency "${CURRENCY:-USD}"
	wp option update woocommerce_coming_soon no
	wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
	wp wc tool run install_pages --user=admin
	wp wc product create --name='Test product' --regular_price=1.00 --virtual=true --user=admin >/dev/null
fi

wp plugin is-active komerza-for-woocommerce || wp plugin activate komerza-for-woocommerce >/dev/null

cat <<EOF

  Shop      $url
  Admin     $url/wp-admin    admin / $password
  Settings  $url/wp-admin/admin.php?page=wc-settings&tab=checkout&section=komerza

  The address changes whenever the tunnel restarts. If it has changed since you last saved the
  Komerza settings, save them again so the webhook follows it.

EOF
