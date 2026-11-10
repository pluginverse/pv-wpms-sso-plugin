# AGENTS.md

## Purpose

PV WPMS SSO (Plugin Verse WordPress Multisite Single Sign On) is a network-activated WordPress plugin that gives domain-mapped multisite — and same-database multi-network (wp-multi-network) — installs single sign-on: one identity site, one login (including two-factor), tickets into every site's session. Deployed from `pluginverse/pv-wpms-sso-plugin`.

## Quick start

```sh
composer install        # dev tooling plus bundled php-saml runtime dependency
composer env:start      # wp-env docker multisite dev env (needs Docker)
composer test           # PHPUnit via wp-env
composer phpstan        # static analysis, level 9
composer env:stop
```

`composer test` and `env:start` need Docker running; they fail closed without it. `composer phpstan` runs on the host and needs only `composer install`.

## Repo layout

- `pv-wpms-sso.php` — main plugin file: header, autoloader, hook wiring (the only file WordPress loads)
- `src/` — `PV\WpmsSso` classes; runtime class-map autoloader, no Composer at runtime
- `tests/` — PHPUnit: pure unit tests + wp-phpunit integration tests
- `scripts/` — release tooling (`release.py`, `package.py`) and its tests
- `docs/` — specs, decisions, plans, guides (see below)
- `assets/` — plugin icon and brand assets
- `.agents/skills/` — project skills (WordPress CLI, VIP CLI, performance)

## Documentation

Durable project knowledge lives in `docs/` as Markdown with `title` and `description` frontmatter and no body H1 (Starlight-compatible). Before changing a feature's behavior, read its spec and the decisions that touch it.

| Kind | Path | Rule |
|---|---|---|
| Specs: what each feature does now | `docs/specs/<feature>.md` | Change in the same commit as the behavior; never ahead of the code |
| Decisions: why | `docs/decisions/NNNN-<title>.md` | New record for every strong decision; supersede, never rewrite |
| Plans: one change, designed and tasked | `docs/plans/YYYY-MM-DD-<topic>.md` | Write and commit before the code |
| Product | `docs/product/vision.md` | Read before scoping a change |
| Guides | `docs/guides/security.md` | Binding for anything touching auth, keys, redirects, or the probe endpoint |
| Changelog | `CHANGELOG.md` | Add an `## [Unreleased]` line in the same commit as any user-visible change |

Templates: `_template.md` in `docs/specs/`, `docs/decisions/`, and `docs/plans/`. A decision is strong when it is hard to reverse, adds a dependency or a pattern others will copy, changes a public interface or data model, touches security, privacy, or pricing, or departs from an accepted decision (or changes something a spec states that the change did not set out to change). Smaller calls go in the plan's `## Rulings`. Never deviate silently from a spec or an accepted decision: ask first; only when no human is in the loop, write the superseding record and call it out in the pull request.

## Progressive disclosure

Before editing, check whether the task touches one of these areas and read the guide first (a TODO line is a guide not written yet):

- Security/auth: `docs/guides/security.md` (trust model, key handling, attack table)
- Testing: `docs/guides/testing.md` — PHPUnit, executable BDD, behavior IDs, and isolated HTTPS browser fixtures.
- Releasing: `docs/guides/releasing.md` — version preparation, the release archive, tags, and the release workflow.

## Skills

Use `.agents/skills/` when the task matches a documented workflow:

- `.agents/skills/wordpress-cli/`: WP-CLI commands for managing the plugin's test site.
- `.agents/skills/wp-vip-cli/`: VIP-CLI for VIP local dev environments.
- `.agents/skills/wordpress-performance/`: hook-wiring rules (binding for `src/`), measurement workflow.

## Coding standards

1. **YAGNI**: Don't build it until you need it
2. **DRY**: Extract patterns after second duplication, not before
3. **Fail Fast**: Explicit errors beat silent failures
4. **Simple First**: Write the obvious solution, optimize only if needed
5. **Delete Aggressively**: Less code = fewer bugs
6. **Semantic Naming**: Always name variables, parameters, and API endpoints with verbose, self-documenting names that optimize for comprehension by both humans and LLMs, not brevity (e.g., `wait_until_obs_is_saved=true` vs `wait=true`)

Plugin-specific, binding:

- PHP 8.2 floor, WordPress 6.5 floor, multisite only. `declare(strict_types=1)` in every `src/` file.
- Hook callbacks are static `['Class', 'method']`; classes load at first fire, not at registration; no I/O in constructors (`.agents/skills/wordpress-performance/`).
- All user-facing strings translatable under text domain `pv-wpms-sso`.
- Signatures compared with `hash_equals`; redirects through `wp_safe_redirect` after `RedirectGuard` validation.

## Commits

A change starts with its plan, committed before any code together with the decision records made while designing. After that, one commit is one complete change: the code, its tests, and every doc it changes (spec, guide, changelog line, decision record). Conventional Commits subject (`feat(scope): ...`); the body says why and names the docs it touched (`Spec:`, `Decision:`, `Plan:`).

## Done criteria

Before finishing any task:

- Run `composer phpstan` and `composer test` and fix failures before declaring done (say so if Docker was unavailable and tests were skipped)
- Behavior changed: the spec, changelog, and any affected guide changed in the same commit
- A strong decision was made: its decision record exists
- If a check was skipped, say why; flag risky assumptions explicitly
