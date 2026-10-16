---
name: WordPress.org Reviewer
description: Use when preparing a plugin for WordPress.org directory review or responding to review feedback: slug and text domain rules, readme.txt validity, prefixing, Plugin Check findings, licensing, and what reviewers actually reject.
---

# WordPress.org Reviewer

Predict what the directory team will flag, and fix the cause rather than the
symptom. Never claim a review will pass or that a plugin has zero issues.

## When to use

- Before submitting or resubmitting to the WordPress.org directory.
- When reconciling Plugin Check (PCP) output with the review guidelines.
- When the local folder name does not match the intended slug.

## Identifier rules

- **Prefix everything global**: options, cron hooks, transients, classes,
  functions, asset handles, and global variables. Unprefixed globals in files
  that run before/outside the bootstrap (`advanced-cache.php`, `uninstall.php`)
  are an accepted exception — document why.
- **Slug / folder / text domain.** WordPress.org slugs are lowercase. Plugin
  Check derives the expected text domain from the **plugin directory name**, so a
  capitalised folder produces hundreds of false `TextDomainMismatch` errors.
  Rename the folder to the lowercase slug; never change the text domain.
- Renaming the folder deactivates the plugin (WP stores `folder/mainfile.php`);
  reactivate and remove the stale capitalised `active_plugins` entry.

## readme.txt

- Valid header, ≤ 5 tags, a one-line short description, a Description section,
  a changelog, and `Tested up to` at a **real current** WordPress version.
- Validate with the official WordPress.org Readme Validator, not by eye.
- `Stable tag` must equal the plugin version.

## Plugin Check triage

Classify every finding as one of:
1. **Fixed** — code changed.
2. **Environment** — e.g. text-domain mismatch from a capitalised folder; clears
   with the correct slug.
3. **False positive** — trace the value and record why.
4. **Intentional, documented** — native calls before WordPress loads, maintenance
   SQL, older-WP compat (`load_plugin_textdomain`).

Never lower the count by blanket-suppressing a real finding. Each keep gets a
narrow, line-scoped `phpcs:ignore` with the reason on the line.

## Licensing / packaging

- GPLv2 or later, matching `License` header and `License URI`.
- Exclude dev-only files (`AGENTS.md`, `docs/`, `tests/`, `.gitignore`) from the
  production ZIP; Plugin Check flags them but they are packaging, not defects.
- No secrets, no external calls that phone home, no bundling of unlicensed code.

## Honesty

Findings counts, "no error" claims, and readme validation must cite an executed
command. If the review message cannot be located, say the issue list is
reconstructed — do not fabricate reviewer quotes.
