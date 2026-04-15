<?php

/*
Plugin Name: Product Stock History for WooCommerce
Plugin URI: https://wordpress.org/plugins/product-stock-history-for-woocommerce
Requires Plugins: woocommerce
Description: Tracks and displays the full stock change history for WooCommerce products.
Author: Senff
Author URI: http://www.senff.com
Version: 1.0
Requires at least: 6.0
Requires PHP: 8.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: product-stock-history-for-woocommerce
*/

defined( 'ABSPATH' ) || exit;

define( 'PSH_VERSION', '1.0' );
define( 'PSH_PLUGIN_FILE', __FILE__ );
define( 'PSH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once PSH_PLUGIN_DIR . 'includes/class-psh-db.php';
require_once PSH_PLUGIN_DIR . 'includes/class-psh-tracker.php';
require_once PSH_PLUGIN_DIR . 'includes/class-psh-meta-box.php';

register_activation_hook( __FILE__, array( 'PSH_DB', 'create_table' ) );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

PSH_Tracker::init();
PSH_Meta_Box::init();
