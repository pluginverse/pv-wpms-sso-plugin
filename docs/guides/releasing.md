---
title: Releasing
description: Prepare a version, verify its archive, and publish a tagged GitHub release.
---

## Prepare

Release from `main`, on a commit whose CI run is green. Release notes are the `## [Unreleased]` section of `CHANGELOG.md`; every user-visible change already adds its line there.

```sh
python3 scripts/release.py prepare X.Y.Z   # moves the notes under the version, sets the plugin header
python3 scripts/release.py notes X.Y.Z     # prints what the GitHub release and the tag will say
```

`prepare` refuses an empty `[Unreleased]`, a version that already has a section, and a version below the plugin header. Review the diff, run the checks in [Testing](testing.md), and commit as `chore(release): X.Y.Z`.

## Verify the archive

The archive bundles `vendor/` (onelogin/php-saml), so it must be built from a production install. Build it from a clean export so the development checkout keeps its tooling:

```sh
rm -rf /tmp/pv-wpms-sso-release && mkdir /tmp/pv-wpms-sso-release
git archive HEAD | tar -x -C /tmp/pv-wpms-sso-release
cd /tmp/pv-wpms-sso-release
composer install --no-dev --optimize-autoloader
python3 scripts/package.py --tag vX.Y.Z
python3 scripts/package.py --tag vX.Y.Z --verify dist/pv-wpms-sso.zip
```

`package.py` writes `dist/pv-wpms-sso.zip` with one top-level `pv-wpms-sso/` folder holding `pv-wpms-sso.php`, `uninstall.php`, `LICENSE.txt`, `README.md`, `CHANGELOG.md`, `composer.json`, `src/`, `assets/`, and `vendor/`. It fails when the tag differs from the plugin header, when a runtime file is missing, or when `vendor/` holds development packages. Timestamps and modes are fixed; the bytes still depend on the Composer install, so an archive is verified against the checkout that built it. Install the archive on a multisite before tagging.

## Tag and publish

```sh
python3 scripts/release.py notes X.Y.Z > /tmp/pv-wpms-sso-notes.txt
git tag -a vX.Y.Z -F /tmp/pv-wpms-sso-notes.txt
git push origin vX.Y.Z
```

The **GitHub release** workflow runs on the tag: it checks the tag against the plugin header and changelog, runs static analysis, the script tests, the behavior trace, PHPUnit and the BDD journeys, reinstalls production dependencies, builds and verifies the archive, and publishes a release with the changelog notes and `pv-wpms-sso.zip`. The Playwright journeys run in CI on the branch push of the same commit, not again on the tag. Do not move a published tag; correct a release with a new version.

## Built branches

CI still force-pushes `<branch>-built` for every branch push (`main-built` for deployments that track it). Those branches carry no version of their own; the tag and its archive are the release.
