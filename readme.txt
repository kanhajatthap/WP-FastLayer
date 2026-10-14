=== FastLayer ===
Contributors: kanhajatthap
Tags: cache, performance, optimization, minify, lazy load
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires PHP: 7.4

FastLayer speeds up WordPress with page caching, CSS/JS optimization, WebP images, lazy loading, optional ImageKit CDN and database cleanup.

== Description ==

FastLayer is a performance plugin that brings page caching, code optimization, image delivery and database cleanup together under one **FastLayer** menu in the WordPress admin. Instead of installing and tuning several single-purpose plugins, you enable only the tools you need from one place.

It is built for typical WordPress sites, such as blogs, business sites and WooCommerce stores. Every feature is off by default and can be switched on independently, and FastLayer only writes to files it created itself, so it will not overwrite another plugin's cache drop-in or configuration line.

= Highlights =

* **Page caching** that serves static HTML to anonymous visitors, with optional background preloading.
* **Asset optimization** for HTML, CSS and JavaScript: minify, combine, defer and delay.
* **Image optimization** with WebP conversion and responsive delivery.
* **Lazy loading** for images, iframes and videos, with low-quality image placeholders.
* **Optional ImageKit CDN** for image delivery, using an endpoint you control.
* **Database cleanup** for revisions, transients and orphaned metadata.

== Features ==

Every tool has its own settings screen and can be enabled independently.

= Page caching and cache preloading =

Caches anonymous page views as static HTML and refills the cache automatically after a clear.

* **Cache lifetime:** cached pages are kept for a set time (1 to 720 hours) and cleared automatically after that.
* **Automatic preloading:** after a cache clear, pages are warmed in background batches so visitors are less likely to hit a cold cache.
* **Page discovery:** preloading reads your XML sitemap (the WordPress core sitemap, Yoast SEO or All in One SEO), with built-in limits so a large or slow sitemap cannot stall the job. It can also be started manually at any time.
* **Manual control:** clear everything from the **FastLayer** admin-bar menu or the Dashboard.
* **Never cached:** logged-in users, the admin area, AJAX and REST requests, previews, 404 pages, search results, and POST or HEAD requests.

= HTML, CSS and JavaScript optimization =

Reduces page weight and render-blocking requests. Each option can be enabled separately.

* **HTML:** minifies markup by removing comments and collapsing extra whitespace, with an option to keep Gutenberg block comments so block markup stays intact.
* **Minify:** shrinks CSS and JavaScript files and can remove comments.
* **Combine:** merges stylesheets and scripts to reduce requests while preserving media-specific styles.
* **CSS delivery:** loads styles without blocking rendering, with a custom critical CSS box, a one-click sample generator, Smart Gutenberg CSS optimization and `display=swap` for Google Fonts.
* **JavaScript loading:** defers and/or async-loads eligible scripts.
* **Smart JS Delay:** waits for the first user interaction before running eligible non-critical scripts, then restores them after a configurable timeout so nothing is left unloaded.
* **Safety controls:** Safe Mode and exclusion lists (by handle, keyword or pattern) keep chosen scripts untouched.

= Image optimization, WebP and lazy loading =

Generates lighter image files and defers media that is off screen.

* **WebP conversion:** converts JPEG and PNG attachments to WebP, either automatically on upload or in resumable batches for images that already exist.
* **Responsive delivery:** serves optimized responsive sizes and WebP variants.
* **Lazy loading:** defers images, iframes (YouTube, Vimeo and others) and HTML5 videos.
* **LQIP:** progressive loading with low-quality image placeholders, including placeholder width, quality, blur strength and fade duration controls.
* **Smart LCP:** prioritizes one above-the-fold hero image or background without disabling lazy loading everywhere, with optional preload hints, hero background support and debug logging.

= CDN and optional ImageKit integration =

Serves assets and images from a host outside your server, or keeps everything on your own domain.

