# AUDIT-BASELINE.md — FastLayer audit baseline

**Recorded:** 2026-10-15
**Working directory:** `…/wp-content/plugins/fastlayer`
**Purpose:** capture the exact starting state so every later “before → after”
claim in `docs/AUDIT-FINDINGS.md` and `docs/REVIEW-HISTORY-MATRIX.md` is
reproducible.

## Repository

| Item | Value |
| --- | --- |
| Branch | `main` |
| Baseline commit | `1713fff` — “docs: align readme.txt with the real wordpress.org tab structure and add callouts” |
| Working tree at start | clean except pre-existing plugin-check install in a sibling folder |
| Tracked production files | 18 (see manifest below) |
| Dev-only paths | `AGENTS.md`, `docs/`, `tests/`, `.gitignore`, `.opencode/`, `.github/` |
| Tags | none |

## Version baseline

| Location | Value |
| --- | --- |
| `wp-fastlayer.php` `Version:` header | `1.1.0` |
| `wp-fastlayer.php` `FASTLAYER_VERSION` | `1.1.0` |
| `readme.txt` `Stable tag:` | `1.1.0` |

All three matched at baseline and still match after this pass.

## Toolchain (verified available)

| Tool | Version | Notes |
| --- | --- | --- |
| Node.js | v22.23.2 | `node --check` for `assets/js/*.js` |
| npm | 10.9.8 | — |
| git | 2.54.0 | local repo only; nothing pushed |
| PHP (CLI) | 7.4.30 | LocalWP `lightning-services/php-7.4.30+6`; no `php.ini`, extensions loaded with `-d` |
| PHP (alt) | 8.2.29 | lint + regression cross-check |
| WP-CLI | 2.12.0 | `wp-cli.phar` |
| WordPress | 7.1.3 | live local site |
| MySQL | 8.4 (port 10027) | LocalWP |
| Plugin Check | 2.1.0 | with bundled PHPCS 3.13.6 / WPCS / phpcsextra / phpcsutils |
| **Unavailable** | — | `composer`, `rg` (ripgrep), PHPUnit |

## Baseline Plugin Check (before this pass)

`wp plugin check fastlayer` → **0 ERROR / 3 WARNING**:

| Finding | Level | File |
| --- | --- | --- |
| `hidden_files` | Warning | `.gitignore` |
| `unexpected_markdown_file` | Warning | `AGENTS.md` |
| `PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound` | Warning | `wp-fastlayer.php:47` |

The same result (0 E / 3 W) is observed after this pass — no new finding was
introduced.

## Baseline PHPCS (WPCS)

Full `--standard=WordPress` on the 11 plugin PHP files (tests excluded):
**57 distinct sniffs**, dominated by `Generic.WhiteSpace.DisallowSpaceIndent`
(13,588) from the plugin’s intentional 4-space PHP style. The full per-sniff
counts are committed as `tests/phpcs-baseline.json` for the CI delta gate. Full
WPCS is **not** a pass/fail gate for this codebase — see
`docs/CI-RELEASE-GATES.md`.

## Production manifest (18 files, dev files excluded)

The production ZIP is rooted at `fastlayer/` and contains the plugin PHP files,
`readme.txt`, `uninstall.php`, `advanced-cache.php`, `assets/` and
`languages/`. `AGENTS.md`, `docs/`, `tests/`, `.gitignore`, `.opencode/` and
`.github/` are **not** shipped. The authoritative byte-size/`sha256` list lives
in `docs/RELEASE-CHECKLIST.md` §5.

## Backups (created before any change)

| Artefact | Purpose |
| --- | --- |
| `%TEMP%\opencode\fastlayer-backups\fastlayer-plugin-audit-20261015-101934.zip` | Full plugin snapshot |
| `%TEMP%\opencode\fastlayer-backups\wp-config-20261015-101934.php.bak` | `wp-config.php` snapshot |

Pre-existing backups from earlier rounds were left untouched.

## Measurement method

- Code inspection: reading the current source at the cited lines.
- Executed: a command that ran in this workspace and produced the recorded
  output (`php -l`, `node --check`, `wp plugin check`, PHPCS, `wp eval-file`).
- Manual: performed by a human in a live site (maintainer-reported only).
- **Blocked / Not Run:** could not be performed here; never reported as passing.

A test claim is only valid if the test was actually executed; this document and
its companions follow that rule throughout.
