=== FastLayer ===
Contributors: kanhajatthap
Tags: cache, performance, optimization, minify, lazy load
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 7.4

== Description ==
FastLayer is a lightweight speed optimization plugin for WordPress. It includes page cache, HTML/CSS/JS minification, lazy loading, WebP conversion, database cleanup tools, and cache preloading.

== Installation ==
1. Upload the `FastLayer` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to FastLayer in the admin menu and configure the features you want to enable.

== External Services ==

This plugin can optionally integrate with one external, third-party service. It is disabled by default, and nothing is sent to it unless you turn it on yourself.

= ImageKit =

ImageKit is a third-party image optimization and delivery service operated by ImageKit (https://imagekit.io/). It is not operated by, affiliated with, or endorsed by FastLayer. By enabling it you are choosing to have your site contact a service outside of your own hosting, so you should review ImageKit's terms of service and privacy policy on their own website before enabling it.

* **Is it on by default?** No. ImageKit stays completely inactive until you both enable the "Enable ImageKit for image delivery" toggle and enter your own ImageKit URL endpoint under FastLayer > CDN. While it is inactive, no data leaves your site for ImageKit.
* **What does FastLayer ask you for?** Only your ImageKit URL endpoint. FastLayer does not ask for, store, or transmit an ImageKit public key, private key, auth key, or any other account credential. Authentication and billing remain entirely between you and ImageKit.
* **What is sent?** When you use the plugin's ImageKit features, your WordPress server sends a request to the ImageKit endpoint containing:
  * the path of an image already in your Media Library (the uploads path of that image, with your site's scheme and domain removed);
  * an image transformation string in the `tr` query parameter, requesting an automatically chosen output format, automatically chosen quality, and a specific display width.
  FastLayer never uploads the contents of your image files to ImageKit, and it never sends any visitor data. Specifically, it does not transmit IP addresses, user agents, cookies, referrer data, WordPress user names, e-mail addresses, or any other information about your site's visitors or users.
* **When is it sent?** Only from the admin screen, and only after you have enabled ImageKit: when you click the plugin's ImageKit connection test, and when an administrator loads the FastLayer CDN settings screen.
* **How is it protected?** FastLayer restricts these requests to ImageKit's own hosts (`imagekit.io` and its subdomains) and rejects requests to any other host, to IP addresses, and to URLs containing embedded credentials. If you enter an endpoint without a scheme, FastLayer adds `https://`. If you enter one that explicitly begins with `http://`, FastLayer will use it as written, so enter your `https://` endpoint to keep that connection encrypted.
* **What is stored locally?** The endpoint you enter is saved in your WordPress options and is also written into a configuration file FastLayer generates at `wp-content/cache/wp-fastlayer/config.php`. This value is deleted when you uninstall the plugin.

== Frequently Asked Questions ==
= Does FastLayer modify core WordPress files? =
FastLayer never modifies WordPress core source files (the files in `wp-includes/` and `wp-admin/`). It does, however, create and edit configuration and drop-in files outside of the plugin folder. This is normal for a caching plugin, but you should know about it before activating:

* **On activation**, FastLayer installs an `advanced-cache.php` drop-in in `wp-content/`. This is the file WordPress loads to serve a cached page.
* **On activation**, FastLayer adds one line to your `wp-config.php`, above the "That's all, stop editing!" comment:
`define( 'WP_CACHE', true );`
FastLayer backs up `wp-config.php` to a temporary location outside the web root before editing it, and it only removes this line again if the line carries its own ownership marker.
* **When WebP is enabled**, FastLayer adds WebP rewrite rules to the `.htaccess` file in your WordPress root directory, inside a clearly marked `WP FastLayer WebP` block.

FastLayer backs off rather than clobbering other software: it will not overwrite an `advanced-cache.php` drop-in owned by another caching plugin, it will not rewrite a `WP_CACHE` definition it did not create itself, and its `.htaccess` rules are wrapped in markers so they can be removed cleanly. Deactivating the plugin removes the drop-in and its `wp-config.php` line; deleting it also removes the plugin's options and generated cache files.

== Screenshots ==
1. FastLayer admin dashboard showing optimization features.

== Changelog ==
= 1.0.0 =
* Initial release.
* Added page cache, cache preload, minify options, lazy load, WebP conversion, and database optimization.

== Upgrade Notice ==
= 1.0.0 =
* Initial release.
