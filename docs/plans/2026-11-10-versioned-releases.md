---
title: "Plan: Versioned releases"
description: "Release tooling and the first tagged version, following the PV Redirects process."
status: approved
date: 2026-11-10
size: bounded
specs: []
---

## Intent

Spenser asked to review the local-session work and, if sound, prepare a release "like we did with pv-quick-redirects". This repository has no tags, no release notes per version, and no installable archive. Success: one command prepares a version, one pushed tag produces a tested GitHub release with `pv-wpms-sso.zip`.

## Acceptance criteria

- `python3 scripts/release.py prepare X.Y.Z` moves `[Unreleased]` notes under `## [X.Y.Z] - date` and sets the plugin header version; it refuses an empty changelog, an existing version, and a version below the header.
- `python3 scripts/release.py notes X.Y.Z` prints exactly that version's notes.
- `python3 scripts/package.py` writes `dist/pv-wpms-sso.zip` containing only runtime files under `pv-wpms-sso/`; `--tag` must equal the header version; `--verify` compares an archive with the checkout.
- Packaging fails when `vendor/` is missing or holds development packages.
- A pushed `v*` tag runs the checks, builds, verifies, and publishes the archive with the changelog notes. A tag push no longer breaks the built-branch job.
- Script behavior is covered by `composer test-scripts`, which CI runs.

## Non-goals

- WordPress.org or Packagist publication.
- Changing or retiring the `<branch>-built` branches.
- Translations: there is no `languages/` directory yet.

## Design

Port `scripts/release.py` and `scripts/package.py` from PV Redirects, minus `readme.txt`, `package.json` versions, and JavaScript bundles; plus `vendor/`, guarded through Composer's own `vendor/composer/installed.json` (`"dev": false`). The archive holds `pv-wpms-sso.php`, `uninstall.php`, `LICENSE.txt`, `README.md`, `CHANGELOG.md`, `composer.json`, `src/`, `assets/`, and `vendor/`, with fixed timestamps and modes. `.github/workflows/release.yml` runs on `v*` tags: static analysis, script tests, behavior trace, PHPUnit and BDD under wp-env, then a production Composer install, package, verify, notes, release. The runner-up, tagging the built branch, lost because those commits never run the tests.

## Spec delta

None: no plugin behavior changes.

## Docs impact

- `docs/guides/releasing.md`: new guide.
- `AGENTS.md`, `docs/guides/testing.md`, `README.md`: point at the guide, the script tests, and the release archive.
- `CHANGELOG.md`: first version section.

## Decisions

- [0010: Publish versioned release archives from semantic tags](../decisions/0010-versioned-release-archives.md)

## Tasks

1. Release tooling: `scripts/`, `scripts/tests/`, `composer.json` script, `.github/workflows/release.yml`, `ci.yml` (script tests; built branch only for branch pushes), ignore files, guide and pointers. Commit: `build(release): add versioned release archives`.
2. First release: run `prepare 0.1.0`, review, commit `chore(release): 0.1.0`. Tagging and pushing wait for Spenser.

## Rulings

- [version] First release is 0.1.0, the version the header has carried since the first commit — real OneLogin and production two-factor validation are still open, so not 1.0.0 — cost if wrong: low, retag before publishing.
- [workflow] The tag workflow does not repeat the Playwright suite; CI runs it on the same commit when the branch is pushed — cost if wrong: low, a browser regression would already show on the branch.
- [archive] `CHANGELOG.md` ships in the archive although the built branch drops it: a release should carry its notes — cost if wrong: low.
- [hygiene] Stop tracking `.phpunit.result.cache`; it is a local cache that every test run dirties — cost if wrong: low.

## Deferred

- Publishing: pushing the branch, merging to `main`, and pushing the tag are Spenser's call.
