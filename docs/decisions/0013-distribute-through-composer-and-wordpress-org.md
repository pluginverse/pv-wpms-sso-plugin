---
title: "0013: Distribute through Composer and a gated WordPress.org deployment"
description: "Make tagged versions requirable with Composer and publish the same validated archive to WordPress.org through a separately approved workflow."
status: accepted
date: 2026-11-10
decision-makers: "Spenser Hale (requested, following PV Redirects), Claude (draft)"
---

## Context

[Decision 0010](0010-versioned-release-archives.md) left WordPress.org and Composer out and named them as its revisit signal. Spenser now wants 0.1.0 set up for both, the way PV Redirects is ([its decision 0007](https://github.com/pluginverse/pv-redirects-plugin/blob/main/docs/decisions/0007-versioned-release-archives.md)). WordPress.org assigns the SVN repository only after review, reviewers run Plugin Check, and the directory needs a `readme.txt`, standard licence metadata, and listing artwork.

## Options considered

1. **Same archive everywhere, WordPress.org behind a manual, environment-gated workflow**: GitHub and the directory ship identical files; publication timing and credentials stay explicit. This is the PV Redirects arrangement.
2. **Deploy every tag to SVN automatically**: one step fewer, but ties every GitHub release to directory access that does not exist yet.
3. **Composer only**: nothing to review, but no directory listing or update channel for sites that do not use Composer.

## Decision

We will keep one validated `pv-wpms-sso.zip` per `vX.Y.Z` tag. A manually dispatched **Publish to WordPress.org** workflow downloads the published release archive, verifies it against the tag, and commits it to SVN trunk and a new `tags/X.Y.Z` only after the `wordpress-org` environment approves; listing artwork lives in `.wordpress-org/`, outside the archive. The archive must pass Plugin Check with no errors in CI and on the tag. `readme.txt` carries the stable tag and changelog, kept in step by `scripts/release.py`.

Composer consumers require `pluginverse/pv-wpms-sso` at a tagged version through a VCS repository; the package declares PHP 8.2 and its SAML dependency, and keeps test classes out of the consumer autoloader. No Packagist registration is implied.

Following the PV Redirects precedent, the distributed plugin uses plain GPL-3.0-or-later metadata and a full licence copy; the bespoke brand paragraph headed "Additional Permission" is removed because it reads as a further restriction. Copyright attribution stays.

## Consequences

Directory and GitHub users get the same files. Submission, the `wordpress-org` environment's required reviewers, and the SVN secrets are the owner's manual steps. Scanner annotations in `src/` are narrow and explained; remaining Plugin Check warnings are documented for review rather than silenced. Plugin Check flags the name and slug for containing "wp"; whether the directory accepts `pv-wpms-sso` is decided at review, and a rename would be a separate decision. The repository is private, so Composer consumers need GitHub credentials until it is opened.

## Revisit when

WordPress.org assigns a different slug or rejects the name, the repository becomes public or is registered on Packagist, or the licence wording needs to change.

## Links

- Plan: [Composer and WordPress.org distribution](../plans/2026-11-10-composer-and-wordpress-org.md)
- Guides: [Releasing](../guides/releasing.md), [WordPress.org listing](../guides/listing.md)
