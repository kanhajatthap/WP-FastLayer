# CI-RELEASE-GATES.md — automated quality gates

**Recorded:** 2026-10-15
**Workflow:** `.github/workflows/quality-gates.yml`
**Supporting files:** `tests/phpcs-gate.php`, `tests/phpcs-baseline.json`

The workflow runs on `push` to `main`, on every `pull_request`, and manually via
`workflow_dispatch`. It only **checks** — it never builds, tags, uploads or
publishes a release.

## Jobs

| Job | What it proves | Fails the build when |
| --- | --- | --- |
| `lint` (matrix PHP 7.4 + 8.2) | Every `.php` file parses on the declared minimum **and** a current PHP branch; every `assets/js/*.js` file parses under Node 22 | Any `php -l` or `node --check` error |
| `version-sync` | The three version locations agree: header, `FASTLAYER_VERSION`, `readme.txt` `Stable tag:` | Any mismatch or unparseable location |
| `plugin-check` | Runs the official `WordPress/plugin-check-action` (Plugin Check) with dev-only directories excluded | Any Plugin Check **ERROR** |
| `phpcs` | Per-sniff PHPCS (WPCS) counts have not regressed vs the committed baseline | A non-formatting sniff grows, or a new non-allow-listed sniff appears |

## Why Plugin Check is the primary code gate

The plugin intentionally uses **4-space PHP indentation**, which the WordPress
core standard flags thousands of times (`Generic.WhiteSpace.DisallowSpaceIndent`
≈ 13,588 findings at baseline). A naive `phpcs --standard=WordPress` pass/fail
gate is therefore meaningless for this codebase. Plugin Check is the gate that
reflects the WordPress.org directory rules, so it is the authoritative check for
code quality, and it must stay at **0 ERROR**.

## How the WPCS delta gate works

1. CI installs PHPCS + WPCS + phpcsextra + phpcsutils in an **isolated**
   directory under `$RUNNER_TEMP` (no `composer.json`/`vendor/` is committed to
   the plugin root, so the fixed 18-file production manifest is untouched).
2. PHPCS runs the full `WordPress` standard over the plugin PHP files
   (`tests/` ignored, matching the committed baseline) and writes a JSON report.
3. `tests/phpcs-gate.php` aggregates findings **per sniff** and compares against
   `tests/phpcs-baseline.json`. A message matches an allow-listed sniff when the
   source equals the entry **or** starts with `entry . '.'` (PHPCS reports
   `Standard.Category.Sniff.MessageCode` and the baseline/allow-list use the
   bare sniff name), so formatting sniffs ignored by design are actually
   skipped:
   - Sniffs on the allow-list (pure formatting/documentation that grow with
     added lines in the house style) are ignored.
   - Any other sniff that grows, or any brand-new non-allow-listed sniff, fails
     the job. (Verified 2026-10-15: synthetic non-formatting regression fails; a
     synthetic `Generic.WhiteSpace.DisallowSpaceIndent.SpacesUsed` passes.)

This blocks **new** security / i18n / database regressions while accepting the
established formatting style.

Regenerate the baseline deliberately (and review the diff) only when the
codebase legitimately moves, e.g.:

```bash
phpcs --standard=WordPress --extensions=php --ignore='*/tests/*' --report=json . > report.json
# then fold report.json counts into tests/phpcs-baseline.json
```

## What the workflow does NOT cover (still manual)

| Not covered | Gate in `RELEASE-CHECKLIST.md` |
| --- | --- |
| Functional regression on a live site (cache serve/save, preload, WebP, CDN, DB tools) | §3 (walk `tests/QA-CHECKLIST.md`) |
| Environment matrix (WP 6.0, Apache/LiteSpeed, fresh install, upgrade) | §4 |
| Production ZIP assembly and byte/`sha256` verification | §5 |
| Readme validation against the live WordPress.org validator | §2.7 |
| Manual security review and triage of each remaining warning | `docs/REVIEW-FIXES.md` |

## Gate policy (permanent)

- Do not ship a version whose Plugin Check report is **worse** than the
  previous one, and never ship with any ERROR.
- The three current warnings (`hidden_files` `.gitignore`,
  `unexpected_markdown_file` `AGENTS.md`, `load_plugin_textdomainFound`) are
  classified as packaging / intentional. They are excluded from the production
  ZIP and do not block a release; they are documented in
  `docs/AUDIT-FINDINGS.md` → “Retained findings” (and in
  `docs/AUDIT-FINDINGS.md` → **K** / the round-9 runtime record).
- A check is only “green” if it actually ran. Blocked checks are recorded as
  Blocked, with the reason.
