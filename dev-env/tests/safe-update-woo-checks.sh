#!/usr/bin/env bash
# Safe Updates on a WooCommerce store. Installs WooCommerce 9.9.7 from
# dev-env/cache (offline), runs safe-update-woo-checks.php, then removes
# WooCommerce including its tables so later suites see a plain site.
#
#   bash tests/safe-update-woo-checks.sh
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1
wpx() { MSYS_NO_PATHCONV=1 docker compose exec -T wp "$@"; }
ZIP=/cache/woocommerce-9.9.7.zip

if ! wpx test -s "$ZIP"; then
  echo "FAIL  $ZIP missing (download woocommerce.9.9.7.zip from downloads.wordpress.org into dev-env/cache/)"
  exit 1
fi

cleanup() {
  wpx wp --user=chrono eval '
    if ( ! defined( "WC_REMOVE_ALL_DATA" ) ) { define( "WC_REMOVE_ALL_DATA", true ); }
    deactivate_plugins( "woocommerce/woocommerce.php" );
    if ( function_exists( "uninstall_plugin" ) ) { uninstall_plugin( "woocommerce/woocommerce.php" ); }
    delete_plugins( array( "woocommerce/woocommerce.php" ) );
    global $wpdb;
    foreach ( $wpdb->get_col( "SHOW TABLES" ) as $t ) {
      if ( preg_match( "/^" . $wpdb->prefix . "(wc_|woocommerce_|actionscheduler_)/", $t ) ) { $wpdb->query( "DROP TABLE IF EXISTS `$t`" ); }
    }
    foreach ( array( "cart", "checkout", "myaccount", "shop", "refund_returns" ) as $p ) {
      $id = (int) get_option( "woocommerce_{$p}_page_id" ); if ( $id ) { wp_delete_post( $id, true ); }
    }
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE \"woocommerce\\_%\" OR option_name LIKE \"\\_transient%wc\\_%\"" );
  ' >/dev/null 2>&1
}
trap cleanup EXIT

echo "== WooCommerce not installed: home page only =="
KEYS="$(wpx wp --user=chrono eval 'echo implode( ",", array_keys( WPChrono\Services\SafeUpdates::targets() ) );' 2>/dev/null | tr -d '\r')"
if [ "$KEYS" = "front" ]; then echo "PASS  without WooCommerce only the home page is checked  ($KEYS)"; else echo "FAIL  without WooCommerce only the home page is checked  ($KEYS)"; fi

echo; echo "== Install WooCommerce $(basename "$ZIP" .zip | sed 's/woocommerce-//') (offline) =="
wpx wp plugin install "$ZIP" --activate >/dev/null 2>&1
wpx sh -c 'chown -R $(stat -c %u /var/www/html/wp-content) /var/www/html/wp-content/plugins/woocommerce'
# New shops start in "coming soon" mode (WooCommerce 9.3+); the tests need a live store.
wpx wp eval 'update_option( "woocommerce_coming_soon", "no" ); update_option( "woocommerce_store_pages_only", "no" );' >/dev/null 2>&1
wpx wp eval 'WC_Install::create_pages(); echo "pages: ", wc_get_page_id( "cart" ), " ", wc_get_page_id( "checkout" ), " ", wc_get_page_id( "myaccount" ), "\n";' 2>/dev/null

echo
wpx wp eval-file /tests/safe-update-woo-checks.php --user=chrono 2>&1
