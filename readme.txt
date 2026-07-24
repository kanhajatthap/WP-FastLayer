=== WP FastLayer ===
Contributors: kanhajatthap
Tags: cache, performance, optimization, speed, minify, lazy load, database
Requires at least: 6.0
Tested up to: 6.8
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 7.4

== Description ==
WP FastLayer is a lightweight speed optimization plugin for WordPress. It includes page cache, HTML/CSS/JS minification, lazy loading, WebP conversion, database cleanup tools, and cache preloading.

== Installation ==
1. Upload the `wp-fastlayer` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to WP FastLayer in the admin menu and configure the features you want to enable.

== Frequently Asked Questions ==
= Does WP FastLayer modify core WordPress files? =
No. WP FastLayer only uses plugin hooks and stores its own options and generated cache files.

= Can I safely uninstall the plugin? =
Yes. The plugin removes its options, scheduled event, cache files, minified files, and logs on uninstall.

== Changelog ==
= 1.0.0 =
* Initial release.
* Added page cache, cache preload, minify options, lazy load, WebP conversion, and database optimization.

== Upgrade Notice ==
= 1.0.0 =
* Initial release.
