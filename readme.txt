=== FastLayer ===
Contributors: kanhajatthap
Tags: cache, performance, optimization, minify, lazy load
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 7.4

FastLayer speeds up WordPress with page caching, CSS/JS minification, lazy loading, WebP conversion, CDN delivery and database cleanup.

== Description ==

FastLayer is a lightweight performance plugin that brings the most common WordPress speed tools together in one place. Page caching, HTML/CSS/JS optimization, image optimization, CDN delivery and database cleanup all live under a single **FastLayer** menu in the admin.

Every main feature starts disabled, so you enable only what your site needs. Before FastLayer changes anything outside the plugin folder, it checks that it owns the file first, so it will not overwrite a cache drop-in or a configuration line that belongs to another plugin.

= Why FastLayer =

* **Everything in one place.** Cache, assets, images and the database are organized into clear, predictable settings pages.
* **Safe by default.** Page caching, minification, lazy loading, WebP conversion and the CDN are off until you turn them on.
* **Built for compatibility.** Exclude URLs, cookies, user agents, and individual CSS/JS files when a theme or plugin needs to be left alone.
* **Works with page builders.** When Smart JS Delay is enabled, it keeps critical dependencies, Elementor front-end and WooCommerce checkout/cart scripts, plus navigation, running immediately while the rest of the page is optimized.

== Features ==

FastLayer groups its tools by area. Each area has its own settings screen, and every feature can be turned on or off independently.

= Page cache =

* Serve cached static HTML to anonymous visitors, so repeat visits are answered without running WordPress on every request.
* Choose how long a cached page is kept before it is cleared automatically (1 to 720 hours).
* Clear the cache at any time from the **FastLayer** admin-bar menu or the plugin's Dashboard.
* Not cached automatically: logged-in users, the admin area, AJAX and REST requests, previews, 404 pages, search results, and POST or HEAD requests.

= Cache preloading =

* Warm the cache in the background after it has been cleared, so visitors are less likely to hit a cold cache.
* Pages are discovered from your site's XML sitemap (the WordPress core sitemap, Yoast SEO or All in One SEO), with built-in limits so a large or slow sitemap cannot stall the job.
* Preloading runs in background batches and can also be started manually at any time.

= HTML optimization =

* Minify HTML by removing comments and collapsing extra whitespace.
* Optionally keep Gutenberg block comments so block markup stays intact.

= CSS optimization =

* Minify CSS files and optionally remove comments.
* Combine multiple stylesheets to reduce requests while preserving media-specific styles.
* Optimize CSS delivery so styles no longer block rendering.
* Inline critical CSS, with a custom critical CSS box and a one-click sample generator.
* Smart Gutenberg CSS optimization and `display=swap` for Google Fonts.
* Exclude individual CSS files by path, pattern or external domain.

= JavaScript optimization =

* Minify JavaScript files and optionally remove comments.
* Combine scripts, or defer and/or async-load eligible scripts to reduce render blocking.
* Smart JS Delay waits for the first user interaction before running eligible non-critical scripts, and restores them after a configurable timeout so nothing is left unloaded.
* Safe Mode and exclusion lists (by handle, keyword or pattern) for scripts that must stay untouched.

= Images and media =

* Convert JPEG and PNG attachments to WebP, either automatically or in resumable batches for images that already exist.
* Improve image delivery by serving optimized responsive sizes and WebP variants.
* Lazy load images, iframes (YouTube, Vimeo and others) and HTML5 videos.
* Progressive image loading with low-quality image placeholders (LQIP), including placeholder width, quality, blur strength and fade duration controls.
* Smart LCP optimization detects one above-the-fold hero image or background and prioritizes it without disabling lazy loading everywhere. LCP preload hints, hero background support and debug logging are also available.

= CDN and image delivery =

* Rewrite internal asset URLs to a CDN hostname you configure.
* Optional ImageKit integration for image delivery. Only your ImageKit endpoint is stored; no API keys are requested or saved. See the External Services section for exactly what is sent, and by whom.

= Database cleanup =

* Optimize database tables and remove post revisions, trashed posts, spam comments and auto-drafts.
* Remove expired or all transients, oEmbed cache rows, and duplicate or orphaned post, comment, user and term metadata.
* Review the item counts before cleaning, and use the Clean All control to select every option at once.

= Advanced rules and diagnostics =

* Exclude URLs, cookies and user agents from caching.
* Debug logging for cache decisions, Smart LCP and Smart JS Delay.
* FastLayer admin-bar actions: Clear All Cache, Clear CSS/JS Cache and Preload Cache.

== Installation ==

1. In your WordPress admin, open the Plugins screen, click Add New, search for "FastLayer", then click Install Now and Activate.
2. To install manually, upload the `fastlayer` folder to `/wp-content/plugins/` and activate the plugin from the Plugins screen.
3. On activation, FastLayer installs its `advanced-cache.php` drop-in and adds `define( 'WP_CACHE', true );` to `wp-config.php` so page caching can work.
4. Open **FastLayer** in the admin menu. The Dashboard shows cache status and quick actions.
5. Work through the settings pages and enable only the features you need, then save.

