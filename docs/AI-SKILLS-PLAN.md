# AI-SKILLS-PLAN.md — reusable engineering skills for FastLayer

**Recorded:** 2026-10-15
**Purpose:** turn the knowledge gained in this audit into seven reusable,
loadable skills, so future work on FastLayer (and other WordPress plugins)
starts from the same standards instead of rediscovering them.

## Format

OpenCode V2 project skills live at `.opencode/skills/<id>/SKILL.md`:

- YAML frontmatter with `name` (display) and `description` (used to advertise
  and auto-load the skill).
- The **skill id is the directory name** (kebab-case, case-sensitive).
- The body is the instructions the agent follows when the skill is loaded.

They are project-scoped (committed under the plugin, excluded from the
production ZIP) and require no external dependencies.

## The seven skills

| # | Skill id | Description (frontmatter) | Why it exists |
| --- | --- | --- | --- |
| 1 | `wordpress-plugin-architect` | Designing a plugin’s skeleton: namespaces/autoloader, bootstrap order, drop-ins, naming, BC migrations, three-place version sync | Locks in the invariants FastLayer must never break (slug, text domain, autoloader, drop-in ownership). |
| 2 | `wordpress-security-auditor` | Nonce/capability, sanitize/unslash, escape, SQL preparation, request-handler review | Codifies the mandatory §4 security rules and the “prove it, do not suppress it” triage used for findings C and the nonce/sanitize set. |
| 3 | `wordpress-filesystem-auditor` | Path validation, atomic writes, `WP_Filesystem`, load-order exceptions, safe dir removal | Encodes findings A and B plus the R7 WP_Filesystem work and the legitimate native calls in `advanced-cache.php`. |
| 4 | `wordpress-performance-auditor` | Page cache, output buffering, invalidation, minify/combine, lazy load, WebP, CDN, preload | Encodes finding D (buffer ordering) and the correctness-first mindset for the performance features. |
| 5 | `wordpress-test-engineer` | Testing without PHPUnit via `wp eval-file`, A/B regression proof, honest reporting vocabulary | Encodes the `tests/*.php` pattern and the “A test claim is only valid if executed” rule. |
| 6 | `wordpress-org-reviewer` | Slug/text-domain rules, readme validity, prefixing, Plugin Check triage, licensing | Encodes why the folder is lowercase and how to classify the three remaining warnings. |
| 7 | `release-gatekeeper` | Release checklist, version sync, gate comparison, READY/BLOCKED verdict | Encodes the release rules and the “do not ship a worse report” policy. |

## How they map to this audit

| Audit finding / artifact | Skill that carries the lesson forward |
| --- | --- |
| A — URL→path conversion | `wordpress-filesystem-auditor` |
| B — executable config | `wordpress-filesystem-auditor`, `wordpress-plugin-architect` |
| C — escaping | `wordpress-security-auditor`, `wordpress-performance-auditor` |
| D — output buffers | `wordpress-performance-auditor` |
| E — log location | `wordpress-plugin-architect`, `wordpress-filesystem-auditor` |
| F — inline scripts | `wordpress-performance-auditor` |
| G — whole-plugin audit | `wordpress-org-reviewer`, `release-gatekeeper` |
| Regression scripts | `wordpress-test-engineer` |
| CI workflow | `release-gatekeeper` |

## Usage

- An agent loads a skill automatically when a task matches the `description`,
  or explicitly by id.
- Each skill is self-contained and ends with a “deliverable” checklist so its
  output is reviewable.
- Keep the ids stable: renaming a directory changes the skill id.

## Maintenance

- When a new permanent rule is discovered, add it to the most specific skill and
  to `AGENTS.md`.
- When a rule becomes enforced automatically (e.g. in CI), link the two rather
  than duplicating the check in prose.
