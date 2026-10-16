---
name: WordPress Filesystem Auditor
description: Use when auditing or writing files, directories, drop-ins, generated config, cache files, or uninstall cleanup in a WordPress plugin: path validation, atomic writes, WP_Filesystem usage, load-order exceptions, and safe directory removal.
---

# WordPress Filesystem Auditor

Make every file write safe, verifiable, and reversible, and every file read fail
closed. The dangerous cases are generated executables, atomic writes, and code
that runs before WordPress is loaded.

## When to use

- Writing any file (cache, config, `.htaccess`, minified assets, logs).
- Installing/removing a drop-in (`advanced-cache.php`, `object-cache.php`).
- Cleaning directories at uninstall.
- Reviewing `AlternativeFunctions` findings (`unlink`, `rmdir`, `readfile`,
  `rename`, `parse_url`, `is_writable`).

## Safe write pattern

1. Resolve the target and confirm it stays **inside a plugin-owned directory**
   (reject `..` traversal and symlink escapes).
2. Write to a **staged** file first, verify the byte count and read the content
   back, then `rename()` into place — `rename()` within one filesystem is atomic.
3. Check every return value; on failure record it
   (`record_write_failure()` / a log entry) and surface it, never continue.
4. For a config that another process will `include`, prefer **JSON read with
   `file_get_contents` + `json_decode`** over an executable PHP file. Nothing
   under `wp-content/` should be executed by an `include`.
5. Self-heal: if the generated file is missing or in an old format, regenerate it
   on `init()`.

## Path / URL conversions

- Centralise URL→path conversion in **one** helper and delegate all callers to
  it; duplicated conversions drift and one copy misses traversal checks.
- Prefer `wp_parse_url()` where WordPress is loaded; the drop-in must use native
  `parse_url()` (loaded before WordPress — see below).

## Load-order exceptions (legitimate, document each)

`advanced-cache.php` is included by `wp-settings.php` at line 100, **before**
`wp-includes/functions.php` (line 117) defines `wp_parse_url()`, `wp_unslash()`
and `wp_delete_file()`, and before WP_Filesystem exists. There it is correct to
use native `parse_url()` / `readfile()` / `unlink()`. Each such call gets a
narrow, line-scoped `phpcs:ignore` naming the sniff and the reason.

## WP_Filesystem

- Use `WP_Filesystem` (via `Bootstrap::get_filesystem()`) for directory removal;
  there is no `wp_rmdir()`. **Fail closed**: if initialisation fails (e.g. not the
  `direct` method), leave the directory in place rather than falling back to a
  raw `rmdir()`.
- `wp_delete_file()` for deleting files; `wp_is_writable()` for checks.
- `WP_Filesystem::move()` is **not** guaranteed atomic — keep a real `rename()`
  for the `wp-config.php` writer.

## Drop-in ownership

- Overwrite/delete a drop-in only when ownership is proven: bundled hash, an
  ownership marker comment, or your own (including legacy) function names.
- Never touch a foreign caching plugin's drop-in.

## Deliverable

- Per-operation table: target, containment check, staging, verification, failure
  path, and the reason any native call was kept.