* **Custom CDN:** rewrites internal asset URLs to a CDN hostname you configure.
* **ImageKit:** optionally delivers images through ImageKit. Only your ImageKit endpoint is stored; no API keys are requested or saved. See External Services for exactly what is sent, and by whom.

= Database cleanup =

Removes unused rows and optimizes tables, showing counts before anything is deleted.

* **Tables:** optimizes database tables.
* **Posts:** removes post revisions, trashed posts, spam comments and auto-drafts.
* **Transients and cache:** removes expired or all transients, and oEmbed cache rows.
* **Orphaned data:** removes duplicate or orphaned post, comment, user and term metadata.
* **Safety:** item counts are shown before cleaning, and a Clean All control selects every option at once.

= Exclusions and diagnostics =

Keeps FastLayer away from anything that must stay untouched, and explains cache decisions when something looks wrong.

* **Cache exclusions:** exclude URLs, cookies and user agents from caching.
* **Asset exclusions:** exclude individual CSS and JS files by path, pattern or external domain.
* **Debug logging:** records cache decisions, Smart LCP and Smart JS Delay activity.
* **Admin-bar actions:** Clear All Cache, Clear CSS/JS Cache and Preload Cache.

== Installation ==

FastLayer installs like any other WordPress plugin.

1. In your WordPress admin, open Plugins > Add New, search for "FastLayer", then click Install Now and Activate.
2. To install manually, upload the `fastlayer` folder to `/wp-content/plugins/` and activate the plugin from the Plugins screen.
3. On activation, FastLayer installs its `advanced-cache.php` drop-in and adds `define( 'WP_CACHE', true );` to `wp-config.php` so page caching can work.
4. Open **FastLayer** in the admin menu. The Dashboard shows cache status, a cached-pages count and quick actions.

= Recommended configuration =

Enable features one group at a time and check the front end after each change.

1. **Cache:** enable **Page Caching**, then turn on **Cache Preloading** if you want pages warmed automatically after a cache clear.
2. **CSS and JavaScript:** enable minification first, then add combination, delivery and delay options one at a time.
3. **Media:** enable **Convert Images to WebP Format** and run **Bulk WebP Generation** for existing images, then enable lazy loading and LQIP if your theme supports them.
4. **Database:** review the item counts, then run the cleanup and optimize tables.

= Verify it is working =

1. **Page cache:** open a front-end page in a private or logged-out browser window. A cached response includes the `X-Cache-Status: HIT` response header.
2. **Dashboard:** the FastLayer Dashboard reports a cached-pages count and cache status, and offers quick actions to clear or preload the cache.
3. **Media:** the Media screen reports whether the server supports WebP, and bulk generation reports how many images converted, failed or remain.
4. **Assets:** with combination enabled, check that combined CSS and JS files are served from `wp-content/uploads/wp-fastlayer-min/`.

= Requirements =

* WordPress 6.0 or newer and PHP 7.4 or newer.
* WebP conversion needs the GD library with WebP support, or Imagick with WebP support. The Media screen reports what your server supports.
* FastLayer writes to `wp-config.php` and, when WebP is enabled, to the site root `.htaccess`. Both files must be writable by WordPress for FastLayer to add its rules automatically.

== Compatibility ==

FastLayer is built to sit alongside the plugins and themes you already run.

* **Other caching and optimization plugins:** run one page-cache engine and one minify engine per site to avoid conflicting output.
* **Page builders and e-commerce:** Smart JS Delay keeps critical dependencies, Elementor and WooCommerce checkout/cart, and navigation scripts running immediately; exclusions and Safe Mode cover anything left over.
* **Servers:** Apache and LiteSpeed apply the `.htaccess` rules directly, while nginx needs a manual server-level WebP rule. Page caching and asset optimization work on nginx, and FastLayer shows an admin notice with guidance when it detects nginx.
* **Multisite:** not currently supported.

== Frequently Asked Questions ==

Answers to the questions that come up most often.

= Does FastLayer change WordPress core files? =

