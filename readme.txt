=== Product Stock History for WooCommerce ===
Contributors: senff
Donate link: http://donate.senff.com
Tags: woocommerce, stock, inventory, history, log
Plugin URI: https://wordpress.org/plugins/product-stock-history-for-woocommerce
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html


Tracks and displays the complete stock change history for WooCommerce products.


== Description ==

Product Stock History for WooCommerce keeps track of every stock change for your products and displays a full history log in a meta box on the product edit page.

Tracked events include:

* Orders placed, cancelled, or failed
* Refunds with restocked items
* Manual edits in the product editor
* REST API updates
* Bulk edit and Quick edit changes
* Changes made by third-party plugins (logged as unknown)

For variable products, a dropdown lets you browse the history for each variation individually.


== Installation ==

= Installation from within WordPress =

1. Visit **Plugins > Add New**.
2. Search for **Product Stock History**.
3. Install and activate the Product Stock History for WooCommerce plugin.

= Manual installation =

1. Upload the entire `product-stock-history-for-woocommerce` folder to the `/wp-content/plugins/` directory.
2. Visit **Plugins**.
3. Activate the Product Stock History plugin.


== Frequently Asked Questions ==

= What does it do exactly? =
After installing Product Stock History, your products will get an new meta box on their editing pages, where you will see a history of when the stock/quantity changed for that product. In most cases, it shows you what caused it (order placed, manual edit, etc.).

= What if I have a Variable Product? =
For Variable Products, the box will have a dropdown where you can select any variation to see it's own stock history. Note that it's possible to keep track of inventory for one variation, but not the other! In that case, the variation that doesn't have any tracking, will follow the stock quantity of the parent product.

= Come again? =
- If a variation has its own stock tracking, you can see it's history by selecting that variation from the dropdown.
- If a variation does not have stock tracking enabled, it will use the stock history of the parent's inventory.
- If the parent also doesn't have stock tracking enabled, the stock of that variation will just not be tracked and will have no history.

= Will it show the stock history from before this plugin was installed/activated? =
No, the tracking will only start once the plugin is installed. It can not generate a history retroactively.

= I'm not seeing any stock history for my product. =
Make sure that "Track stock quantity for this product" is checked (under Inventory in the product settings).

= Can it generate nice looking reports for multiple products? =
Not at this time, but this may be added later. For now, it only shows stock history on individual product pages.

= I need more help please! =
If you're not sure how to use this, or you're running into any issues with it, post a message on the plugin's [WordPress.org support forum](https://wordpress.org/support/plugin/product-stock-history-for-woocommerce).

= I've noticed that something doesn't work right, or I have an idea for improvement. How can I report this? =
Product Stock History's community support forum at https://wordpress.org/support/plugin/product-stock-history-for-woocommerce would a good place, though if you want to add all sorts of -technical- details, it's best to report it on the plugin's Github page at https://github.com/senff/WooCommerce-Product-Stock-History/issues . This is also where I consider code contributions.

= My question isn't listed here? =
Please go to the plugin's community support forum at https://wordpress.org/support/plugin/product-stock-history-for-woocommerce and post a message. Note that support is provided on a voluntary basis and that it can be difficult to troubleshoot, and may require access to your admin area. Needless to say, NEVER include any passwords of your site on a public forum!


== Screenshots ==

1. Single Product log example
2. Variable Product log example


== Changelog ==

= 1.0 =
* Initial release.


== Upgrade Notice ==

= 1.0 =
Initial release of the plugin.