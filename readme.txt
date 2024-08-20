=== Page Transfer for Elementor ===
Contributors: raulpeixoto
Tags: elementor, transfer, export, import, page
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Seamlessly export and import Elementor-designed pages between development and production environments, or across different domains.

== Description ==

Page Transfer for Elementor is a WordPress plugin that allows you to seamlessly export and import Elementor-designed pages between development and production environments, or across different domains. This plugin ensures that all associated media files uploaded via Elementor are copied and preserved with the same relative path, while simultaneously updating the domain name during import and maintaining the integrity of your page layouts.

== Features ==

* Export Elementor-designed pages with all associated media files.
* Import Elementor-designed pages, together with associated media files and updating domain names.
* Maintain the integrity of your page layouts during the transfer process.

== Installation ==

1. Upload the `elementor-page-transfer` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure you have Elementor installed and activated.

== Usage ==

=== Exporting a Page ===

1. Navigate to the Pages section in the WordPress admin dashboard.
2. Hover over the page you want to export.
3. Click on the "Export" link that appears under the page title.

=== Importing a Page ===

1. Navigate to the Pages section in the WordPress admin dashboard.
2. Hover over the page you want to import content into.
3. Click on the "Import" link that appears under the page title.
4. In the modal that appears, upload the JSON file you exported earlier.
5. Click "Import" to complete the process.

== Frequently Asked Questions ==

=== What is required to use this plugin? ===

You need to have Elementor installed and activated on your WordPress site.

=== Can I transfer pages between different domains? ===

Yes, the plugin updates the domain names during the import process to ensure that all media files are correctly linked.

=== What happens if the import fails? ===

If the import fails, an error message will be displayed. Ensure that you have the correct permissions and that the JSON file is valid.

== Changelog ==

=== 1.0 ===

* Initial release.

== License ==

This plugin is licensed under the GPL2 license. For more information, see [https://wordpress.org/about/license/](https://wordpress.org/about/license/).

== Author ==

* **Raul Peixoto**
* [Upwork Profile](https://www.upwork.com/fl/raulpeixoto)

== Support ==

For extra support, please contact [Raul Peixoto](https://www.upwork.com/fl/raulpeixoto).
