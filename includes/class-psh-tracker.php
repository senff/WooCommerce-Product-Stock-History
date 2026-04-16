<?php
defined( 'ABSPATH' ) || exit;

class PSH_Tracker {

	/**
	 * 'bulk_edit' | 'quick_edit' | '' (empty = neither).
	 * Set once at init before any save hooks fire.
	 *
	 * @var string
	 */
	private static string $edit_context = '';

	public static function init(): void {
		// Detect bulk edit (product list bulk action) and quick edit (inline save)
		// before any save hooks fire, so on_product_set_stock can use the context.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		// These are WooCommerce's own form submissions; nonces are verified by WooCommerce itself.
		if ( ! empty( $_POST['bulk_edit'] ) ) {
			self::$edit_context = 'bulk_edit';
		} elseif ( isset( $_REQUEST['action'] ) && 'inline-save' === $_REQUEST['action'] ) {
			self::$edit_context = 'quick_edit';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		// Stock reduced by a placed order.
		add_action( 'woocommerce_reduce_order_stock', array( __CLASS__, 'on_order_stock_reduced' ) );

		// Stock restored when an order is cancelled or fails.
		add_action( 'woocommerce_restore_order_stock', array( __CLASS__, 'on_order_stock_restored' ) );

		// Stock restored when a refund is created and "Restock items" is ticked.
		add_action( 'woocommerce_restock_refunded_item', array( __CLASS__, 'on_refund_restock' ), 10, 5 );

		// Manual / API stock change for simple products.
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'on_product_set_stock' ) );

		// Same, but for variation-level stock (variable products).
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_product_set_stock' ) );

		// Bulk edit and quick edit from the product list — handled separately
		// because woocommerce_product_set_stock may not fire reliably in that context.
		add_action( 'woocommerce_product_bulk_edit_save', array( __CLASS__, 'on_bulk_edit_save' ) );
		add_action( 'woocommerce_product_quick_edit_save', array( __CLASS__, 'on_quick_edit_save' ) );

