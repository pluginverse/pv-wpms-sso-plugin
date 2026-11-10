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

`prepare` also sets `Stable tag` in `readme.txt` and adds the notes to its changelog. It refuses an empty `[Unreleased]`, a version that already has a section, and a version below the plugin header. Review the diff, run the checks in [Testing](testing.md), and commit as `chore(release): X.Y.Z`.

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

`package.py` writes `dist/pv-wpms-sso.zip` with one top-level `pv-wpms-sso/` folder holding `pv-wpms-sso.php`, `uninstall.php`, `readme.txt`, `LICENSE.txt`, `README.md`, `CHANGELOG.md`, `composer.json`, `src/`, and `vendor/`. It fails when the tag or the readme's stable tag differs from the plugin header, when a runtime file is missing, or when `vendor/` holds development packages. Timestamps and modes are fixed. `--verify` compares every file byte for byte except Composer's generated `vendor/composer/` files, whose names must match but whose content varies per install. Install the archive on a multisite before tagging.

## Tag and publish

```sh
python3 scripts/release.py notes X.Y.Z > /tmp/pv-wpms-sso-notes.txt
git tag -a vX.Y.Z -F /tmp/pv-wpms-sso-notes.txt
git push origin vX.Y.Z
```

The **GitHub release** workflow runs on the tag: it checks the tag against the plugin header and changelog, runs static analysis, the script tests, the behavior trace, PHPUnit and the BDD journeys, reinstalls production dependencies, builds and verifies the archive, runs Plugin Check on it, and publishes a release with the changelog notes and `pv-wpms-sso.zip`. The Playwright journeys run in CI on the branch push of the same commit, not again on the tag. Do not move a published tag; correct a release with a new version.

## WordPress.org

First publication needs steps only the owner can take:

1. Submit `pv-wpms-sso.zip` from the GitHub release at [wordpress.org/plugins/developers/add](https://wordpress.org/plugins/developers/add/) and wait for review. Plugin Check flags the name and slug for containing "wp"; if review assigns another slug, stop and plan the rename (slug, text domain, folder, and archive name change together).
2. In the repository settings, create the environment `wordpress-org` and **configure required reviewers**. Naming the environment in the workflow does not by itself require approval.
3. Add the environment secrets `SVN_USERNAME` and `SVN_PASSWORD`. Never put credentials in the repository.

After that, each version is published by dispatching **Publish to WordPress.org** with its `vX.Y.Z` tag once the GitHub release exists. The workflow refuses drafts and prereleases, downloads the release archive, verifies it against the tag, and, on approval, replaces SVN `trunk` and `assets`, creates `tags/X.Y.Z`, and commits once. An existing SVN tag stops it: correct a release with a new version. Listing artwork comes from `.wordpress-org/` at the same tag and stays outside the archive; see [WordPress.org listing](listing.md).

## Composer

The package is `pluginverse/pv-wpms-sso`, type `wordpress-plugin`; a pushed tag is a Composer version. It is not on Packagist, so consumers add the repository:

```json
{
    "repositories": [ { "type": "vcs", "url": "https://github.com/pluginverse/pv-wpms-sso-plugin" } ],
    "require": { "pluginverse/pv-wpms-sso": "^0.1" }
}
```

The repository is private: Composer needs GitHub credentials (`composer config --global github-oauth.github.com <token>`) until it is opened. Consumers also need `composer/installers` (allowed in `config.allow-plugins`) and an `extra.installer-paths` entry placing `type:wordpress-plugin` packages in the plugins directory. Installed this way the plugin has no `vendor/` of its own; the SAML library comes from the consumer's autoloader, which must be loaded before plugins (as Bedrock does).

## Plugin Check review notes

The packaged archive was checked with Plugin Check 2.2.0 on WordPress 7.1.4 and PHP 8.2, excluding `vendor/`. It reports no errors. These warnings remain on purpose and should be explained during directory review:

- **Name and slug contain "wp"** (`trademarked_term`): the name stands for "WordPress Multisite"; kept pending the directory's decision.
- **Reading `$_GET`, `$_POST`, and `$_COOKIE` without a nonce**: single sign-on callbacks arrive by redirect from another site, where a WordPress nonce cannot exist. Each flow is bound instead by a random state value held in an HttpOnly cookie and by signed, single-use tickets; the settings screen verifies its nonce in `SettingsPage::handleSubmission` before reading input. Values are validated by exact comparison, pattern, or signature rather than text sanitizers, which would corrupt tokens and URLs.
- **`wp_redirect()`**: used only for URLs the plugin builds itself (the identity site's authorize endpoint, the SAML provider's sign-on URL). User-supplied destinations go through `RedirectGuard` and `wp_safe_redirect()`.
- **Direct database queries without caching**: the single-use ticket registry is the plugin's own table, where a cached read would defeat the atomic claim; uninstall deletes the plugin's own options and drops that table.

Scanner annotations in `src/` are single-line and state their reason: exception text that is escaped where it is shown, login markup assembled from escaped parts, and `parse_url()` in `RedirectGuard`, which is unit-tested without WordPress. No check or file is disabled wholesale. Review the warnings again for each release.

## Built branches

CI still force-pushes `<branch>-built` for every branch push (`main-built` for deployments that track it). Those branches carry no version of their own; the tag and its archive are the release.
