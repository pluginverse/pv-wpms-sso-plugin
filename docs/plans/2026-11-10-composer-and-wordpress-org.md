---
title: "Plan: Composer and WordPress.org distribution"
description: "Make 0.1.0 requirable with Composer and ready for WordPress.org review and publication."
status: done
date: 2026-11-10
size: bounded
specs: []
---

## Intent

Spenser wants the 0.1.0 release set up like PV Redirects: requirable with Composer and ready for WordPress.org. Today there is no `readme.txt`, Plugin Check reports 40 errors on the archive, there is no listing artwork or SVN workflow, and `composer.json` exposes test classes to consumers. Success: the archive passes Plugin Check without errors, the directory files exist, and publishing is one approved dispatch after review.

## Acceptance criteria

- Plugin Check reports no errors on the packaged archive, in CI and in the tag workflow.
- `readme.txt` exists with name, short description, required headers, and a changelog; `release.py prepare` keeps its stable tag and changelog in step; `package.py` refuses a stable tag that differs from the plugin header.
- `.wordpress-org/` holds icons (128, 256), banners (772×250, 1544×500), and screenshots of the real plugin matching the readme captions.
- `Publish to WordPress.org` is manual, environment-gated, verifies the published archive against its tag, and refuses an existing SVN tag.
- `composer.json` declares PHP, keywords, and the runtime dependency, and autoloads tests only in development.
- All existing suites stay green.

## Non-goals

- Submitting the plugin, configuring the GitHub environment or secrets, or making the repository public.
- Renaming the plugin or slug.
- Translations.

## Design

Port PV Redirects' `readme.txt` handling, deploy script, and workflow, adapted to this slug and to the bundled `vendor/`. Fix Plugin Check errors at the cause where possible: `%i` identifier placeholders for the ticket registry queries, `wp_parse_url` in WordPress-only code, a direct-access guard, escaping of a developer notice. Where the scanner cannot see the boundary (exception text, pre-escaped login markup, the pure `RedirectGuard` that is unit-tested without WordPress), add one-line annotations with the reason. Drop `load_plugin_textdomain`: there is no bundled `languages/` directory and WordPress loads directory translations itself. Listing artwork reuses the Pluginverse symbol and banner layout; screenshots are captured with Playwright from the disposable browser environment. `assets/` leaves the archive: it holds brand and listing sources, nothing the plugin loads.

## Spec delta

None: no behavior changes.

## Docs impact

- `docs/guides/releasing.md`: WordPress.org, Composer, Plugin Check notes. New `docs/guides/listing.md`.
- `README.md`: Composer instructions. `AGENTS.md`: pointers. `CHANGELOG.md`: amend unpublished 0.1.0 notes.

## Decisions

- [0013: Distribute through Composer and a gated WordPress.org deployment](../decisions/0013-distribute-through-composer-and-wordpress-org.md)

## Tasks

1. Plugin Check fixes, `readme.txt`, licence metadata, Composer metadata, scripts and tests, workflows, artwork, guides. Commit: `build(release): prepare Composer and WordPress.org distribution`.

## Rulings

- [licence] Remove the brand paragraph and ship the full GPL text, as PV Redirects did with owner approval — cost if wrong: medium, restore before tagging; called out in the pull request.
- [name] Keep `PV WPMS SSO` / `pv-wpms-sso` despite the "wp" trademark warning — renaming is a product decision — cost if wrong: medium, directory review may require a rename before approval.
- [tested up to] 7.1, the version the suites run on — cost if wrong: low.

## Deferred

- Directory submission and environment setup: owner steps, listed in the releasing guide.
