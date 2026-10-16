---
name: WordPress Security Auditor
description: Use when auditing or hardening a WordPress plugin for security: nonce and capability checks, sanitizing input, escaping output, SQL preparation, avoiding direct superglobal reads, and reviewing request handlers before a WordPress.org submission.
---

# WordPress Security Auditor

Review the request → sanitize → store → output path of every piece of data, and
prove each state-changing action is protected. Report false positives honestly
instead of suppressing them.

## When to use

- Before a WordPress.org submission or a security review.
- When Plugin Check / WPCS reports NonceVerification, InputNotSanitized,
  MissingUnslash, EscapeOutput, PreparedSQL or UnescapedDBParameter.
- When adding a new `admin_post_*`, `wp_ajax_*`, REST route, or form handler.

## The four rules

1. **Capability** — every handler and admin screen checks the correct capability
   (`manage_options` or narrower) before doing anything.
2. **Nonce** — every state-changing request verifies one:
   `check_admin_referer()` / `check_ajax_referer()` / `wp_verify_nonce()`.
   The Settings API (`register_setting`) supplies its own nonce — do not add a
   second.
3. **Sanitize on input, validate before use** — always `wp_unslash()` **then**
   `sanitize_text_field()` / `absint()` / `sanitize_key()` / `esc_url_raw()`.
   Never trust a value because it "looks internal".
4. **Escape on output** — `esc_html()`, `esc_attr()`, `esc_url()`,
   `wp_kses_post()`, `wp_json_encode()` at the point of output.

## Triage playbook

When a scanner flags a read, walk it end-to-end before deciding:

1. Where does the value come from (request? `$_SERVER`? JSON body? DB schema?)
2. Is the handler that owns it nonce + capability protected?
3. Is it sanitized, and is the sanitizer the right one for the sink?
4. Is it escaped at output, exactly once (no double-escaping)?
5. If it is a **read-only** display flag, that is a false positive — record why,
   do not bolt on a nonce that does not belong.

## What is NOT a pass

- Adding `phpcs:ignore` to a **real** finding instead of fixing it.
- Assuming `sanitize_key()` is a SQL identifier escaper (it strips characters;
  it is not a quoting function).
- Escaping at assignment when the value is later concatenated into markup.
- Claiming a read is safe without showing where the value flows.

## SQL

- `$wpdb->prepare()` for any query containing a variable.
- Identifiers (table/column names) cannot use `%s`: use `%i` on WP ≥ 6.2
  (`$wpdb->has_cap( 'identifier_placeholders' )`) with a backtick-quoted,
  allowlisted fallback for 6.0–6.1.
- `DirectQuery` / `NoCaching` on core tables is usually warning-level and by
  design for maintenance tools — document, do not suppress.

## Deliverable

- A table of every finding: source → sink, protected?, verdict
  (Exploitable / Safe by construction / False positive / Low-severity), evidence.
- Zero unsubstantiated "safe" claims; each cites a file, line and code path.
