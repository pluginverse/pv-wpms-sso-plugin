# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- SSO button on each site's login screen delegating to the network's root or designated identity site, with signed single-use tickets, a credentialed session probe that personalizes the button ("Continue as {name}" with avatar), and super-admin-default scope configurable per network. Works across networks of a wp-multi-network (same-database) install.
- Network admin settings screen (Settings → SSO): enable/disable, identity site selection (root or designated, cross-network capable), scope, button label, signing-key rotation.
- WP-CLI commands: `wp pv-wpms-sso status`, `wp pv-wpms-sso rotate-key`.

