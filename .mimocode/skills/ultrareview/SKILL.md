# Skill: ultrareview

Multi-agent parallel code review: spawn specialized review agents, cross-validate findings, output a consolidated report.

## Quick Start

1. Determine review scope (files, directories, PR diff, or full project)
2. Spawn 5 parallel review agents (scale to 20 for large codebases):
   - **Logic Verifier** -- algorithmic correctness, off-by-one, unreachable code
   - **Security Sentinel** -- OWASP Top 10, injection, auth bypass, secrets
   - **Performance Oracle** -- N+1 queries, blocking I/O, complexity
   - **Boundary Inspector** -- null handling, empty collections, overflow, timezones
   - **Architecture Reviewer** -- SOLID violations, coupling, API mismatches
3. Cross-validate: each agent challenges other agents' findings; discard unconfirmed issues
4. Aggregate, deduplicate, rank by severity, output report

## Review Process

### Phase 1: Context Collection
- Read project's `AGENTS.md` for custom rules
- Identify scope: specific files, git diff, or full codebase
- Map entry points, dependencies, and data flow paths

### Phase 2: Parallel Agent Dispatch
Spawn agents as subagents. Each agent receives:
- Full code context for the review scope
- Specialization prompt defining its review angle
- Project-specific rules from AGENTS.md
- **Directive langue** : Tous les constats, descriptions, causes racines et corrections proposées DOIVENT être rédigés en français.

Minimum 5 agents for meaningful cross-validation.

### Phase 3: Cross-Validation
After initial findings:
1. Share findings across agents
2. Each agent attempts to disprove others' findings
3. Retain only findings confirmed by evidence
4. Assign confidence score based on cross-agent agreement

### Phase 4: Report Generation

Severity classification:
- **Critical**: Must fix. Confirmed bugs, security vulnerabilities, data corruption
- **Warning**: Should fix. Potential issues, performance concerns, code smells
- **Pre-existing**: Not introduced by current changes. Historical tech debt

## Output Format

**Language**: All reports MUST be written in French (français). All findings, descriptions, root causes, and suggested fixes must be in French.

```markdown
# Rapport Ultrareview
**Périmètre**: [fichiers/dossiers revus]
**Agents**: [nombre] | **Durée**: [temps] | **Constats**: [nombre]

## Critique (N)
### [C1] [Titre court]
- **Emplacement**: `chemin/vers/fichier.php:42-58`
- **Problème**: [Description]
- **Cause racine**: [Pourquoi c'est un bug]
- **Correction**: [Correction suggérée]

## Avertissement (N)
### [W1] ...

## Prédéfini (N)
### [P1] ...

## Zones analysées — Aucun problème
- [Liste des zones sans problème]
```

## Excluding From Review

Respect `AGENTS.md` ignore patterns. Common exclusions:
- Generated code, vendored dependencies, test fixtures
- Style/formatting issues (never flag these)

## After Review

- Fix critical issues first, then warnings
- Run `rtk phpunit` after each fix
- Commit with `--author="onoblanc <admin.local@exemple.invalid>"`
- Update CHANGELOG.md and TODO.md

## KISS Reminder

This is a small intranet project. Do not flag:
- Architectural preferences that work fine as-is
- Missing patterns that aren't needed for the scale
- "Should" refactorings that don't fix real bugs

Only flag things that are **actually broken**, **actually exploitable**, or **actually slow**.
