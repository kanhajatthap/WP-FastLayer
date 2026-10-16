---
name: WordPress Plugin Architect
description: Use when designing, structuring, or refactoring a WordPress plugin: namespaces and autoloaders, bootstrap order, drop-ins, hooks, option naming, backward-compatibility migrations, and the three-place version sync.
---

# WordPress Plugin Architect

Design and maintain the skeleton of a WordPress plugin so that runtime order,
naming, and upgrades stay predictable. Apply this before writing feature code;
changing the shape of a plugin later is expensive.

## When to use

- Creating a new plugin or a new module inside one.
- Adding a drop-in (`advanced-cache.php`, `object-cache.php`, `db.php`).
- Renaming options, hooks, shortcodes, filters, or the plugin folder/slug.
- Reviewing whether a plugin can be upgraded in place without data loss.

## Non-negotiables

1. **Never change without a migration:** the plugin slug/folder, the text
   domain, and any public option/filter/shortcode/action name. Ship the rename
   and a migration path in the same change.
2. **One class per file**, namespaced, file name `class-<kebab-case>.php`, with
   an autoloader that maps `Vendor\Foo_Bar` to `includes/class-foo-bar.php`.
   Keep that mapping intact — a mismatch is a fatal error at runtime.
3. **No closing `?>`** in pure-PHP files. No short tags.
4. **Version lives in three places** and must stay in sync: the main-file
   `Version:` header, the `define( 'PLUGIN_VERSION', ... )`, and `readme.txt`
   `Stable tag:`. Keep `Tested up to:` at a real current WordPress release.

## Bootstrap order

- The main file defines constants and paths first, then registers the
  autoloader, then boots the loader on `plugins_loaded`/`init`.
- Anything that must run before WordPress is fully loaded (drop-ins,
  `advanced-cache.php`, `uninstall.php`) must use native PHP only — no
  `wp_parse_url()`, no WP functions — and must **prefixed-global** its variables.
- Guard every module load in `try/catch (\Throwable)` and log failures; one bad
  module must not white-screen the site.
- Register lifecycle hooks with `register_activation_hook` /
  `register_deactivation_hook` and require an `uninstall.php` (or register an
  uninstall hook) that removes options, transients, cron jobs, and generated
  files.

## Drop-ins

- A drop-in is global. Own it or do not touch it.
- Install/remove it **only** when you can prove ownership (a bundled hash, an
  ownership marker comment, or your own function names including legacy ones).
  Never overwrite or delete a foreign caching plugin's drop-in.
- Read its config from a **non-executable** data file (JSON), not an included
  PHP file, so nothing under `wp-content/` is executed by an include.

## Backward compatibility

- Add a one-time migration that copies legacy option rows to the new key and
  keeps both rows in sync until the next settings save.
- Migrate and clear legacy cron hooks; never abandon a scheduled event.
- Uninstall and ownership checks must understand both new and legacy key names.
- Self-heal on `init()`: if generated state is missing or in an old format,
  regenerate it so an upgraded-but-not-yet-saved install converges automatically.

## Deliverable checklist

- [ ] Folder, text domain, and public names unchanged (or migration shipped).
- [ ] Autoloader mapping matches file names.
- [ ] Version identical in all three locations.
- [ ] Drop-in ownership proven before any write/delete.
- [ ] Legacy migration present and self-healing.
- [ ] `uninstall.php` removes everything the plugin created.
- [ ] PHP lint clean on every changed file.
