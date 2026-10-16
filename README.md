<div align="center">

# ⚡ FastLayer

### High-Performance WordPress Caching & Optimization Engine

[![WordPress](https://img.shields.io/badge/WordPress-6.0%20to%207.1-21759B?style=for-the-badge&logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%20|%208.0%20|%208.1%20|%208.2%20|%208.3-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2%20or%20later-4CAF50?style=for-the-badge)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Plugin Check](https://img.shields.io/badge/Plugin%20Check-0%20Errors%20Pass-brightgreen?style=for-the-badge&logo=checkmarx&logoColor=white)](#quality-gates)
[![Quality Gates](https://img.shields.io/badge/CI%20Gates-Passing-success?style=for-the-badge&logo=github-actions&logoColor=white)](#development--quality-gates)

<br/>

**FastLayer** combines static HTML page caching, asset minification & combination, smart script delay, WebP image conversion, low-quality image placeholders (LQIP), edge CDN delivery, and deep database cleanup into a single, cohesive, zero-bloat performance suite.

[Features](#-key-features) • [Architecture](#-architecture--request-lifecycle) • [Installation](#-installation) • [Security Guarantees](#-security-by-design) • [CLI & Testing](#-automated-testing--quality-gates)

---

</div>

<br/>

## 🌟 Why FastLayer?

Most WordPress sites install 4 to 6 disconnected optimization plugins: one for caching, one for minifying scripts, another for WebP images, one for lazy loading, and one for database cleanup. 

This causes **output buffer conflicts**, **redundant file writes**, **inconsistent cache invalidation**, and **admin bloat**.

FastLayer eliminates the chaos by unifying every optimization layer into a synchronized pipeline:

```
┌────────────────────────────────────────────────────────────────────────┐
│                          THE FASTLAYER PIPELINE                        │
│                                                                        │
│   Incoming Request                                                     │
│          │                                                             │
│          ▼                                                             │
│  [ advanced-cache.php ] ─────── Static HTML Hit? ──────► [ Sub-10ms ]  │
│          │ (Pre-WordPress)                                Response     │
│          ▼ (Cache Miss / Logged-in)                                    │
│  [ Core WordPress Load ]                                               │
│          │                                                             │
│          ▼                                                             │
│  [ Optimizer Engine ]   ─────── Minify CSS/JS + HTML                   │
│          │               ─────── Smart JS Delay (on Interaction)       │
│          │               ─────── Defer & Async Non-Critical Scripts    │
│          ▼                                                             │
│  [ Next-Gen Media ]     ─────── WebP Auto-Serve                        │
│          │               ─────── LQIP Blurred Placeholder Injection    │
│          │               ─────── Smart LCP Hero Image Prioritization   │
│          ▼                                                             │
│  [ CDN Transformer ]    ─────── Edge CDN / ImageKit URL Rewriting      │
│          │                                                             │
│          ▼                                                             │
│  [ Static Page Cache ]  ─────── Save Static HTML to Disk               │
│          │                                                             │
│          ▼                                                             │
│    Fast Delivery                                                       │
└────────────────────────────────────────────────────────────────────────┘
```

---

## 🚀 Key Features

### ⚡ 1. High-Speed Page Cache & Background Preload
* **Drop-in Static Cache:** Serves static HTML before WordPress, database, or themes load via `advanced-cache.php`.
* **Sub-10ms TTFB:** Drastically reduces server load and accelerates Time to First Byte.
* **Smart Background Preloading:** Automatically re-warms cache in non-blocking background batches using XML sitemaps (WordPress Core, Yoast, Rank Math, or All in One SEO).
* **Safe Invalidation:** Automatically flushes post, category, and homepage cache on content updates without clearing unrelated pages.
* **Per-Site Multisite Isolation:** Network installations store per-site cache configurations with index-based routing (`sites.json`).

### 📦 2. Advanced Asset Optimization (HTML, CSS & JS)
* **HTML Minification:** Strips comments and collapses whitespace while safely preserving Gutenberg block boundaries.
* **CSS & JS Minification:** Removes dead code and shrinks payloads without breaking execution.
* **Smart Script Combination:** Merges styles and scripts into optimized bundles.
* **Smart JS Delay:** Pauses heavy non-critical JavaScript until real user interaction (scroll, touch, click, or mouse movement) with safe auto-restore timeouts.
* **Critical CSS & Font Optimization:** Inlines above-the-fold CSS, optimizes Google Fonts with `display=swap`, and defers remaining styles.

### 🖼️ 3. Next-Gen Media Engine (WebP & LQIP)
* **Automatic WebP Conversion:** Converts uploaded and existing JPG/PNG media into WebP format with quality controls.
* **Low-Quality Image Placeholders (LQIP):** Injects ultra-lightweight blurred inline placeholders for smooth, cumulative layout shift (CLS) free image loading.
* **Smart LCP (Largest Contentful Paint) Priority:** Automatically detects above-the-fold hero images, injects high-priority preload hints, and prevents lazy loading on the hero asset.
* **Universal Lazy Loading:** Defers off-screen images, YouTube/Vimeo iframes, and HTML5 video tags.

### 🌐 4. CDN & Edge Delivery
* **Custom CDN Support:** Rewrites static asset URLs to custom CDN CNAMEs with full HTTPS enforcement.
* **ImageKit.io Integration:** Seamless edge image delivery with on-the-fly transformations and zero API credential exposure.
* **Safe CSS Context Sanitization:** Eliminates attribute breakout vulnerabilities with strict `sanitize_css_url()` and `esc_attr()`.

### 🧹 5. Deep Database Sanitation
* **Revisions & Auto-Drafts Cleanup:** Removes stale revisions, auto-drafts, and trashed content.
* **Transient Purge:** Clears expired and orphaned WordPress transients safely.
* **Metadata Defrag:** Deletes orphaned postmeta, commentmeta, usermeta, and termmeta rows.
* **One-Click Table Optimization:** Defragments and optimizes MySQL/MariaDB database tables with row counts displayed before execution.

---

## 🔒 Security by Design

FastLayer is engineered to adhere to strict enterprise security standards:

| Security Principle | Implementation in FastLayer |
| :--- | :--- |
| **No Executable Cache** | Configuration is stored strictly as static JSON (`config.json`), completely eliminating the risk of arbitrary PHP execution under `wp-content/`. |
| **Webroot Log Isolation** | Diagnostics are saved strictly outside the public web root (`dirname(ABSPATH) . '/fastlayer-logs/'`). Requests to legacy public log paths return HTTP 404. |
| **Fail-Closed Logging** | If an outside-webroot directory cannot be safely established or is unwritable, file logging aborts and delegates to standard `error_log()`. It never exposes logs in public directories. |
| **Safe `wp-config.php` Rewriting** | `WP_CACHE` defines use staged temporary files and atomic `rename()` operations with automatic backups, strictly modifying lines marked with plugin ownership markers. |
| **Directory Traversal Protection** | Unified path resolution enforces internal host validation, blocks `../` traversal, rejects NUL bytes, and matches directory boundaries. |
| **Guarded Buffer Flushes** | Explicit shutdown handlers execute at priorities 1 (Optimizer) and 5 (Page Cache) under strict `buffer_level` guards, never truncating foreign output buffers. |

---

## 📋 Requirements & Compatibility

* **WordPress:** 6.0 or higher (Tested up to 7.1)
* **PHP:** 7.4 to 8.3 supported (Tested on 7.4.30 and 8.2.29)
* **Web Server:** Nginx, Apache (with `mod_rewrite` / `mod_headers`), LiteSpeed, or IIS
* **Database:** MySQL 5.7+ / MariaDB 10.3+
* **Multisite:** Subdomain and Subdirectory networks fully supported

---

## 📦 Installation

### Via WordPress Admin
1. Go to **Plugins** > **Add New**.
2. Search for **FastLayer**.
3. Click **Install Now**, then **Activate**.
4. Navigate to the **FastLayer** dashboard in your admin sidebar to review and enable features.

### Manual Installation
1. Clone or download this repository:
   ```bash
   git clone https://github.com/kanhajatthap/WP-FastLayer.git fastlayer
   ```
2. Upload the `fastlayer` directory to `/wp-content/plugins/`.
3. Activate the plugin from the **Plugins** screen in WordPress.

---

## 🛠️ Configuration & Settings

Every feature in FastLayer is disabled by default, allowing you to enable tools modularly and verify front-end behavior after each step:

```
WordPress Admin
└── ⚡ FastLayer
    ├── 📊 Dashboard      (Cache statistics, quick purge & preload triggers)
    ├── ⚡ Page Cache     (TTL, preloading, query string rules, exclusions)
    ├── 🎨 Optimizer      (Minify, combine, defer, Smart JS Delay)
    ├── 🖼️ WebP & Media   (WebP conversion, LQIP styling, Smart LCP priority)
    ├── 🌐 CDN & ImageKit (CDN hostnames, ImageKit endpoints)
    ├── 🧹 Database       (Revisions, transients, orphaned metadata, optimize)
    └── ⚙️ Diagnostics    (Performance log viewer, system status)
```

---

## 🧪 Automated Testing & Quality Gates

FastLayer maintains a zero-error policy across WordPress.org Plugin Check and strict regression coverage:

```bash
# 1. Run PHP syntax linting across all files
php -l wp-fastlayer.php
php -l advanced-cache.php
php -l uninstall.php

# 2. Run the secure logging & HTTP 404 isolation regression test
wp eval-file tests/test-log-security.php

# 3. Run the uninstall cleanup & artifact purge verification
wp eval-file tests/test-uninstall-cleanup.php

# 4. Run the CDN attribute escaping & CSS sanitization check
wp eval-file tests/test-cdn-escaping.php

# 5. Run the config.json migration & legacy PHP drop check
wp eval-file tests/test-config-json.php

# 6. Run the guarded dual output buffer flush test
wp eval-file tests/test-buffer-flush.php

# 7. Run official WordPress.org Plugin Check
wp plugin check fastlayer
```

---

## 📁 Production File Structure

The production distribution consists of exactly 18 core files:

```text
fastlayer/
├── wp-fastlayer.php                  # Main entry point, autoloader, constants
├── advanced-cache.php                # Drop-in pre-bootstrap caching engine
├── uninstall.php                     # Complete database & filesystem cleanup
├── readme.txt                        # WordPress.org directory readme
├── includes/
│   ├── class-admin-bar.php           # Toolbar quick-actions & purge triggers
│   ├── class-admin.php               # Admin interface, settings & diagnostics
│   ├── class-bootstrap.php           # Core lifecycle, pathing, config & logging
│   ├── class-cdn.php                 # CDN URL rewriting & CSS sanitization
│   ├── class-database-optimization.php# Database cleanup & table optimization
│   ├── class-optimizer.php           # HTML/CSS/JS minifier & Smart JS delay
│   ├── class-page-cache.php          # Page cache generator & preload engine
│   └── class-webp.php                # WebP generation, LQIP & media engine
├── assets/
│   ├── css/admin.css                 # Admin UI styling
│   ├── js/admin.js                   # Settings dashboard interactions
│   ├── js/imagekit-runtime-debug.js  # CDN diagnostics helper
│   ├── js/lazyload.js                # Frontend lightweight lazy loader
│   └── js/lqip-frontend.js           # Progressive LQIP image reveal
└── languages/
    └── fastlayer.pot                 # Localization translation template
```

---

## 📄 License & Credits

* **Author:** [Kanha Jatthap](https://github.com/kanhajatthap)
* **License:** FastLayer is open-source software licensed under the **GNU General Public License v2 or later** (GPLv2+).
* **Text Domain:** `fastlayer`

---

<div align="center">
  <sub>Engineered with precision for blazing fast WordPress experiences.</sub>
</div>
