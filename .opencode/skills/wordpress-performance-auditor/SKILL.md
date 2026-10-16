---
name: WordPress Performance Auditor
description: Use when auditing or building WordPress performance features: page caching, output buffering, cache invalidation, minification and combination, lazy loading, WebP, CDN rewriting, and preloading. Covers correctness of the fast path as well as speed.
---

# WordPress Performance Auditor

Performance code fails in two directions: it makes sites slower, or it serves
the wrong bytes fast. Check correctness of the fast path first, then its cost.

## When to use

- Page cache serving/saving, preload batches, invalidation.
- HTML/CSS/JS minify, combine, defer, smart delay.
- Lazy loading, LQIP placeholders, WebP, CDN/ImageKit URL rewriting, LCP preload.

## Output buffering

- Start buffering on a hook you can guarantee fires (`template_redirect`), and
  **flush every buffer you opened** at shutdown or the page can arrive truncated.
- Track a `buffer_level`; flush with a level guard and reset it, in a defined
  order (optimizer → page cache → save). Never `ob_end_flush()` blindly.
- When the cache is disabled or the user is logged in, do not buffer at all.

## Cache correctness

- Cache key must include the things that change the bytes: host, scheme, path,
  query (normalised), mobile/device, cookies that matter, and version.
- Skip caching for logged-in users, POST/HEAD, preview, and known bypass
  cookies/query flags.
- Invalidate on publish/update/comment/settings change; never leave stale pages
  for content changes.
- Set `WP_CACHE` true only when the drop-in is present; the drop-in owns serving.

## Generated assets

- Escape generated URLs/attributes at insertion (`esc_url`/`esc_attr`); a URL put
  into `srcset`/`src` raw can break the attribute and is an XSS surface.
- Register and enqueue combined assets through `wp_register_style/script()` +
  `wp_enqueue_*()`, with `FASTLAYER_VERSION` (a real version, not `null`).
- Inline CSS/JS must use `wp_add_inline_style()` / `wp_add_inline_script()` /
  `wp_print_inline_script_tag()` — never a hand-built `<script>`/`<link>`.

## Images / WebP / CDN

- Verify the conversion actually produces a valid image before serving it, and
  fall back to the original on any failure.
- LCP preload must point at the real candidate and must not double-apply
  attributes when the tag is re-parsed.

## Measuring honestly

- State what was measured (TTFB, HTML size, request count) and on what
  environment. Do not claim a speedup that was not measured.
- Correctness regressions (wrong bytes, truncated output, 500s) outrank any
  speed claim.

## Deliverable

- For each feature: the fast-path invariant, its failure mode, and the evidence
  (test or measurement) that the fast path is correct.