No. FastLayer never edits files in `wp-includes/` or `wp-admin/`. Like most caching plugins, it creates and manages its own files outside the plugin folder:

* On activation it installs `wp-content/advanced-cache.php`.
* On activation it adds `define( 'WP_CACHE', true );` to `wp-config.php`, above the "That's all, stop editing!" line. `wp-config.php` is backed up outside the web root first, and the line is removed again only when it carries FastLayer's own ownership marker.
* When WebP is enabled it adds a marked `WP FastLayer WebP` block to the `.htaccess` file in your WordPress root.

See Uninstall and data removal for what happens when you deactivate or delete the plugin.

= How do I clear the cache? =

Use the **FastLayer** menu in the admin bar to **Clear All Cache**, **Clear CSS/JS Cache** or **Preload Cache**. The same actions are on the plugin Dashboard.

= How do I exclude a URL, cookie or script? =

Cache exclusions for URLs, cookies and user agents are on the Advanced Rules screen. Individual CSS and JS files can be excluded by path, pattern or external domain on their own screens, and **Safe Mode** disables script delaying entirely. See Exclusions and diagnostics.

= Do I need an ImageKit account? =

No. ImageKit is optional and off by default, and FastLayer works fully without it. See External Services for exactly what is sent if you enable it.

= Which files and folders does FastLayer create? =

* Page cache, configuration and the cache `.htaccess`: `wp-content/cache/wp-fastlayer/`
* Combined and minified CSS/JS: `wp-content/uploads/wp-fastlayer-min/`
* WebP files are generated next to the original images in your uploads folder.

= What happens when I deactivate or delete FastLayer? =

Deactivating removes the drop-in, the `wp-config.php` line and the WebP `.htaccess` directives, and leaves your settings and cached files in place. Deleting removes the plugin's options, transients, cron jobs and cache files. See Uninstall and data removal for the full details.

= Is any data sent to a third party? =

No data leaves your site by default. The only external service is the optional ImageKit integration, and it does nothing until you configure it. See External Services for exactly what is sent, and by whom.

= Where can I report a bug or ask for help? =

Please open an issue on the plugin's GitHub repository: https://github.com/kanhajatthap/WP-FastLayer

== Troubleshooting ==

Start here when something does not look right, and change one option at a time.

= My changes are not showing on the front end =

FastLayer clears its cache automatically when posts, comments, terms or the active theme change. If you are also running a server, CDN or second caching layer, that layer may still be serving an older copy. Clear the FastLayer cache from the admin bar, then clear your CDN or server cache as well.

= Styles or scripts break after I enable optimization =

Turn the feature off, then re-enable options one at a time to find the cause. Add the affected files to the exclude list on the CSS or JavaScript screen. For JavaScript, enable **Safe Mode** or use **Smart JS Delay** instead of plain defer, so fragile scripts keep working.

= WebP conversion is unavailable on my server =

WebP conversion needs GD with WebP support or Imagick with WebP support. The Media screen reports exactly what your server has. Ask your host to enable GD WebP or Imagick WebP, then reload the screen.

= Some images were not converted to WebP =

WebP conversion only processes images the server can read and write to. The bulk screen reports how many images converted, how many failed and how many remain, and **View Detailed Logs** shows which files were skipped.

= My host does not allow .htaccess changes =

On nginx and on hosting that blocks `.htaccess` edits, add the WebP rule at the server level instead. Page caching does not depend on `.htaccess` and continues to work normally.

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

== Uninstall and data removal ==

* **Deactivate:** removes the `advanced-cache.php` drop-in, the `define( 'WP_CACHE', true );` line in `wp-config.php`, and the marked WebP block in `.htaccess`. Your settings and cached files are kept.
* **Delete:** removes the plugin's options, transients, cron jobs and generated cache files, including the saved ImageKit endpoint, which is stored in your options and in the generated `wp-content/cache/wp-fastlayer/config.php`.
* **Not removed:** WebP image files created next to your uploads are left in place.

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