		// Snapshot the persisted stock value just before a WooCommerce save.
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'before_product_save' ), 10, 1 );
		add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'after_product_save' ), 10, 1 );

		// Catch third-party plugins that write _stock directly via update_post_meta,
		// bypassing all WooCommerce hooks, so the failsafe can pick them up.
		add_action( 'updated_post_meta', array( __CLASS__, 'on_stock_meta_updated' ), 10, 4 );

		// Detect admin order-item edits (quantity changed on the order edit screen).
		add_action( 'woocommerce_before_save_order_items', array( __CLASS__, 'on_before_save_order_items' ) );

		// Remove history when a product or variation is permanently deleted.
		add_action( 'before_delete_post', array( __CLASS__, 'on_product_deleted' ) );
	}

	// ------------------------------------------------------------------
	// Order-item editing
	// ------------------------------------------------------------------

	/**
	 * Fired before WooCommerce saves order items from the admin order edit screen.
	 * Captures the order ID so subsequent stock changes can be attributed to it.
	 *
	 * @param int $order_id
	 */
	public static function on_before_save_order_items( int $order_id ): void {
		self::$order_edit_id = $order_id;
	}

	// ------------------------------------------------------------------
	// Order-driven stock changes
	// ------------------------------------------------------------------

	/**
	 * Fired by WooCommerce after stock has been decremented for each order item.
	 *
	 * @param WC_Order $order
	 */
	public static function on_order_stock_reduced( WC_Order $order ): void {
		self::$in_order_context = true;

		// Group quantities by stock product so that multiple items sharing the
		// same stock (e.g. two parent-managed variations) chain correctly.
		$groups = array();

		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product = $item->get_product();
			if ( ! $product || ! $product->managing_stock() || $product->is_type( 'variable' ) ) {
				continue;
			}

			$stock_product = self::resolve_stock_product( $product );
			$stock_id      = $stock_product->get_id();

			if ( ! isset( $groups[ $stock_id ] ) ) {
				$groups[ $stock_id ] = array( 'product' => $stock_product, 'qtys' => array() );
			}
			$groups[ $stock_id ]['qtys'][] = (float) $item->get_quantity();
		}

		foreach ( $groups as $stock_id => $group ) {
			// Stock is already at its final (reduced) value. Walk backwards to reconstruct the chain.
			$running = (float) $group['product']->get_stock_quantity() + array_sum( $group['qtys'] );

			foreach ( $group['qtys'] as $qty ) {
				$old_stock = $running;
				$new_stock = $running - $qty;
				PSH_DB::insert( $stock_id, $old_stock, $new_stock, 'order', $order->get_id(), null, '' );
				$running = $new_stock;
			}
		}

		self::$in_order_context = false;
	}

	/**
	 * Fired by WooCommerce after stock has been restored when an order is
	 * cancelled or fails. Uses $order->get_status() to distinguish the two.
	 *
	 * @param WC_Order $order
	 */
	public static function on_order_stock_restored( WC_Order $order ): void {
		self::$in_order_context = true;

		$reason = ( 'failed' === $order->get_status() ) ? 'failed_order' : 'cancellation';

		$groups = array();

		foreach ( $order->get_items() as $item ) {
			/** @var WC_Order_Item_Product $item */
			$product = $item->get_product();
			if ( ! $product || ! $product->managing_stock() || $product->is_type( 'variable' ) ) {
				continue;
			}

			$stock_product = self::resolve_stock_product( $product );
			$stock_id      = $stock_product->get_id();

			if ( ! isset( $groups[ $stock_id ] ) ) {
				$groups[ $stock_id ] = array( 'product' => $stock_product, 'qtys' => array() );
			}
			$groups[ $stock_id ]['qtys'][] = (float) $item->get_quantity();
		}

		foreach ( $groups as $stock_id => $group ) {
			// Stock is already at its final (restored) value. Walk forwards to reconstruct the chain.
			$running = (float) $group['product']->get_stock_quantity() - array_sum( $group['qtys'] );

			foreach ( $group['qtys'] as $qty ) {
				$old_stock = $running;
				$new_stock = $running + $qty;
				PSH_DB::insert( $stock_id, $old_stock, $new_stock, $reason, $order->get_id(), null, '' );
				$running = $new_stock;
			}
		}

		self::$in_order_context = false;
	}

	/**
	 * Fired by WooCommerce after restocking an individual line item during a refund.
	 * Note: the 5th parameter is WC_Product (not WC_Order_Refund as one might expect).
	 *
	 * @param int        $product_id
	 * @param mixed      $old_stock
	 * @param mixed      $new_stock  New stock level, or false on failure.
	 * @param WC_Order   $order
	 * @param WC_Product $product
	 */
	public static function on_refund_restock( int $product_id, $old_stock, $new_stock, WC_Order $order, WC_Product $product ): void {
		if ( false === $new_stock ) {
			return;
		}

		$stock_product = self::resolve_stock_product( $product );

		PSH_DB::insert( $stock_product->get_id(), (float) $old_stock, (float) $new_stock, 'refund', $order->get_id(), null, '' );

		// Prevent on_product_set_stock from double-logging this as manual/api.
		self::$refund_restocked[ $stock_product->get_id() ] = true;
	}

	// ------------------------------------------------------------------
	// Manual / API stock changes
	// ------------------------------------------------------------------

	/**
	 * Fired by WooCommerce (via the data store) after a stock quantity change is
	 * persisted. Covers both admin product-edit saves and REST API updates.
	 *
	 * @param WC_Product $product
	 */
	public static function on_product_set_stock( WC_Product $product ): void {
		$product_id = $product->get_id();

		// Bulk/quick edit is handled by their own hooks instead.
		if ( '' !== self::$edit_context ) {
			return;
		}

		// Already logged by an order/refund hook.
		if ( self::$in_order_context || ! empty( self::$refund_restocked[ $product_id ] ) ) {
			return;
		}

		$new_stock = (float) $product->get_stock_quantity();
		$old_stock = self::get_snapshot( $product_id );

		if ( null === $old_stock || $old_stock === $new_stock ) {
			return;
		}

		if ( null !== self::$order_edit_id ) {
			PSH_DB::insert( $product_id, $old_stock, $new_stock, 'order_edit', self::$order_edit_id, null, '' );
		} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			PSH_DB::insert( $product_id, $old_stock, $new_stock, 'api', null, get_current_user_id() ?: null, '' );
		} else {
			PSH_DB::insert( $product_id, $old_stock, $new_stock, 'manual', null, get_current_user_id() ?: null, '' );
		}

		// Refresh snapshot so a second rapid save in the same request doesn't re-log.
		self::set_snapshot( $product_id, $new_stock );
	}

	/**
	 * Fired by WooCommerce after each product is saved during a bulk or quick edit.
	 * Handles the stock-change log that on_product_set_stock skips for bulk edits.
	 *
	 * @param WC_Product $product
	 */
	public static function on_bulk_edit_save( WC_Product $product ): void {
		self::log_admin_edit_save( $product, 'bulk_edit' );
	}

	public static function on_quick_edit_save( WC_Product $product ): void {
		self::log_admin_edit_save( $product, 'quick_edit' );
	}

	private static function log_admin_edit_save( WC_Product $product, string $reason ): void {
		$product_id = $product->get_id();

		if ( ! $product->managing_stock() ) {
			return;
		}

		$new_stock = $product->get_stock_quantity();
		if ( null === $new_stock ) {
			return;
		}
		$new_stock = (float) $new_stock;

		// Prefer the pre-save snapshot; fall back to the last logged value.
		$old_stock = self::$bulk_snapshots[ $product_id ] ?? null;
		unset( self::$bulk_snapshots[ $product_id ] );

		if ( null === $old_stock ) {
			$last_entry = PSH_DB::get_last_entry( $product_id );
			$old_stock  = $last_entry ? (float) $last_entry['new_stock'] : null;
		}

		if ( null === $old_stock || $old_stock === $new_stock ) {
			return;
		}

		$user_id = get_current_user_id() ?: null;
		PSH_DB::insert( $product_id, $old_stock, $new_stock, $reason, null, $user_id, '' );
	}

	// ------------------------------------------------------------------
	// Before / after product save — snapshot + failsafe tracking
	// ------------------------------------------------------------------

	/** @var bool True while on_order_stock_reduced / on_order_stock_restored is executing. */
	private static bool $in_order_context = false;

	/** @var int|null Order ID being edited via the admin order-items save, if any. */
	private static ?int $order_edit_id = null;

	/** @var array<int,float|null> Persisted stock captured before each save. */
	private static array $snapshots = array();

	/**
	 * Persisted stock captured before each bulk/quick edit save.
	 * Kept separately because after_product_save clears $snapshots before
	 * woocommerce_product_bulk_edit_save / woocommerce_product_quick_edit_save fires.
	 *
	 * @var array<int,float|null>
	 */
	private static array $bulk_snapshots = array();

	/** @var array<int,bool> Products whose stock was just restored by a refund. */
	private static array $refund_restocked = array();

	/** @var array<int,bool> Products touched this request — checked at shutdown. */
	private static array $pending_failsafe = array();

	public static function before_product_save( WC_Product $product ): void {
		$id = $product->get_id();
		if ( ! $id ) {
			return;
		}

		if ( ! isset( self::$snapshots[ $id ] ) ) {
			$persisted              = get_post_meta( $id, '_stock', true );
			self::$snapshots[ $id ] = ( '' !== $persisted ) ? (float) $persisted : null;
		}

		// Preserve a copy for the bulk/quick edit hook, which fires after
		// after_product_save has already cleared the main snapshot.
		if ( '' !== self::$edit_context && ! isset( self::$bulk_snapshots[ $id ] ) ) {
			self::$bulk_snapshots[ $id ] = self::$snapshots[ $id ];
		}

		self::queue_failsafe( $id );
	}

	public static function after_product_save( WC_Product $product ): void {
		$id = $product->get_id();
		unset( self::$snapshots[ $id ], self::$refund_restocked[ $id ] );
	}

	/**
	 * Catches third-party plugins that write _stock directly via update_post_meta,
	 * bypassing WooCommerce product hooks entirely.
	 *
	 * @param int    $meta_id
	 * @param int    $object_id
	 * @param string $meta_key
	 * @param mixed  $meta_value
	 */
	public static function on_stock_meta_updated( $meta_id, $object_id, $meta_key, $meta_value ): void {
		if ( '_stock' !== $meta_key || ! $object_id ) {
			return;
		}
		if ( ! in_array( get_post_type( $object_id ), array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		self::queue_failsafe( (int) $object_id );
	}

	// ------------------------------------------------------------------
	// Shutdown failsafe
	// ------------------------------------------------------------------

	/**
	 * Runs at PHP shutdown. For every product touched this request, compares
	 * the actual _stock meta against the last logged value and fills any gap.
	 */
	public static function failsafe_check(): void {
		foreach ( array_keys( self::$pending_failsafe ) as $product_id ) {
			$current_stock = get_post_meta( $product_id, '_stock', true );
			if ( '' === $current_stock ) {
				continue; // Stock management not active.
			}
			$current_stock = (float) $current_stock;

			$last_entry = PSH_DB::get_last_entry( $product_id );

			if ( ! $last_entry ) {
				// No history yet — log the current stock as the initial value.
				PSH_DB::insert( $product_id, 0, $current_stock, 'initial', null, null, '' );
				continue;
			}

			$last_logged = (float) $last_entry['new_stock'];
			if ( $current_stock === $last_logged ) {
				continue;
			}

			if ( '' !== self::$edit_context ) {
				$user_id = get_current_user_id() ?: null;
				PSH_DB::insert( $product_id, $last_logged, $current_stock, self::$edit_context, null, $user_id, '' );
			} elseif ( null !== self::$order_edit_id ) {
				PSH_DB::insert( $product_id, $last_logged, $current_stock, 'order_edit', self::$order_edit_id, null, '' );
			} else {
				PSH_DB::insert( $product_id, $last_logged, $current_stock, 'unknown', null, null, '' );
			}
		}
	}

	/**
	 * Fired when a post is permanently deleted. Removes history rows for
	 * products and variations so the table doesn't accumulate orphaned data.
	 *
	 * @param int $post_id
	 */
	public static function on_product_deleted( int $post_id ): void {
		if ( ! in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
			return;
		}
		PSH_DB::delete_product_history( $post_id );
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	private static function get_snapshot( int $product_id ): ?float {
		return self::$snapshots[ $product_id ] ?? null;
	}

	private static function set_snapshot( int $product_id, float $value ): void {
		self::$snapshots[ $product_id ] = $value;
	}

	private static function queue_failsafe( int $product_id ): void {
		if ( ! isset( self::$pending_failsafe[ $product_id ] ) ) {
			self::$pending_failsafe[ $product_id ] = true;
			if ( count( self::$pending_failsafe ) === 1 ) {
				add_action( 'shutdown', array( __CLASS__, 'failsafe_check' ) );
			}
		}
	}

	/**
	 * Returns the product whose stock is actually being managed.
	 * For variations that delegate stock to their parent, returns the parent product.
	 *
	 * @param WC_Product $product
	 * @return WC_Product
	 */
	private static function resolve_stock_product( WC_Product $product ): WC_Product {
		if ( $product->is_type( 'variation' ) && 'parent' === $product->get_manage_stock() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				return $parent;
			}
		}
		return $product;
	}


}
