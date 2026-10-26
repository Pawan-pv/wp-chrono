<?php
/**
 * Removes WooCommerce data — products, orders, coupons, sessions and lookup
 * tables — without touching pages, posts, users or WooCommerce settings.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Reset;

use WPChrono\Services\Database;

defined( 'ABSPATH' ) || exit;

final class WooCommerceCleaner extends Cleaner {

	const POST_TYPES = array( 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_coupon' );

	/** Data tables (unprefixed). Configuration tables — tax rates, shipping zones, webhooks, attributes — are kept. */
	const DATA_TABLES = array(
		'wc_orders',
		'wc_orders_meta',
		'wc_order_addresses',
		'wc_order_operational_data',
		'wc_order_stats',
		'wc_order_product_lookup',
		'wc_order_tax_lookup',
		'wc_order_coupon_lookup',
		'wc_customer_lookup',
		'wc_product_meta_lookup',
		'wc_product_attributes_lookup',
		'wc_download_log',
		'wc_reserved_stock',
		'wc_rate_limits',
		'woocommerce_sessions',
		'woocommerce_order_items',
		'woocommerce_order_itemmeta',
		'woocommerce_downloadable_product_permissions',
		'woocommerce_payment_tokens',
		'woocommerce_payment_tokenmeta',
	);

	const POST_BATCH = 500;

	public function availability() {
		if ( ! $this->tables() && ! $this->post_counts() ) {
			return 'No WooCommerce data found on this site.';
		}
		return true;
	}

	public function preview(): array {
		$items   = 0;
		$bytes   = 0;
		$details = array();

		foreach ( $this->post_counts() as $type => $count ) {
			$items    += $count;
			$details[] = sprintf( '%d × %s', $count, $type );
		}
		foreach ( $this->tables() as $table => $stats ) {
			$items    += $stats['rows'];
			$bytes    += $stats['bytes'];
			$details[] = sprintf( '%s (~%d rows)', $table, $stats['rows'] );
		}

		return self::summary( $items, $bytes, $details, true );
	}

	public function run( array $options, float $deadline ): array {
		global $wpdb;
		$log       = array();
		$processed = 0;

		// Phase 1: truncate data tables (cheap, metadata-only operations).
		Database::without_foreign_keys(
			function () use ( &$log, &$processed ) {
				global $wpdb;
				foreach ( array_keys( $this->tables() ) as $table ) {
					if ( Database::table_has_rows( $table ) ) {
						$wpdb->query( 'TRUNCATE TABLE ' . Database::quote_identifier( $table ) ); // phpcs:ignore WordPress.DB
						$log[] = "Truncated {$table}.";
						$processed++;
					}
				}
			}
		);

		// Phase 2: delete WooCommerce posts with their meta, comments (order notes) and term links.
		$placeholders = implode( ',', array_fill( 0, count( self::POST_TYPES ), '%s' ) );
		$deleted      = 0;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		while ( true ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($placeholders) LIMIT %d", array_merge( self::POST_TYPES, array( self::POST_BATCH ) ) ) );
			if ( ! $ids ) {
				break;
			}
			$in = Database::id_list( $ids );
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)" );
			$wpdb->query( "DELETE cm FROM {$wpdb->commentmeta} cm INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_post_ID IN ($in)" );
			$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_post_ID IN ($in)" );
			$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($in)" );
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($in)" );
			$deleted += count( $ids );

			if ( self::out_of_time( $deadline ) ) {
				$log[] = sprintf( 'Deleted %d WooCommerce posts so far…', $deleted );
				return self::result( false, $processed + $deleted, $log );
			}
		}

		// Phase 3: fix product taxonomy counts and drop cached WooCommerce transients.
		$wpdb->query(
			"UPDATE {$wpdb->term_taxonomy} tt SET tt.count = (SELECT COUNT(*) FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id)
			WHERE tt.taxonomy IN ('product_cat','product_tag','product_type','product_visibility','product_shipping_class')"
		);
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_wc_' ) . '%', $wpdb->esc_like( '_transient_timeout_wc_' ) . '%' ) );
		// phpcs:enable
		wp_cache_flush();

		$log[] = sprintf( 'Deleted %d products, orders and coupons.', $deleted );
		$log[] = 'Recounted product taxonomies and cleared WooCommerce caches.';

		return self::result( true, $processed + $deleted, $log );
	}

	/**
	 * @return array<string,array{rows:int,bytes:int}>
	 */
	private function tables(): array {
		global $wpdb;
		$wanted = array_map(
			static function ( $t ) use ( $wpdb ) {
				return $wpdb->prefix . $t;
			},
			self::DATA_TABLES
		);
		return array_intersect_key( Database::site_tables(), array_flip( $wanted ) );
	}

	/**
	 * @return array<string,int>
	 */
	private function post_counts(): array {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( self::POST_TYPES ), '%s' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT post_type, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type IN ($placeholders) GROUP BY post_type", self::POST_TYPES ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( 'intval', array_column( (array) $rows, 'n', 'post_type' ) );
	}
}
