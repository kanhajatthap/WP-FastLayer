---
name: WordPress Test Engineer
description: Use when writing or running tests for a WordPress plugin without PHPUnit, converting a bug into a regression test, or proving a fix with wp eval-file scripts, PHP lint, node --check, and honest test reporting.
---

# WordPress Test Engineer

A test claim is only valid if the test was actually executed. Record the command,
the environment, and the exit status — never write "passes" for a check that did
not run.

## When to use

- Converting a fixed bug into a regression test.
- No PHPUnit harness (typical for a single-plugin repo) — use `wp eval-file`.
- Preparing the "Tests actually executed" section of a report.

## Test harness without PHPUnit

Write a standalone script under `tests/test-<name>.php` that:
1. Asserts preconditions via `WP_CLI` availability (`class_exists('WP_CLI')`),
   then exercises the code under test.
2. Prints a clear `PASS`/`FAIL` line and calls `exit(0)`/`exit(1)` so CI can gate
   on it.
3. Cleans up anything it created (transients, options, temp files).
4. Is runnable with
   `wp eval-file tests/test-<name>.php --path=<site>`.

Add each to the CI workflow and to `tests/QA-CHECKLIST.md`.

## Prove the test catches the bug (A/B)

Temporarily revert the fix (keep the old body), show the test **FAILS**, then
restore the fix and show it **PASSES**. A regression test that passes against the
broken code is worthless.

## Static checks that must run

- `php -l` on every changed `.php` file.
- `node --check` on every changed `assets/js/*.js` file.
- Plugin Check for plugin-level rule regressions.
- WPCS (or a per-sniff delta) for coding-standard regressions — see the caveat
  below.

## WPCS caveat

Do **not** treat full `--standard=WordPress` as a pass/fail gate on a codebase
that intentionally uses 4-space PHP indentation: it reports thousands of
pre-existing `DisallowSpaceIndent` findings. Instead run WPCS before and after
and diff **per sniff**, asserting no *new* rule category appeared. Adding lines
in the house style is expected to raise the space-indent count.

## Reporting vocabulary

- **Executed** — a command ran and produced the cited output.
- **Source inspection** — read the code; no runtime proof.
- **Manual** — a human did it in a live site; not reproducible here.
- **Blocked / Not Run** — could not be performed; say why.

## Deliverable

- A table: test name, exact command, environment, result, and (for regressions)
  the A/B evidence that it fails without the fix.
