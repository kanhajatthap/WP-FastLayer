---
name: Release Gatekeeper
description: Use when deciding whether a plugin is ready to release: running the release checklist, verifying the three-place version sync, comparing quality-gate results against the previous release, and issuing a READY or BLOCKED verdict with evidence.
---

# Release Gatekeeper

Decide, with evidence, whether a build may ship. A release is READY only when
every gate is green **and** was actually executed. When in doubt, BLOCKED.

## When to use

- Final step before tagging/uploading a release.
- Reconciling a release checklist against the current code.
- Issuing a go/no-go verdict.

## Gates (all must pass)

1. **Version sync** — the version is identical in all three places: main-file
   `Version:` header, the `define()` constant, and `readme.txt` `Stable tag:`.
   Bump all three together or none.
2. **Static** — `php -l` on every file; `node --check` on every JS file.
3. **Plugin Check** — no new ERROR vs the previous release; total must not be
   worse. Compare against the previous run explicitly.
4. **WPCS delta** — no new rule category (see the space-indent caveat in
   `wordpress-test-engineer`).
5. **Functional** — the relevant QA-checklist sections walked, or listed as Not
   Run with a reason.
6. **Docs** — changelog + upgrade notice updated; checklists current; no doc
   referencing a section that does not exist.
7. **Packaging** — the production ZIP manifest matches the intended file list;
   dev-only files excluded.

## Rules

- Do **not** create or upload a release ZIP, tag, or publish anything unless
  explicitly asked. Preparing a release-candidate is not shipping it.
- Do not ship a version whose Plugin Check report is worse than the previous one.
- Never mark a check "passed" that was not executed. Use **Blocked / Not Run**
  with a reason.
- Keep evidence: exact command, environment, and the resulting numbers.

## Verdict format

Produce one of:

- **READY** — every gate green; list each command and result.
- **BLOCKED** — one or more gates failed or could not be run; list exactly what
  blocks and what would unblock it.

Always include the residual risks and the checks that remain Blocked, even on
READY. Report uncertainty honestly; do not claim guaranteed approval.
