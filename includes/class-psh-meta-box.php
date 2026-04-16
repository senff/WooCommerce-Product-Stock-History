<?php
defined( 'ABSPATH' ) || exit;

class PSH_Meta_Box {

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_psh_variation_history', array( __CLASS__, 'ajax_variation_history' ) );
		add_action( 'wp_ajax_psh_load_more_history', array( __CLASS__, 'ajax_load_more_history' ) );
		add_action( 'wp_ajax_psh_clear_history', array( __CLASS__, 'ajax_clear_history' ) );
	}

	public static function register(): void {
		add_meta_box(
			'psh-stock-history',
			__( 'Stock History', 'product-stock-history-for-woocommerce' ),
			array( __CLASS__, 'render' ),
			'product',
			'side',
			'default'
		);
	}

	public static function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style(
			'psh-meta-box',
			plugin_dir_url( PSH_PLUGIN_FILE ) . 'assets/css/meta-box.css',
			array(),
			PSH_VERSION
		);

		wp_enqueue_script(
			'psh-meta-box',
			plugin_dir_url( PSH_PLUGIN_FILE ) . 'assets/js/meta-box.js',
			array( 'jquery' ),
			PSH_VERSION,
			true
		);

		wp_localize_script( 'psh-meta-box', 'pshI18n', array(
			'loading'          => __( 'Loading…', 'product-stock-history-for-woocommerce' ),
			'error'            => __( 'Could not load history.', 'product-stock-history-for-woocommerce' ),
			'showMore'         => __( 'Show more', 'product-stock-history-for-woocommerce' ),
			'loadMoreNonce'    => wp_create_nonce( 'psh_load_more_history' ),
			'clearHistory'     => __( 'Clear history', 'product-stock-history-for-woocommerce' ),
			/* translators: %s: product or variation name */
			'confirmClear'     => __( 'Are you sure you want to delete the entire stock history for %s? This cannot be undone.', 'product-stock-history-for-woocommerce' ),
			'noHistory'        => __( 'No stock history recorded yet.', 'product-stock-history-for-woocommerce' ),
			'clearHistoryNonce'=> wp_create_nonce( 'psh_clear_history' ),
		) );
	}

	// ------------------------------------------------------------------
	// Meta box render
	// ------------------------------------------------------------------

	public static function render( WP_Post $post ): void {
		$product = wc_get_product( $post->ID );

		if ( ! $product ) {
			echo '<p>' . esc_html__( 'Could not load product.', 'product-stock-history-for-woocommerce' ) . '</p>';
			return;
		}

		if ( $product->is_type( 'variable' ) ) {
			self::render_variable( $product );
			return;
		}

		if ( ! $product->managing_stock() ) {
			echo '<p>' . esc_html__( 'Stock management is not enabled for this product.', 'product-stock-history-for-woocommerce' ) . '</p>';
			return;
		}

		self::render_stock_and_history( $product->get_id(), $product->get_stock_quantity(), $product->get_name() );
	}

	private static function render_variable( WC_Product $product ): void {
		$parent_has_stock = $product->managing_stock() && null !== $product->get_stock_quantity();

		// Build an ordered list of options, preserving the Variations tab order.
		$options      = array();
		$has_parent_managed = false;

		foreach ( $product->get_children() as $variation_id ) {
			$variation    = wc_get_product( $variation_id );
			$manage_stock = $variation ? $variation->get_manage_stock() : false;

			if ( true === $manage_stock && null !== $variation->get_stock_quantity() ) {
				$options[] = array( 'id' => $variation_id, 'variation' => $variation, 'type' => 'own' );
			} elseif ( 'parent' === $manage_stock && $parent_has_stock ) {
				$options[] = array( 'id' => $variation_id, 'variation' => $variation, 'type' => 'parent' );
				$has_parent_managed = true;
			}
		}

		if ( empty( $options ) && ! $parent_has_stock ) {
			echo '<p>' . esc_html__( 'There are no variations with tracked stock.', 'product-stock-history-for-woocommerce' ) . '</p>';
			return;
		}

		$nonce = wp_create_nonce( 'psh_variation_history' );

		$data_attrs = 'data-nonce="' . esc_attr( $nonce ) . '"';
		if ( $parent_has_stock ) {
			$data_attrs .= ' data-parent-id="' . esc_attr( $product->get_id() ) . '"';
			$data_attrs .= ' data-parent-name="' . esc_attr( $product->get_name() ) . '"';
		}

		echo '<select id="psh-variation-select" ' . $data_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- All values in $data_attrs are individually escaped above.
		echo '<option value="">' . esc_html__( '— Select a variation —', 'product-stock-history-for-woocommerce' ) . '</option>';

		if ( $parent_has_stock ) {
			echo '<option value="' . esc_attr( $product->get_id() ) . '">' . esc_html( $product->get_name() ) . '</option>';
		}

		foreach ( $options as $opt ) {
			$attrs = wc_get_formatted_variation( $opt['variation'], true, false );
			$label = '#' . $opt['id'] . ( $attrs ? ' – ' . $attrs : '' );
			echo '<option value="' . esc_attr( $opt['id'] ) . '">' . esc_html( $label ) . '</option>';
		}

		echo '</select>';
		echo '<p id="psh-variation-label"></p>';
		echo '<div id="psh-history-container"></div>';
	}

	// ------------------------------------------------------------------
	// AJAX handler
	// ------------------------------------------------------------------

	public static function ajax_variation_history(): void {
		check_ajax_referer( 'psh_variation_history', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		if ( ! $variation_id ) {
			wp_send_json_error( 'Invalid variation ID.' );
		}

		$variation = wc_get_product( $variation_id );
		if ( ! $variation || ( ! $variation->is_type( 'variation' ) && ! $variation->is_type( 'variable' ) ) ) {
			wp_send_json_error( 'Not a valid product.' );
		}

		// For variations that delegate stock to the parent, show the parent's history.
		$stock_product      = self::resolve_stock_product( $variation );
		$is_parent_managed  = $stock_product->get_id() !== $variation->get_id();

		if ( $is_parent_managed ) {
			$label = $stock_product->get_name();
		} else {
			$attrs = wc_get_formatted_variation( $variation, true, false );
			$label = $attrs ? $attrs : $variation->get_name();
		}

		ob_start();
		if ( $is_parent_managed ) {
			echo '<p class="psh-parent-notice">'
				. esc_html__( 'This variation has no stock tracking of its own; showing the parent stock instead.', 'product-stock-history-for-woocommerce' )
				. '</p>';
		}
		self::render_stock_and_history( $stock_product->get_id(), $stock_product->get_stock_quantity(), $label );
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	public static function ajax_load_more_history(): void {
		check_ajax_referer( 'psh_load_more_history', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( 'Invalid product ID.' );
		}

		$per_page = 20;
		$history  = PSH_DB::get_history( $product_id, $per_page + 1, $offset );
		$has_more = count( $history ) > $per_page;
		if ( $has_more ) {
			array_pop( $history );
		}

		ob_start();
		self::render_history_items( $history );
		$html = ob_get_clean();

		wp_send_json_success( array(
			'html'        => $html,
			'has_more'    => $has_more,
			'next_offset' => $offset + $per_page,
		) );
	}

	public static function ajax_clear_history(): void {
		check_ajax_referer( 'psh_clear_history', 'nonce' );

		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! $product_id ) {
			wp_send_json_error( 'Invalid product ID.' );
		}

		PSH_DB::delete_product_history( $product_id );
		wp_send_json_success();
	}

	// ------------------------------------------------------------------
	// Shared history renderer (used for both simple products and AJAX)
	// ------------------------------------------------------------------

	private static function render_stock_and_history( int $product_id, $current_stock, string $label = '' ): void {
		echo '<p class="psh-current-stock">';
		echo esc_html__( 'Current stock:', 'product-stock-history-for-woocommerce' ) . ' ';
		echo '<strong>' . esc_html( self::format_qty( (float) $current_stock ) ) . '</strong>';
		echo '</p>';

		$per_page = 20;
		$history  = PSH_DB::get_history( $product_id, $per_page + 1, 0 );
		$has_more = count( $history ) > $per_page;
		if ( $has_more ) {
			array_pop( $history );
		}

		if ( empty( $history ) ) {
			echo '<p class="psh-no-history">' . esc_html__( 'No stock history recorded yet.', 'product-stock-history-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<div class="psh-history-wrap">';
		echo '<ul class="psh-notes">';
		self::render_history_items( $history );
		echo '</ul>';
		echo '<div class="psh-history-footer">';
		if ( $has_more ) {
			echo '<button type="button" class="psh-load-more" data-product-id="' . esc_attr( $product_id ) . '" data-offset="' . esc_attr( $per_page ) . '">'
				. esc_html__( 'Show more', 'product-stock-history-for-woocommerce' )
				. '</button>';
		}
		echo '<button type="button" class="psh-clear-history" data-product-id="' . esc_attr( $product_id ) . '" data-label="' . esc_attr( $label ) . '">'
			. esc_html__( 'Clear history', 'product-stock-history-for-woocommerce' )
			. '</button>';
		echo '</div>';
		echo '</div>';
	}

	private static function render_history_items( array $history ): void {
		foreach ( $history as $row ) {
			$old        = (float) $row['old_stock'];
			$new        = (float) $row['new_stock'];
			$diff       = $new - $old;
			$note_class = in_array( $row['reason'], array( 'manual', 'bulk_edit', 'quick_edit' ), true )
				? 'psh-note-manual'
				: ( $diff > 0 ? 'psh-note-up' : ( $diff < 0 ? 'psh-note-down' : 'psh-note-neutral' ) );

			$note_text = '<strong>' . self::format_qty( $old ) . '</strong> &rarr; <strong>' . self::format_qty( $new ) . '</strong>, '
				. self::reason_phrase( $row['reason'], (int) $row['order_id'], (int) $row['user_id'] );

			$meta_suffix = ( in_array( $row['reason'], array( 'manual', 'api', 'bulk_edit', 'quick_edit' ), true ) && ! empty( $row['user_id'] ) )
				? ' ' . self::user_link( (int) $row['user_id'] )
				: '';

			echo '<li class="psh-note ' . esc_attr( $note_class ) . '">';
			echo '<div class="psh-note-text">' . wp_kses_post( $note_text ) . '</div>';
			echo '</li>';
			echo '<p class="meta"><abbr class="exact-date" title="' . esc_attr( $row['created_at'] ) . '">'
				. esc_html( self::format_date( $row['created_at'] ) ) . '</abbr>' . wp_kses_post( $meta_suffix ) . '</p>';
		}
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Returns the product whose stock is actually managed.
	 * Mirrors PSH_Tracker::resolve_stock_product() for use in the meta box.
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

	private static function user_link( int $user_id ): string {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return esc_html__( 'by unknown user', 'product-stock-history-for-woocommerce' );
		}
		$url  = get_edit_user_link( $user_id );
		$name = esc_html( $user->user_login );
		return 'by ' . ( $url ? '<a href="' . esc_url( $url ) . '">' . $name . '</a>' : $name );
	}

	private static function format_date( string $mysql_date ): string {
		$timestamp = strtotime( $mysql_date );
		return $timestamp ? wp_date( 'd-m-Y \a\t H:i', $timestamp ) : $mysql_date;
	}

	private static function format_qty( float $qty ): string {
		return ( $qty == (int) $qty ) ? number_format_i18n( (int) $qty ) : number_format_i18n( $qty, 2 );
	}

	private static function reason_phrase( string $reason, int $order_id, int $user_id ): string {
		switch ( $reason ) {
			case 'initial':
				return esc_html__( 'initial stock set', 'product-stock-history-for-woocommerce' );

			case 'api':
				return esc_html__( 'updated via REST API', 'product-stock-history-for-woocommerce' );

			case 'order':
				if ( $order_id ) {
					$url  = get_edit_post_link( $order_id );
					$link = $url ? '<a href="' . esc_url( $url ) . '">#' . $order_id . '</a>' : '#' . $order_id;
					/* translators: %s: order link */
					return sprintf( __( 'ordered in %s', 'product-stock-history-for-woocommerce' ), $link );
				}
				return esc_html__( 'ordered', 'product-stock-history-for-woocommerce' );

			case 'failed_order':
				if ( $order_id ) {
					$url  = get_edit_post_link( $order_id );
					$link = $url ? '<a href="' . esc_url( $url ) . '">#' . $order_id . '</a>' : '#' . $order_id;
					/* translators: %s: order link */
					return sprintf( __( 'failed order %s', 'product-stock-history-for-woocommerce' ), $link );
				}
				return esc_html__( 'failed order', 'product-stock-history-for-woocommerce' );

			case 'cancellation':
				if ( $order_id ) {
					$url  = get_edit_post_link( $order_id );
					$link = $url ? '<a href="' . esc_url( $url ) . '">#' . $order_id . '</a>' : '#' . $order_id;
					/* translators: %s: order link */
					return sprintf( __( 'cancellation of order %s', 'product-stock-history-for-woocommerce' ), $link );
				}
				return esc_html__( 'order cancellation', 'product-stock-history-for-woocommerce' );

			case 'refund':
				if ( $order_id ) {
					$url  = get_edit_post_link( $order_id );
					$link = $url ? '<a href="' . esc_url( $url ) . '">#' . $order_id . '</a>' : '#' . $order_id;
					/* translators: %s: order link */
					return sprintf( __( 'refunded in order %s', 'product-stock-history-for-woocommerce' ), $link );
				}
				return esc_html__( 'refunded', 'product-stock-history-for-woocommerce' );

			case 'bulk_edit':
				return esc_html__( 'manually adjusted via bulk edit', 'product-stock-history-for-woocommerce' );

			case 'quick_edit':
				return esc_html__( 'manually adjusted via quick edit', 'product-stock-history-for-woocommerce' );

			case 'manual':
				return esc_html__( 'manually adjusted', 'product-stock-history-for-woocommerce' );

			case 'order_edit':
				if ( $order_id ) {
					$url  = get_edit_post_link( $order_id );
					$link = $url ? '<a href="' . esc_url( $url ) . '">#' . $order_id . '</a>' : '#' . $order_id;
					/* translators: %s: order link */
					return sprintf( __( 'changed by editing order %s', 'product-stock-history-for-woocommerce' ), $link );
				}
				return esc_html__( 'changed by editing order', 'product-stock-history-for-woocommerce' );

			case 'unknown':
				return esc_html__( 'changed by unknown process (plugin or external service)', 'product-stock-history-for-woocommerce' );

			default:
				return esc_html( $reason );
		}
	}
}