= Recommended first setup =

* **Cache:** enable **Page Caching**, then enable **Cache Preloading** if you want pages warmed automatically after a clear.
* **CSS and JS:** enable minification first, then add combination, delivery and delay options one at a time and check the front end after each change.
* **Media:** enable **Convert Images to WebP Format**, then run **Bulk WebP Generation** for existing images. Turn on lazy loading and LQIP if your theme is compatible.
* **Database:** review the cleanup list before removing anything, then run the cleanup and optimize tables.

= Requirements =

* WordPress 6.0 or newer, and PHP 7.4 or newer.
* WebP conversion needs the GD library with WebP support, or Imagick with WebP support. The Media screen reports what your server supports.
* FastLayer writes to `wp-config.php` and, when WebP is enabled, to the site root `.htaccess`. Both files must be writable by WordPress for FastLayer to add its rules automatically.

== Compatibility ==

* **Caching and optimization plugins:** use a single page-cache engine and a single minify engine per site to avoid conflicting output.
* **Page builders and e-commerce:** Smart JS Delay keeps critical dependencies, Elementor and WooCommerce checkout/cart, and navigation scripts running immediately.
* **Servers:** Apache and LiteSpeed apply the `.htaccess` rules directly; nginx needs a manual server-level WebP rule.
* **Multisite:** not currently supported.

== Frequently Asked Questions ==

= Does FastLayer modify WordPress core files? =

No. FastLayer never edits the files in `wp-includes/` or `wp-admin/`. Like most caching plugins, it creates and manages configuration and drop-in files outside the plugin folder:

* On activation it installs `wp-content/advanced-cache.php`.
* On activation it adds `define( 'WP_CACHE', true );` to `wp-config.php`, above the "That's all, stop editing!" line. FastLayer backs up `wp-config.php` outside the web root before editing it, and it only removes the line again if it carries FastLayer's own ownership marker.
* When WebP is enabled it adds a marked `WP FastLayer WebP` block to the `.htaccess` file in your WordPress root.

Deactivating the plugin removes the drop-in and its `wp-config.php` line, and clears FastLayer's WebP `.htaccess` directives. Deleting it also removes the plugin's options and generated cache files.

= How do I clear the cache? =

Use the **FastLayer** menu in the admin bar to **Clear All Cache**, **Clear CSS/JS Cache**, or **Preload Cache**. The same actions are available on the plugin's Dashboard.

= Will FastLayer conflict with another caching or optimization plugin? =

FastLayer is written to back off rather than clobber other software: it will not overwrite an `advanced-cache.php` drop-in owned by another caching plugin, it will not remove a `WP_CACHE` line it did not create, and its `.htaccess` rules are wrapped in removable markers. Even so, running two page-cache engines or two minify engines at once usually produces confusing results, so enable one caching system and one asset optimization system per site.

= Is FastLayer compatible with nginx? =

Page caching and asset optimization work on nginx. WebP rewrite rules are written to `.htaccess`, which nginx does not read, so on nginx you need to apply the equivalent WebP rule at the server level. FastLayer shows an admin notice with guidance when it detects nginx.

= Why is WebP conversion unavailable on my server? =

WebP conversion needs GD with WebP support or Imagick with WebP support. The Media screen reports exactly what your server has. Ask your host to enable GD WebP or Imagick WebP, then reload the screen.

= Do I need an ImageKit account? =

No. ImageKit is an optional third-party image delivery service and is off by default. FastLayer works fully without it. See the External Services section for exactly what is sent if you enable it.

= Where are the generated files stored? =

* Page cache, configuration and the cache `.htaccess`: `wp-content/cache/wp-fastlayer/`
* Combined and minified CSS/JS: `wp-content/uploads/wp-fastlayer-min/`
* WebP files are generated next to the original images in your uploads folder.

= Does FastLayer support WordPress Multisite? =

FastLayer is built and tested for single-site installations. Multisite is not currently supported.

= Is any data sent to a third party? =

No data leaves your site by default. The only external service is the optional ImageKit integration, and it does nothing until you configure it. Once you save an ImageKit endpoint and enable the integration, your front-end pages point image requests at ImageKit, so visitors' browsers load those images directly from ImageKit. See the External Services section for exactly what is sent, and by whom.

= Where can I report a bug or ask for help? =

Please open an issue on the plugin's GitHub repository: https://github.com/kanhajatthap/WP-FastLayer

== External Services ==

This plugin can optionally integrate with one external, third-party service. It is off by default, and FastLayer only contacts that service once you configure it. The per-service notes below explain exactly when a request is made and what it contains.

= ImageKit =

