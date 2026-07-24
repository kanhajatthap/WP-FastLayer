(function () {
    'use strict';

    if (!window.wpFastLayerImageRuntime || !window.wpFastLayerImageRuntime.enabled) {
        return;
    }

    var cfg = window.wpFastLayerImageRuntime;
    var ratioLimit = Number(cfg.maxOversizeRatio || cfg.oversizeRatio || 1.5);
    var enableOverlay = !!cfg.overlayEnabled;
    var pendingReports = [];
    var seenImages = new WeakSet();

    function parseRequestedWidth(url) {
        if (!url || url.indexOf('imagekit.io') === -1) {
            return 0;
        }

        var match = url.match(/[?&]tr=([^&#]+)/i);
        if (!match || !match[1]) {
            return 0;
        }

        var tr = decodeURIComponent(match[1]);
        var widthMatch = tr.match(/(?:^|,)w-(\d+)(?:,|$)/i);
        return widthMatch ? parseInt(widthMatch[1], 10) : 0;
    }

    function extractSrcsetWidths(srcset) {
        if (!srcset) {
            return [];
        }

        var widths = [];
        var parts = String(srcset).split(',');
        for (var i = 0; i < parts.length; i++) {
            var descriptorMatch = parts[i].trim().match(/\s(\d+)w$/i);
            if (descriptorMatch) {
                widths.push(parseInt(descriptorMatch[1], 10));
            }
        }

        return widths;
    }

    function estimateViewportWidth(img, viewport) {
        var sizes = img.getAttribute('sizes') || '';
        if (!sizes) {
            return Math.round(img.getBoundingClientRect().width || 0);
        }

        var fixed = sizes.match(/(?:^|,)\s*(\d+)px\s*$/);
        if (fixed) {
            return parseInt(fixed[1], 10);
        }

        if (sizes.indexOf('100vw') !== -1) {
            return viewport;
        }

        return Math.round(img.getBoundingClientRect().width || 0);
    }

    function addOverlay(img, renderedWidth, requestedWidth, oversizeRatio) {
        if (!enableOverlay) {
            return;
        }

        var existing = img.parentNode ? img.parentNode.querySelector('.wp-fastlayer-image-debug-overlay') : null;
        if (existing) {
            existing.textContent = 'rw:' + renderedWidth + ' | req:' + requestedWidth + ' | x' + oversizeRatio.toFixed(2);
            return;
        }

        if (!img.parentNode || !img.parentNode.style) {
            return;
        }

        if (window.getComputedStyle(img.parentNode).position === 'static') {
            img.parentNode.style.position = 'relative';
        }

        var overlay = document.createElement('div');
        overlay.className = 'wp-fastlayer-image-debug-overlay';
        overlay.textContent = 'rw:' + renderedWidth + ' | req:' + requestedWidth + ' | x' + oversizeRatio.toFixed(2);
        overlay.style.cssText = 'position:absolute;left:0;top:0;z-index:9999;padding:2px 6px;background:rgba(194,60,15,.86);color:#fff;font:11px/1.4 monospace;pointer-events:none;';
        img.parentNode.appendChild(overlay);
    }

    function pushReport(row) {
        pendingReports.push(row);
        if (pendingReports.length >= Number(cfg.collectLimit || 40)) {
            flushReports();
        }
    }

    function flushReports() {
        if (!pendingReports.length || !cfg.ajaxUrl || !cfg.nonce) {
            return;
        }

        var payload = pendingReports.slice(0);
        pendingReports = [];

        var body = new URLSearchParams();
        body.append('action', cfg.collectAction || 'wp_fastlayer_image_debug_collect');
        body.append('nonce', cfg.nonce);
        body.append('entries', JSON.stringify(payload));

        fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).catch(function () {
            // Keep runtime debugger non-blocking.
        });
    }

    function inspectImage(img) {
        if (!img || seenImages.has(img)) {
            return;
        }

        seenImages.add(img);

        var dataSrc = img.getAttribute('data-src') || img.getAttribute('data-lazy-src') || '';
        var src = img.getAttribute('src') || '';
        var srcset = img.getAttribute('srcset') || img.getAttribute('data-srcset') || '';
        var chosenUrl = dataSrc || src;
        var requestedWidth = parseRequestedWidth(chosenUrl);

        if (!requestedWidth && srcset) {
            var firstSrcset = srcset.split(',')[0] || '';
            var firstUrl = firstSrcset.trim().split(/\s+/)[0] || '';
            requestedWidth = parseRequestedWidth(firstUrl);
            chosenUrl = firstUrl || chosenUrl;
        }

        if (!requestedWidth || !chosenUrl || chosenUrl.indexOf('imagekit.io') === -1) {
            return;
        }

        var renderedWidth = Math.round(img.getBoundingClientRect().width || 0);
        if (!renderedWidth) {
            return;
        }

        var oversizeRatio = requestedWidth / Math.max(1, renderedWidth);

        if (oversizeRatio > ratioLimit) {
            console.warn('[WP FastLayer Image Debug]', {
                renderedWidth: renderedWidth,
                requestedWidth: requestedWidth,
                oversizeRatio: Number(oversizeRatio.toFixed(2)),
                imageUrl: chosenUrl
            });
        }

        addOverlay(img, renderedWidth, requestedWidth, oversizeRatio);

        pushReport({
            pageUrl: window.location.href,
            imageUrl: chosenUrl,
            renderedWidth: renderedWidth,
            requestedWidth: requestedWidth,
            oversizeRatio: Number(oversizeRatio.toFixed(3)),
            srcsetWidths: extractSrcsetWidths(srcset),
            mobileViewportResult: estimateViewportWidth(img, 375),
            desktopViewportResult: estimateViewportWidth(img, 1366),
            runtimeCorrectionApplied: false
        });
    }

    function scan() {
        var imgs = document.querySelectorAll('img[src*="imagekit.io"], img[data-src*="imagekit.io"], img[data-lazy-src*="imagekit.io"], img[srcset*="imagekit.io"], img[data-srcset*="imagekit.io"]');
        for (var i = 0; i < imgs.length; i++) {
            inspectImage(imgs[i]);
        }
        flushReports();
    }

    document.addEventListener('DOMContentLoaded', function () {
        scan();

        // Read-only debug mode: rescan on known lifecycle events, never mutate image URLs.
        window.setTimeout(scan, 600);
        window.setTimeout(scan, 1800);

        window.addEventListener('load', scan);

        window.addEventListener('resize', function () {
            window.clearTimeout(scan.__resizeTimer);
            scan.__resizeTimer = window.setTimeout(scan, 250);
        });
    });

    window.addEventListener('beforeunload', flushReports);
})();
