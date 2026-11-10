---
title: "0010: Publish versioned release archives from semantic tags"
description: "Cut releases as vX.Y.Z tags whose workflow tests the commit and attaches one validated, installable plugin ZIP."
status: accepted
date: 2026-11-10
decision-makers: "Spenser Hale (requested the pv-redirects release process), Claude (draft)"
---

## Context

The plugin has no versions: CI force-pushes a `<branch>-built` branch per push, the changelog only has `[Unreleased]`, and nothing says which commit an install runs. The [release plan](../plans/2026-11-10-versioned-releases.md) asks for the process PV Redirects already uses. This plugin differs in one way that matters: it bundles `onelogin/php-saml` under `vendor/`, so an archive built from a development checkout would ship test tooling.

## Options considered

1. **Semantic tag, validated ZIP on a GitHub release**: one reviewed artifact per version, release notes taken from the changelog, same routine as PV Redirects. Costs two small scripts and a workflow.
2. **Keep only the built branch**: nothing to add, but no version, no notes, and a moving target for every consumer.
3. **Tag the built branch**: versions without an archive, but tags would point at commits that never ran the tests.

## Decision

We will release from annotated `vX.Y.Z` tags on tested source commits. `scripts/release.py prepare` moves the unreleased changelog notes under the version and sets the plugin header; the tag workflow re-runs the checks, installs production dependencies only, and attaches `pv-wpms-sso.zip` (top-level folder `pv-wpms-sso/`) to a GitHub release whose body is that changelog section. `scripts/package.py` refuses a tag that disagrees with the plugin header and a `vendor/` that contains development packages. The built branches stay as they are for existing deployments.

## Consequences

Every install can name its version and its notes. Releasing needs the changelog to be current, which the commit rules already require. The archive depends on the Composer install it was built from, so it is verified against the checkout that produced it, not rebuilt elsewhere byte for byte. WordPress.org publication is out of scope: the plugin is multisite-only and the repository is private.

## Revisit when

The plugin is submitted to WordPress.org or Packagist, or deployments stop using the built branches.

## Links

- Plan: [Versioned releases](../plans/2026-11-10-versioned-releases.md)
- Guide: [Releasing](../guides/releasing.md)