ImageKit is a third-party image optimization and delivery service operated by ImageKit (https://imagekit.io/). It is not operated by, affiliated with, or endorsed by FastLayer. By enabling it you are choosing to have your site contact a service outside of your own hosting, so you should review ImageKit's terms of service and privacy policy on their own website before enabling it.

* **Is it on by default?** No. Image delivery stays off until you turn on the "Enable ImageKit for image delivery" toggle under FastLayer > CDN. FastLayer only rewrites your image URLs to ImageKit while that toggle is on and an endpoint is saved. Be aware that FastLayer still contacts your endpoint from the admin screen even when the toggle is off: once an endpoint is saved, opening the CDN settings screen, or clicking the connection test, sends the server-side request described below before you enable the toggle.
* **What does FastLayer ask you for?** Only your ImageKit URL endpoint. FastLayer does not ask for, store, or transmit an ImageKit public key, private key, auth key, or any other account credential. Authentication and billing remain entirely between you and ImageKit.
* **What does FastLayer itself send, and when?** Only from your admin screen, and only when you act: when you click the plugin's ImageKit connection test, and when an administrator opens the FastLayer > CDN settings screen. On each of those actions your WordPress server requests one image URL at the endpoint you configured. That request contains:
  * the uploads path of one of your own images (with your site's scheme and domain removed);
  * an image transformation string in the `tr` query parameter that asks ImageKit to choose the output format and quality automatically and to return a specific display width.
  FastLayer never uploads the contents of your image files to ImageKit, and these server-side requests carry no visitor or user data: no IP addresses, user agents, cookies, referrer data, WordPress user names, or e-mail addresses.
* **What happens once the toggle is on (visitors' browsers)?** When the toggle is on and an endpoint is saved, FastLayer rewrites the image URLs in your front-end pages to that endpoint. The images are then loaded by each visitor's own browser directly from ImageKit, not through your WordPress server. ImageKit therefore receives those requests straight from the browser, including the requested image path and transformation and standard request data such as the visitor's IP address and user agent (and the referring page). FastLayer does not send that data; the browser does, and ImageKit handles it under ImageKit's own privacy policy. This affects front-end page views only, never admin, AJAX, cron or REST requests.
* **How is it protected?** For the requests FastLayer itself makes, FastLayer restricts the host to ImageKit's own hosts (`imagekit.io` and its subdomains) and rejects requests to any other host, to IP addresses, and to URLs containing embedded credentials. If you enter an endpoint without a scheme, FastLayer adds `https://`. If you enter one that explicitly begins with `http://`, FastLayer will use it as written, so enter your `https://` endpoint to keep that connection encrypted. Front-end image URLs are built from whichever endpoint you save, so the same advice applies there.
* **What is stored locally?** The endpoint you enter is saved in your WordPress options and is also written into a configuration file FastLayer generates at `wp-content/cache/wp-fastlayer/config.php`. This value is deleted when you uninstall the plugin.

== Troubleshooting ==

= My changes are not showing on the front end =

FastLayer clears its cache automatically when posts, comments, terms or the active theme change. If you are also running a server, CDN or second caching layer, that layer may still be serving an older copy. Clear the FastLayer cache from the admin bar, then clear your CDN or server cache as well.

= Styles or scripts break after I enable optimization =

Turn the feature off, then re-enable options one at a time to find the cause. Add the affected files to the exclude list on the CSS or JavaScript screen. For JavaScript, enable **Safe Mode** or use **Smart JS Delay** instead of plain defer, so fragile scripts keep working.

= Some images were not converted to WebP =

WebP conversion only processes images the server can read and write to. The bulk screen reports how many images converted, how many failed and how many remain, and **View Detailed Logs** shows which files were skipped.

= My host does not allow .htaccess changes =

On nginx and on hosting that blocks `.htaccess` edits, add the WebP rule at the server level instead. Page caching does not depend on `.htaccess` and continues to work normally.

== Screenshots ==

1. FastLayer admin dashboard showing optimization features.

== Changelog ==

= 1.1.0 =
* Renamed all plugin-prefixed identifiers (options, cron hooks, transients, asset handles, constants and classes) to the `fastlayer_*` naming scheme required by the WordPress.org plugin directory.
* Added automatic migration for existing installs: settings, scheduled preload jobs, transient keys and cache drop-in ownership are carried over safely, with no manual steps required.
* Combined CSS and JavaScript files are now registered through the WordPress core style and script printing APIs.
* Added extra safety checks for cache and minified-asset file writes.
* WebP rewrite directives are now cleared from the root `.htaccess` when the plugin is deactivated or uninstalled.
* Uninstall now fully removes plugin options, transients, cron jobs and cache files.

= 1.0.0 =
* Initial release.
* Added page cache, cache preload, minify options, lazy load, WebP conversion, and database optimization.

== Upgrade Notice ==

= 1.1.0 =
This release renames the plugin's internal identifiers to meet the WordPress.org plugin directory requirements. Your settings, preload schedules and cached content are migrated automatically, so you can update normally with no manual steps.
