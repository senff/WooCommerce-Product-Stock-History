<?php
defined( 'ABSPATH' ) || exit;

class PSH_DB {

	const TABLE_NAME = 'psh_stock_history';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	public static function create_table(): void {
		global $wpdb;

		$table      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id  BIGINT UNSIGNED NOT NULL,
			old_stock   DOUBLE          NOT NULL DEFAULT 0,
			new_stock   DOUBLE          NOT NULL DEFAULT 0,
			reason      VARCHAR(64)     NOT NULL DEFAULT '',
			order_id    BIGINT UNSIGNED          DEFAULT NULL,
			user_id     BIGINT UNSIGNED          DEFAULT NULL,
			note        VARCHAR(255)             DEFAULT NULL,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY product_id (product_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a stock history entry.
	 *
	 * @param int        $product_id
	 * @param float      $old_stock
	 * @param float      $new_stock
	 * @param string     $reason     One of: initial, manual, order, refund, other
	 * @param int|null   $order_id
	 * @param int|null   $user_id
	 * @param string     $note
	 */
	public static function insert( int $product_id, float $old_stock, float $new_stock, string $reason, ?int $order_id = null, ?int $user_id = null, string $note = '' ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table_name(),
			array(
				'product_id' => $product_id,
				'old_stock'  => $old_stock,
				'new_stock'  => $new_stock,
				'reason'     => $reason,
				'order_id'   => $order_id,
				'user_id'    => $user_id,
				'note'       => $note,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%f', '%f', '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Get the most recent history entry for a product.
	 *
	 * @param int $product_id
	 * @return array|null
	 */
	public static function get_last_entry( int $product_id ): ?array {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE product_id = %d ORDER BY created_at DESC, id DESC LIMIT 1",
				$product_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Delete all history entries for a product.
	 *
	 * @param int $product_id
	 */
	public static function delete_product_history( int $product_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table_name(), array( 'product_id' => $product_id ), array( '%d' ) );
	}

	/**
	 * Get history entries for a product, newest first.
	 *
	 * @param int $product_id
	 * @param int $limit  0 = no limit.
	 * @param int $offset
	 * @return array
	 */
	public static function get_history( int $product_id, int $limit = 0, int $offset = 0 ): array {
		global $wpdb;

		$table = self::table_name();

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE product_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
					$product_id,
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE product_id = %d ORDER BY created_at DESC, id DESC",
				$product_id
			),
			ARRAY_A
		);
	}
}
